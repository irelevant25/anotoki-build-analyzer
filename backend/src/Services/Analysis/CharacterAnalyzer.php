<?php

namespace App\Services\Analysis;

use App\Enums\Game;
use App\Models\Account;
use App\Models\AccountCharacter;
use App\Models\AccountItem;
use App\Models\Build;
use App\Presenters\Icons;
use App\Repositories\Accounts;
use App\Repositories\Builds;
use App\Repositories\CatalogRows;
use App\Services\Builds\BuildSourceRegistry;
use App\Support\Clock;
use App\Support\Neutral;
use App\Support\Stats;

/**
 * Account-level entry point of the analysis: evaluates characters against every build of every source.
 */
class CharacterAnalyzer
{
    public function __construct(private readonly Accounts $accounts, private readonly Builds $builds, private readonly CatalogRows $rows, private readonly BuildSourceRegistry $registry) {}

    /**
     * One row per owned character with its best-matching build.
     *
     * @return list<array>
     */
    public function overview(Account $account): array
    {
        $context = $this->context($account);
        $owned = $this->accounts->characters($account->id);
        $builds = $this->builds->forCharacters(array_map(fn(AccountCharacter $character) => $character->game_character_id, $owned));

        $rows = [];
        foreach ($owned as $character) {
            $characterBuilds = $builds[$character->game_character_id] ?? [];
            $evaluations = $this->evaluateAll($context, $character, $characterBuilds);
            $best = $this->best($evaluations);

            $rows[] = $this->characterSummary($context, $character) + [
                'builds_count' => count($characterBuilds),
                'best' => $best
                    ? [
                        'build_id' => $best['build']['id'],
                        'source' => $best['build']['source']['name'],
                        'variant' => $best['build']['variant'],
                        'overall' => $best['score']['overall'],
                        'grade' => $best['score']['grade'],
                        'parts' => $best['score']['parts'],
                        'top_issue' => $best['recommendations'][0]['message'] ?? null,
                        // The same recommendation as a kind with its parameters (BuildEvaluator::KINDS), for a
                        // page to word; both null when there is nothing to recommend.
                        'top_issue_kind' => $best['recommendations'][0]['kind'] ?? null,
                        'top_issue_params' => $best['recommendations'][0]['params'] ?? null,
                        'issues' => count(array_filter($best['recommendations'], fn($r) => $r['severity'] !== 'low')),
                    ]
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Full analysis of one character: every build evaluated, consensus between sources and (with a full
     * inventory) better pieces the player already owns.
     */
    public function detail(Account $account, AccountCharacter $character, ?int $focusBuildId = null): array
    {
        $context = $this->context($account);
        $builds = $this->builds->forCharacters([$character->game_character_id])[$character->game_character_id] ?? [];

        $evaluations = $this->evaluateAll($context, $character, $builds);
        $best = $this->best($evaluations);
        $focus = $best;
        if ($focusBuildId) {
            $focus = null;
            foreach ($evaluations as $evaluation) {
                if ($evaluation['build']['id'] === $focusBuildId) {
                    $focus = $evaluation;
                    break;
                }
            }
        }

        $suggestions = ['items' => [], 'set_plans' => []];
        if ($focus && $account->has_full_inventory) {
            $build = null;
            foreach ($builds as $candidate) {
                if ($candidate->id === $focus['build']['id']) {
                    $build = $candidate;
                    break;
                }
            }
            $suggestions = $this->suggestions($context, $character, $build, $focus, $this->fallbackSubstats($builds));
        }

        return [
            'character' => $this->characterSummary($context, $character) + [
                'talents' => $character->talents,
                'final_stats' => $character->final_stats,
                'final_stats_source' => $character->final_stats ? self::importKind($character->source) : null,
                'synced_at' => Clock::iso($character->synced_at),
            ],
            'equipment' => $this->equipment($context, $character),
            'evaluations' => $evaluations,
            'best_build_id' => $best['build']['id'] ?? null,
            'focus_build_id' => $focus['build']['id'] ?? null,
            // The build's names as the build page shows them: neutral.
            'consensus' => Neutral::deep($this->consensus($builds, $character->character->element)),
            'suggestions' => $suggestions['items'],
            'set_plans' => $suggestions['set_plans'],
            'has_full_inventory' => $account->has_full_inventory,
        ];
    }

    /**
     * How the API names where data came from: "showcase" (a UID import) or "file" (an inventory export),
     * never the service or tool behind it.
     */
    public static function importKind(string $source): string
    {
        return $source === 'enka' ? 'showcase' : 'file';
    }

    private function context(Account $account): AccountContext
    {
        return new AccountContext($account, $this->accounts, $this->rows);
    }

    /**
     * @param  list<Build>  $builds
     * @return list<array>
     */
    private function evaluateAll(AccountContext $context, AccountCharacter $character, array $builds): array
    {
        $fallback = $this->fallbackSubstats($builds);
        $evaluator = new BuildEvaluator($context, $this->registry);

        $evaluations = [];
        foreach ($builds as $build) {
            $evaluations[] = $evaluator->evaluate($character, $build, $fallback);
        }

        return $evaluations;
    }

    /**
     * Best evaluation: the highest score, preferring builds the source marks as recommended on ties.
     */
    private function best(array $evaluations): ?array
    {
        $best = null;
        foreach ($evaluations as $evaluation) {
            $score = $evaluation['score']['overall'] ?? -1;
            $bestScore = $best['score']['overall'] ?? -1;
            if ($best === null || $score > $bestScore || ($score === $bestScore && $evaluation['build']['recommended'] && !$best['build']['recommended'])) {
                $best = $evaluation;
            }
        }

        return $best;
    }

    /**
     * Substat priorities borrowed from another source when a build has none (usage-statistics builds).
     *
     * @param  list<Build>  $builds
     */
    private function fallbackSubstats(array $builds): ?array
    {
        foreach ($builds as $build) {
            if (!empty($build->substats)) {
                return $build->substats;
            }
        }

        return null;
    }

    private function characterSummary(AccountContext $context, AccountCharacter $character): array
    {
        $catalog = $character->character;
        $weapon = $context->equippedWeapon($catalog->id);
        $items = $context->equippedItems($catalog->id);
        $setNames = [];
        foreach ($items as $item) {
            $setNames[$item->set->key] = $item->set->name;
        }
        $counts = AccountContext::setCounts($items);

        return [
            'id' => $character->id,
            'key' => $catalog->key,
            'name' => $catalog->name,
            'element' => $catalog->element,
            'rarity' => $catalog->rarity,
            'weapon_type' => $catalog->weapon_type,
            'icon_url' => Icons::of($catalog),
            'level' => $character->level,
            'rank' => $character->rank,
            'weapon' => $weapon
                ? [
                    'name' => $weapon->weapon->name,
                    'icon_url' => Icons::of($weapon->weapon),
                    'rarity' => $weapon->weapon->rarity,
                    'level' => $weapon->level,
                    'refinement' => $weapon->refinement,
                ]
                : null,
            'sets' => array_map(fn($key, $count) => ['key' => $key, 'name' => $setNames[$key], 'count' => $count], array_keys($counts), $counts),
            'items_count' => count($items),
        ];
    }

    /**
     * Totals of everything the equipped artifacts / relics give (main + sub stats), plus crit value.
     */
    private function equipment(AccountContext $context, AccountCharacter $character): array
    {
        $game = $context->account->game;
        $items = $context->equippedItems($character->game_character_id);

        $totals = [];
        foreach ($items as $item) {
            if ($item->main_value !== null) {
                $totals[$item->main_stat] = ($totals[$item->main_stat] ?? 0) + $item->main_value;
            }
            foreach ($item->substats as $substat) {
                $totals[$substat['key']] = ($totals[$substat['key']] ?? 0) + $substat['value'];
            }
        }
        $totals = array_map(fn($value) => round($value, 1), $totals);

        return [
            'totals' => $totals,
            'crit_value' => round(2 * ($totals['critRate_'] ?? 0) + ($totals['critDMG_'] ?? 0), 1),
            'slots' => $game->slots(),
        ];
    }

    /**
     * What the sources agree on: top main stats per slot, top set option and best weapon.
     *
     * @param  list<Build>  $builds
     */
    private function consensus(array $builds, ?string $element): array
    {
        $mainStats = [];
        $sets = [];
        $setParts = [];
        $weapons = [];

        foreach ($builds as $build) {
            foreach ($build->main_stats as $slot => $list) {
                $top = min(array_column($list, 'priority') ?: [1]);
                foreach ($list as $entry) {
                    if ($entry['priority'] === $top) {
                        $stat = Stats::resolveElemental($entry['stat'], $element);
                        $mainStats[$slot][$stat] = ($mainStats[$slot][$stat] ?? 0) + 1;
                    }
                }
            }

            foreach (['artifact', 'relic', 'planar'] as $kind) {
                $first = null;
                foreach ($build->sets as $option) {
                    if (($option['kind'] ?? null) === $kind) {
                        $first = $option;
                        break;
                    }
                }
                if ($first) {
                    // Counted by the English label; a page words the option from its pieces and its "any N".
                    $label = BuildEvaluator::optionLabel($first);
                    $sets[$kind][$label] = ($sets[$kind][$label] ?? 0) + 1;
                    $setParts[$kind][$label] ??= ['pieces' => BuildEvaluator::optionPieces($first), 'choose' => BuildEvaluator::optionChoose($first)];
                }
            }

            $weapon = $build->weapons[0] ?? null;
            if ($weapon) {
                $weapons[$weapon['name']] = ($weapons[$weapon['name']] ?? 0) + 1;
            }
        }

        $sortDesc = function (array $counts): array {
            arsort($counts);

            return array_map(fn($name, $count) => ['name' => $name, 'count' => $count], array_keys($counts), $counts);
        };

        // A set row is {name, pieces, choose, count}: "name" stays the label it was counted by.
        $setRows = [];
        foreach ($sets as $kind => $counts) {
            $setRows[$kind] = array_map(fn(array $row) => ['name' => $row['name']] + $setParts[$kind][$row['name']] + ['count' => $row['count']], $sortDesc($counts));
        }

        $sources = [];
        foreach ($builds as $build) {
            $sources[] = $this->registry->label($build->source->key);
        }

        return [
            'builds' => count($builds),
            'sources' => array_values(array_unique($sources)),
            'main_stats' => array_map($sortDesc, $mainStats),
            'sets' => $setRows,
            'weapons' => $sortDesc($weapons),
        ];
    }

    /**
     * Better pieces from the inventory for the focused build:
     * - "items": per slot, the best owned piece that completes the set bonus, fixes a wrong main stat or has
     *   clearly better substats, never making the set bonus worse;
     * - "set_plans": when no recommended set is in use, the best owned pieces of the source's top set.
     *
     * @return array{items: list<array>, set_plans: list<array>}
     */
    private function suggestions(AccountContext $context, AccountCharacter $character, ?Build $build, array $evaluation, ?array $fallback): array
    {
        if (!$build) {
            return ['items' => [], 'set_plans' => []];
        }

        $game = $context->account->game;
        $element = $character->character->element;
        $priorities = $build->substats ?: $fallback ?? [];
        $priorities = array_map(fn($e) => ['stat' => Stats::resolveElemental($e['stat'], $element), 'priority' => $e['priority']], $priorities);
        $scorer = new ItemScorer($game, $priorities ?: ItemScorer::fallbackSubstats($game, $build->main_stats));

        $equipped = $context->equippedItems($character->game_character_id);
        $items = [];

        foreach ($game->slots() as $slot) {
            $kind = $this->setKind($game, $slot);
            $options = array_values(array_filter($build->sets, fn($o) => $o['kind'] === $kind));
            $mains = $this->mainStatsFor($build, $slot, $element);

            // Set pieces in the same group (HSR: cavern relics and planar ornaments are separate).
            $group = array_filter($equipped, fn($item, $itemSlot) => $this->setKind($game, $itemSlot) === $kind, ARRAY_FILTER_USE_BOTH);
            $countsWith = AccountContext::setCounts($group);
            $countsWithout = AccountContext::setCounts(array_filter($group, fn($item, $itemSlot) => $itemSlot !== $slot, ARRAY_FILTER_USE_BOTH));

            // Aim for the option already in use; otherwise for the source's top option.
            $target = null;
            foreach ($options as $option) {
                if (SetMatcher::evaluate($countsWith, $option)['satisfied']) {
                    $target = $option;
                    break;
                }
            }
            $target ??= $options[0] ?? null;
            $baseline = $target ? SetMatcher::evaluate($countsWith, $target)['fraction'] : 1.0;

            $current = $evaluation['items'][$slot] ?? null;
            $currentPercent = $current['percent'] ?? 0;
            $currentMainOk = $current !== null && $current['main_ok'] !== false;

            $completes = null; // a swap that on its own completes the set bonus
            $sameSet = null; // the best swap that keeps the set bonus as it is
            foreach ($context->itemsInSlot($slot) as $item) {
                if (($current['id'] ?? null) === $item->id || ($mains !== [] && !in_array($item->main_stat, $mains, true))) {
                    continue;
                }

                $fraction = 1.0;
                if ($target) {
                    $after = $countsWithout;
                    $after[$item->set->key] = ($after[$item->set->key] ?? 0) + 1;
                    $fraction = SetMatcher::evaluate($after, $target)['fraction'];
                    if ($fraction < $baseline - 1e-9) {
                        continue; // never make the set bonus worse
                    }
                }

                $completesTarget = $baseline < 1.0 - 1e-9 && $fraction >= 1.0 - 1e-9;
                // Not on a recommended set yet: the set plan covers switching; single swaps must at least
                // keep the 2pc / 4pc bonuses the character has now.
                if (!$completesTarget && $baseline < 1.0 - 1e-9 && !$this->keepsBonuses($countsWith, $countsWithout, $item->set->key)) {
                    continue;
                }

                $candidate = ['item' => $item, 'score' => $scorer->score($item->main_stat, $item->rarity, $item->substats)];
                if ($completesTarget) {
                    if (!$completes || $candidate['score']['percent'] > $completes['score']['percent']) {
                        $completes = $candidate;
                    }
                } elseif (abs($fraction - $baseline) < 1e-9 && (!$sameSet || $candidate['score']['percent'] > $sameSet['score']['percent'])) {
                    $sameSet = $candidate;
                }
            }

            if ($completes && $completes['score']['percent'] >= 25) {
                $items[] = $this->suggestion($context, $character, $slot, 'completes_set', $completes, $current);

                continue;
            }
            if (!$sameSet) {
                continue;
            }

            $gain = $sameSet['score']['percent'] - $currentPercent;
            $reason = match (true) {
                !$currentMainOk && $sameSet['score']['percent'] >= 25 => 'fixes_main_stat',
                $currentMainOk && $gain >= 8 => 'better_substats',
                default => null,
            };
            if ($reason) {
                $items[] = $this->suggestion($context, $character, $slot, $reason, $sameSet, $current);
            }
        }

        return ['items' => $items, 'set_plans' => $this->setPlans($context, $character, $build, $evaluation, $scorer)];
    }

    /**
     * When the character wears none of the recommended sets, list the best owned piece of the top set for each
     * slot and how many of the needed pieces exist. Only for single-set options (4pc, or a 2pc planar set).
     */
    private function setPlans(AccountContext $context, AccountCharacter $character, Build $build, array $evaluation, ItemScorer $scorer): array
    {
        $game = $context->account->game;
        $element = $character->character->element;

        $plans = [];
        foreach (['artifact', 'relic', 'planar'] as $kind) {
            $group = null;
            foreach ($evaluation['sets']['groups'] ?? [] as $candidate) {
                if ($candidate['kind'] === $kind) {
                    $group = $candidate;
                    break;
                }
            }
            $option = null;
            foreach ($build->sets as $candidate) {
                if (($candidate['kind'] ?? null) === $kind) {
                    $option = $candidate;
                    break;
                }
            }
            if (!$group || $group['matched_rank'] !== null || !$option || count($option['pieces']) !== 1) {
                continue;
            }

            $piece = $option['pieces'][0];
            $slots = array_values(array_filter($game->slots(), fn($slot) => $this->setKind($game, $slot) === $kind));

            $picks = [];
            foreach ($slots as $slot) {
                $mains = $this->mainStatsFor($build, $slot, $element);
                $best = null;
                foreach ($context->itemsInSlot($slot) as $item) {
                    if (!in_array($item->set->key, $piece['keys'], true) || ($mains !== [] && !in_array($item->main_stat, $mains, true))) {
                        continue;
                    }
                    $score = $scorer->score($item->main_stat, $item->rarity, $item->substats);
                    if (!$best || $score['percent'] > $best['score']['percent']) {
                        $best = ['item' => $item, 'score' => $score];
                    }
                }
                if ($best) {
                    $picks[$slot] = $best;
                }
            }
            if ($picks === []) {
                continue;
            }

            // Genshin 4pc: keep the four best; the fifth slot stays free for an off-piece. Show them in slot order.
            uasort($picks, fn($a, $b) => $b['score']['percent'] <=> $a['score']['percent']);
            $chosen = array_slice($picks, 0, $piece['count'], true);

            $pieces = [];
            foreach ($slots as $slot) {
                if (isset($chosen[$slot])) {
                    $pieces[] = ['slot' => $slot] + $this->itemPayload($context, $character, $chosen[$slot]['item'], $chosen[$slot]['score']);
                }
            }

            $plans[] = [
                'kind' => $kind,
                'set' => BuildEvaluator::optionLabel($option),
                'needed' => $piece['count'],
                'owned' => count($pieces),
                'pieces' => $pieces,
            ];
        }

        return $plans;
    }

    private function suggestion(AccountContext $context, AccountCharacter $character, string $slot, string $reason, array $candidate, ?array $current): array
    {
        return [
            'slot' => $slot,
            'reason' => $reason,
            'gain' => $candidate['score']['percent'] - ($current['percent'] ?? 0),
            'current_percent' => $current['percent'] ?? null,
            'item' => $this->itemPayload($context, $character, $candidate['item'], $candidate['score']),
        ];
    }

    private function itemPayload(AccountContext $context, AccountCharacter $character, AccountItem $item, array $score): array
    {
        return [
            'id' => $item->id,
            'set' => ['key' => $item->set->key, 'name' => $item->set->name, 'icon_url' => Icons::of($item->set)],
            'rarity' => $item->rarity,
            'level' => $item->level,
            'main_stat' => $item->main_stat,
            'main_value' => $item->main_value,
            'equipped_on' => $item->equipped_character_id && $item->equipped_character_id !== $character->game_character_id ? $context->characterName($item->equipped_character_id) : null,
        ] + $score;
    }

    /**
     * Would swapping in a piece of $setKey keep every 2pc / 4pc bonus the character has now?
     *
     * @param  array<string, int>  $countsWith  current set counts of the slot group
     * @param  array<string, int>  $countsWithout  the same without the slot being replaced
     */
    private function keepsBonuses(array $countsWith, array $countsWithout, string $setKey): bool
    {
        $tier = fn(int $pieces) => $pieces >= 4 ? 2 : ($pieces >= 2 ? 1 : 0);
        $after = $countsWithout;
        $after[$setKey] = ($after[$setKey] ?? 0) + 1;

        foreach ($countsWith as $key => $pieces) {
            if ($tier($after[$key] ?? 0) < $tier($pieces)) {
                return false;
            }
        }

        return true;
    }

    private function setKind(Game $game, string $slot): string
    {
        return $game === Game::Genshin ? 'artifact' : ($game->isPlanarSlot($slot) ? 'planar' : 'relic');
    }

    /**
     * @return list<string>
     */
    private function mainStatsFor(Build $build, string $slot, ?string $element): array
    {
        return array_map(fn($entry) => Stats::resolveElemental($entry['stat'], $element), $build->main_stats[$slot] ?? []);
    }
}
