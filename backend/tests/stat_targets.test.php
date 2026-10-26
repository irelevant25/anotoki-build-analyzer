<?php

/**
 * The stat targets worked out from the game's numbers (App\Services\Analysis\TargetTiers): a character's and a
 * weapon's stats taken to level 90 from what the catalog keeps (App\Support\MaxLevelStats), a set's 2-piece bonus
 * read from its words (App\Support\SetBonus), the fixed sources of the sheet, the three tiers as budgets of rolls
 * split by the substat priorities, the requirements paid first, the caps, the crit pair's 1:2 rule, and what a
 * build gets when the catalog lacks the stats.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Enums\Game;
use App\Models\Build;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Services\Analysis\TargetTiers;
use App\Support\MaxLevelStats;
use App\Support\SetBonus;

bootTests();

const GAME = Game::Genshin;

/** Kamisato Ayaka as the catalog keeps her: level 1 and level 80 stats, CRIT DMG from the ascensions. */
function ayaka(array $meta = []): GameCharacter
{
    return new GameCharacter(
        1,
        GAME,
        'kamisatoayaka',
        'Kamisato Ayaka',
        '10000002',
        null,
        $meta + [
            'stats' => [
                'base' => ['hp' => 1000.99, 'atk' => 26.63, 'def' => 61.03],
                'lv80' => ['hp' => 11954.32, 'atk' => 317.98, 'def' => 728.82],
                'ascension' => ['stat' => 'critDMG_', 'value' => 38.4],
            ],
        ],
        null,
        element: 'cryo',
        rarity: 5,
        weapon_type: 'sword',
    );
}

function weapon(string $name, int $rarity, float $base, float $lv80, ?array $secondary): GameWeapon
{
    return new GameWeapon(
        2,
        GAME,
        strtolower(str_replace(' ', '', $name)),
        $name,
        null,
        null,
        ['stats' => ['base_atk' => $base, 'lv80_atk' => $lv80, 'secondary' => $secondary]],
        null,
        rarity: $rarity,
        weapon_type: 'sword',
    );
}

function mistsplitter(): GameWeapon
{
    return weapon('Mistsplitter Reforged', 5, 48, 621, ['stat' => 'critDMG_', 'value' => 9.6]);
}

function set(string $name, string $twoPiece, string $fourPiece = 'Something conditional.'): GameSet
{
    return new GameSet(3, GAME, strtolower(str_replace(' ', '', $name)), $name, null, null, ['rarities' => [4, 5], 'bonuses' => [$twoPiece, $fourPiece]]);
}

const BLIZZARD_4PC = 'When a character attacks an opponent affected by Cryo, their CRIT Rate is increased by 20%. If the opponent is Frozen, CRIT Rate is increased by an additional 20%.';

function blizzard(): GameSet
{
    return set('Blizzard Strayer', 'Cryo DMG Bonus +15%', BLIZZARD_4PC);
}

/** A build of Ayaka's: the file's main DPS build, as the 2026-10-22 file has it, unless $fields says otherwise. */
function build(array $fields = []): Build
{
    return new Build(
        ...$fields + [
            'id' => 10,
            'game_character_id' => 1,
            'role' => 'main_dps',
            'weapons' => [['key' => 'mistsplitterreforged', 'name' => 'Mistsplitter Reforged', 'rank' => 1], ['key' => 'harangeppakufutsu', 'name' => 'Haran Geppaku Futsu', 'rank' => 2]],
            'sets' => [
                ['rank' => 1, 'kind' => 'artifact', 'choose' => null, 'pieces' => [['label' => 'Blizzard Strayer', 'count' => 4, 'keys' => ['blizzardstrayer'], 'names' => ['Blizzard Strayer']]]],
            ],
            'main_stats' => [
                'sands' => [['stat' => 'atk_', 'priority' => 1]],
                'goblet' => [['stat' => 'elemental_dmg_', 'priority' => 1]],
                'circlet' => [['stat' => 'critDMG_', 'priority' => 1], ['stat' => 'atk_', 'priority' => 2]],
            ],
            'substats' => [['stat' => 'critDMG_', 'priority' => 1], ['stat' => 'atk_', 'priority' => 2], ['stat' => 'enerRech_', 'priority' => 3], ['stat' => 'critRate_', 'priority' => 4]],
            'stat_targets' => null,
            'character' => ayaka(),
        ],
    );
}

/** The rows by stat, each tier rounded as the API sends it. */
function byStat(array $result): array
{
    $rows = [];
    foreach ($result['rows'] as $row) {
        $rows[$row['stat']] = $row;
    }

    return $rows;
}

/** The share of a tier's rolls that stats of these priorities take together, by TargetTiers::PRIORITY_SHARES. */
function share(array $priorities, array $all): float
{
    $weight = fn(int $priority): float => TargetTiers::PRIORITY_SHARES[$priority] ?? TargetTiers::LOWEST_SHARE;

    return array_sum(array_map($weight, $priorities)) / array_sum(array_map($weight, $all));
}

/** The CRIT Rate rolls out of $joint by the 1:2 rule in combat, as critSplit() takes them (no piece cap reached). */
function rateRolls(float $joint, float $rateFixed, float $dmgFixed, float $bonus = 0.0): float
{
    $solve = ($dmgFixed + $joint * 6.6045 - 2 * ($rateFixed + $bonus)) / (2 * 3.3065 + 6.6045);

    return min(max(0.0, $solve), $joint, max(0.0, (TargetTiers::CRIT_RATE_CAP - $rateFixed - $bonus) / 3.3065));
}

function about(float $expected, float $actual, string $label, float $within = 0.15): void
{
    check(abs($expected - $actual) <= $within, "{$label}: {$actual} near {$expected}");
}

// ── Level 90 ─────────────────────────────────────────────────────────────────────────────────────

test('a character at level 90 from its level 1 and level 80 stats: the game\'s own numbers within a point', function () {
    $stats = MaxLevelStats::character(ayaka());
    about(12858, $stats['hp'], 'Ayaka\'s HP', 1);
    about(342, $stats['atk'], 'her ATK', 1);
    about(784, $stats['def'], 'her DEF', 1);
    same(['stat' => 'critDMG_', 'value' => 38.4], $stats['ascension'], 'the ascension stat whole');

    $bennett = new GameCharacter(
        5,
        GAME,
        'bennett',
        'Bennett',
        null,
        null,
        [
            'stats' => [
                'base' => ['hp' => 1039.44, 'atk' => 16.03, 'def' => 64.66],
                'lv80' => ['hp' => 11538.82, 'atk' => 177.92, 'def' => 717.84],
                'ascension' => ['stat' => 'enerRech_', 'value' => 26.68],
            ],
        ],
        null,
        rarity: 4,
    );
    $stats = MaxLevelStats::character($bennett);
    about(12397, $stats['hp'], 'a 4-star\'s HP', 1);
    about(191, $stats['atk'], 'his ATK', 1);
});

test('no stats, a number missing or a rarity the game has not: no level 90', function () {
    same(null, MaxLevelStats::character(ayaka(['stats' => null])), 'none kept');
    same(
        null,
        MaxLevelStats::character(ayaka(['stats' => ['base' => ['hp' => 1, 'atk' => 1], 'lv80' => ['hp' => 1, 'atk' => 1, 'def' => 1], 'ascension' => ['stat' => 'critDMG_', 'value' => 1]]])),
        'DEF missing',
    );
    $odd = ayaka();
    $odd->rarity = 3;
    same(null, MaxLevelStats::character($odd), 'a 3-star character');
});

test('a weapon at level 90: base ATK by its rarity and level 1 value, the secondary stat by the one curve; a pair the table lacks keeps level 80', function () {
    same(['atk' => 674.0, 'secondary' => ['stat' => 'critDMG_', 'value' => 44.1]], MaxLevelStats::weapon(mistsplitter()), 'Mistsplitter: 48 -> 674, 9.6 -> 44.1');
    same(
        ['atk' => 454.0, 'secondary' => ['stat' => 'enerRech_', 'value' => 61.1]],
        MaxLevelStats::weapon(weapon('Favonius Sword', 4, 41, 427, ['stat' => 'enerRech_', 'value' => 13.3])),
        'a 4-star: 41 -> 454',
    );
    same(
        ['atk' => 542.0, 'secondary' => ['stat' => 'eleMas', 'value' => 264.6]],
        MaxLevelStats::weapon(weapon('A Thousand Floating Dreams', 5, 44, 506, ['stat' => 'eleMas', 'value' => 57.6])),
        'Elemental Mastery by the same curve',
    );
    same(['atk' => 186.0, 'secondary' => null], MaxLevelStats::weapon(weapon('Silver Sword', 2, 33, 186, null)), 'a 2-star: its level 80 value, no secondary stat');
    same(
        null,
        MaxLevelStats::weapon(
            weapon('Nameless', 5, 48, 621, null)->meta === [] ? weapon('Nameless', 5, 48, 621, null) : new GameWeapon(9, GAME, 'nameless', 'Nameless', null, null, [], null, rarity: 5),
        ),
        'no stats kept: none',
    );
});

test('a set\'s 2-piece bonus from its words: the sheet\'s stats as the catalog keys them, anything else none', function () {
    $parse = fn(string $text) => SetBonus::parse(GAME, $text);
    same(['stat' => 'atk_', 'value' => 18.0], $parse('ATK +18%.'), 'ATK +18%.');
    same(['stat' => 'atk_', 'value' => 18.0], $parse('ATK increased by 18%.'), 'ATK increased by 18%.');
    same(['stat' => 'eleMas', 'value' => 80.0], $parse('Increases Elemental Mastery by 80.'), 'Elemental Mastery');
    same(['stat' => 'cryo_dmg_', 'value' => 15.0], $parse('Cryo DMG Bonus +15%'), 'an element\'s DMG');
    same(['stat' => 'physical_dmg_', 'value' => 25.0], $parse('Physical DMG Bonus +25%.'), 'Physical DMG');
    same(['stat' => 'enerRech_', 'value' => 20.0], $parse('Energy Recharge + 20%.'), 'a space after the plus');
    same(['stat' => 'hp_', 'value' => 20.0], $parse('HP +20%'), 'HP%');
    same(['stat' => 'def_', 'value' => 30.0], $parse('DEF +30%'), 'DEF%');
    same(['stat' => 'critRate_', 'value' => 12.0], $parse('CRIT Rate +12%'), 'CRIT Rate');
    same(['stat' => 'heal_', 'value' => 15.0], $parse('Healing Bonus +15%.'), 'Healing Bonus');
    same(['stat' => 'heal_', 'value' => 15.0], $parse('Character Healing Effectiveness +15%'), 'the other words for it');
    same(['stat' => 'hp', 'value' => 1000.0], $parse('Max HP increased by 1000.'), 'flat HP');
    same(['stat' => 'def', 'value' => 100.0], $parse('DEF increased by 100.'), 'flat DEF');
    foreach (
        [
            'Increases Elemental Skill DMG by 20%.',
            'Elemental Burst DMG +20%',
            'Pyro RES increased by 40%.',
            'Normal and Charged Attack DMG +15%',
            'Increases Shield Strength by 35%.',
            'Increases incoming healing by 20%.',
            'Plunging Attack DMG increased by 25%.',
            'All Elemental RES increased by 20%.',
            'Elemental Mastery +80%',
            '',
        ]
        as $text
    ) {
        same(null, $parse($text), "no sheet stat: {$text}");
    }
    same(['stat' => 'cryo_dmg_', 'value' => 15.0], SetBonus::twoPiece(GAME, set('Blizzard Strayer', 'Cryo DMG Bonus +15%')), 'from the set\'s meta');
});

test('the CRIT Rate a 4-piece adds in combat, from its words: every "CRIT Rate ... N%" added up, stacks counted; nought where it says nothing of it', function () {
    same(40.0, SetBonus::parseCritRate(BLIZZARD_4PC), 'Blizzard Strayer: 20 and 20 more');
    same(36.0, SetBonus::parseCritRate('When current HP increases or decreases, CRIT Rate will be increased by 12% for 5s. Max 3 stacks.'), 'Marechaussee Hunter: 12 three times');
    same(
        40.0,
        SetBonus::parseCritRate('After the equipping character consumes 1 Nightsoul point while on the field, CRIT Rate increases by 40% for 6s. This effect can trigger once every second.'),
        'Obsidian Codex',
    );
    same(15.0, SetBonus::parseCritRate('Increases CRIT Rate by 15%/30% when the party\'s Moonsign is Nascent Gleam/Ascendant Gleam.'), 'two offered: the first');
    same(
        16.0,
        SetBonus::parseCritRate('Increases the equipping character\'s CRIT Rate by 16%, and their Stellar Swirl reaction dealt by 40%, for 10s after they trigger a Stellar Swirl reaction.'),
        'the number after CRIT Rate, not the next',
    );
    same(0.0, SetBonus::parseCritRate('Increases Overloaded and Burning DMG by 40%.'), 'nothing of CRIT Rate');
    same(0.0, SetBonus::parseCritRate('Increases CRIT DMG by 40%.'), 'CRIT DMG is not CRIT Rate');
    same(40.0, SetBonus::fourPieceCritRate(blizzard()), 'from the set\'s meta');
    same(0.0, SetBonus::fourPieceCritRate(set('Crimson Witch of Flames', 'Pyro DMG Bonus +15%')), 'a set without');
});

// ── The rows ─────────────────────────────────────────────────────────────────────────────────────

test('the fixed sources and the three tiers of a crit build: the sheet\'s base, the ascension, the weapon, the main stats and the 2-piece bonus, then the rolls split by priority', function () {
    $result = TargetTiers::forBuild(build(), ayaka(), ['mistsplitterreforged' => mistsplitter()], ['blizzardstrayer' => blizzard()], null, [], 0);
    same(
        ['weapon' => 'Mistsplitter Reforged', 'sets' => '4pc Blizzard Strayer', 'main_stats' => ['sands' => 'atk_', 'goblet' => 'cryo_dmg_', 'circlet' => 'critDMG_']],
        $result['basis'],
        'what the rows rest on',
    );
    $rows = byStat($result);
    same(['critDMG_', 'atk', 'enerRech_', 'critRate_'], array_keys($rows), 'a row per substat, by priority, ATK% as the sheet\'s ATK');
    same([null, null, null], [$rows['critDMG_']['min'], $rows['critDMG_']['max'], $rows['critDMG_']['from_rank']], 'a tiered row has no requirement');

    // Fixed: CRIT DMG 50 + 38.4 (ascension) + 44.1 (Mistsplitter) + 62.2 (circlet) = 194.7, CRIT Rate 5 - and 40 more
    // in combat from Blizzard Strayer's 4-piece. The pair is one share (1.0 + 0.2 of 2.0) whatever the file's
    // priorities: 9.6, 14.4 and 19.2 rolls, CRIT Rate first until it is 1:2 in combat, never past 85 % there - a
    // sheet of 45 %, from the intermediate tier on; the rest to CRIT DMG.
    $crit = fn(int $budget) => $budget * share([1, 4], [1, 2, 3, 4]);
    $rate = fn(int $budget) => rateRolls($crit($budget), 5, 194.7, 40);
    about(5 + $rate(16) * 3.3065, $rows['critRate_']['tiers']['basic'], 'CRIT Rate basic: every joint roll');
    about(194.7, $rows['critDMG_']['tiers']['basic'], 'CRIT DMG basic: the fixed sources alone');
    about(45, $rows['critRate_']['tiers']['intermediate'], 'CRIT Rate intermediate: at the cap, Blizzard counted');
    about(194.7 + ($crit(24) - $rate(24)) * 6.6045, $rows['critDMG_']['tiers']['intermediate'], 'CRIT DMG intermediate: the rolls past the cap', 0.3);
    about(45, $rows['critRate_']['tiers']['advanced'], 'CRIT Rate advanced: still at the cap');
    about(194.7 + ($crit(32) - $rate(32)) * 6.6045, $rows['critDMG_']['tiers']['advanced'], 'CRIT DMG advanced', 0.3);
    // Energy Recharge: 100 and its share, under the main DPS ceiling of 140.
    about(100 + 16 * share([3], [1, 2, 3, 4]) * 5.508, $rows['enerRech_']['tiers']['basic'], 'ER basic');
    // ATK: (342 + 674) * (1 + 46.6% sands + the share's rolls of 5.83 * 0.85) + 311 plume.
    about(1016 * (1 + (46.6 + 16 * share([2], [1, 2, 3, 4]) * 4.9555) / 100) + 311, $rows['atk']['tiers']['basic'], 'ATK basic', 3);
    about(1016 * (1 + (46.6 + 32 * share([2], [1, 2, 3, 4]) * 4.9555) / 100) + 311, $rows['atk']['tiers']['advanced'], 'ATK advanced', 3);
});

test('a stated target is a requirement: paid first from each budget, shown as stated, its stat out of the split', function () {
    $build = build([
        'main_stats' => ['sands' => [['stat' => 'enerRech_', 'priority' => 1]], 'goblet' => [['stat' => 'elemental_dmg_', 'priority' => 1]], 'circlet' => [['stat' => 'critDMG_', 'priority' => 1]]],
        'stat_targets' => [
            ['stat' => 'enerRech_', 'min' => 160, 'max' => 180, 'text' => '160-180% ER', 'from_rank' => 0],
            ['stat' => 'enerRech_', 'min' => 130, 'max' => null, 'text' => '', 'from_rank' => 2],
        ],
    ]);
    $rows = byStat(TargetTiers::forBuild($build, ayaka(), ['mistsplitterreforged' => mistsplitter()], [], null, [], 0));
    same(['enerRech_', 'critDMG_', 'atk', 'critRate_'], array_keys($rows), 'the requirement first, Energy Recharge no longer split');
    same(['stat' => 'enerRech_', 'tiers' => null, 'min' => 160, 'max' => 180, 'from_rank' => 0], $rows['enerRech_'], 'as stated, untiered');
    // 100 + 51.8 (sands) = 151.8; 160 takes 2 rolls of 5.508: 14, 22 and 30 are left, CRIT DMG's share 1.0 of 1.7.
    // No set here: CRIT Rate from 5 takes every joint roll (the pair's share 1.2 of 1.7) until the sheet is 1:2.
    about(5 + rateRolls(14 * share([1, 4], [1, 2, 4]), 5, 194.7) * 3.3065, $rows['critRate_']['tiers']['basic'], 'CRIT Rate basic after the requirement');
    about(194.7, $rows['critDMG_']['tiers']['basic'], 'CRIT DMG basic: nothing yet');
    about(5 + rateRolls(30 * share([1, 4], [1, 2, 4]), 5, 194.7) * 3.3065, $rows['critRate_']['tiers']['advanced'], 'CRIT Rate advanced after the requirement');

    $rows = byStat(TargetTiers::forBuild($build, ayaka(), ['mistsplitterreforged' => mistsplitter()], [], null, [], 2));
    same([130, null, 2], [$rows['enerRech_']['min'], $rows['enerRech_']['max'], $rows['enerRech_']['from_rank']], 'at C2 the C2 target holds');
    about(5 + rateRolls(16 * share([1, 4], [1, 2, 4]), 5, 194.7) * 3.3065, $rows['critRate_']['tiers']['basic'], 'the lower requirement takes no roll');
});

test('a requirement a tier cannot pay stands all the same; what is left goes to the others, nothing where nothing is left', function () {
    $build = build(['stat_targets' => [['stat' => 'enerRech_', 'min' => 270, 'max' => 300, 'text' => '', 'from_rank' => 0]]]);
    $rows = byStat(TargetTiers::forBuild($build, ayaka(), ['mistsplitterreforged' => mistsplitter()], [], null, [], 0));
    same(270, $rows['enerRech_']['min'], 'the requirement as stated');
    // From 100, 270 needs 31 rolls - more than the 25 five pieces hold: 25 paid, 16 and 24 give nothing; the 7 that
    // 32 leaves go to the other three (CRIT DMG's share 1.0 of 1.7).
    about(5, $rows['critRate_']['tiers']['basic'], 'CRIT Rate basic: the fixed sources alone');
    about(194.7, $rows['critDMG_']['tiers']['intermediate'], 'CRIT DMG intermediate: still nothing');
    about(5 + rateRolls(7 * share([1, 4], [1, 2, 4]), 5, 194.7) * 3.3065, $rows['critRate_']['tiers']['advanced'], 'advanced: the 7 rolls left, CRIT Rate takes them until 1:2');
});

test('CRIT Rate and CRIT DMG of one priority share one budget by the 1:2 rule, CRIT Rate never past the cap', function () {
    $homa = weapon('Staff of Homa', 5, 46, 563, ['stat' => 'critDMG_', 'value' => 14.4]);
    $build = build([
        'weapons' => [['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1]],
        'main_stats' => [
            'sands' => [['stat' => 'hp_', 'priority' => 1]],
            'goblet' => [['stat' => 'elemental_dmg_', 'priority' => 1]],
            'circlet' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1]],
        ],
        'substats' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1], ['stat' => 'eleMas', 'priority' => 2], ['stat' => 'hp_', 'priority' => 3]],
    ]);
    $rows = byStat(TargetTiers::forBuild($build, ayaka(), ['staffofhoma' => $homa], [], null, [], 0));
    same(['critRate_', 'critDMG_', 'eleMas', 'hp'], array_keys($rows), 'the pair first, as listed');
    // Fixed: CRIT Rate 5 + 31.1 (circlet) = 36.1, CRIT DMG 50 + 38.4 + 66.2 (Homa) = 154.6: twice the rate is 72.2,
    // far below the DMG, so the joint share (2.0 of 2.8 of 16 rolls) goes to CRIT Rate until the sheet is 1:2.
    about(36.1 + rateRolls(16 * share([1, 1], [1, 1, 2, 3]), 36.1, 154.6) * 3.3065, $rows['critRate_']['tiers']['basic'], 'basic: nearly every joint roll into CRIT Rate');
    about(
        154.6 + (16 * share([1, 1], [1, 1, 2, 3]) - rateRolls(16 * share([1, 1], [1, 1, 2, 3]), 36.1, 154.6)) * 6.6045,
        $rows['critDMG_']['tiers']['basic'],
        'basic: CRIT DMG all but the sliver the 1:2 rule leaves it',
        0.3,
    );
    // Advanced: 22.86 joint rolls would take CRIT Rate past the cap of 85; it stops there, the rest to CRIT DMG.
    about(TargetTiers::CRIT_RATE_CAP, $rows['critRate_']['tiers']['advanced'], 'advanced: CRIT Rate at the cap');
    $rateRolls = (TargetTiers::CRIT_RATE_CAP - 36.1) / 3.3065;
    about(154.6 + (32 * share([1, 1], [1, 1, 2, 3]) - $rateRolls) * 6.6045, $rows['critDMG_']['tiers']['advanced'], 'advanced: CRIT DMG the rolls past the cap', 0.3);
});

test('a stat takes no more rolls than the pieces that can roll it allow; the rest flows on', function () {
    $dreams = weapon('A Thousand Floating Dreams', 5, 44, 506, ['stat' => 'eleMas', 'value' => 57.6]);
    $build = build([
        'weapons' => [['key' => 'athousandfloatingdreams', 'name' => 'A Thousand Floating Dreams', 'rank' => 1]],
        'main_stats' => ['sands' => [['stat' => 'eleMas', 'priority' => 1]], 'goblet' => [['stat' => 'eleMas', 'priority' => 1]], 'circlet' => [['stat' => 'eleMas', 'priority' => 1]]],
        'substats' => [['stat' => 'eleMas', 'priority' => 1], ['stat' => 'enerRech_', 'priority' => 2]],
    ]);
    $rows = byStat(TargetTiers::forBuild($build, ayaka(), ['athousandfloatingdreams' => $dreams], [], null, [], 0));
    // Elemental Mastery can roll on the flower and the plume alone: 10 rolls at most, however large its share. The
    // main stat as MainStatValues rounds it: 187 a piece.
    $fixed = 264.6 + 3 * 187;
    about($fixed + 10 * 23.31 * 0.85, $rows['eleMas']['tiers']['advanced'], 'Elemental Mastery capped at ten rolls', 0.3);
    // Energy Recharge takes the rest only up to the main DPS ceiling of 140 (8 rolls); what is left over goes nowhere.
    about(100 + ceil((TargetTiers::ENERGY_RECHARGE_MOST['main_dps'] - 100) / 5.508) * 5.508, $rows['enerRech_']['tiers']['advanced'], 'Energy Recharge at the role\'s ceiling', 0.3);
});

test('the player\'s own weapon and main stats where the build lists them; a stated total of ATK rolls ATK%', function () {
    $haran = weapon('Haran Geppaku Futsu', 5, 46, 563, ['stat' => 'critRate_', 'value' => 7.2]);
    $build = build(['stat_targets' => [['stat' => 'atk', 'min' => 2000, 'max' => null, 'text' => '2000+ ATK', 'from_rank' => 0]]]);
    $result = TargetTiers::forBuild($build, ayaka(), ['mistsplitterreforged' => mistsplitter()], [], $haran, ['circlet' => 'atk_', 'sands' => 'hp_', 'goblet' => 'cryo_dmg_'], 0);
    same(
        ['weapon' => 'Haran Geppaku Futsu', 'sets' => '4pc Blizzard Strayer', 'main_stats' => ['sands' => 'atk_', 'goblet' => 'cryo_dmg_', 'circlet' => 'atk_']],
        $result['basis'],
        'Haran in hand; the ATK% circlet the build lists, the HP% sands it does not',
    );
    $rows = byStat($result);
    same([2000, null], [$rows['atk']['min'], $rows['atk']['tiers']], 'the stated total first, untiered');
    // The stated ATK is met by the sources alone ((342 + 608) * 1.932 + 311), so ATK% takes no roll - and none of
    // the split either; CRIT DMG is 50 + 38.4 without a CRIT DMG circlet, its share 1.0 of 1.5.
    check(!isset($rows['atk_']), 'ATK% out of the split');
    // CRIT Rate 5 + 33.1 (Haran at 90), CRIT DMG 50 + 38.4 without a CRIT DMG circlet; the pair's share 1.2 of 1.5 -
    // its rolls split so the sheet is 1:2.
    $joint = 16 * share([1, 4], [1, 3, 4]);
    $rate = rateRolls($joint, 38.1, 88.4);
    about(38.1 + $rate * 3.3065, $rows['critRate_']['tiers']['basic'], 'CRIT Rate basic by the 1:2 rule', 0.3);
    about(88.4 + ($joint - $rate) * 6.6045, $rows['critDMG_']['tiers']['basic'], 'CRIT DMG basic by the 1:2 rule', 0.3);
    about(2 * $rows['critRate_']['tiers']['basic'], $rows['critDMG_']['tiers']['basic'], 'twice the rate', 0.5);
});

test('no stats of the character, or of no weapon the build lists: no targets', function () {
    same(null, TargetTiers::forBuild(build(), ayaka(['stats' => null]), ['mistsplitterreforged' => mistsplitter()], [], null, [], 0), 'the character unknown');
    same(null, TargetTiers::forBuild(build(), ayaka(), [], [], null, [], 0), 'no weapon known');
    $bare = new GameWeapon(7, GAME, 'mistsplitterreforged', 'Mistsplitter Reforged', null, null, [], null, rarity: 5);
    same(null, TargetTiers::forBuild(build(), ayaka(), ['mistsplitterreforged' => $bare], [], $bare, [], 0), 'a weapon without stats, in hand or listed');
    $result = TargetTiers::forBuild(
        build(),
        ayaka(),
        ['mistsplitterreforged' => $bare, 'harangeppakufutsu' => weapon('Haran Geppaku Futsu', 5, 46, 563, ['stat' => 'critRate_', 'value' => 7.2])],
        [],
        null,
        [],
        0,
    );
    same('Haran Geppaku Futsu', $result['basis']['weapon'], 'the first listed weapon with stats');
});

test('the set\'s CRIT Rate in combat is in the pair\'s balance: without Blizzard Strayer the same build asks more CRIT Rate of the sheet', function () {
    $bare = byStat(TargetTiers::forBuild(build(), ayaka(), ['mistsplitterreforged' => mistsplitter()], [], null, [], 0));
    $blizzard = byStat(TargetTiers::forBuild(build(), ayaka(), ['mistsplitterreforged' => mistsplitter()], ['blizzardstrayer' => blizzard()], null, [], 0));
    // 19.2 joint rolls: with no set the cap is 85 on the sheet, far off, and the 1:2 rule asks (194.7 + 126.8 - 10)
    // / 13.2 = 23.5 rolls of CRIT Rate - all 19.2 go there; with Blizzard's 40 the sheet stops at 45.
    about(5 + rateRolls(32 * share([1, 4], [1, 2, 3, 4]), 5, 194.7) * 3.3065, $bare['critRate_']['tiers']['advanced'], 'no set: CRIT Rate takes every joint roll', 0.3);
    about(194.7, $bare['critDMG_']['tiers']['advanced'], 'no set: CRIT DMG the fixed sources alone');
    about(45, $blizzard['critRate_']['tiers']['advanced'], 'Blizzard: CRIT Rate stops at 85 less its 40');
    check($blizzard['critDMG_']['tiers']['advanced'] > 230, 'Blizzard: the rolls past the cap in CRIT DMG: ' . $blizzard['critDMG_']['tiers']['advanced']);
    // The 2-piece bonus is on the sheet either way it is given: the first option's.
    same(
        ['sands' => 'atk_', 'goblet' => 'cryo_dmg_', 'circlet' => 'critDMG_'],
        TargetTiers::forBuild(build(), ayaka(), ['mistsplitterreforged' => mistsplitter()], ['blizzardstrayer' => blizzard()], null, [], 0)['basis']['main_stats'],
        'the basis as before',
    );
});

test('the set option worn, where the build lists it, gives the bonuses: the second option\'s 2-piece pair, no 4-piece CRIT Rate', function () {
    $build = build([
        'sets' => [
            ['rank' => 1, 'kind' => 'artifact', 'choose' => null, 'pieces' => [['label' => 'Blizzard Strayer', 'count' => 4, 'keys' => ['blizzardstrayer'], 'names' => ['Blizzard Strayer']]]],
            [
                'rank' => 2,
                'kind' => 'artifact',
                'choose' => null,
                'pieces' => [
                    ['label' => 'Blizzard Strayer', 'count' => 2, 'keys' => ['blizzardstrayer'], 'names' => ['Blizzard Strayer']],
                    ['label' => 'ATK +18% set', 'count' => 2, 'keys' => ['gladiatorsfinale', 'shimenawasreminiscence'], 'names' => ["Gladiator's Finale", "Shimenawa's Reminiscence"]],
                ],
            ],
        ],
    ]);
    $sets = ['blizzardstrayer' => blizzard(), 'gladiatorsfinale' => set("Gladiator's Finale", 'ATK +18%.')];
    same(['blizzardstrayer', 'gladiatorsfinale', 'shimenawasreminiscence'], TargetTiers::setKeys($build), 'every option\'s sets are asked of the catalog');

    $first = TargetTiers::forBuild($build, ayaka(), ['mistsplitterreforged' => mistsplitter()], $sets, null, [], 0);
    $second = TargetTiers::forBuild($build, ayaka(), ['mistsplitterreforged' => mistsplitter()], $sets, null, [], 0, 1);
    $none = TargetTiers::forBuild($build, ayaka(), ['mistsplitterreforged' => mistsplitter()], $sets, null, [], 0, 7);
    same(
        ['4pc Blizzard Strayer', '2pc Blizzard Strayer + 2pc ATK +18% set', '4pc Blizzard Strayer'],
        [$first['basis']['sets'], $second['basis']['sets'], $none['basis']['sets']],
        'the option named; one the build has not: the first',
    );
    $firstRows = byStat($first);
    $secondRows = byStat($second);
    // The 2-piece ATK on the sheet: 18 % more of the base ATK (1016); and no 4-piece CRIT Rate in the balance, so
    // the sheet's CRIT Rate goes on past 45.
    about($firstRows['atk']['tiers']['basic'] + 1016 * 0.18, $secondRows['atk']['tiers']['basic'], 'ATK basic with the 2-piece', 3);
    about(45, $firstRows['critRate_']['tiers']['advanced'], 'with the 4-piece: CRIT Rate stops at 45');
    check($secondRows['critRate_']['tiers']['advanced'] > 60, 'without it: past 60 (' . $secondRows['critRate_']['tiers']['advanced'] . ')');
});

test('a build without substats is split by what its main stats ask for, as the scorer does', function () {
    $build = build(['substats' => []]);
    $rows = byStat(TargetTiers::forBuild($build, ayaka(), ['mistsplitterreforged' => mistsplitter()], [], null, [], 0));
    same(['critRate_', 'critDMG_', 'atk', 'enerRech_'], array_keys($rows), 'the crit pair, the main stats\' ATK%, Energy Recharge');
});

finishTests();
