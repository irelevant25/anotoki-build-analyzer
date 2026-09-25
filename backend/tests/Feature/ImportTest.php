<?php

namespace Tests\Feature;

use App\Enums\Game;
use App\Models\Account;
use App\Models\AccountCharacter;
use App\Models\AccountItem;
use App\Models\AccountWeapon;
use App\Services\Import\AccountImporter;
use App\Services\Import\Enka\EnkaGenshinParser;
use App\Services\Import\Enka\EnkaHsrParser;
use App\Services\Import\Enka\EnkaStore;
use App\Services\Import\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGenshinCatalog();
        $this->seedHsrCatalog();

        // Never download Enka's store in tests.
        $this->app->instance(EnkaStore::class, new class extends EnkaStore
        {
            public function __construct() {}

            public function genshinAvatar(string $key): ?array
            {
                return ['SkillOrder' => [10051, 10052, 10053], 'ProudMap' => ['10052' => 532, '10053' => 539]];
            }
        });
    }

    private function goodFile(): string
    {
        return json_encode([
            'format' => 'GOOD',
            'version' => 3,
            'source' => 'Irminsul',
            'characters' => [
                ['key' => 'HuTao', 'level' => 90, 'constellation' => 1, 'ascension' => 6, 'talent' => ['auto' => 10, 'skill' => 9, 'burst' => 8]],
                ['key' => 'TravelerAnemo', 'level' => 80, 'constellation' => 6, 'ascension' => 6, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]],
            ],
            'weapons' => [
                ['key' => 'StaffOfHoma', 'level' => 90, 'ascension' => 6, 'refinement' => 1, 'location' => 'HuTao', 'lock' => true],
                ['key' => 'DragonsBane', 'level' => 90, 'ascension' => 6, 'refinement' => 5, 'location' => '', 'lock' => false],
            ],
            'artifacts' => [
                ['setKey' => 'CrimsonWitchOfFlames', 'slotKey' => 'flower', 'level' => 20, 'rarity' => 5, 'mainStatKey' => 'hp', 'location' => 'HuTao', 'lock' => true,
                    'substats' => [['key' => 'critRate_', 'value' => 10.5], ['key' => 'critDMG_', 'value' => 14.0], ['key' => 'atk', 'value' => 16], ['key' => '', 'value' => 0]]],
                ['setKey' => 'CrimsonWitchOfFlames', 'slotKey' => 'sands', 'level' => 16, 'rarity' => 5, 'mainStatKey' => 'hp_', 'location' => 'Traveler', 'lock' => false,
                    'substats' => [['key' => 'critDMG_', 'value' => 7.8]]],
                ['setKey' => 'SomeFutureSet', 'slotKey' => 'goblet', 'level' => 20, 'rarity' => 5, 'mainStatKey' => 'pyro_dmg_', 'location' => '', 'lock' => false, 'substats' => []],
            ],
        ]);
    }

    public function test_imports_a_good_file(): void
    {
        $run = app(ImportService::class)->fromFile($this->goodFile(), Game::Genshin, null, 'export.json');

        $account = Account::query()->findOrFail($run->account_id);
        $this->assertTrue($account->has_full_inventory);
        $this->assertSame('partial', $run->status); // the unknown set is reported
        $this->assertSame(['SomeFutureSet'], $run->summary['unmatched']['genshin:sets']);
        $this->assertSame(2, $run->summary['characters']);
        $this->assertSame(3, $run->summary['items']);

        $huTao = AccountCharacter::query()->whereHas('character', fn ($q) => $q->where('key', 'hutao'))->firstOrFail();
        $this->assertSame(1, $huTao->rank);
        $this->assertSame(['normal' => 10, 'skill' => 9, 'burst' => 8], $huTao->talents);

        $flower = AccountItem::query()->where('slot', 'flower')->firstOrFail();
        $this->assertSame($huTao->game_character_id, $flower->equipped_character_id);
        $this->assertSame(4780.0, $flower->main_value);
        $this->assertCount(3, $flower->substats); // the empty substat is dropped

        // Equipment on "Traveler" goes to the Traveler the file lists.
        $sands = AccountItem::query()->where('slot', 'sands')->with('equippedCharacter')->firstOrFail();
        $this->assertSame('traveleranemo', $sands->equippedCharacter->key);

        $this->assertSame(1, AccountWeapon::query()->whereNull('equipped_character_id')->count());
    }

    public function test_reimporting_a_full_inventory_replaces_items(): void
    {
        $service = app(ImportService::class);
        $first = $service->fromFile($this->goodFile(), Game::Genshin);
        $account = Account::query()->findOrFail($first->account_id);

        $service->fromFile($this->goodFile(), Game::Genshin, $account);

        $this->assertSame(3, AccountItem::query()->where('account_id', $account->id)->count());
        $this->assertSame(1, Account::query()->count());
    }

    public function test_imports_a_reliquary_archiver_file(): void
    {
        $file = json_encode([
            'source' => 'reliquary_archiver',
            'build' => '0.18.0',
            'version' => 4,
            'metadata' => ['uid' => 700000001, 'trailblazer' => 'Stelle'],
            'light_cones' => [
                ['id' => '23006', 'name' => 'Patience Is All You Need', 'level' => 80, 'ascension' => 6, 'superimposition' => 1, 'location' => '1005', 'lock' => true, '_uid' => 'lc1'],
            ],
            'relics' => [
                ['set_id' => '117', 'name' => 'Prisoner in Deep Confinement', 'slot' => 'Feet', 'rarity' => 5, 'level' => 15, 'mainstat' => 'SPD',
                    'substats' => [['key' => 'CRIT Rate_', 'value' => 3.24, 'count' => 1, 'step' => 2], ['key' => 'ATK', 'value' => 42.34, 'count' => 2, 'step' => 2]],
                    'location' => '1005', 'lock' => true, 'discard' => false, '_uid' => 'r1'],
                ['set_id' => '310', 'name' => 'Firmament Frontline: Glamoth', 'slot' => 'Link Rope', 'rarity' => 5, 'level' => 15, 'mainstat' => 'ATK',
                    'substats' => [], 'location' => '8006', 'lock' => false, 'discard' => false, '_uid' => 'r2'],
            ],
            'characters' => [
                ['id' => '1005', 'name' => 'Kafka', 'path' => 'Nihility', 'level' => 80, 'ascension' => 6, 'eidolon' => 1, 'skills' => ['basic' => 6, 'skill' => 10, 'ult' => 10, 'talent' => 10], 'traces' => []],
                ['id' => '8006', 'name' => 'Trailblazer', 'path' => 'Harmony', 'level' => 80, 'ascension' => 6, 'eidolon' => 6, 'skills' => ['basic' => 6, 'skill' => 10, 'ult' => 10, 'talent' => 10], 'traces' => []],
            ],
        ]);

        $run = app(ImportService::class)->fromFile($file);

        $this->assertSame('success', $run->status);
        $account = Account::query()->findOrFail($run->account_id);
        $this->assertSame(Game::Hsr, $account->game);
        $this->assertSame('700000001', $account->uid);

        $feet = AccountItem::query()->where('slot', 'feet')->firstOrFail();
        $this->assertSame('spd', $feet->main_stat);
        $this->assertEqualsWithDelta(25.032, $feet->main_value, 0.001);
        $this->assertSame([
            ['key' => 'critRate_', 'value' => 3.24, 'rolls' => 1],
            ['key' => 'atk', 'value' => 42.34, 'rolls' => 2],
        ], $feet->substats);

        // "ATK" on a rope is ATK%; location 8006 (Stelle, Harmony) maps to the Harmony Trailblazer.
        $rope = AccountItem::query()->where('slot', 'rope')->with('equippedCharacter')->firstOrFail();
        $this->assertSame('atk_', $rope->main_stat);
        $this->assertSame('trailblazerharmony', $rope->equippedCharacter->key);

        $kafka = AccountCharacter::query()->whereHas('character', fn ($q) => $q->where('key', 'kafka'))->firstOrFail();
        $this->assertSame(['basic' => 6, 'skill' => 10, 'ultimate' => 10, 'talent' => 10], $kafka->talents);
    }

    public function test_enka_import_can_complete_an_account_created_from_a_file(): void
    {
        $fromFile = app(ImportService::class)->fromFile($this->goodFile(), Game::Genshin);
        $account = Account::query()->findOrFail($fromFile->account_id);
        $this->assertNull($account->uid);

        Http::fake(['enka.network/*' => Http::response($this->enkaGenshinProfile())]);
        $this->postJson('/api/imports/enka', ['game' => 'genshin', 'uid' => '618285856', 'account_id' => $account->id])
            ->assertCreated()
            ->assertJsonPath('account_id', $account->id);

        $account->refresh();
        $this->assertSame('618285856', $account->uid);
        $this->assertSame('Tester', $account->nickname);
        $this->assertSame(1, Account::query()->count());
        // Enka data only replaced the showcased Traveler's gear; the rest of the inventory is untouched.
        $this->assertTrue($account->has_full_inventory);
        $this->assertSame(3, AccountItem::query()->where('account_id', $account->id)->count());
    }

    private function enkaGenshinProfile(): array
    {
        return [
            'uid' => '618285856',
            'playerInfo' => ['nickname' => 'Tester', 'level' => 57, 'worldLevel' => 8],
            'avatarInfoList' => [[
                'avatarId' => 10000005,
                'skillDepotId' => 504,
                'propMap' => ['4001' => ['val' => '90'], '1002' => ['val' => '6']],
                'talentIdList' => [1, 2, 3],
                'skillLevelMap' => ['10051' => 9, '10052' => 8, '10053' => 10],
                'proudSkillExtraLevelMap' => ['532' => 3],
                'fightPropMap' => ['2000' => 15000.4, '2001' => 2000.2, '20' => 0.652, '22' => 1.5, '23' => 1.805, '28' => 100],
                'equipList' => [
                    ['itemId' => 13501, 'weapon' => ['level' => 90, 'promoteLevel' => 6, 'affixMap' => ['113501' => 0]], 'flat' => ['itemType' => 'ITEM_WEAPON']],
                    ['itemId' => 81543, 'reliquary' => ['level' => 21, 'appendPropIdList' => [501204, 501224, 501223, 501201]],
                        'flat' => ['itemType' => 'ITEM_RELIQUARY', 'equipType' => 'EQUIP_BRACER', 'setId' => 15006, 'rankLevel' => 5,
                            'reliquaryMainstat' => ['mainPropId' => 'FIGHT_PROP_HP', 'statValue' => 4780],
                            'reliquarySubstats' => [['appendPropId' => 'FIGHT_PROP_CRITICAL', 'statValue' => 7.4], ['appendPropId' => 'FIGHT_PROP_CRITICAL_HURT', 'statValue' => 14.8]]]],
                ],
            ]],
        ];
    }

    public function test_parses_an_enka_genshin_profile(): void
    {
        $parsed = app(EnkaGenshinParser::class)->parse($this->enkaGenshinProfile());

        $this->assertSame('Tester', $parsed->nickname);
        $character = $parsed->characters[0];
        $this->assertSame(['key' => 'traveleranemo', 'name' => 'Traveler (Anemo)'], $character['ref']);
        $this->assertSame(3, $character['rank']);
        $this->assertSame(['normal' => 9, 'skill' => 8, 'burst' => 10, 'bonus' => ['skill' => 3]], $character['talents']);
        $this->assertSame(['hp' => 15000.0, 'atk' => 2000.0, 'eleMas' => 100.0, 'critRate_' => 65.2, 'critDMG_' => 150.0, 'enerRech_' => 180.5], $character['final_stats']);

        $this->assertSame(1, $parsed->weapons[0]['refinement']);
        $item = $parsed->items[0];
        $this->assertSame('flower', $item['slot']);
        $this->assertSame(20, $item['level']);
        $this->assertSame(['key' => 'critRate_', 'value' => 7.4, 'rolls' => 2], $item['substats'][0]);
        $this->assertSame(['key' => 'critDMG_', 'value' => 14.8, 'rolls' => 2], $item['substats'][1]);

        // And it lands on the right catalog rows.
        $run = app(AccountImporter::class)->import($parsed);
        $this->assertSame('success', $run->status);
        $this->assertSame('Crimson Witch of Flames', AccountItem::query()->firstOrFail()->set->name);
    }

    public function test_parses_an_enka_hsr_profile_and_deduplicates_support_characters(): void
    {
        $kafka = [
            'avatarId' => 1005, 'level' => 80, 'promotion' => 6, 'rank' => 1,
            'skillTreeList' => [['pointId' => 1005001, 'level' => 6], ['pointId' => 1005002, 'level' => 10], ['pointId' => 1005003, 'level' => 10], ['pointId' => 1005004, 'level' => 10], ['pointId' => 1005201, 'level' => 1]],
            'equipment' => ['tid' => 23006, 'level' => 80, 'promotion' => 6, 'rank' => 1],
            'relicList' => [[
                'tid' => 61174, 'type' => 4, 'level' => 15, 'mainAffixId' => 4,
                'subAffixList' => [['affixId' => 8, 'cnt' => 3, 'step' => 2], ['affixId' => 7, 'cnt' => 2]],
                '_flat' => ['setID' => 117, 'props' => [['type' => 'SpeedDelta', 'value' => 25.032], ['type' => 'CriticalChance', 'value' => 0.0972], ['type' => 'SpeedDelta', 'value' => 4.6]]],
            ]],
        ];
        $json = ['uid' => '700000002', 'detailInfo' => [
            'uid' => 700000002, 'nickname' => 'Pom', 'level' => 70, 'worldLevel' => 6,
            'avatarDetailList' => [$kafka + ['_assist' => true], $kafka],
        ]];

        $parsed = app(EnkaHsrParser::class)->parse($json);

        $this->assertCount(1, $parsed->characters);
        $this->assertSame(['basic' => 6, 'skill' => 10, 'ultimate' => 10, 'talent' => 10], $parsed->characters[0]['talents']);
        $relic = $parsed->items[0];
        $this->assertSame(5, $relic['rarity']);
        $this->assertSame('spd', $relic['main_stat']);
        $this->assertSame(['key' => 'critRate_', 'value' => 9.72, 'rolls' => 3], $relic['substats'][0]);
        $this->assertSame(['key' => 'spd', 'value' => 4.6, 'rolls' => 2], $relic['substats'][1]);
    }

    public function test_api_file_import_and_errors(): void
    {
        $this->postJson('/api/imports/file', ['content' => $this->goodFile(), 'file_name' => 'x.json'])
            ->assertCreated()
            ->assertJsonPath('summary.characters', 2)
            ->assertJsonPath('account.game', 'genshin');

        $this->postJson('/api/imports/file', ['content' => 'not json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The file is not valid JSON.');

        $this->postJson('/api/imports/file', ['content' => $this->goodFile(), 'game' => 'hsr'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This file contains Genshin Impact data, but you chose Honkai: Star Rail.');
    }

    public function test_api_enka_import_errors(): void
    {
        Http::fake(['enka.network/*' => Http::response('', 404)]);

        $this->postJson('/api/imports/enka', ['game' => 'genshin', 'uid' => '123'])->assertStatus(422);
        $this->postJson('/api/imports/enka', ['game' => 'genshin', 'uid' => '700000009'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No player with this UID exists (check the UID and the game).');
    }
}
