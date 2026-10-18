<?php

/**
 * How pieces are scored against a build's substat priorities, and how equipped sets are matched against its
 * set options. No database.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Enums\Game;
use App\Services\Analysis\ItemScorer;
use App\Services\Analysis\SetMatcher;

$scorer = fn() => new ItemScorer(Game::Genshin, [
    ['stat' => 'critRate_', 'priority' => 1],
    ['stat' => 'critDMG_', 'priority' => 1],
    ['stat' => 'atk_', 'priority' => 2],
    ['stat' => 'enerRech_', 'priority' => 3],
]);

test('flat stats get a fraction of their percentage\'s weight', function () use ($scorer) {
    $weights = $scorer()->weights();

    same(1.0, $weights['critRate_'], 'priority 1');
    same(0.8, $weights['atk_'], 'priority 2');
    near(0.28, $weights['atk'], 0.001, 'flat ATK');
    check(!array_key_exists('hp', $weights), 'flat HP has no weight when HP% has none');
});

test('a perfect piece scores close to ideal', function () use ($scorer) {
    // All five upgrades into CRIT DMG, max rolls everywhere.
    $result = $scorer()->score('atk_', 5, [
        ['key' => 'critDMG_', 'value' => 7.77 * 6],
        ['key' => 'critRate_', 'value' => 3.89],
        ['key' => 'enerRech_', 'value' => 6.48],
        ['key' => 'eleMas', 'value' => 23.31],
    ]);

    check($result['percent'] >= 90, "percent {$result['percent']}");
    same('S', $result['grade'], 'grade');
});

test('useless substats score low', function () use ($scorer) {
    $result = $scorer()->score('atk_', 5, [['key' => 'hp', 'value' => 896], ['key' => 'def', 'value' => 58], ['key' => 'def_', 'value' => 21.9], ['key' => 'eleMas', 'value' => 40]]);

    same(0, $result['percent'], 'percent');
    same('D', $result['grade'], 'grade');
});

test('substats get their weight and an estimate of their rolls', function () use ($scorer) {
    $result = $scorer()->score('hp', 5, [['key' => 'critDMG_', 'value' => 21.0]]);

    same(1.0, $result['substats'][0]['weight'], 'weight');
    same(3, $result['substats'][0]['rolls'], 'rolls');
});

$piece = fn(array $keys, int $count) => ['keys' => $keys, 'count' => $count];

test('a four-piece option', function () use ($piece) {
    $option = ['pieces' => [$piece(['emblem'], 4)]];

    check(SetMatcher::evaluate(['emblem' => 4, 'glad' => 1], $option)['satisfied'], '4 pieces satisfy it');
    $partial = SetMatcher::evaluate(['emblem' => 2, 'glad' => 3], $option);
    check(!$partial['satisfied'], '2 pieces do not');
    check($partial['fraction'] > 0 && $partial['fraction'] < 1, 'but earn part of it');
});

test('a two-plus-two option', function () use ($piece) {
    $option = ['pieces' => [$piece(['glad'], 2), $piece(['shimenawa'], 2)]];

    check(SetMatcher::evaluate(['glad' => 2, 'shimenawa' => 2, 'x' => 1], $option)['satisfied'], '2 + 2');
    check(!SetMatcher::evaluate(['glad' => 4, 'x' => 1], $option)['satisfied'], '4 of one set is not 2 + 2');
});

test('the pieces of a group need distinct sets', function () use ($piece) {
    // "2pc EM set + 2pc EM set": a single 4pc Gilded Dreams gives the +80 EM bonus only once.
    $em = ['gilded', 'wanderer', 'instructor'];
    $option = ['pieces' => [$piece($em, 2), $piece($em, 2)]];

    check(!SetMatcher::evaluate(['gilded' => 4, 'x' => 1], $option)['satisfied'], 'one set twice');
    check(SetMatcher::evaluate(['gilded' => 2, 'wanderer' => 2, 'x' => 1], $option)['satisfied'], 'two sets');
});

test('choose two of many', function () use ($piece) {
    $option = [
        'choose' => 2,
        'pieces' => [$piece(['noblesse'], 2), $piece(['fury'], 2), $piece(['emblem'], 2)],
    ];

    check(SetMatcher::evaluate(['fury' => 2, 'emblem' => 3], $option)['satisfied'], 'two of three');
    check(!SetMatcher::evaluate(['fury' => 2, 'other' => 3], $option)['satisfied'], 'one of three');
});

test('many pieces listing many sets are matched at once, with the same result', function () use ($piece) {
    // Five sets worn one piece each, and pieces that list all five ten times over and ask for one piece:
    // tried entry by entry this took seconds for five pieces, and for thirty would never end.
    $sets = ['a', 'b', 'c', 'd', 'e'];
    $keys = array_merge(...array_fill(0, 10, $sets));
    $equipped = array_fill_keys($sets, 1);

    $started = microtime(true);
    $some = SetMatcher::evaluate($equipped, ['choose' => 5, 'pieces' => array_fill(0, 30, $piece($keys, 1))]);
    $all = SetMatcher::evaluate($equipped, ['pieces' => array_fill(0, 30, $piece($keys, 1))]);
    $took = microtime(true) - $started;

    check($took < 0.5, "took {$took} s");
    same([true, 5, 5], [$some['satisfied'], $some['matched'], $some['required']], 'five of thirty, five distinct sets');
    same([false, 5, 30], [$all['satisfied'], $all['matched'], $all['required']], 'all thirty: five sets satisfy five pieces at most');
});

finishTests();
