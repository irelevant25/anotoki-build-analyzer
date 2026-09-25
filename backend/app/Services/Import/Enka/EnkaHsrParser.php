<?php

namespace App\Services\Import\Enka;

use App\Enums\Game;
use App\Services\Import\ImportException;
use App\Services\Import\ParsedAccount;

/**
 * Enka.Network Honkai: Star Rail profile => ParsedAccount (showcase + support characters).
 */
class EnkaHsrParser
{
    /** _flat prop types; main stats keep a "Base" suffix, substats drop it, so both are accepted. */
    public const PROPS = [
        'HPDelta' => 'hp', 'HPAddedRatio' => 'hp_',
        'AttackDelta' => 'atk', 'AttackAddedRatio' => 'atk_',
        'DefenceDelta' => 'def', 'DefenceAddedRatio' => 'def_',
        'SpeedDelta' => 'spd',
        'CriticalChance' => 'critRate_', 'CriticalDamage' => 'critDMG_',
        'StatusProbability' => 'effectHitRate_', 'StatusResistance' => 'effectRes_',
        'BreakDamageAddedRatio' => 'breakEffect_', 'SPRatio' => 'energyRegen_', 'HealRatio' => 'heal_',
        'PhysicalAddedRatio' => 'physical_dmg_', 'FireAddedRatio' => 'fire_dmg_', 'IceAddedRatio' => 'ice_dmg_',
        'ThunderAddedRatio' => 'lightning_dmg_', 'WindAddedRatio' => 'wind_dmg_',
        'QuantumAddedRatio' => 'quantum_dmg_', 'ImaginaryAddedRatio' => 'imaginary_dmg_',
    ];

    private const SLOTS = [1 => 'head', 2 => 'hands', 3 => 'body', 4 => 'feet', 5 => 'sphere', 6 => 'rope'];

    /** Relic substat affix IDs (same for every rarity). */
    private const SUB_AFFIXES = [
        1 => 'hp', 2 => 'atk', 3 => 'def', 4 => 'hp_', 5 => 'atk_', 6 => 'def_', 7 => 'spd',
        8 => 'critRate_', 9 => 'critDMG_', 10 => 'effectHitRate_', 11 => 'effectRes_', 12 => 'breakEffect_',
    ];

    /** Last three digits of a skill tree pointId. */
    private const SKILL_POINTS = ['001' => 'basic', '002' => 'skill', '003' => 'ultimate', '004' => 'talent'];

    public function parse(array $json): ParsedAccount
    {
        $detail = $json['detailInfo'] ?? [];
        $account = new ParsedAccount(
            game: Game::Hsr,
            source: 'enka',
            fullInventory: false,
            uid: isset($detail['uid']) ? (string) $detail['uid'] : (isset($json['uid']) ? (string) $json['uid'] : null),
            nickname: $detail['nickname'] ?? null,
            level: isset($detail['level']) ? (int) $detail['level'] : null,
            worldLevel: isset($detail['worldLevel']) ? (int) $detail['worldLevel'] : null,
            signature: $detail['signature'] ?? null,
            meta: ['region' => $json['region'] ?? null, 'platform' => $detail['platform'] ?? null],
        );

        $avatars = $detail['avatarDetailList'] ?? [];
        if ($avatars === []) {
            throw new ImportException('This profile shows no characters. In-game open Phone > Profile, add characters to the Character Showcase and Support Characters, then try again in a few minutes.');
        }

        $seen = [];
        foreach ($avatars as $avatar) {
            $id = (string) ($avatar['avatarId'] ?? '');
            // Support characters can also be in the showcase; keep one copy.
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $characterRef = ['external_id' => $id];

            $account->characters[] = [
                'ref' => $characterRef,
                'level' => (int) ($avatar['level'] ?? 1),
                'ascension' => (int) ($avatar['promotion'] ?? 0),
                'rank' => (int) ($avatar['rank'] ?? 0),
                'talents' => $this->skills($avatar['skillTreeList'] ?? []),
                'final_stats' => null,
            ];

            if (! empty($avatar['equipment']['tid'])) {
                $cone = $avatar['equipment'];
                $account->weapons[] = [
                    'ref' => ['external_id' => (string) $cone['tid']],
                    'level' => (int) ($cone['level'] ?? 1),
                    'ascension' => (int) ($cone['promotion'] ?? 0),
                    'refinement' => (int) ($cone['rank'] ?? 1),
                    'location' => $characterRef,
                    'locked' => false,
                    'uid' => null,
                ];
            }

            foreach ($avatar['relicList'] ?? [] as $relic) {
                $item = $this->relic($relic, $characterRef);
                if ($item) {
                    $account->items[] = $item;
                } else {
                    $account->warnings[] = 'Skipped a relic with unknown data.';
                }
            }
        }

        return $account;
    }

    private function relic(array $relic, array $characterRef): ?array
    {
        $slot = self::SLOTS[(int) ($relic['type'] ?? 0)] ?? null;
        $props = $relic['_flat']['props'] ?? [];
        $setId = $relic['_flat']['setID'] ?? null;
        if (! $slot || $props === [] || ! $setId) {
            return null;
        }

        $main = $this->prop($props[0]);
        if (! $main) {
            return null;
        }

        // Roll counts come from subAffixList (cnt = rolls including the initial one).
        $rolls = [];
        foreach ($relic['subAffixList'] ?? [] as $affix) {
            $stat = self::SUB_AFFIXES[(int) ($affix['affixId'] ?? 0)] ?? null;
            if ($stat) {
                $rolls[$stat] = (int) ($affix['cnt'] ?? 1);
            }
        }

        $substats = [];
        foreach (array_slice($props, 1) as $prop) {
            $parsed = $this->prop($prop);
            if ($parsed) {
                $substats[] = ['key' => $parsed['key'], 'value' => $parsed['value'], 'rolls' => $rolls[$parsed['key']] ?? null];
            }
        }

        $tid = (int) ($relic['tid'] ?? 0);

        return [
            'set' => ['external_id' => (string) $setId],
            'slot' => $slot,
            'rarity' => $tid >= 10000 ? max(2, min(5, intdiv($tid, 10000) - 1)) : 5, // 6xxxx = 5-star
            'level' => (int) ($relic['level'] ?? 0),
            'main_stat' => $main['key'],
            'main_value' => $main['value'],
            'substats' => $substats,
            'location' => $characterRef,
            'locked' => false,
            'uid' => null,
        ];
    }

    /**
     * @return array{key: string, value: float}|null
     */
    private function prop(array $prop): ?array
    {
        $type = preg_replace('/Base$/', '', (string) ($prop['type'] ?? '')) ?? '';
        $key = self::PROPS[$type] ?? null;
        if (! $key) {
            return null;
        }
        $value = (float) ($prop['value'] ?? 0);

        return ['key' => $key, 'value' => str_ends_with($key, '_') ? round($value * 100, 3) : round($value, 3)];
    }

    private function skills(array $tree): ?array
    {
        $skills = [];
        foreach ($tree as $node) {
            $name = self::SKILL_POINTS[substr((string) ($node['pointId'] ?? ''), -3)] ?? null;
            if ($name) {
                $skills[$name] = (int) ($node['level'] ?? 1);
            }
        }

        return $skills ?: null;
    }
}
