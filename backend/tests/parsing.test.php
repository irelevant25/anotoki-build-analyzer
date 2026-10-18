<?php

/**
 * The three ways build sites embed their data (Astro island props, the Next.js RSC payload, JSON.parse()
 * literals in a JS bundle), and stat goals read out of prose. No database.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Enums\Game;
use App\Services\Builds\Parsing\AstroIslands;
use App\Services\Builds\Parsing\NextFlight;
use App\Services\Builds\Parsing\StatTargets;
use App\Services\Builds\Sources\GenshinGgGenshin;
use App\Services\Builds\Sources\GenshinGgSource;

test('astro island props are revived', function () {
    $props = ['builds' => [1, [[0, ['name' => [0, 'DPS'], 'weapons' => [1, [[0, ['id' => [0, 'staff_of_homa'], 'r' => [0, 5]]]]]]]]], 'lang' => [0, 'en']];
    $attribute = htmlspecialchars(json_encode($props), ENT_QUOTES);
    $html =
        '<html><body><astro-island component-url="/_astro/Other.js" props="{}"></astro-island>' .
        '<astro-island component-url="/_astro/CharacterBuildTabs.C1.js" props="' .
        $attribute .
        '"></astro-island></body></html>';

    $found = AstroIslands::find($html, 'CharacterBuildTabs');

    same('en', $found['lang'], 'plain value');
    same('DPS', $found['builds'][0]['name'], 'nested object');
    same(['id' => 'staff_of_homa', 'r' => 5], $found['builds'][0]['weapons'][0], 'array of objects');
});

test('next flight rows, text rows included', function () {
    // A text row ("2:T5,hello") is followed directly by the next row without a newline.
    $payload = '0:["$","$L1",null,{"x":1}]' . "\n" . '2:T5,hello' . '3:["$","$L4",null,{"statBuilds":[{"name":"Kafka DoT"}],"lastUpdate":{"build":"3.5"}}]' . "\n";
    $html = '<html><body>';
    foreach (str_split($payload, 20) as $chunk) {
        // the payload is split over several push() calls
        $html .= '<script>self.__next_f.push(' . json_encode([1, $chunk]) . ')</script>';
    }
    $html .= '</body></html>';

    $rows = NextFlight::rows(NextFlight::payload($html));
    same('T:hello', $rows['2'], 'text row');

    $props = NextFlight::findProps($html, 'statBuilds');
    same('Kafka DoT', $props['statBuilds'][0]['name'], 'props');
    same('3.5', $props['lastUpdate']['build'], 'sibling props');
});

test('bundle entries with line breaks are separate options', function () {
    $source = (new ReflectionClass(GenshinGgGenshin::class))->newInstanceWithoutConstructor();
    $lines = (new ReflectionMethod(GenshinGgSource::class, 'lines'))->invoke($source, ["Tenacity of the Millelith\nNoblesse Oblige", 'Crimson Witch of Flames + Gilded Dreams', '  ']);

    same(['Tenacity of the Millelith', 'Noblesse Oblige', 'Crimson Witch of Flames + Gilded Dreams'], $lines, 'options');
});

test('JSON literals in a JS bundle are decoded', function () {
    $js = <<<'JS'
    var a=1;var chars=JSON.parse('[{"name":"Hu Tao","type":"Pyro","weapon":"Polearm","build":{"weapons":["Staff of Homa","The Catch R5"]}}]');
    var w=JSON.parse('[{"name":"Wolf\'s Gravestone","note":"a \\"quoted\\" word\\nnext"}]');
    JS;

    $arrays = GenshinGgSource::decodeBundle($js);

    same(2, count($arrays), 'literals');
    same('Hu Tao', $arrays[0][0]['name'], 'first');
    same("Wolf's Gravestone", $arrays[1][0]['name'], 'escaped quote');
    same("a \"quoted\" word\nnext", $arrays[1][0]['note'], 'escaped double quotes and newline');
});

test('stat goals are read from Genshin notes', function () {
    $targets = StatTargets::extract(Game::Genshin, 'Goal: 2,000+ <stat>ATK</stat>, 125-145% <stat>Energy Recharge</stat>, 180%+ <stat>CRIT DMG</stat>, 150-200 Elemental Mastery.');

    $byStat = array_column($targets, null, 'stat');
    same(2000.0, $byStat['atk']['min'], 'flat ATK');
    same(125.0, $byStat['enerRech_']['min'], 'ER min');
    same(145.0, $byStat['enerRech_']['max'], 'ER max');
    same(180.0, $byStat['critDMG_']['min'], 'CRIT DMG');
    same(150.0, $byStat['eleMas']['min'], 'EM min');
    same(200.0, $byStat['eleMas']['max'], 'EM max');
});

test('stat goals are read from an endgame list', function () {
    $html = '<ul><li><p>HP: <b>3000+</b></p></li><li><p>ATK: <b>2500 - 3000+ </b></p></li>' . '<li><p>EHR: <b>75%</b> (More can be fine)</p></li><li><p>SPD: <b>160+</b></p></li></ul>';
    $byStat = array_column(StatTargets::extract(Game::Hsr, $html), null, 'stat');

    same(3000.0, $byStat['hp']['min'], 'HP');
    same(2500.0, $byStat['atk']['min'], 'ATK min');
    same(3000.0, $byStat['atk']['max'], 'ATK max');
    same(75.0, $byStat['effectHitRate_']['min'], 'EHR');
    same(160.0, $byStat['spd']['min'], 'SPD');
});

test('effect descriptions are not goals', function () {
    same([], StatTargets::extract(Game::Genshin, '4pc grants the party 20% ATK after Burst and 35% Charged Attack DMG.'), 'targets');
});

finishTests();
