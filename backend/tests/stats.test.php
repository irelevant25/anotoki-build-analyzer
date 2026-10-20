<?php

/**
 * Canonical stat keys: reading every way the sites write a stat, priority lists with their ties, talent
 * priorities, and the keys names become. No database.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Enums\Game;
use App\Support\Key;
use App\Support\Stats;
use App\Support\Talents;

$genshinLabels = [
    ['ATK%', ['atk_']],
    ['ATK', ['atk_']],
    ['Flat ATK', ['atk']],
    ['Energy Recharge', ['enerRech_']],
    ['ER%', ['enerRech_']],
    ['Elemental Mastery', ['eleMas']],
    ['EM', ['eleMas']],
    ['CRIT Rate', ['critRate_']],
    ['Crit DMG', ['critDMG_']],
    ['CRIT Rate/DMG', ['critRate_', 'critDMG_']],
    ['Crit', ['critRate_', 'critDMG_']],
    ['Pyro DMG Bonus', ['pyro_dmg_']],
    ['Hydro DMG%', ['hydro_dmg_']],
    ['Physical DMG Bonus', ['physical_dmg_']],
    ['Elemental DMG Bonus', [Stats::ELEMENTAL_DMG]],
    ['Healing Bonus', ['heal_']],
    ['ele mas', ['eleMas']],
];
foreach ($genshinLabels as [$label, $expected]) {
    test("parses the Genshin label \"{$label}\"", function () use ($label, $expected) {
        same($expected, Stats::parse(Game::Genshin, $label), $label);
    });
}

test('a priority list keeps ties together', function () {
    same(
        [
            ['stat' => 'critRate_', 'priority' => 1],
            ['stat' => 'critDMG_', 'priority' => 1],
            ['stat' => 'atk_', 'priority' => 2],
            ['stat' => 'enerRech_', 'priority' => 3],
            ['stat' => 'eleMas', 'priority' => 3],
        ],
        Stats::parsePriorityList(Game::Genshin, 'CRIT Rate = CRIT DMG > ATK% > Energy Recharge / EM'),
        'priorities',
    );
});

test('a priority list strips notes and reads a combined crit', function () {
    same(
        [['stat' => 'enerRech_', 'priority' => 1], ['stat' => 'eleMas', 'priority' => 2], ['stat' => 'critRate_', 'priority' => 3], ['stat' => 'critDMG_', 'priority' => 3]],
        Stats::parsePriorityList(Game::Genshin, 'Energy Recharge (Until 160%) > EM >= Crit Rate/DMG'),
        'priorities',
    );
});

test('an ordered list pairs adjacent crit stats', function () {
    same(
        [['stat' => 'enerRech_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 2], ['stat' => 'critRate_', 'priority' => 2], ['stat' => 'atk_', 'priority' => 3]],
        Stats::ordered(Game::Genshin, ['Energy Recharge', 'CRIT DMG', 'CRIT Rate', 'ATK%']),
        'priorities',
    );
});

test('the elemental placeholder becomes the character\'s element', function () {
    same('pyro_dmg_', Stats::resolveElemental(Stats::ELEMENTAL_DMG, 'pyro'), 'placeholder');
    same('atk_', Stats::resolveElemental('atk_', 'pyro'), 'other stats');
});

test('talent priorities', function () {
    same(
        [['talent' => 'burst', 'priority' => 1], ['talent' => 'skill', 'priority' => 1], ['talent' => 'normal', 'priority' => 2]],
        Talents::parsePriority(Game::Genshin, 'Burst = Skill > Normal Attack'),
        'ties and a lower group',
    );

    same([['talent' => 'skill', 'priority' => 1], ['talent' => 'burst', 'priority' => 2]], Talents::parsePriority(Game::Genshin, 'Skill >> Burst'), 'Genshin');
});

test('keys are normalized names', function () {
    same('kaedeharakazuha', Key::from('Kaedehara Kazuha'), 'spaces');
    same('kaedeharakazuha', Key::from('KaedeharaKazuha'), 'GOOD keys');
    same('wolfsgravestone', Key::from("Wolf's Gravestone"), 'apostrophes');
    same('thecatch', Key::from('"The Catch"'), 'quotes');
    same('danhengimbibitorlunae', Key::from('Dan Heng • Imbibitor Lunae'), 'bullets');
    same('topaznumby', Key::from('Topaz &amp; Numby'), 'entities');
    // Accents become their letters, as in the keys the first version stored.
    same('pokemon', Key::from('Pokémon'), 'accents');
});

finishTests();
