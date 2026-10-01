<?php

namespace App\Services\Import\Files;

use App\Enums\Game;
use App\Services\Import\ImportException;
use App\Services\Import\ParsedAccount;
use App\Support\MainStatValues;
use App\Support\Stats;

/**
 * GOOD (Genshin Open Object Description), the format written by Irminsul, Inventory Kamera,
 * GOODScanner and Genshin Optimizer itself. It is a full inventory export.
 */
class GoodParser
{
    private const SLOTS = ['flower', 'plume', 'sands', 'goblet', 'circlet'];

    public static function matches(array $json): bool
    {
        return ($json['format'] ?? null) === 'GOOD';
    }

    public function parse(array $json): ParsedAccount
    {
        if (! self::matches($json)) {
            throw new ImportException('This is not a GOOD file (missing "format": "GOOD").', 'good_not_good');
        }

        $account = new ParsedAccount(
            game: Game::Genshin,
            source: 'good',
            fullInventory: true,
            meta: ['tool' => $json['source'] ?? 'unknown', 'format_version' => $json['version'] ?? null],
        );

        $travelerKeys = [];
        foreach ($json['characters'] ?? [] as $character) {
            $key = (string) ($character['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if ($key === 'Traveler') {
                $account->warn('good_traveler_no_element', [], 'The file has a Traveler without an element (old GOOD version); it was skipped.');

                continue;
            }
            if (str_starts_with($key, 'Traveler')) {
                $travelerKeys[] = $key;
            }

            $talent = $character['talent'] ?? [];
            $account->characters[] = [
                'ref' => ['name' => $key],
                'level' => (int) ($character['level'] ?? 1),
                'ascension' => (int) ($character['ascension'] ?? 0),
                'rank' => (int) ($character['constellation'] ?? 0),
                'talents' => [
                    'normal' => (int) ($talent['auto'] ?? 1),
                    'skill' => (int) ($talent['skill'] ?? 1),
                    'burst' => (int) ($talent['burst'] ?? 1),
                ],
                'final_stats' => null,
            ];
        }

        // Equipment on the Traveler uses location "Traveler"; attach it to the Traveler the file lists.
        $travelerLocation = $travelerKeys !== [] ? ['name' => $travelerKeys[0]] : ['name' => 'Traveler'];
        $location = function (?string $key) use ($travelerLocation): ?array {
            if ($key === null || $key === '') {
                return null;
            }

            return $key === 'Traveler' ? $travelerLocation : ['name' => $key];
        };

        foreach ($json['weapons'] ?? [] as $weapon) {
            if (empty($weapon['key'])) {
                continue;
            }
            $account->weapons[] = [
                'ref' => ['name' => (string) $weapon['key']],
                'level' => (int) ($weapon['level'] ?? 1),
                'ascension' => (int) ($weapon['ascension'] ?? 0),
                'refinement' => (int) ($weapon['refinement'] ?? 1),
                'location' => $location($weapon['location'] ?? null),
                'locked' => (bool) ($weapon['lock'] ?? false),
                'uid' => isset($weapon['id']) ? (string) $weapon['id'] : null,
            ];
        }

        $skipped = 0;
        foreach ($json['artifacts'] ?? [] as $artifact) {
            $slot = $artifact['slotKey'] ?? null;
            $main = $artifact['mainStatKey'] ?? null;
            if (empty($artifact['setKey']) || ! in_array($slot, self::SLOTS, true) || ! is_string($main) || $main === '') {
                $skipped++;

                continue;
            }

            $rarity = (int) ($artifact['rarity'] ?? 5);
            $level = (int) ($artifact['level'] ?? 0);

            $substats = [];
            foreach ($artifact['substats'] ?? [] as $substat) {
                $key = $substat['key'] ?? '';
                $value = (float) ($substat['value'] ?? 0);
                if ($key === '' || $value <= 0 || Stats::maxRoll(Game::Genshin, $key) === null) {
                    continue;
                }
                $substats[] = ['key' => $key, 'value' => $value, 'rolls' => null];
            }

            $account->items[] = [
                'set' => ['name' => (string) $artifact['setKey']],
                'slot' => $slot,
                'rarity' => $rarity,
                'level' => $level,
                'main_stat' => $main,
                'main_value' => MainStatValues::compute(Game::Genshin, $main, $rarity, $level),
                'substats' => $substats,
                'location' => $location($artifact['location'] ?? null),
                'locked' => (bool) ($artifact['lock'] ?? false),
                'uid' => isset($artifact['id']) ? (string) $artifact['id'] : null,
            ];
        }

        if ($skipped > 0) {
            $account->warn('good_artifacts_skipped', ['count' => $skipped], "{$skipped} artifact(s) with missing set, slot or main stat were skipped.");
        }
        if ($account->characters === [] && $account->items === []) {
            throw new ImportException('The GOOD file contains no characters or artifacts. Check the exporter settings.', 'good_empty');
        }

        return $account;
    }
}
