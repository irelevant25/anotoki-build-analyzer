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

test('a shorter catalog key never stands in for a longer name', function () use ($catalog) {
    seedCharacter('genshin', 'Furina');
    $catalog = $catalog();

    same('furina', $catalog->find(Game::Genshin, Catalog::CHARACTER, 'Furina')?->key, 'Furina');
    // A brand-new alternate version must never be mistaken for the base character.
    same(null, $catalog->find(Game::Genshin, Catalog::CHARACTER, 'Furina • Nova'), 'Furina • Nova');
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
