<?php

/**
 * Finding catalog rows by the many names the sites and exports use.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Enums\Game;
use App\Services\Catalog\Catalog;

bootTests();

$catalog = fn() => T::$app->make(Catalog::class);

test('resolves Genshin character variants', function () use ($catalog) {
    seedCharacter('genshin', 'Kaedehara Kazuha');
    seedCharacter('genshin', 'Raiden Shogun');
    seedCharacter('genshin', 'Traveler (Anemo)', ['key' => 'traveleranemo']);
    $catalog = $catalog();

    foreach (['Kazuha', 'KaedeharaKazuha', 'kaedehara-kazuha'] as $name) {
        same('kaedeharakazuha', $catalog->find(Game::Genshin, Catalog::CHARACTER, $name)?->key, $name);
    }
    same('raidenshogun', $catalog->find(Game::Genshin, Catalog::CHARACTER, 'Raiden')?->key, 'Raiden');
    foreach (['Anemo Traveler', 'Traveler (Anemo)', 'TravelerAnemo', 'Traveler Anemo'] as $name) {
        same('traveleranemo', $catalog->find(Game::Genshin, Catalog::CHARACTER, $name)?->key, $name);
    }
});

test('resolves the special Star Rail names', function () use ($catalog) {
    seedCharacter('hsr', 'Trailblazer (Harmony)', ['key' => 'trailblazerharmony']);
    seedCharacter('hsr', 'March 7th');
    seedCharacter('hsr', 'March 7th (The Hunt)', ['key' => 'march7thhunt']);
    seedCharacter('hsr', 'Dan Heng • Imbibitor Lunae');
    seedCharacter('hsr', 'Topaz & Numby');
    seedCharacter('hsr', 'Himeko');
    $catalog = $catalog();

    $cases = [
        'Trailblazer • Harmony' => 'trailblazerharmony',
        'Trailblazer (Imaginary)' => 'trailblazerharmony', // a build site names Trailblazers by element
        'March 7th • The Hunt' => 'march7thhunt',
        'March 7th' => 'march7th',
        'Imbibitor Lunae' => 'danhengimbibitorlunae',
        'Topaz and Numby' => 'topaznumby',
    ];
    foreach ($cases as $name => $key) {
        same($key, $catalog->find(Game::Hsr, Catalog::CHARACTER, $name)?->key, $name);
    }

    // A brand-new alternate version must never be mistaken for the base character.
    same(null, $catalog->find(Game::Hsr, Catalog::CHARACTER, 'Himeko • Nova'), 'Himeko • Nova');
});

test('resolves set abbreviations and prefixes', function () use ($catalog) {
    seedSet('genshin', 'Emblem of Severed Fate', 'artifact');
    seedSet('genshin', 'Viridescent Venerer', 'artifact');
    $catalog = $catalog();

    same('emblemofseveredfate', $catalog->find(Game::Genshin, Catalog::SET, 'EoSF')?->key, 'EoSF');
    same('emblemofseveredfate', $catalog->find(Game::Genshin, Catalog::SET, 'Emblem')?->key, 'Emblem');
    same('viridescentvenerer', $catalog->find(Game::Genshin, Catalog::SET, 'VV')?->key, 'VV');
});

test('unknown names are created and reported', function () use ($catalog) {
    $catalog = $catalog();
    $created = $catalog->character(Game::Genshin, 'Brand New Character');

    same('brandnewcharacter', $created->key, 'key');
    same(['genshin:characters' => ['Brand New Character']], $catalog->unmatched(), 'unmatched');
    same(1, (int) db()->value("SELECT count(*) FROM game_characters WHERE key = 'brandnewcharacter'"), 'stored');
});

finishTests();
