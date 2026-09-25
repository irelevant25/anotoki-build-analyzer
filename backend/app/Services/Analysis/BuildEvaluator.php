<?php

namespace App\Services\Analysis;

use App\Enums\Game;
use App\Models\AccountCharacter;
use App\Models\AccountItem;
use App\Models\Build;
use App\Support\Stats;
use App\Support\Talents;

/**
 * Compares one character's current equipment with one recommended build.
 *
 * Produces a 0-100 score per part (weapon, sets, main stats, substats, stat targets), an overall grade and
 * a prioritized list of recommendations. Parts a build has no data for are left out of the overall score.
 */
final class BuildEvaluator
{
    public const PART_WEIGHTS = ['weapon' => 20, 'sets' => 25, 'main_stats' => 20, 'substats' => 25, 'targets' => 10];

    private Game $game;

    /** @var list<array{severity: string, category: string, message: string, slot?: string}> */
    private array $recommendations = [];

    public function __construct(private readonly AccountContext $context) {}

    /**
     * @param  list<array{stat: string, priority: int}>|null  $fallbackSubstats  used when the build lists no substats
     */
    public function evaluate(AccountCharacter $owned, Build $build, ?array $fallbackSubstats = null): array
    {
        $this->game = $this->context->account->game;
        $this->recommendations = [];

        $character = $owned->character;
        $element = $character->element;
        $items = $this->context->equippedItems($character->id);
        $weapon = $this->context->equippedWeapon($character->id);

        $mainStats = $this->resolveMainStats($build->main_stats ?? [], $element);
        $substatPriorities = $this->resolveList($build->substats ?: ($fallbackSubstats ?? ItemScorer::fallbackSubstats($this->game, $mainStats)), $element);
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
            'build' => $this->buildSummary($build),
            'score' => ['overall' => $overall, 'grade' => self::grade($overall), 'parts' => $parts],
            'weapon' => $weaponPart,
            'sets' => $setPart,
            'main_stats' => $mainPart,
            'items' => $itemResults,
            'targets' => $targetPart['rows'] ?? [],
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

        $label = strtolower($this->game->weaponLabel());
        $top = array_map(fn ($w) => $w['name'], array_slice($list, 0, 3));

        if (! $weapon) {
            $this->recommend('high', 'weapon', "No {$label} equipped. Best options: ".implode(', ', $top).'.');

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
        $maxLevel = $this->game === Game::Genshin ? 90 : 80;
        if ($weapon->level < $maxLevel - 10) {
            $score = (int) round($score * 0.9);
            $this->recommend('low', 'weapon', "Level up {$weapon->weapon->name} (level {$weapon->level}/{$maxLevel}).");
        }

        // Better-ranked weapons the player already owns.
        $ownedBetter = [];
        foreach (array_slice($list, 0, $rank === null ? count($list) : $rank - 1) as $index => $entry) {
            $copies = $this->context->ownedWeapons($entry['key']);
            if ($copies === []) {
                continue;
            }
            usort($copies, fn ($a, $b) => [$b->refinement, $b->level] <=> [$a->refinement, $a->level]);
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

        $refinementPrefix = $this->game === Game::Genshin ? 'R' : 'S';
        if ($rank === null) {
            $message = "{$weapon->weapon->name} is not on this build's {$label} list. Recommended: ".implode(', ', $top).'.';
            if ($ownedBetter) {
                $message .= ' You already own '.$this->describeOwned($ownedBetter[0], $refinementPrefix).'.';
            }
            $this->recommend('medium', 'weapon', $message);
        } elseif ($rank > 1 && $ownedBetter) {
            $this->recommend($rank > 3 ? 'medium' : 'low', 'weapon',
                'You own '.$this->describeOwned($ownedBetter[0], $refinementPrefix)." (#{$ownedBetter[0]['rank']}), which ranks above {$weapon->weapon->name} (#{$rank}).");
        } elseif ($rank > 3) {
            $this->recommend('low', 'weapon', "{$weapon->weapon->name} is option #{$rank}. Upgrades to aim for: ".implode(', ', $top).'.');
        }

        $recommendedRefinement = $rank !== null ? ($list[$rank - 1]['refinement'] ?? null) : null;

        return [
            'score' => $score,
            'equipped' => [
                'key' => $key,
                'name' => $weapon->weapon->name,
                'icon_url' => $weapon->weapon->icon_url,
                'rarity' => $weapon->weapon->rarity,
                'level' => $weapon->level,
                'refinement' => $weapon->refinement,
            ],
            'rank' => $rank,
            'recommended_refinement' => $recommendedRefinement,
            'owned_better' => $ownedBetter,
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

        if ($this->game === Game::Genshin) {
            $result = $this->evaluateOptions(AccountContext::setCounts($items), $names, $options, 'artifact');

            return $result ? ['score' => $result['score'], 'groups' => [$result]] : null;
        }

        $relicItems = array_filter($items, fn ($item, $slot) => ! $this->game->isPlanarSlot($slot), ARRAY_FILTER_USE_BOTH);
        $planarItems = array_filter($items, fn ($item, $slot) => $this->game->isPlanarSlot($slot), ARRAY_FILTER_USE_BOTH);

        $groups = array_values(array_filter([
            $this->evaluateOptions(AccountContext::setCounts($relicItems), $names, array_values(array_filter($options, fn ($o) => $o['kind'] === 'relic')), 'relic'),
            $this->evaluateOptions(AccountContext::setCounts($planarItems), $names, array_values(array_filter($options, fn ($o) => $o['kind'] === 'planar')), 'planar'),
        ]));
        if ($groups === []) {
            return null;
        }

        // Cavern relics carry 4 pieces, planar ornaments 2.
        $weights = ['relic' => 0.6, 'planar' => 0.4];
        $total = array_sum(array_map(fn ($g) => $weights[$g['kind']], $groups));
        $score = array_sum(array_map(fn ($g) => $g['score'] * $weights[$g['kind']], $groups)) / $total;

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
        $noun = match ($kind) {
            'planar' => 'Planar Ornament set',
            'relic' => 'Cavern Relic set',
            default => 'artifact set',
        };
        $current = $equipped === [] ? 'nothing' : implode(' + ', array_map(fn ($e) => "{$e['count']}pc {$e['name']}", $equipped));

        if ($matchedRank === null) {
            $score = (int) round(10 + 60 * $bestFraction);
            $this->recommend('high', 'sets', "Use the recommended {$noun}: {$bestLabel}. Currently: {$current}.");
        } else {
            $score = max(70, 100 - ($matchedRank - 1) * 8);
            if ($matchedRank > 1) {
                $this->recommend($matchedRank > 2 ? 'medium' : 'low', 'sets',
                    "Your {$noun} ({$current}) is option #{$matchedRank}. The top choice is {$bestLabel}.");
            }
        }

        return [
            'kind' => $kind,
            'score' => $score,
            'matched_rank' => $matchedRank,
            'equipped' => $equipped,
            'best' => $bestLabel,
        ];
    }

    public static function optionLabel(array $option): string
    {
        return implode(' + ', array_map(fn ($piece) => "{$piece['count']}pc {$piece['label']}", $option['pieces'] ?? []))
            .(! empty($option['choose']) && $option['choose'] < count($option['pieces'] ?? []) ? " (any {$option['choose']})" : '');
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
            $wantedLabel = implode(' / ', array_map(fn ($stat) => Stats::label($stat, $this->game), $recommended));

            if (! $item) {
                $rows[$slot] = ['current' => null, 'recommended' => $recommended, 'score' => 0, 'ok' => false];
                $this->recommend('high', 'main_stats', "No {$slotLabel} equipped. Look for one with {$wantedLabel}.", $slot);

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
                $this->recommend('high', 'main_stats',
                    "{$slotLabel} has ".Stats::label($item->main_stat, $this->game)." as main stat; this build wants {$wantedLabel}.", $slot);
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
            if (! $item) {
                continue;
            }

            $score = $scorer->score($item->main_stat, $item->rarity, $item->substats ?? []);
            $maxLevel = $this->game->maxItemLevel($item->rarity);
            $wanted = array_column($mainStats[$slot] ?? [], 'stat');

            $results[$slot] = [
                'id' => $item->id,
                'slot' => $slot,
                'set' => ['key' => $item->set->key, 'name' => $item->set->name, 'icon_url' => $item->set->icon_url],
                'rarity' => $item->rarity,
                'level' => $item->level,
                'max_level' => $maxLevel,
                'main_stat' => $item->main_stat,
                'main_value' => $item->main_value,
                'main_ok' => $wanted === [] ? null : in_array($item->main_stat, $wanted, true),
            ] + $score;

            if ($item->level < $maxLevel) {
                $this->recommend($item->level <= $maxLevel - 8 ? 'medium' : 'low', 'levels',
                    "Level up your {$this->game->slotLabel($slot)} (+{$item->level} → +{$maxLevel}).", $slot);
            }
        }

        if ($results === []) {
            return [null, []];
        }

        // Point out the weakest pieces worth replacing (only fully leveled ones; others may still improve).
        $weak = array_filter($results, fn ($r) => in_array($r['grade'], ['C', 'D'], true) && $r['level'] >= $r['max_level']);
        uasort($weak, fn ($a, $b) => $a['percent'] <=> $b['percent']);
        $wantedSubs = array_keys(array_filter($scorer->weights(), fn ($w) => $w >= 0.8));
        $subsLabel = implode(', ', array_map(fn ($s) => Stats::label($s, $this->game, true), array_slice($wantedSubs, 0, 3)));
        foreach (array_slice($weak, 0, 2, true) as $slot => $result) {
            $this->recommend('medium', 'substats',
                "Your {$this->game->slotLabel($slot)} has weak substats ({$result['percent']}% of an ideal piece). Replace it with one rolling {$subsLabel}.", $slot);
        }

        // 70% of an ideal piece is already excellent; scale so that counts as full marks.
        $average = array_sum(array_column($results, 'percent')) / max(1, count($this->game->slots()));

        return [(int) round(min(100, $average / 70 * 100)), $results];
    }

    // ---------------------------------------------------------------- stat targets

    private function targetPart(Build $build, AccountCharacter $owned): ?array
    {
        $targets = $build->stat_targets ?? [];
        $final = $owned->final_stats ?? [];
        if ($targets === [] || $final === []) {
            return $targets === [] ? null : ['score' => null, 'rows' => array_map(fn ($t) => $t + ['current' => null, 'ok' => null], $targets)];
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
            $scores[] = $ok ? 100 : max(0, (int) round($current / $target['min'] * 100) - 10);
            $rows[] = $target + ['current' => $current, 'ok' => $ok];

            if (! $ok) {
                $unit = Stats::isPercent($target['stat']) ? '%' : '';
                $this->recommend('medium', 'targets', sprintf('%s is %s%s; the build aims for %s%s+.',
                    Stats::label($target['stat'], $this->game), $this->number($current), $unit, $this->number($target['min']), $unit));
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
            if ($entry['priority'] !== 1 || ! isset($levels[$entry['talent']])) {
                continue;
            }
            $level = (int) $levels[$entry['talent']];
            if ($level < 8) {
                $this->recommend('low', 'talents', sprintf('Level %s first (priority #1, currently %d).', Talents::label($this->game, $entry['talent']), $level));
            }
        }
    }

    private function levelAdvice(AccountCharacter $owned): void
    {
        $target = $this->game === Game::Genshin ? 80 : 70;
        if ($owned->level < $target) {
            $this->recommend('medium', 'levels', "Level the character up (level {$owned->level}); most of their stats come from character level.");
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

    private function buildSummary(Build $build): array
    {
        return [
            'id' => $build->id,
            'source' => ['key' => $build->source->key, 'name' => $build->source->name],
            'variant' => $build->variant,
            'role' => $build->role,
            'recommended' => $build->recommended,
            'source_url' => $build->source_url,
            'source_updated_at' => $build->source_updated_at?->toIso8601String(),
            'game_version' => $build->game_version,
            'fetched_at' => $build->fetched_at?->toIso8601String(),
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
            if (! in_array($stat, array_column($result, 'stat'), true)) {
                $result[] = ['stat' => $stat, 'priority' => $entry['priority']];
            }
        }

        return $result;
    }

    private function recommend(string $severity, string $category, string $message, ?string $slot = null): void
    {
        $this->recommendations[] = array_filter(compact('severity', 'category', 'message', 'slot'), fn ($v) => $v !== null);
    }

    private function sortedRecommendations(): array
    {
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        $recommendations = $this->recommendations;
        usort($recommendations, fn ($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);

        return $recommendations;
    }

    private function number(float|int $value): string
    {
        return fmod((float) $value, 1.0) === 0.0 ? number_format($value) : number_format($value, 1);
    }
}
