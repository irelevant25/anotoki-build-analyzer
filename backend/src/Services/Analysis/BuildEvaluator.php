<?php

namespace App\Services\Analysis;

use App\Enums\Game;
use App\Models\AccountCharacter;
use App\Models\AccountItem;
use App\Models\Build;
use App\Presenters\Icons;
use App\Support\Clock;
use App\Support\Neutral;
use App\Support\Stats;
use App\Support\Talents;

/**
 * Compares one character's current equipment with one recommended build.
 *
 * A build needs a constellation (min_rank): one the character has not reached is judged all the same, and says so
 * (applies: false) - the analysis never calls it the best. Its stat targets are those of the character's
 * constellation: of a stat's targets, the one from the highest constellation the character has reached.
 *
 * Produces a 0-100 score per part (weapon, sets, main stats, substats, stat targets), an overall grade and
 * a prioritized list of recommendations. Parts a build has no data for are left out of the overall score.
 *
 * A recommendation says what it is about twice: as a kind with its parameters (KINDS), which a page can word
 * in any language, and as the English sentence in "message".
 */
final class BuildEvaluator
{
    public const PART_WEIGHTS = ['weapon' => 20, 'sets' => 25, 'main_stats' => 20, 'substats' => 25, 'targets' => 10];

    /**
     * Every kind of recommendation with the names of its parameters, in the order they are sent.
     *
     * A clause a sentence may or may not have is a kind of its own (_owned, _on, _empty), never an English
     * fragment inside a parameter. Parameters are flat: names and labels as the message uses them
     * ("slot_label" is the label; the recommendation's own "slot" is the key), numbers as numbers, lists
     * joined as in the message. "weapon_label" and "set_label" start with a capital, "refinement" is
     * prefix plus number ("R1", "S1"), "best" is the option without its "(any N)" and "best_choose" that N
     * or null.
     */
    public const KINDS = [
        'weapon_none' => ['weapon_label', 'options'],
        'weapon_level' => ['name', 'level', 'max_level'],
        'weapon_unlisted' => ['name', 'weapon_label', 'options'],
        'weapon_unlisted_owned' => ['name', 'weapon_label', 'options', 'owned', 'refinement'],
        'weapon_unlisted_owned_on' => ['name', 'weapon_label', 'options', 'owned', 'refinement', 'character'],
        'weapon_better_owned' => ['owned', 'refinement', 'owned_rank', 'name', 'rank'],
        'weapon_better_owned_on' => ['owned', 'refinement', 'owned_rank', 'name', 'rank', 'character'],
        'weapon_low_rank' => ['name', 'rank', 'options'],
        'sets_missing' => ['set_label', 'best', 'best_choose', 'current'],
        'sets_missing_empty' => ['set_label', 'best', 'best_choose'],
        'sets_lower_option' => ['set_label', 'current', 'rank', 'best', 'best_choose'],
        'main_stat_missing' => ['slot_label', 'wanted'],
        'main_stat_wrong' => ['slot_label', 'stat', 'wanted'],
        'item_level' => ['slot_label', 'level', 'max_level'],
        'substats_weak' => ['slot_label', 'percent', 'stats'],
        'target_below' => ['stat', 'stat_key', 'current', 'min'],
        'talent_level' => ['talent', 'level'],
        'character_level' => ['level'],
    ];

    private Game $game;

    /** @var list<array{severity: string, category: string, kind: string, params: array<string, string|int|float|null>, message: string, slot?: string}> */
    private array $recommendations = [];

    public function __construct(private readonly AccountContext $context) {}

    public function evaluate(AccountCharacter $owned, Build $build): array
    {
        $this->game = $this->context->account->game;
        $this->recommendations = [];

        $character = $owned->character;
        $element = $character->element;
        $items = $this->context->equippedItems($character->id);
        $weapon = $this->context->equippedWeapon($character->id);

        $mainStats = $this->resolveMainStats($build->main_stats ?? [], $element);
        // A build that lists no substats is scored by what its own main stats ask for.
        $substatPriorities = $this->resolveList($build->substats ?: ItemScorer::fallbackSubstats($this->game, $mainStats), $element);
        $scorer = new ItemScorer($this->game, $substatPriorities);

        $weaponPart = $this->weaponPart($build, $weapon);
        $setPart = $this->setPart($build, $items);
        $mainPart = $this->mainStatPart($mainStats, $items);
        [$substatPart, $itemResults] = $this->substatPart($scorer, $items, $mainStats);
        $targetPart = $this->targetPart($build, $owned);
        $this->talentAdvice($build, $owned);
        $this->levelAdvice($owned);

        $parts = [
            'weapon' => $weaponPart['score'] ?? null,
            'sets' => $setPart['score'] ?? null,
            'main_stats' => $mainPart['score'] ?? null,
            'substats' => $substatPart,
            'targets' => $targetPart['score'] ?? null,
        ];

        $overall = $this->overall($parts);

        return [
            'build' => $this->buildSummary($build, $owned),
            'score' => ['overall' => $overall, 'grade' => self::grade($overall), 'parts' => $parts],
            'weapon' => $weaponPart,
            'sets' => $setPart,
            'main_stats' => $mainPart,
            'items' => $itemResults,
            'targets' => Neutral::deep($targetPart['rows'] ?? []),
            'substat_weights' => $scorer->weights(),
            'recommendations' => $this->sortedRecommendations(),
        ];
    }

    public static function grade(?int $score): string
    {
        return match (true) {
            $score === null => '-',
            $score >= 90 => 'S',
            $score >= 80 => 'A',
            $score >= 65 => 'B',
            $score >= 50 => 'C',
            default => 'D',
        };
    }

    // ---------------------------------------------------------------- weapon

    private function weaponPart(Build $build, $weapon): ?array
    {
        $list = $build->weapons ?? [];
        if ($list === []) {
            return null;
        }

        // The label goes out as it is ("Weapon"); only the English sentence lower-cases it.
        $weaponLabel = $this->game->weaponLabel();
        $label = strtolower($weaponLabel);
        $top = array_map(fn($w) => $w['name'], array_slice($list, 0, 3));
        $options = implode(', ', $top);

        if (!$weapon) {
            $this->recommend('high', 'weapon', 'weapon_none', ['weapon_label' => $weaponLabel, 'options' => $options], "No {$label} equipped. Best options: " . implode(', ', $top) . '.');

            return ['score' => 0, 'equipped' => null, 'rank' => null, 'owned_better' => [], 'total' => count($list)];
        }

        $key = $weapon->weapon->key;
        $rank = null;
        foreach ($list as $index => $entry) {
            if ($entry['key'] === $key) {
                $rank = $index + 1;
                break;
            }
        }

        $score = $rank === null ? 40 : max(60, 100 - ($rank - 1) * 8);
        $maxLevel = $this->game->maxWeaponLevel();
        if ($weapon->level < $maxLevel - 10) {
            $score = (int) round($score * 0.9);
            $this->recommend(
                'low',
                'weapon',
                'weapon_level',
                ['name' => $weapon->weapon->name, 'level' => $weapon->level, 'max_level' => $maxLevel],
                "Level up {$weapon->weapon->name} (level {$weapon->level}/{$maxLevel}).",
            );
        }

        // Better-ranked weapons the player already owns.
        $ownedBetter = [];
        foreach (array_slice($list, 0, $rank === null ? count($list) : $rank - 1) as $index => $entry) {
            $copies = $this->context->ownedWeapons($entry['key']);
            if ($copies === []) {
                continue;
            }
            usort($copies, fn($a, $b) => [$b->refinement, $b->level] <=> [$a->refinement, $a->level]);
            $best = $copies[0];
            $ownedBetter[] = [
                'key' => $entry['key'],
                'name' => $entry['name'],
                'rank' => $index + 1,
                'refinement' => $best->refinement,
                'level' => $best->level,
                'equipped_on' => $best->equipped_character_id !== $weapon->equipped_character_id ? $this->context->characterName($best->equipped_character_id) : null,
            ];
        }

        $refinementPrefix = $this->game->refinementPrefix();
        // The best better-ranked weapon owned, as the sentences name it; who holds it is the "_on" kind.
        $owned = $ownedBetter ? ['owned' => $ownedBetter[0]['name'], 'refinement' => $refinementPrefix . $ownedBetter[0]['refinement']] : [];
        $on = $ownedBetter && $ownedBetter[0]['equipped_on'] ? ['character' => $ownedBetter[0]['equipped_on']] : [];
        if ($rank === null) {
            $kind = 'weapon_unlisted';
            $params = ['name' => $weapon->weapon->name, 'weapon_label' => $weaponLabel, 'options' => $options];
            $message = "{$weapon->weapon->name} is not on this build's {$label} list. Recommended: " . implode(', ', $top) . '.';
            if ($ownedBetter) {
                $kind = $on ? 'weapon_unlisted_owned_on' : 'weapon_unlisted_owned';
                $params += $owned + $on;
                $message .= ' You already own ' . $this->describeOwned($ownedBetter[0], $refinementPrefix) . '.';
            }
            $this->recommend('medium', 'weapon', $kind, $params, $message);
        } elseif ($rank > 1 && $ownedBetter) {
            $this->recommend(
                $rank > 3 ? 'medium' : 'low',
                'weapon',
                $on ? 'weapon_better_owned_on' : 'weapon_better_owned',
                $owned + ['owned_rank' => $ownedBetter[0]['rank'], 'name' => $weapon->weapon->name, 'rank' => $rank] + $on,
                'You own ' . $this->describeOwned($ownedBetter[0], $refinementPrefix) . " (#{$ownedBetter[0]['rank']}), which ranks above {$weapon->weapon->name} (#{$rank}).",
            );
        } elseif ($rank > 3) {
            $this->recommend(
                'low',
                'weapon',
                'weapon_low_rank',
                ['name' => $weapon->weapon->name, 'rank' => $rank, 'options' => $options],
                "{$weapon->weapon->name} is option #{$rank}. Upgrades to aim for: " . implode(', ', $top) . '.',
            );
        }

        $recommendedRefinement = $rank !== null ? $list[$rank - 1]['refinement'] ?? null : null;

        return [
            'score' => $score,
            'equipped' => [
                'key' => $key,
                'name' => $weapon->weapon->name,
                'icon_url' => Icons::of($weapon->weapon),
                'rarity' => $weapon->weapon->rarity,
                'level' => $weapon->level,
                'refinement' => $weapon->refinement,
            ],
            'rank' => $rank,
            'recommended_refinement' => $recommendedRefinement,
            'owned_better' => Neutral::deep($ownedBetter),
            'total' => count($list),
        ];
    }

    private function describeOwned(array $owned, string $prefix): string
    {
        $text = "{$owned['name']} ({$prefix}{$owned['refinement']})";

        return $owned['equipped_on'] ? "{$text}, currently on {$owned['equipped_on']}" : $text;
    }

    // ---------------------------------------------------------------- sets

    /**
     * @param  array<string, AccountItem>  $items
     */
    private function setPart(Build $build, array $items): ?array
    {
        $options = $build->sets ?? [];
        if ($options === []) {
            return null;
        }

        $names = [];
        foreach ($items as $item) {
            $names[$item->set->key] = $item->set->name;
        }

        // A group per kind of set the game has (its pieces on their own slots), weighed by the game's rule.
        $groups = [];
        foreach ($this->game->setKinds() as $kind) {
            $kindItems = array_filter($items, fn($item, $slot) => $this->game->setKindOf($slot) === $kind, ARRAY_FILTER_USE_BOTH);
            $kindOptions = array_values(array_filter($options, fn($o) => ($o['kind'] ?? null) === $kind));
            $group = $this->evaluateOptions(AccountContext::setCounts($kindItems), $names, $kindOptions, $kind);
            if ($group !== null) {
                $groups[] = $group;
            }
        }
        if ($groups === []) {
            return null;
        }

        $total = array_sum(array_map(fn($g) => $this->game->setWeight($g['kind']), $groups));
        $score = array_sum(array_map(fn($g) => $g['score'] * $this->game->setWeight($g['kind']), $groups)) / $total;

        return ['score' => (int) round($score), 'groups' => $groups];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, string>  $names  set key => display name
     */
    private function evaluateOptions(array $counts, array $names, array $options, string $kind): ?array
    {
        if ($options === []) {
            return null;
        }

        $equipped = [];
        foreach ($counts as $key => $pieces) {
            $equipped[] = ['key' => $key, 'name' => $names[$key] ?? $key, 'count' => $pieces];
        }

        $matchedRank = null;
        $bestFraction = 0.0;
        foreach (array_values($options) as $index => $option) {
            $result = SetMatcher::evaluate($counts, $option);
            if ($result['satisfied']) {
                $matchedRank = $index + 1;
                break;
            }
            $bestFraction = max($bestFraction, $result['fraction']);
        }

        $best = $options[0];
        $bestLabel = self::optionLabel($best);
        $noun = lcfirst($this->game->setLabel($kind));
        $current = $equipped === [] ? 'nothing' : implode(' + ', array_map(fn($e) => "{$e['count']}pc {$e['name']}", $equipped));

        // For a page: the noun with a capital (it can open a sentence before a colon) and the top option
        // without its "(any N)", which the page words itself. The English sentences keep their own nouns.
        $setLabel = $this->game->setLabel($kind);
        $bestParams = ['best' => self::optionPieces($best), 'best_choose' => self::optionChoose($best)];

        if ($matchedRank === null) {
            $score = (int) round(10 + 60 * $bestFraction);
            // Nothing equipped is a kind of its own: "nothing" is an English word, not a list of sets.
            $this->recommend(
                'high',
                'sets',
                $equipped === [] ? 'sets_missing_empty' : 'sets_missing',
                ['set_label' => $setLabel] + $bestParams + ($equipped === [] ? [] : ['current' => $current]),
                "Use the recommended {$noun}: {$bestLabel}. Currently: {$current}.",
            );
        } else {
            $score = max(70, 100 - ($matchedRank - 1) * 8);
            if ($matchedRank > 1) {
                $this->recommend(
                    $matchedRank > 2 ? 'medium' : 'low',
                    'sets',
                    'sets_lower_option',
                    ['set_label' => $setLabel, 'current' => $current, 'rank' => $matchedRank] + $bestParams,
                    "Your {$noun} ({$current}) is option #{$matchedRank}. The top choice is {$bestLabel}.",
                );
            }
        }

        return [
            'kind' => $kind,
            'score' => $score,
            'matched_rank' => $matchedRank,
            'equipped' => $equipped,
            'best' => Neutral::text($bestLabel),
        ];
    }

    /** A set option in English: its pieces, and "(any N)" when only some of them are needed. */
    public static function optionLabel(array $option): string
    {
        $choose = self::optionChoose($option);

        return self::optionPieces($option) . ($choose === null ? '' : " (any {$choose})");
    }

    /** The pieces of a set option, "2pc A + 2pc B": game terms and names, the same in every language. */
    public static function optionPieces(array $option): string
    {
        return implode(' + ', array_map(fn($piece) => "{$piece['count']}pc {$piece['label']}", $option['pieces'] ?? []));
    }

    /** How many of the option's pieces are needed, when that is fewer than it lists; null: all of them. */
    public static function optionChoose(array $option): ?int
    {
        return !empty($option['choose']) && $option['choose'] < count($option['pieces'] ?? []) ? (int) $option['choose'] : null;
    }

    // ---------------------------------------------------------------- main stats

    /**
     * @param  array<string, list<array{stat: string, priority: int}>>  $wanted
     * @param  array<string, AccountItem>  $items
     */
    private function mainStatPart(array $wanted, array $items): ?array
    {
        $rows = [];
        foreach ($this->game->variableSlots() as $slot) {
            $options = $wanted[$slot] ?? [];
            if ($options === []) {
                continue;
            }

            $item = $items[$slot] ?? null;
            $recommended = array_column($options, 'stat');
            $topPriority = min(array_column($options, 'priority'));
            $slotLabel = $this->game->slotLabel($slot);
            $wantedLabel = implode(' / ', array_map(fn($stat) => Stats::label($stat, $this->game), $recommended));

            if (!$item) {
                $rows[$slot] = ['current' => null, 'recommended' => $recommended, 'score' => 0, 'ok' => false];
                $this->recommend(
                    'high',
                    'main_stats',
                    'main_stat_missing',
                    ['slot_label' => $slotLabel, 'wanted' => $wantedLabel],
                    "No {$slotLabel} equipped. Look for one with {$wantedLabel}.",
                    $slot,
                );

                continue;
            }

            $match = null;
            foreach ($options as $option) {
                if ($option['stat'] === $item->main_stat) {
                    $match = $option;
                    break;
                }
            }

            $score = match (true) {
                $match === null => 0,
                $match['priority'] === $topPriority => 100,
                $match['priority'] === $topPriority + 1 => 85,
                default => 75,
            };
            $rows[$slot] = ['current' => $item->main_stat, 'recommended' => $recommended, 'score' => $score, 'ok' => $match !== null];

            if ($match === null) {
                $statLabel = Stats::label($item->main_stat, $this->game);
                $this->recommend(
                    'high',
                    'main_stats',
                    'main_stat_wrong',
                    ['slot_label' => $slotLabel, 'stat' => $statLabel, 'wanted' => $wantedLabel],
                    "{$slotLabel} has " . $statLabel . " as main stat; this build wants {$wantedLabel}.",
                    $slot,
                );
            }
        }

        if ($rows === []) {
            return null;
        }

        return ['score' => (int) round(array_sum(array_column($rows, 'score')) / count($rows)), 'slots' => $rows];
    }

    // ---------------------------------------------------------------- substats

    /**
     * @param  array<string, AccountItem>  $items
     * @return array{0: int|null, 1: array<string, array>}
     */
    private function substatPart(ItemScorer $scorer, array $items, array $mainStats): array
    {
        $results = [];
        foreach ($this->game->slots() as $slot) {
            $item = $items[$slot] ?? null;
            if (!$item) {
                continue;
            }

            $score = $scorer->score($item->main_stat, $item->rarity, $item->substats ?? []);
            $maxLevel = $this->game->maxItemLevel($item->rarity);
            $wanted = array_column($mainStats[$slot] ?? [], 'stat');

            $results[$slot] =
                [
                    'id' => $item->id,
                    'slot' => $slot,
                    'set' => ['key' => $item->set->key, 'name' => $item->set->name, 'icon_url' => Icons::of($item->set)],
                    'rarity' => $item->rarity,
                    'level' => $item->level,
                    'max_level' => $maxLevel,
                    'main_stat' => $item->main_stat,
                    'main_value' => $item->main_value,
                    'main_ok' => $wanted === [] ? null : in_array($item->main_stat, $wanted, true),
                ] + $score;

            if ($item->level < $maxLevel) {
                $this->recommend(
                    $item->level <= $maxLevel - 8 ? 'medium' : 'low',
                    'levels',
                    'item_level',
                    ['slot_label' => $this->game->slotLabel($slot), 'level' => $item->level, 'max_level' => $maxLevel],
                    "Level up your {$this->game->slotLabel($slot)} (+{$item->level} → +{$maxLevel}).",
                    $slot,
                );
            }
        }

        if ($results === []) {
            return [null, []];
        }

        // Point out the weakest pieces worth replacing (only fully leveled ones; others may still improve).
        $weak = array_filter($results, fn($r) => in_array($r['grade'], ['C', 'D'], true) && $r['level'] >= $r['max_level']);
        uasort($weak, fn($a, $b) => $a['percent'] <=> $b['percent']);
        $wantedSubs = array_keys(array_filter($scorer->weights(), fn($w) => $w >= 0.8));
        $subsLabel = implode(', ', array_map(fn($s) => Stats::label($s, $this->game, true), array_slice($wantedSubs, 0, 3)));
        foreach (array_slice($weak, 0, 2, true) as $slot => $result) {
            $this->recommend(
                'medium',
                'substats',
                'substats_weak',
                ['slot_label' => $this->game->slotLabel($slot), 'percent' => $result['percent'], 'stats' => $subsLabel],
                "Your {$this->game->slotLabel($slot)} has weak substats ({$result['percent']}% of an ideal piece). Replace it with one rolling {$subsLabel}.",
                $slot,
            );
        }

        // 70% of an ideal piece is already excellent; scale so that counts as full marks.
        $average = array_sum(array_column($results, 'percent')) / max(1, count($this->game->slots()));

        return [(int) round(min(100, ($average / 70) * 100)), $results];
    }

    // ---------------------------------------------------------------- stat targets

    /**
     * The targets of a character's constellation: per stat, the one from the highest constellation reached (of a
     * build's targets from C0 and from C1, a C2 character is held to the C1 one). A stat whose targets all start
     * later than the character's constellation has none.
     *
     * @param  list<array<string, mixed>>  $targets
     * @return list<array<string, mixed>>
     */
    public static function targetsFor(array $targets, int $rank): array
    {
        $chosen = [];
        foreach ($targets as $target) {
            $from = (int) ($target['from_rank'] ?? 0);
            if ($from > $rank) {
                continue;
            }
            $held = $chosen[$target['stat']] ?? null;
            if ($held === null || $from > $held['from_rank']) {
                $chosen[$target['stat']] = ['from_rank' => $from] + $target;
            }
        }

        // In the order the build first names each stat.
        return array_values($chosen);
    }

    private function targetPart(Build $build, AccountCharacter $owned): ?array
    {
        $targets = self::targetsFor($build->stat_targets ?? [], $owned->rank);
        $final = $owned->final_stats ?? [];
        if ($targets === [] || $final === []) {
            return $targets === [] ? null : ['score' => null, 'rows' => array_map(fn($t) => $t + ['current' => null, 'ok' => null], $targets)];
        }

        $rows = [];
        $scores = [];
        foreach ($targets as $target) {
            $current = $final[$target['stat']] ?? null;
            if ($current === null || empty($target['min'])) {
                $rows[] = $target + ['current' => null, 'ok' => null];

                continue;
            }

            $ok = $current >= $target['min'];
            $scores[] = $ok ? 100 : max(0, (int) round(($current / $target['min']) * 100) - 10);
            $rows[] = $target + ['current' => $current, 'ok' => $ok];

            if (!$ok) {
                $unit = Stats::isPercent($target['stat']) ? '%' : '';
                // The two values go out as numbers ("+ 0": whatever the stored JSON held); the page writes
                // them in the game's notation for the stat, which is why it gets the stat's key too.
                $this->recommend(
                    'medium',
                    'targets',
                    'target_below',
                    ['stat' => Stats::label($target['stat'], $this->game), 'stat_key' => $target['stat'], 'current' => $current + 0, 'min' => $target['min'] + 0],
                    sprintf('%s is %s%s; the build aims for %s%s+.', Stats::label($target['stat'], $this->game), $this->number($current), $unit, $this->number($target['min']), $unit),
                );
            }
        }

        return ['score' => $scores ? (int) round(array_sum($scores) / count($scores)) : null, 'rows' => $rows];
    }

    // ---------------------------------------------------------------- talents & levels

    private function talentAdvice(Build $build, AccountCharacter $owned): void
    {
        $priorities = $build->talent_priority ?? [];
        $levels = $owned->talents ?? [];
        if ($priorities === [] || $levels === []) {
            return;
        }

        foreach ($priorities as $entry) {
            if ($entry['priority'] !== 1 || !isset($levels[$entry['talent']])) {
                continue;
            }
            $level = (int) $levels[$entry['talent']];
            if ($level < 8) {
                $this->recommend(
                    'low',
                    'talents',
                    'talent_level',
                    ['talent' => Talents::label($this->game, $entry['talent']), 'level' => $level],
                    sprintf('Level %s first (priority #1, currently %d).', Talents::label($this->game, $entry['talent']), $level),
                );
            }
        }
    }

    private function levelAdvice(AccountCharacter $owned): void
    {
        $target = $this->game->characterLevelTarget();
        if ($owned->level < $target) {
            $this->recommend('medium', 'levels', 'character_level', ['level' => $owned->level], "Level the character up (level {$owned->level}); most of their stats come from character level.");
        }
    }

    // ---------------------------------------------------------------- helpers

    private function overall(array $parts): ?int
    {
        $sum = 0;
        $weights = 0;
        foreach (self::PART_WEIGHTS as $part => $weight) {
            if ($parts[$part] !== null) {
                $sum += $parts[$part] * $weight;
                $weights += $weight;
            }
        }

        return $weights > 0 ? (int) round($sum / $weights) : null;
    }

    /** The build as an evaluation names it: its variation, whether the character has what it needs, its place. */
    private function buildSummary(Build $build, AccountCharacter $owned): array
    {
        return [
            'id' => $build->id,
            'role' => $build->role,
            // Neutral, as every text a build was read with.
            'qualifier' => (string) Neutral::text($build->qualifier),
            'min_rank' => $build->min_rank,
            'applies' => $build->appliesTo($owned->rank),
            'position' => $build->position,
            'game_version' => Neutral::text($build->game_version),
            'checked_at' => Clock::iso($build->checked_at),
        ];
    }

    /**
     * @param  array<string, list<array{stat: string, priority: int}>>  $mainStats
     */
    private function resolveMainStats(array $mainStats, ?string $element): array
    {
        $resolved = [];
        foreach ($mainStats as $slot => $list) {
            $resolved[$slot] = $this->resolveList($list, $element);
        }

        return $resolved;
    }

    /**
     * Replace the generic "Elemental DMG" placeholder with the character's element.
     */
    private function resolveList(array $list, ?string $element): array
    {
        $result = [];
        foreach ($list as $entry) {
            $stat = Stats::resolveElemental($entry['stat'], $element);
            if (!in_array($stat, array_column($result, 'stat'), true)) {
                $result[] = ['stat' => $stat, 'priority' => $entry['priority']];
            }
        }

        return $result;
    }

    /**
     * @param  string  $kind  a key of KINDS
     * @param  array<string, string|int|float|null>  $params  exactly the parameters KINDS names for the kind
     * @param  string  $message  the same in English, for the command line and for whoever has no words for the kind
     */
    private function recommend(string $severity, string $category, string $kind, array $params, string $message, ?string $slot = null): void
    {
        // Both quote names a build was read with (weapons, sets), as the build page shows them: neutral.
        $params = Neutral::deep($params);
        $message = (string) Neutral::text($message);
        $this->recommendations[] = array_filter(compact('severity', 'category', 'kind', 'params', 'message', 'slot'), fn($v) => $v !== null);
    }

    private function sortedRecommendations(): array
    {
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        $recommendations = $this->recommendations;
        usort($recommendations, fn($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return $recommendations;
    }

    private function number(float|int $value): string
    {
        return fmod((float) $value, 1.0) === 0.0 ? number_format($value) : number_format($value, 1);
    }
}
