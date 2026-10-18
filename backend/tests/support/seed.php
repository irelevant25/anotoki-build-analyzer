<?php

/**
 * Small catalog and account fixtures, written straight into the test database - no request to any site.
 */

use App\Support\Clock;
use App\Support\Json;
use App\Support\Key;

/** @param array<string, mixed> $attributes */
function seedCharacter(string $game, string $name, array $attributes = []): int
{
    return seedRow('game_characters', $game, $name, $attributes);
}

/** @param array<string, mixed> $attributes */
function seedWeapon(string $game, string $name, array $attributes = []): int
{
    return seedRow('game_weapons', $game, $name, $attributes + ['rarity' => 5]);
}

/** @param array<string, mixed> $attributes */
function seedSet(string $game, string $name, string $kind, array $attributes = []): int
{
    return seedRow('game_sets', $game, $name, $attributes + ['kind' => $kind]);
}

/** @param array<string, mixed> $attributes */
function seedRow(string $table, string $game, string $name, array $attributes): int
{
    $now = Clock::now();
    if (array_key_exists('meta', $attributes)) {
        $attributes['meta'] = Json::column($attributes['meta']);
    }

    return (int) db()->insert(
        $table,
        ['game' => $game, 'key' => $attributes['key'] ?? Key::from($name), 'name' => $name] + array_diff_key($attributes, ['key' => true]) + ['created_at' => $now, 'updated_at' => $now],
    );
}

function seedGenshinCatalog(): void
{
    seedCharacter('genshin', 'Hu Tao', ['element' => 'pyro', 'rarity' => 5, 'weapon_type' => 'polearm', 'external_id' => '10000046']);
    seedCharacter('genshin', 'Kaedehara Kazuha', ['element' => 'anemo', 'rarity' => 5, 'external_id' => '10000047']);
    seedCharacter('genshin', 'Traveler (Anemo)', ['key' => 'traveleranemo', 'element' => 'anemo', 'external_id' => '10000005-anemo', 'meta' => ['ids' => ['10000005-anemo', '10000007-anemo']]]);
    seedWeapon('genshin', 'Staff of Homa', ['external_id' => '13501', 'weapon_type' => 'polearm']);
    seedWeapon('genshin', "Dragon's Bane", ['external_id' => '13401', 'rarity' => 4, 'weapon_type' => 'polearm']);
    seedSet('genshin', 'Crimson Witch of Flames', 'artifact', ['external_id' => '15006']);
    seedSet('genshin', "Shimenawa's Reminiscence", 'artifact', ['external_id' => '15019']);
    seedSet('genshin', 'Emblem of Severed Fate', 'artifact', ['external_id' => '15020']);
}

function seedHsrCatalog(): void
{
    seedCharacter('hsr', 'Kafka', ['element' => 'lightning', 'rarity' => 5, 'weapon_type' => 'nihility', 'external_id' => '1005']);
    seedCharacter('hsr', 'Trailblazer (Harmony)', ['key' => 'trailblazerharmony', 'element' => 'imaginary', 'external_id' => '8005', 'meta' => ['ids' => ['8005', '8006']]]);
    seedWeapon('hsr', 'Patience Is All You Need', ['external_id' => '23006', 'weapon_type' => 'nihility']);
    seedSet('hsr', 'Prisoner in Deep Confinement', 'relic', ['external_id' => '117']);
    seedSet('hsr', 'Firmament Frontline: Glamoth', 'planar', ['external_id' => '310']);
}

function seedUser(int $id, string $name): void
{
    $now = Clock::now();
    db()->insert('users', ['id' => $id, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
}

/** @param array<string, mixed> $attributes */
function seedAccount(int $userId, string $game, array $attributes = []): int
{
    $now = Clock::now();
    if (array_key_exists('meta', $attributes)) {
        $attributes['meta'] = Json::column($attributes['meta']);
    }

    return (int) db()->insert('accounts', ['user_id' => $userId, 'game' => $game] + $attributes + ['created_at' => $now, 'updated_at' => $now]);
}

function idOf(string $table, string $key): int
{
    return (int) db()->value("SELECT id FROM {$table} WHERE key = ?", [$key]);
}
