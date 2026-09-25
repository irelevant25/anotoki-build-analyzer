<?php

namespace Tests\Feature;

use App\Enums\Game;
use App\Services\Catalog\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalog;

    public function test_resolves_genshin_character_variants(): void
    {
        $this->character('genshin', 'Kaedehara Kazuha');
        $this->character('genshin', 'Raiden Shogun');
        $this->character('genshin', 'Traveler (Anemo)', ['key' => 'traveleranemo']);
        $catalog = app(Catalog::class);

        foreach (['Kazuha', 'KaedeharaKazuha', 'kaedehara-kazuha'] as $name) {
            $this->assertSame('kaedeharakazuha', $catalog->find(Game::Genshin, Catalog::CHARACTER, $name)?->key, $name);
        }
        $this->assertSame('raidenshogun', $catalog->find(Game::Genshin, Catalog::CHARACTER, 'Raiden')?->key);
        foreach (['Anemo Traveler', 'Traveler (Anemo)', 'TravelerAnemo', 'Traveler Anemo'] as $name) {
            $this->assertSame('traveleranemo', $catalog->find(Game::Genshin, Catalog::CHARACTER, $name)?->key, $name);
        }
    }

    public function test_resolves_hsr_special_names(): void
    {
        $this->character('hsr', 'Trailblazer (Harmony)', ['key' => 'trailblazerharmony']);
        $this->character('hsr', 'March 7th');
        $this->character('hsr', 'March 7th (The Hunt)', ['key' => 'march7thhunt']);
        $this->character('hsr', 'Dan Heng • Imbibitor Lunae');
        $this->character('hsr', 'Topaz & Numby');
        $this->character('hsr', 'Himeko');
        $catalog = app(Catalog::class);

        $cases = [
            'Trailblazer • Harmony' => 'trailblazerharmony',
            'Trailblazer (Imaginary)' => 'trailblazerharmony', // genshin.gg names Trailblazers by element
            'March 7th • The Hunt' => 'march7thhunt',
            'March 7th' => 'march7th',
            'Imbibitor Lunae' => 'danhengimbibitorlunae',
            'Topaz and Numby' => 'topaznumby',
        ];
        foreach ($cases as $name => $key) {
            $this->assertSame($key, $catalog->find(Game::Hsr, Catalog::CHARACTER, $name)?->key, $name);
        }

        // A brand-new alternate version must never be mistaken for the base character.
        $this->assertNull($catalog->find(Game::Hsr, Catalog::CHARACTER, 'Himeko • Nova'));
    }

    public function test_resolves_set_abbreviations_and_prefixes(): void
    {
        $this->set('genshin', 'Emblem of Severed Fate', 'artifact');
        $this->set('genshin', 'Viridescent Venerer', 'artifact');
        $catalog = app(Catalog::class);

        $this->assertSame('emblemofseveredfate', $catalog->find(Game::Genshin, Catalog::SET, 'EoSF')?->key);
        $this->assertSame('emblemofseveredfate', $catalog->find(Game::Genshin, Catalog::SET, 'Emblem')?->key);
        $this->assertSame('viridescentvenerer', $catalog->find(Game::Genshin, Catalog::SET, 'VV')?->key);
    }

    public function test_unknown_names_are_created_and_reported(): void
    {
        $catalog = app(Catalog::class);
        $created = $catalog->character(Game::Genshin, 'Brand New Character');

        $this->assertSame('brandnewcharacter', $created->key);
        $this->assertSame(['genshin:characters' => ['Brand New Character']], $catalog->unmatched());
    }
}
