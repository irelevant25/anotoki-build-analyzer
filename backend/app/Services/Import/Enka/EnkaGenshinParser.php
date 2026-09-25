<?php

namespace App\Services\Import\Enka;

use App\Enums\Game;
use App\Services\Import\ImportException;
use App\Services\Import\ParsedAccount;

/**
 * Enka.Network Genshin profile => ParsedAccount (showcase characters with their equipped gear).
 */
class EnkaGenshinParser
{
    public const FIGHT_PROPS = [
        'FIGHT_PROP_HP' => 'hp', 'FIGHT_PROP_HP_PERCENT' => 'hp_',
        'FIGHT_PROP_ATTACK' => 'atk', 'FIGHT_PROP_ATTACK_PERCENT' => 'atk_',
        'FIGHT_PROP_DEFENSE' => 'def', 'FIGHT_PROP_DEFENSE_PERCENT' => 'def_',
        'FIGHT_PROP_ELEMENT_MASTERY' => 'eleMas', 'FIGHT_PROP_CHARGE_EFFICIENCY' => 'enerRech_',
        'FIGHT_PROP_HEAL_ADD' => 'heal_', 'FIGHT_PROP_CRITICAL' => 'critRate_', 'FIGHT_PROP_CRITICAL_HURT' => 'critDMG_',
        'FIGHT_PROP_PHYSICAL_ADD_HURT' => 'physical_dmg_', 'FIGHT_PROP_WIND_ADD_HURT' => 'anemo_dmg_',
        'FIGHT_PROP_ROCK_ADD_HURT' => 'geo_dmg_', 'FIGHT_PROP_ELEC_ADD_HURT' => 'electro_dmg_',
        'FIGHT_PROP_WATER_ADD_HURT' => 'hydro_dmg_', 'FIGHT_PROP_FIRE_ADD_HURT' => 'pyro_dmg_',
        'FIGHT_PROP_ICE_ADD_HURT' => 'cryo_dmg_', 'FIGHT_PROP_GRASS_ADD_HURT' => 'dendro_dmg_',
    ];

    /** fightPropMap ids of final (total) stats. Ratios are fractions and get converted to %. */
    private const FINAL_STATS = [
        2000 => 'hp', 2001 => 'atk', 2002 => 'def', 28 => 'eleMas',
        20 => 'critRate_', 22 => 'critDMG_', 23 => 'enerRech_', 26 => 'heal_',
        30 => 'physical_dmg_', 40 => 'pyro_dmg_', 41 => 'electro_dmg_', 42 => 'hydro_dmg_',
        43 => 'dendro_dmg_', 44 => 'anemo_dmg_', 45 => 'geo_dmg_', 46 => 'cryo_dmg_',
    ];

    private const SLOTS = [
        'EQUIP_BRACER' => 'flower', 'EQUIP_NECKLACE' => 'plume', 'EQUIP_SHOES' => 'sands',
        'EQUIP_RING' => 'goblet', 'EQUIP_DRESS' => 'circlet',
    ];

    /** Substat code in appendPropIdList (floor(id / 10) % 1000) => stat. Each entry is one roll. */
    private const ROLL_CODES = [
        102 => 'hp', 103 => 'hp_', 105 => 'atk', 106 => 'atk_', 108 => 'def', 109 => 'def_',
        120 => 'critRate_', 122 => 'critDMG_', 123 => 'enerRech_', 124 => 'eleMas',
    ];

    /** Traveler element from the last two digits of skillDepotId. */
    private const TRAVELER_ELEMENTS = [
        2 => 'pyro', 3 => 'hydro', 4 => 'anemo', 5 => 'cryo', 6 => 'geo', 7 => 'electro', 8 => 'dendro',
    ];

    public function __construct(private readonly EnkaStore $store) {}

    public function parse(array $json): ParsedAccount
    {
        $player = $json['playerInfo'] ?? [];
        $account = new ParsedAccount(
            game: Game::Genshin,
            source: 'enka',
            fullInventory: false,
            uid: isset($json['uid']) ? (string) $json['uid'] : null,
            nickname: $player['nickname'] ?? null,
            level: isset($player['level']) ? (int) $player['level'] : null,
            worldLevel: isset($player['worldLevel']) ? (int) $player['worldLevel'] : null,
            signature: $player['signature'] ?? null,
            meta: ['region' => $json['region'] ?? null, 'achievements' => $player['finishAchievementNum'] ?? null],
        );

        $avatars = $json['avatarInfoList'] ?? [];
        if ($avatars === []) {
            $shown = count($player['showAvatarInfoList'] ?? []);
            throw new ImportException($shown > 0
                ? 'The profile shows characters, but "Show Character Details" is turned off. Enable it in-game (Profile > Character Showcase) and try again in a few minutes.'
                : 'This profile has no characters in its Character Showcase. Add characters in-game (Profile > Character Showcase), enable "Show Character Details", then try again.');
        }

        foreach ($avatars as $avatar) {
            $characterRef = $this->characterRef($avatar);

            $account->characters[] = [
                'ref' => $characterRef,
                'level' => (int) ($avatar['propMap']['4001']['val'] ?? 1),
                'ascension' => (int) ($avatar['propMap']['1002']['val'] ?? 0),
                'rank' => count($avatar['talentIdList'] ?? []),
                'talents' => $this->talents($avatar),
                'final_stats' => $this->finalStats($avatar['fightPropMap'] ?? []),
            ];

            foreach ($avatar['equipList'] ?? [] as $equip) {
                $flat = $equip['flat'] ?? [];
                if (($flat['itemType'] ?? null) === 'ITEM_WEAPON') {
                    $affix = array_values($equip['weapon']['affixMap'] ?? []);
                    $account->weapons[] = [
                        'ref' => ['external_id' => (string) $equip['itemId']],
                        'level' => (int) ($equip['weapon']['level'] ?? 1),
                        'ascension' => (int) ($equip['weapon']['promoteLevel'] ?? 0),
                        'refinement' => isset($affix[0]) ? (int) $affix[0] + 1 : 1,
                        'location' => $characterRef,
                        'locked' => false,
                        'uid' => null,
                    ];

                    continue;
                }

                if (($flat['itemType'] ?? null) === 'ITEM_RELIQUARY') {
                    $item = $this->artifact($equip, $characterRef);
                    if ($item) {
                        $account->items[] = $item;
                    } else {
                        $account->warnings[] = 'Skipped an artifact with unknown data.';
                    }
                }
            }
        }

        return $account;
    }

    private function characterRef(array $avatar): array
    {
        $id = (string) $avatar['avatarId'];
        if (in_array($id, ['10000005', '10000007'], true)) {
            $element = self::TRAVELER_ELEMENTS[((int) ($avatar['skillDepotId'] ?? 0)) % 100] ?? null;

            return $element ? ['key' => 'traveler'.$element, 'name' => 'Traveler ('.ucfirst($element).')'] : ['name' => 'Traveler'];
        }

        return ['external_id' => $id];
    }

    private function artifact(array $equip, array $characterRef): ?array
    {
        $flat = $equip['flat'];
        $slot = self::SLOTS[$flat['equipType'] ?? ''] ?? null;
        $main = self::FIGHT_PROPS[$flat['reliquaryMainstat']['mainPropId'] ?? ''] ?? null;
        $setId = $flat['setId'] ?? (preg_match('/UI_RelicIcon_(\d+)_/', (string) ($flat['icon'] ?? ''), $m) ? $m[1] : null);
        if (! $slot || ! $main || ! $setId) {
            return null;
        }

        $rolls = [];
        foreach ($equip['reliquary']['appendPropIdList'] ?? [] as $propId) {
            $stat = self::ROLL_CODES[intdiv((int) $propId, 10) % 1000] ?? null;
            if ($stat) {
                $rolls[$stat] = ($rolls[$stat] ?? 0) + 1;
            }
        }

        $substats = [];
        foreach ($flat['reliquarySubstats'] ?? [] as $substat) {
            $key = self::FIGHT_PROPS[$substat['appendPropId'] ?? ''] ?? null;
            if ($key) {
                $substats[] = ['key' => $key, 'value' => (float) $substat['statValue'], 'rolls' => $rolls[$key] ?? null];
            }
        }

        return [
            'set' => ['external_id' => (string) $setId],
            'slot' => $slot,
            'rarity' => (int) ($flat['rankLevel'] ?? 5),
            'level' => max(0, (int) ($equip['reliquary']['level'] ?? 1) - 1), // Enka levels are +1
            'main_stat' => $main,
            'main_value' => isset($flat['reliquaryMainstat']['statValue']) ? (float) $flat['reliquaryMainstat']['statValue'] : null,
            'substats' => $substats,
            'location' => $characterRef,
            'locked' => false,
            'uid' => null,
        ];
    }

    /**
     * Base talent levels {normal, skill, burst} plus constellation bonuses under "bonus".
     */
    private function talents(array $avatar): ?array
    {
        $id = (string) $avatar['avatarId'];
        $storeKey = in_array($id, ['10000005', '10000007'], true) ? $id.'-'.($avatar['skillDepotId'] ?? '') : $id;
        $store = $this->store->genshinAvatar($storeKey);
        $order = $store['SkillOrder'] ?? null;
        $levels = $avatar['skillLevelMap'] ?? [];

        if (! is_array($order) || count($order) < 3) {
            return null;
        }

        $talents = [];
        $bonus = [];
        foreach (['normal', 'skill', 'burst'] as $index => $name) {
            $skillId = (string) $order[$index];
            $talents[$name] = (int) ($levels[$skillId] ?? 1);
            $proudGroup = (string) ($store['ProudMap'][$skillId] ?? '');
            $extra = (int) ($avatar['proudSkillExtraLevelMap'][$proudGroup] ?? 0);
            if ($extra > 0) {
                $bonus[$name] = $extra;
            }
        }

        return $bonus ? $talents + ['bonus' => $bonus] : $talents;
    }

    private function finalStats(array $props): ?array
    {
        $stats = [];
        foreach (self::FINAL_STATS as $id => $key) {
            if (! isset($props[(string) $id])) {
                continue;
            }
            $value = (float) $props[(string) $id];
            $stats[$key] = str_ends_with($key, '_') ? round($value * 100, 1) : round($value);
        }

        return $stats ?: null;
    }
}
