<?php

/**
 * The analysis of a character against a build: part scores, recommendations, better pieces from the
 * inventory, set plans, and what the account page and the character page get.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Repositories\Accounts;
use App\Services\Analysis\CharacterAnalyzer;
use App\Support\Json;
use App\Support\Stats;
use Tests\Support\Client;
use Tests\Support\FakeBuildSource;

bootTests();

$state = new stdClass;

beforeEach(function () use ($state) {
    seedGenshinCatalog();
    T::$app->config->set('sources', [FakeBuildSource::class]);

    $source = (int) db()->insert('build_sources', ['key' => 'fake.genshin', 'game' => 'genshin', 'name' => 'Fake Builds', 'base_url' => 'https://fake-builds.example']);
    $character = idOf('game_characters', 'hutao');

    db()->insert('builds', [
        'build_source_id' => $source,
        'game_character_id' => $character,
        'variant' => 'DPS',
        'recommended' => true,
        'weapons' => Json::encode([
            ['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1, 'refinement' => null, 'note' => null, 'known' => true],
            ['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'rank' => 2, 'refinement' => 5, 'note' => null, 'known' => true],
        ]),
        'sets' => Json::encode([
            ['rank' => 1, 'kind' => 'artifact', 'choose' => null, 'note' => null, 'pieces' => [['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']]]],
            ['rank' => 2, 'kind' => 'artifact', 'choose' => null, 'note' => null, 'pieces' => [['label' => "Shimenawa's Reminiscence", 'count' => 4, 'keys' => ['shimenawasreminiscence'], 'names' => ["Shimenawa's Reminiscence"]]]],
        ]),
        'main_stats' => Json::encode([
            'sands' => [['stat' => 'hp_', 'priority' => 1], ['stat' => 'eleMas', 'priority' => 2]],
            'goblet' => [['stat' => Stats::ELEMENTAL_DMG, 'priority' => 1]],
            'circlet' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1]],
        ]),
        'substats' => Json::encode([
            ['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1],
            ['stat' => 'eleMas', 'priority' => 2], ['stat' => 'hp_', 'priority' => 3],
        ]),
        'stat_targets' => Json::encode([['stat' => 'critRate_', 'min' => 70, 'max' => null, 'text' => 'CRIT Rate 70%']]),
        'notes' => 'Prydwen has no curated build for this character yet.',
        'source_url' => 'https://fake-builds.example/hu-tao',
        'content_hash' => 'x',
    ]);

    seedUser(1, 'owner');
    $state->account = seedAccount(1, 'genshin', ['nickname' => 'Test', 'has_full_inventory' => true]);
    $state->character = $character;
    db()->insert('account_characters', [
        'account_id' => $state->account, 'game_character_id' => $character, 'level' => 90, 'rank' => 0, 'source' => 'good',
        'final_stats' => Json::encode(['critRate_' => 62.0, 'critDMG_' => 210.0]),
    ]);

    // Dragon's Bane equipped, Staff of Homa owned but unused.
    db()->insert('account_weapons', ['account_id' => $state->account, 'game_weapon_id' => idOf('game_weapons', 'dragonsbane'), 'level' => 90, 'refinement' => 5, 'equipped_character_id' => $character, 'source' => 'good']);
    db()->insert('account_weapons', ['account_id' => $state->account, 'game_weapon_id' => idOf('game_weapons', 'staffofhoma'), 'level' => 90, 'refinement' => 1, 'source' => 'good']);

    $cw = idOf('game_sets', 'crimsonwitchofflames');
    $emblem = idOf('game_sets', 'emblemofseveredfate');
    $crit = [['key' => 'critRate_', 'value' => 7.0, 'rolls' => 2], ['key' => 'critDMG_', 'value' => 14.0, 'rolls' => 2]];
    $weak = [['key' => 'def', 'value' => 40.0, 'rolls' => 2], ['key' => 'atk', 'value' => 30.0, 'rolls' => 2]];

    analysisItem($state->account, $cw, 'flower', 'hp', $crit, $character);
    analysisItem($state->account, $cw, 'plume', 'atk', $crit, $character);
    analysisItem($state->account, $cw, 'sands', 'hp_', $crit, $character);
    analysisItem($state->account, $emblem, 'goblet', 'atk_', $crit, $character);   // an off-piece with the wrong main stat
    analysisItem($state->account, $cw, 'circlet', 'critRate_', $weak, $character); // weak substats

    // Better pieces in the inventory.
    analysisItem($state->account, $cw, 'circlet', 'critDMG_', [['key' => 'critRate_', 'value' => 14.0, 'rolls' => 4], ['key' => 'hp_', 'value' => 10.0, 'rolls' => 2]]);
    analysisItem($state->account, $emblem, 'goblet', 'pyro_dmg_', $crit);
});

function analysisItem(int $account, int $set, string $slot, string $main, array $substats, ?int $equippedOn = null): void
{
    db()->insert('account_items', [
        'account_id' => $account, 'game_set_id' => $set, 'slot' => $slot, 'rarity' => 5, 'level' => 20,
        'main_stat' => $main, 'main_value' => null, 'substats' => Json::encode($substats), 'equipped_character_id' => $equippedOn, 'source' => 'good',
    ]);
}

$detail = function () use ($state): array {
    $accounts = T::$app->get(Accounts::class);

    return T::$app->make(CharacterAnalyzer::class)->detail($accounts->findOwned(1, $state->account), $accounts->character($state->account, 'hutao'));
};

test('a character analysis', function () use ($detail) {
    $result = $detail();
    $evaluation = $result['evaluations'][0];

    // 4pc Crimson Witch is complete even though the goblet is an off-piece.
    same(100, $evaluation['score']['parts']['sets'], 'sets part');
    same(1, $evaluation['sets']['groups'][0]['matched_rank'], 'matched option');

    // "Elemental DMG" resolves to Pyro for Hu Tao, so the ATK% goblet is wrong.
    same(false, $evaluation['main_stats']['slots']['goblet']['ok'], 'goblet');
    same(['pyro_dmg_'], $evaluation['main_stats']['slots']['goblet']['recommended'], 'goblet recommendation');
    same(67, $evaluation['main_stats']['score'], 'main stats part');

    // Weapon #2 equipped, #1 owned.
    same(2, $evaluation['weapon']['rank'], 'weapon rank');
    same('staffofhoma', $evaluation['weapon']['owned_better'][0]['key'], 'owned better');

    // In-game CRIT Rate 62% vs. the 70% target.
    same(false, $evaluation['targets'][0]['ok'], 'target');

    $messages = array_column($evaluation['recommendations'], 'message');
    same('high', $evaluation['recommendations'][0]['severity'], 'first recommendation');
    check(str_contains($messages[0], 'Goblet of Eonothem has ATK%'), $messages[0]);
    check(array_filter($messages, fn ($m) => str_contains($m, 'You own Staff of Homa')) !== [], 'names the better weapon owned');

    // Suggestions: the Pyro goblet (fixes the main stat) and the better circlet (keeps 4pc Crimson Witch).
    $suggestions = array_column($result['suggestions'], null, 'slot');
    same('fixes_main_stat', $suggestions['goblet']['reason'], 'goblet');
    same('pyro_dmg_', $suggestions['goblet']['item']['main_stat'], 'the Pyro goblet');
    same('better_substats', $suggestions['circlet']['reason'], 'circlet');
    check(! isset($suggestions['flower']), 'nothing for the flower');
});

test('a set plan when no recommended set is worn', function () use ($detail, $state) {
    $emblem = idOf('game_sets', 'emblemofseveredfate');
    $cw = idOf('game_sets', 'crimsonwitchofflames');
    $shimenawa = idOf('game_sets', 'shimenawasreminiscence');

    // Hu Tao now wears 4pc Emblem + a Shimenawa goblet, which no option of the build accepts.
    db()->run('UPDATE account_items SET game_set_id = ? WHERE equipped_character_id = ?', [$emblem, $state->character]);
    db()->run("UPDATE account_items SET game_set_id = ? WHERE equipped_character_id = ? AND slot = 'goblet'", [$shimenawa, $state->character]);
    // Spare Crimson Witch pieces: a flower and a plume, plus the circlet from before.
    $crit = [['key' => 'critRate_', 'value' => 7.0, 'rolls' => 2]];
    analysisItem($state->account, $cw, 'flower', 'hp', $crit);
    analysisItem($state->account, $cw, 'plume', 'atk', $crit);
    // A great flower from another set would break the 4pc Emblem she wears now.
    analysisItem($state->account, $shimenawa, 'flower', 'hp', [['key' => 'critRate_', 'value' => 20.0, 'rolls' => 5], ['key' => 'critDMG_', 'value' => 14.0, 'rolls' => 2]]);

    $result = $detail();

    same(1, count($result['set_plans']), 'one plan');
    $plan = $result['set_plans'][0];
    same('4pc Crimson Witch of Flames', $plan['set'], 'set');
    same(4, $plan['needed'], 'needed');
    same(3, $plan['owned'], 'owned');
    same(['flower', 'plume', 'circlet'], array_column($plan['pieces'], 'slot'), 'slots');

    // A single piece cannot complete a 4pc switch, so none is offered as "completes the set", and no
    // single swap may break the bonus the character has now.
    check(! in_array('completes_set', array_column($result['suggestions'], 'reason'), true), 'no completes_set');
    check(! in_array('flower', array_column($result['suggestions'], 'slot'), true), 'no flower');
});

test('the account overview and the character API, with the source never named', function () use ($state) {
    $accounts = T::$app->get(Accounts::class);
    $overview = T::$app->make(CharacterAnalyzer::class)->overview($accounts->findOwned(1, $state->account));
    same('Hu Tao', $overview[0]['name'], 'name');
    same('Source 1', $overview[0]['best']['source'], 'the source is "Source 1"');
    check($overview[0]['best']['overall'] > 0, 'a score');

    $client = (new Client)->asIam(1);
    $client->get("/api/accounts/{$state->account}")->status(200)->path('characters.0.key', 'hutao');

    $answer = $client->get("/api/accounts/{$state->account}/characters/hutao")->status(200)
        ->path('character.name', 'Hu Tao')
        ->path('builds.0.variant', 'DPS')
        ->path('builds.0.weapons.0.name', 'Staff of Homa')
        ->path('builds.0.source.name', 'Source 1')
        ->path('builds.0.source.key', 'genshin-1')
        ->path('evaluations.0.build.source.name', 'Source 1')
        ->path('consensus.sources', ['Source 1'])
        // A note copied from a page before the sites were named neutrally is shown without the name.
        ->path('builds.0.notes', 'The source has no curated build for this character yet.');
    check(! str_contains($answer->body, 'fake-builds.example'), 'the page the build came from is not sent');
    check(! str_contains($answer->body, 'Fake Builds'), 'the site\'s name is not sent');

    $client->get("/api/accounts/{$state->account}/characters/nobody")->status(404);
});

finishTests();
