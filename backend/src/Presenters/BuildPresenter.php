<?php

namespace App\Presenters;

use App\Models\Build;
use App\Models\GameCharacter;
use App\Repositories\Builds;
use App\Repositories\CatalogRows;
use App\Services\Builds\BuildFile;
use App\Services\Catalog\Catalog;
use App\Support\Clock;
use App\Support\Neutral;

/**
 * Builds as the API sends them, with weapon and set icons from the catalog, and their teams with each teammate as
 * the catalog knows it now - its icon, whether it is there at all, whether it has builds to link to - so a teammate
 * the catalog catches up with shows as soon as it does, with no import.
 *
 * A build is a variation of its character's - a role, a qualifier, the constellation it needs - and names nowhere
 * it came from: every text it was read with (notes, its qualifier, names, stat target texts, its teams' names and
 * notes) is passed through Neutral once more on the way out - the stored texts alone, before an icon's address is
 * added, which Neutral would take for another site's.
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
     * @param  array<string, GameCharacter>  $teammates
     * @param  array<string, true>  $withBuilds
     */
    private function present(Build $build, array $weapons, array $sets, array $teammates, array $withBuilds): array
    {
        return [
            'id' => $build->id,
            'role' => $build->role,
            'qualifier' => (string) Neutral::text($build->qualifier),
            'min_rank' => $build->min_rank,
            'position' => $build->position,
            'weapons' => array_map(
                fn($weapon) => Neutral::deep($weapon) + [
                    'icon_url' => Icons::of($weapons[$weapon['key']] ?? null),
                    'rarity' => ($weapons[$weapon['key']] ?? null)?->rarity,
                ],
                $build->weapons,
            ),
            'sets' => array_map(
                fn($option) => array_merge(Neutral::deep($option), [
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
            'stat_targets' => Neutral::deep($build->stat_targets),
            'teams' => array_map(
                fn(array $team) => [
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
                    'note' => Neutral::text($team['note'] ?? null),
                ],
                $build->teams,
            ),
            'notes' => Neutral::text($build->notes),
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
