<?php

namespace App\Services\Import\Files;

use App\Enums\Game;
use App\Services\Import\ImportException;
use App\Services\Import\ParsedAccount;
use App\Support\MainStatValues;

/**
 * The Honkai: Star Rail scanner format (version 4) read by the Fribbels HSR Optimizer.
 * Written by Reliquary Archiver (packet capture) and HSR-Scanner (OCR). It is a full inventory export.
 */
class HsrScannerParser
{
    private const SLOTS = [
        'Head' => 'head', 'Hands' => 'hands', 'Body' => 'body', 'Feet' => 'feet',
        'Planar Sphere' => 'sphere', 'Link Rope' => 'rope',
    ];

    /** Main stats: HP / ATK are flat only on Head / Hands; DEF is always a percentage. */
    private const MAIN = [
        'SPD' => 'spd', 'DEF' => 'def_', 'CRIT Rate' => 'critRate_', 'CRIT DMG' => 'critDMG_',
        'Effect Hit Rate' => 'effectHitRate_', 'Outgoing Healing Boost' => 'heal_',
        'Break Effect' => 'breakEffect_', 'Energy Regeneration Rate' => 'energyRegen_',
        'Physical DMG Boost' => 'physical_dmg_', 'Fire DMG Boost' => 'fire_dmg_', 'Ice DMG Boost' => 'ice_dmg_',
        'Lightning DMG Boost' => 'lightning_dmg_', 'Wind DMG Boost' => 'wind_dmg_',
        'Quantum DMG Boost' => 'quantum_dmg_', 'Imaginary DMG Boost' => 'imaginary_dmg_',
    ];

    /** Substats: a trailing "_" marks a percentage. OCR can drop it for stats that are always %. */
    private const SUB = [
        'HP' => 'hp', 'ATK' => 'atk', 'DEF' => 'def', 'SPD' => 'spd',
        'HP_' => 'hp_', 'ATK_' => 'atk_', 'DEF_' => 'def_',
        'CRIT Rate_' => 'critRate_', 'CRIT DMG_' => 'critDMG_', 'Effect Hit Rate_' => 'effectHitRate_',
        'Effect RES_' => 'effectRes_', 'Break Effect_' => 'breakEffect_',
        'CRIT Rate' => 'critRate_', 'CRIT DMG' => 'critDMG_', 'Effect Hit Rate' => 'effectHitRate_',
        'Effect RES' => 'effectRes_', 'Break Effect' => 'breakEffect_',
    ];

    public static function matches(array $json): bool
    {
        if (! isset($json['relics']) || ! is_array($json['relics'])) {
            return false;
        }

        $knownTool = in_array($json['source'] ?? '', ['reliquary_archiver', 'HSR-Scanner', 'yas-scanner'], true);
        $looksLikeV4 = isset($json['light_cones']) && (int) ($json['version'] ?? 0) >= 3;

        return $knownTool || $looksLikeV4;
    }

    public function parse(array $json): ParsedAccount
    {
        if (! self::matches($json)) {
            throw new ImportException('This is not a Star Rail inventory export (it has no "relics" and "light_cones").');
        }

        $account = new ParsedAccount(
            game: Game::Hsr,
            source: 'hsr_scanner',
            fullInventory: true,
            uid: ! empty($json['metadata']['uid']) ? (string) $json['metadata']['uid'] : null,
            meta: [
                'tool' => $json['source'] ?? 'unknown',
                'tool_version' => $json['build'] ?? null,
                'format_version' => $json['version'] ?? null,
                'trailblazer' => $json['metadata']['trailblazer'] ?? null,
            ],
        );

        foreach ($json['characters'] ?? [] as $character) {
            $ref = $this->ref($character['id'] ?? null, $character['name'] ?? ($character['key'] ?? null));
            if (! $ref) {
                continue;
            }
            $skills = $character['skills'] ?? [];
            $talents = array_filter([
                'basic' => $skills['basic'] ?? null,
                'skill' => $skills['skill'] ?? null,
                'ultimate' => $skills['ult'] ?? null,
                'talent' => $skills['talent'] ?? null,
                'memo_skill' => $character['memosprite']['skill'] ?? null,
                'memo_talent' => $character['memosprite']['talent'] ?? null,
            ], fn ($level) => $level !== null);

            $account->characters[] = [
                'ref' => $ref,
                'level' => (int) ($character['level'] ?? 1),
                'ascension' => (int) ($character['ascension'] ?? 0),
                'rank' => (int) ($character['eidolon'] ?? 0),
                'talents' => $talents ? array_map('intval', $talents) : null,
                'final_stats' => null,
            ];
        }

        foreach ($json['light_cones'] ?? [] as $cone) {
            $ref = $this->ref($cone['id'] ?? null, $cone['name'] ?? ($cone['key'] ?? null));
            if (! $ref) {
                continue;
            }
            $account->weapons[] = [
                'ref' => $ref,
                'level' => (int) ($cone['level'] ?? 1),
                'ascension' => (int) ($cone['ascension'] ?? 0),
                'refinement' => (int) ($cone['superimposition'] ?? 1),
                'location' => $this->location($cone['location'] ?? null),
                'locked' => (bool) ($cone['lock'] ?? false),
                'uid' => isset($cone['_uid']) ? (string) $cone['_uid'] : null,
            ];
        }

        $skipped = 0;
        foreach ($json['relics'] ?? [] as $relic) {
            $slot = self::SLOTS[$relic['slot'] ?? ''] ?? null;
            $main = $slot ? $this->mainStat($slot, (string) ($relic['mainstat'] ?? '')) : null;
            $set = $this->ref($relic['set_id'] ?? null, $relic['set'] ?? ($relic['name'] ?? null));
            if (! $slot || ! $main || ! $set) {
                $skipped++;

                continue;
            }

            $rarity = (int) ($relic['rarity'] ?? 5);
            $level = (int) ($relic['level'] ?? 0);

            $substats = [];
            foreach ($relic['substats'] ?? [] as $substat) {
                $key = self::SUB[$substat['key'] ?? ''] ?? null;
                if ($key) {
                    $substats[] = [
                        'key' => $key,
                        'value' => (float) ($substat['value'] ?? 0),
                        'rolls' => isset($substat['count']) ? (int) $substat['count'] : null,
                    ];
                }
            }

            $account->items[] = [
                'set' => $set,
                'slot' => $slot,
                'rarity' => $rarity,
                'level' => $level,
                'main_stat' => $main,
                'main_value' => MainStatValues::compute(Game::Hsr, $main, $rarity, $level),
                'substats' => $substats,
                'location' => $this->location($relic['location'] ?? null),
                'locked' => (bool) ($relic['lock'] ?? false),
                'uid' => isset($relic['_uid']) ? (string) $relic['_uid'] : null,
            ];
        }

        if ($skipped > 0) {
            $account->warnings[] = "{$skipped} relic(s) with an unknown slot, set or main stat were skipped.";
        }
        if ($account->characters === [] && $account->items === []) {
            throw new ImportException('The export contains no characters or relics. Check the scanner settings.');
        }

        return $account;
    }

    private function mainStat(string $slot, string $label): ?string
    {
        return match ($label) {
            'HP' => $slot === 'head' ? 'hp' : 'hp_',
            'ATK' => $slot === 'hands' ? 'atk' : 'atk_',
            default => self::MAIN[$label] ?? null,
        };
    }

    private function ref(mixed $id, mixed $name): ?array
    {
        $ref = array_filter([
            'external_id' => $id !== null && $id !== '' ? (string) $id : null,
            'name' => is_string($name) && $name !== '' ? $name : null,
        ]);

        return $ref ?: null;
    }

    /**
     * location is the equipping character's in-game ID (or its name in very old exports); "" = unequipped.
     */
    private function location(mixed $location): ?array
    {
        if ($location === null || $location === '') {
            return null;
        }

        return ctype_digit((string) $location) ? ['external_id' => (string) $location] : ['name' => (string) $location];
    }
}
