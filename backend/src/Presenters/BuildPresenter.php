<?php

namespace App\Presenters;

use App\Models\Build;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Repositories\Builds;
use App\Repositories\CatalogRows;
use App\Services\Analysis\TargetTiers;
use App\Services\Builds\BuildFile;
use App\Services\Builds\BuildNotes;
use App\Services\Catalog\Catalog;
use App\Support\Clock;
use App\Support\Neutral;

/**
 * Builds as the API sends them, with weapon and set icons from the catalog, and their teams with each teammate as
 * the catalog knows it now - its icon, whether it is there at all, whether it has builds to link to - so a weapon or
 * a teammate the catalog catches up with shows as soon as it does, with no import.
 *
 * A build's notes and its teams' are the site's own (builds.site_notes, BuildNotes): {en, sk?} as editors wrote them,
 * or null. The texts the builds file still carries but the site no longer shows - the build's notes and its weapons',
 * set options' and teams' notes, a stat target's text - are not sent at all (docs/builds.md, "Notes").
 *
 * A build is a variation of its character's - a role, a qualifier, the constellation it needs - and names nowhere
 * it came from: every text it was read with (its qualifier, names, its teams' names, the notes) is passed through
 * Neutral once more on the way out - the stored texts alone, before an icon's address is added, which Neutral would
 * take for another site's.
 */
final class BuildPresenter
{
    public function __construct(private readonly CatalogRows $rows, private readonly Builds $builds) {}

    /**
     * @param  list<Build>  $builds  with their character
     * @return list<array>
     */
    public function collection(array $builds): array
    {
        if ($builds === []) {
            return [];
        }

        $game = $builds[0]->character->game;
        $weaponKeys = [];
        $setKeys = [];
        foreach ($builds as $build) {
            array_push($weaponKeys, ...array_column($build->weapons, 'key'));
            foreach ($build->sets as $option) {
                foreach ($option['pieces'] ?? [] as $piece) {
                    array_push($setKeys, ...$piece['keys'] ?? []);
                }
            }
        }

        $weapons = $this->rows->byKeys($game, Catalog::WEAPON, $weaponKeys);
        $sets = $this->rows->byKeys($game, Catalog::SET, $setKeys);

        // Every teammate of every team - a member or a stand-in -, asked for once.
        $teammateKeys = self::teammateKeys($builds);
        $teammates = $teammateKeys === [] ? [] : $this->rows->byKeys($game, Catalog::CHARACTER, $teammateKeys);
        $withBuilds = $teammateKeys === [] ? [] : $this->builds->keysWithBuilds($game, $teammateKeys);

        return array_map(fn(Build $build) => $this->present($build, $weapons, $sets, $teammates, $withBuilds), $builds);
    }

    /**
     * Every teammate key of these builds' teams (members and stand-ins), each once: whom an account's page asks the
     * account about.
     *
     * @param  list<Build>  $builds
     * @return list<string>
     */
    public static function teammateKeys(array $builds): array
    {
        $keys = [];
        foreach ($builds as $build) {
            foreach ($build->teams as $team) {
                foreach ($team['members'] ?? [] as $member) {
                    $keys[(string) ($member['key'] ?? '')] = true;
                    foreach ($member['alternatives'] ?? [] as $alternative) {
                        $keys[(string) ($alternative['key'] ?? '')] = true;
                    }
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * The build's stat targets as the builds browser shows them (App\Services\Analysis\TargetTiers): for the first
     * of the build's weapons the catalog knows the stats of and the build's own main stats, the stated targets
     * held at the constellation the build is for - a 4-star character's at C6 (the owner's rule of 2026-10-26:
     * nearly every player has them there) -; null while the catalog lacks the stats behind them.
     *
     * @param  array<string, GameWeapon>  $weapons
     * @param  array<string, GameSet>  $sets
     * @return array{rows: list<array<string, mixed>>, basis: array<string, mixed>}|null
     */
    private static function targets(Build $build, array $weapons, array $sets): ?array
    {
        $rank = $build->character->rarity === 4 ? max($build->min_rank, $build->character->game->maxRank()) : $build->min_rank;
        $computed = TargetTiers::forBuild($build, $build->character, $weapons, $sets, null, [], $rank);

        return $computed === null ? null : Neutral::deep($computed);
    }

    /**
     * @param  array<string, GameCharacter>  $teammates
     * @param  array<string, true>  $withBuilds
     */
    private function present(Build $build, array $weapons, array $sets, array $teammates, array $withBuilds): array
    {
        $notes = BuildNotes::shown($build->site_notes, $build->teams);
        // The texts the file carries that the site no longer shows (see the class comment).
        $without = fn(array $entry, string $field) => array_diff_key($entry, [$field => true]);

        return [
            'id' => $build->id,
            'role' => $build->role,
            'qualifier' => (string) Neutral::text($build->qualifier),
            'min_rank' => $build->min_rank,
            'position' => $build->position,
            'weapons' => array_map(
                // Whether the catalog knows the weapon is its to say now, as for a teammate: never what was stored.
                fn($weapon) => array_replace(Neutral::deep($without($weapon, 'note')), ['known' => isset($weapons[$weapon['key']])]) + [
                    'icon_url' => Icons::of($weapons[$weapon['key']] ?? null),
                    'rarity' => ($weapons[$weapon['key']] ?? null)?->rarity,
                ],
                $build->weapons,
            ),
            'sets' => array_map(
                fn($option) => array_merge(Neutral::deep($without($option, 'note')), [
                    'pieces' => array_map(
                        fn($piece) => Neutral::deep($piece) + [
                            'icon_url' => count($piece['keys']) === 1 ? Icons::of($sets[$piece['keys'][0]] ?? null) : null,
                        ],
                        $option['pieces'],
                    ),
                ]),
                $build->sets,
            ),
            'main_stats' => (object) $build->main_stats,
            'substats' => $build->substats,
            'talent_priority' => $build->talent_priority,
            'stat_targets' => $build->stat_targets === null ? null : Neutral::deep(array_map(fn(array $target) => $without($target, 'text'), $build->stat_targets)),
            'targets' => self::targets($build, $weapons, $sets),
            'teams' => array_map(
                fn(array $team, int $t) => [
                    'name' => (string) Neutral::text((string) ($team['name'] ?? '')),
                    'members' => array_map(
                        fn(array $member) => self::teammate($member, $teammates, $withBuilds) + [
                            'role' => $member['role'] ?? null,
                            'min_rank' => (int) ($member['min_rank'] ?? 0),
                            // Its keys are stored neutral (BuildFile reads them so); the character's is the catalog's.
                            'own' => ($member['key'] ?? null) === BuildFile::neutralKey($build->character->key),
                            'alternatives' => array_map(fn(array $alternative) => self::teammate($alternative, $teammates, $withBuilds), $member['alternatives'] ?? []),
                        ],
                        $team['members'] ?? [],
                    ),
                    'note' => $notes['teams'][$t],
                ],
                $build->teams,
                array_keys($build->teams),
            ),
            'notes' => $notes['build'],
            'game_version' => Neutral::text($build->game_version),
            'checked_at' => Clock::iso($build->checked_at),
            'changed_at' => Clock::iso($build->changed_at),
        ];
    }

    /**
     * A teammate - a team's member or a stand-in - as the catalog knows it now: its name the catalog's where it is
     * there (the file's otherwise, neutral), its icon, rarity and element, and whether it has builds to link to.
     *
     * @param  array<string, mixed>  $one
     * @param  array<string, GameCharacter>  $teammates
     * @param  array<string, true>  $withBuilds
     * @return array{key: string, name: string, known: bool, icon_url: string|null, rarity: int|null, element: string|null, has_builds: bool}
     */
    private static function teammate(array $one, array $teammates, array $withBuilds): array
    {
        $key = (string) ($one['key'] ?? '');
        $character = $teammates[$key] ?? null;

        return [
            'key' => $key,
            'name' => (string) Neutral::text($character?->name ?? (string) ($one['name'] ?? '')),
            'known' => $character !== null,
            'icon_url' => Icons::of($character),
            'rarity' => $character?->rarity,
            'element' => $character?->element,
            'has_builds' => isset($withBuilds[$key]),
        ];
    }
}
