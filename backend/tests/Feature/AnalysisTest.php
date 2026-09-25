<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountCharacter;
use App\Models\AccountItem;
use App\Models\AccountWeapon;
use App\Models\Build;
use App\Models\BuildSource;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Services\Analysis\CharacterAnalyzer;
use App\Support\Stats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class AnalysisTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalog;

    private Account $account;

    private AccountCharacter $huTao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGenshinCatalog();

        $source = BuildSource::query()->create(['key' => 'test.genshin', 'game' => 'genshin', 'name' => 'Test Source', 'base_url' => 'https://example.test']);
        $character = GameCharacter::query()->where('key', 'hutao')->firstOrFail();

        Build::query()->create([
            'build_source_id' => $source->id,
            'game_character_id' => $character->id,
            'variant' => 'DPS',
            'recommended' => true,
            'weapons' => [
                ['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1, 'refinement' => null, 'note' => null, 'known' => true],
                ['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'rank' => 2, 'refinement' => 5, 'note' => null, 'known' => true],
            ],
            'sets' => [
                ['rank' => 1, 'kind' => 'artifact', 'choose' => null, 'note' => null, 'pieces' => [['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']]]],
                ['rank' => 2, 'kind' => 'artifact', 'choose' => null, 'note' => null, 'pieces' => [['label' => "Shimenawa's Reminiscence", 'count' => 4, 'keys' => ['shimenawasreminiscence'], 'names' => ["Shimenawa's Reminiscence"]]]],
            ],
            'main_stats' => [
                'sands' => [['stat' => 'hp_', 'priority' => 1], ['stat' => 'eleMas', 'priority' => 2]],
                'goblet' => [['stat' => Stats::ELEMENTAL_DMG, 'priority' => 1]],
                'circlet' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1]],
            ],
            'substats' => [
                ['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1],
                ['stat' => 'eleMas', 'priority' => 2], ['stat' => 'hp_', 'priority' => 3],
            ],
            'stat_targets' => [['stat' => 'critRate_', 'min' => 70, 'max' => null, 'text' => 'CRIT Rate 70%']],
            'content_hash' => 'x',
        ]);

        $this->account = Account::query()->create(['game' => 'genshin', 'nickname' => 'Test', 'has_full_inventory' => true]);
        $this->huTao = AccountCharacter::query()->create([
            'account_id' => $this->account->id,
            'game_character_id' => $character->id,
            'level' => 90,
            'rank' => 0,
            'source' => 'good',
            'final_stats' => ['critRate_' => 62.0, 'critDMG_' => 210.0],
        ]);

        // Dragon's Bane equipped, Staff of Homa owned but unused.
        AccountWeapon::query()->create(['account_id' => $this->account->id, 'game_weapon_id' => GameWeapon::query()->where('key', 'dragonsbane')->value('id'), 'level' => 90, 'refinement' => 5, 'equipped_character_id' => $character->id, 'source' => 'good']);
        AccountWeapon::query()->create(['account_id' => $this->account->id, 'game_weapon_id' => GameWeapon::query()->where('key', 'staffofhoma')->value('id'), 'level' => 90, 'refinement' => 1, 'source' => 'good']);

        $cw = GameSet::query()->where('key', 'crimsonwitchofflames')->value('id');
        $emblem = GameSet::query()->where('key', 'emblemofseveredfate')->value('id');
        $crit = [['key' => 'critRate_', 'value' => 7.0, 'rolls' => 2], ['key' => 'critDMG_', 'value' => 14.0, 'rolls' => 2]];
        $weak = [['key' => 'def', 'value' => 40.0, 'rolls' => 2], ['key' => 'atk', 'value' => 30.0, 'rolls' => 2]];

        $this->item($cw, 'flower', 'hp', $crit, $character->id);
        $this->item($cw, 'plume', 'atk', $crit, $character->id);
        $this->item($cw, 'sands', 'hp_', $crit, $character->id);
        $this->item($emblem, 'goblet', 'atk_', $crit, $character->id);   // off-piece with the wrong main stat
        $this->item($cw, 'circlet', 'critRate_', $weak, $character->id); // weak substats

        // Better pieces in the inventory.
        $this->item($cw, 'circlet', 'critDMG_', [['key' => 'critRate_', 'value' => 14.0, 'rolls' => 4], ['key' => 'hp_', 'value' => 10.0, 'rolls' => 2]]);
        $this->item($emblem, 'goblet', 'pyro_dmg_', $crit);
    }

    private function item(int $setId, string $slot, string $main, array $substats, ?int $equippedOn = null): void
    {
        AccountItem::query()->create([
            'account_id' => $this->account->id, 'game_set_id' => $setId, 'slot' => $slot, 'rarity' => 5, 'level' => 20,
            'main_stat' => $main, 'main_value' => null, 'substats' => $substats, 'equipped_character_id' => $equippedOn, 'source' => 'good',
        ]);
    }

    public function test_character_analysis(): void
    {
        $result = app(CharacterAnalyzer::class)->detail($this->account, $this->huTao);
        $evaluation = $result['evaluations'][0];

        // 4pc Crimson Witch is complete even though the goblet is an off-piece.
        $this->assertSame(100, $evaluation['score']['parts']['sets']);
        $this->assertSame(1, $evaluation['sets']['groups'][0]['matched_rank']);

        // "Elemental DMG" resolves to Pyro for Hu Tao, so the ATK% goblet is wrong.
        $this->assertFalse($evaluation['main_stats']['slots']['goblet']['ok']);
        $this->assertSame(['pyro_dmg_'], $evaluation['main_stats']['slots']['goblet']['recommended']);
        $this->assertSame(67, $evaluation['main_stats']['score']);

        // Weapon #2 equipped, #1 owned.
        $this->assertSame(2, $evaluation['weapon']['rank']);
        $this->assertSame('staffofhoma', $evaluation['weapon']['owned_better'][0]['key']);

        // In-game CRIT Rate 62% vs. the 70% target.
        $this->assertFalse($evaluation['targets'][0]['ok']);

        $messages = array_column($evaluation['recommendations'], 'message');
        $this->assertSame('high', $evaluation['recommendations'][0]['severity']);
        $this->assertStringContainsString('Goblet of Eonothem has ATK%', $messages[0]);
        $this->assertNotEmpty(array_filter($messages, fn ($m) => str_contains($m, 'You own Staff of Homa')));

        // Suggestions: the Pyro goblet (fixes the main stat) and the better circlet (keeps 4pc Crimson Witch).
        $suggestions = collect($result['suggestions'])->keyBy('slot');
        $this->assertSame('fixes_main_stat', $suggestions['goblet']['reason']);
        $this->assertSame('pyro_dmg_', $suggestions['goblet']['item']['main_stat']);
        $this->assertSame('better_substats', $suggestions['circlet']['reason']);
        $this->assertArrayNotHasKey('flower', $suggestions->all());
    }

    public function test_set_switch_plan_when_no_recommended_set_is_worn(): void
    {
        $emblem = GameSet::query()->where('key', 'emblemofseveredfate')->value('id');
        $cw = GameSet::query()->where('key', 'crimsonwitchofflames')->value('id');

        $shimenawa = GameSet::query()->where('key', 'shimenawasreminiscence')->value('id');

        // Hu Tao now wears 4pc Emblem + a Shimenawa goblet, which no option of the build accepts.
        AccountItem::query()->where('equipped_character_id', $this->huTao->game_character_id)->update(['game_set_id' => $emblem]);
        AccountItem::query()->where('equipped_character_id', $this->huTao->game_character_id)->where('slot', 'goblet')->update(['game_set_id' => $shimenawa]);
        // Spare Crimson Witch pieces: a flower and a plume, plus the circlet from setUp().
        $crit = [['key' => 'critRate_', 'value' => 7.0, 'rolls' => 2]];
        $this->item($cw, 'flower', 'hp', $crit);
        $this->item($cw, 'plume', 'atk', $crit);
        // A great flower from another set would break the 4pc Emblem she wears now.
        $this->item($shimenawa, 'flower', 'hp', [['key' => 'critRate_', 'value' => 20.0, 'rolls' => 5], ['key' => 'critDMG_', 'value' => 14.0, 'rolls' => 2]]);

        $result = app(CharacterAnalyzer::class)->detail($this->account, $this->huTao);

        $this->assertCount(1, $result['set_plans']);
        $plan = $result['set_plans'][0];
        $this->assertSame('4pc Crimson Witch of Flames', $plan['set']);
        $this->assertSame(4, $plan['needed']);
        $this->assertSame(3, $plan['owned']);
        $this->assertSame(['flower', 'plume', 'circlet'], array_column($plan['pieces'], 'slot'));

        // A single piece cannot complete a 4pc switch, so none is offered as "completes the set",
        // and no single swap may break the bonus the character has now.
        $this->assertNotContains('completes_set', array_column($result['suggestions'], 'reason'));
        $this->assertNotContains('flower', array_column($result['suggestions'], 'slot'));
    }

    public function test_account_overview_and_api(): void
    {
        $overview = app(CharacterAnalyzer::class)->overview($this->account);
        $this->assertSame('Hu Tao', $overview[0]['name']);
        $this->assertSame('Test Source', $overview[0]['best']['source']);
        $this->assertGreaterThan(0, $overview[0]['best']['overall']);

        $this->getJson("/api/accounts/{$this->account->id}")
            ->assertOk()
            ->assertJsonPath('characters.0.key', 'hutao');

        $this->getJson("/api/accounts/{$this->account->id}/characters/hutao")
            ->assertOk()
            ->assertJsonPath('character.name', 'Hu Tao')
            ->assertJsonPath('builds.0.variant', 'DPS')
            ->assertJsonPath('builds.0.weapons.0.name', 'Staff of Homa');

        $this->getJson("/api/accounts/{$this->account->id}/characters/nobody")->assertNotFound();
    }
}
