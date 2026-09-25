<?php

namespace Tests\Support;

use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Support\Key;

/**
 * Small catalog fixtures so tests never call yatta.moe.
 */
trait SeedsCatalog
{
    protected function character(string $game, string $name, array $attributes = []): GameCharacter
    {
        return GameCharacter::query()->create(['game' => $game, 'key' => $attributes['key'] ?? Key::from($name), 'name' => $name] + $attributes);
    }

    protected function weapon(string $game, string $name, array $attributes = []): GameWeapon
    {
        return GameWeapon::query()->create(['game' => $game, 'key' => Key::from($name), 'name' => $name, 'rarity' => 5] + $attributes);
    }

    protected function set(string $game, string $name, string $kind, array $attributes = []): GameSet
    {
        return GameSet::query()->create(['game' => $game, 'key' => Key::from($name), 'name' => $name, 'kind' => $kind] + $attributes);
    }

    protected function seedGenshinCatalog(): void
    {
        $this->character('genshin', 'Hu Tao', ['element' => 'pyro', 'rarity' => 5, 'weapon_type' => 'polearm', 'external_id' => '10000046']);
        $this->character('genshin', 'Kaedehara Kazuha', ['element' => 'anemo', 'rarity' => 5, 'external_id' => '10000047']);
        $this->character('genshin', 'Traveler (Anemo)', ['key' => 'traveleranemo', 'element' => 'anemo', 'external_id' => '10000005-anemo', 'meta' => ['ids' => ['10000005-anemo', '10000007-anemo']]]);
        $this->weapon('genshin', 'Staff of Homa', ['external_id' => '13501', 'weapon_type' => 'polearm']);
        $this->weapon('genshin', "Dragon's Bane", ['external_id' => '13401', 'rarity' => 4, 'weapon_type' => 'polearm']);
        $this->set('genshin', 'Crimson Witch of Flames', 'artifact', ['external_id' => '15006']);
        $this->set('genshin', "Shimenawa's Reminiscence", 'artifact', ['external_id' => '15019']);
        $this->set('genshin', 'Emblem of Severed Fate', 'artifact', ['external_id' => '15020']);
    }

    protected function seedHsrCatalog(): void
    {
        $this->character('hsr', 'Kafka', ['element' => 'lightning', 'rarity' => 5, 'weapon_type' => 'nihility', 'external_id' => '1005']);
        $this->character('hsr', 'Trailblazer (Harmony)', ['key' => 'trailblazerharmony', 'element' => 'imaginary', 'external_id' => '8005', 'meta' => ['ids' => ['8005', '8006']]]);
        $this->weapon('hsr', 'Patience Is All You Need', ['external_id' => '23006', 'weapon_type' => 'nihility']);
        $this->set('hsr', 'Prisoner in Deep Confinement', 'relic', ['external_id' => '117']);
        $this->set('hsr', 'Firmament Frontline: Glamoth', 'planar', ['external_id' => '310']);
    }
}
