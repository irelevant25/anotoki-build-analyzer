<?php

/**
 * The analysis of a character against a build: part scores, recommendations - the English sentence, and the
 * kind with its parameters that a page words in its own language -, better pieces from the inventory, set
 * plans, which build is the best by the character's constellation, the stat targets of their constellation,
 * and what the account page and the character page get.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Repositories\Accounts;
use App\Services\Analysis\BuildEvaluator;
use App\Services\Analysis\CharacterAnalyzer;
use App\Support\Clock;
use App\Support\Json;
use App\Support\Stats;
use Tests\Support\Client;

bootTests();

$state = new stdClass();
// The kinds of recommendation the cases of this file have produced, for the last test.
$state->seen = [];

beforeEach(function () use ($state) {
    seedGenshinCatalog();
    $character = idOf('game_characters', 'hutao');

    // Hu Tao's main DPS build, the only one she has unless a test adds more.
    $state->build = seedBuild($character, [
        'weapons' => Json::encode([
            ['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1, 'refinement' => null, 'note' => null, 'known' => true],
            ['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'rank' => 2, 'refinement' => 5, 'note' => null, 'known' => true],
        ]),
        'sets' => Json::encode([
            [
                'rank' => 1,
                'kind' => 'artifact',
                'choose' => null,
                'note' => null,
                'pieces' => [['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']]],
            ],
            [
                'rank' => 2,
                'kind' => 'artifact',
                'choose' => null,
                'note' => null,
                'pieces' => [['label' => "Shimenawa's Reminiscence", 'count' => 4, 'keys' => ['shimenawasreminiscence'], 'names' => ["Shimenawa's Reminiscence"]]],
            ],
        ]),
        'main_stats' => Json::encode([
            'sands' => [['stat' => 'hp_', 'priority' => 1], ['stat' => 'eleMas', 'priority' => 2]],
            'goblet' => [['stat' => Stats::ELEMENTAL_DMG, 'priority' => 1]],
            'circlet' => [['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1]],
        ]),
        'substats' => Json::encode([['stat' => 'critRate_', 'priority' => 1], ['stat' => 'critDMG_', 'priority' => 1], ['stat' => 'eleMas', 'priority' => 2], ['stat' => 'hp_', 'priority' => 3]]),
        'stat_targets' => Json::encode([['stat' => 'critRate_', 'min' => 70, 'max' => null, 'text' => 'CRIT Rate 70%', 'from_rank' => 0]]),
        'notes' => 'Enka has no curated build for this character yet.',
    ]);

    seedUser(1, 'owner');
    $state->account = seedAccount(1, 'genshin', ['nickname' => 'Test', 'has_full_inventory' => true]);
    $state->character = $character;
    db()->insert('account_characters', [
        'account_id' => $state->account,
        'game_character_id' => $character,
        'level' => 90,
        'rank' => 0,
        'source' => 'good',
        'final_stats' => Json::encode(['critRate_' => 62.0, 'critDMG_' => 210.0]),
    ]);

    // Dragon's Bane equipped, Staff of Homa owned but unused.
    db()->insert('account_weapons', [
        'account_id' => $state->account,
        'game_weapon_id' => idOf('game_weapons', 'dragonsbane'),
        'level' => 90,
        'refinement' => 5,
        'equipped_character_id' => $character,
        'source' => 'good',
    ]);
    db()->insert('account_weapons', ['account_id' => $state->account, 'game_weapon_id' => idOf('game_weapons', 'staffofhoma'), 'level' => 90, 'refinement' => 1, 'source' => 'good']);

    $cw = idOf('game_sets', 'crimsonwitchofflames');
    $emblem = idOf('game_sets', 'emblemofseveredfate');
    $crit = [['key' => 'critRate_', 'value' => 7.0, 'rolls' => 2], ['key' => 'critDMG_', 'value' => 14.0, 'rolls' => 2]];
    $weak = [['key' => 'def', 'value' => 40.0, 'rolls' => 2], ['key' => 'atk', 'value' => 30.0, 'rolls' => 2]];

    analysisItem($state->account, $cw, 'flower', 'hp', $crit, $character);
    analysisItem($state->account, $cw, 'plume', 'atk', $crit, $character);
    analysisItem($state->account, $cw, 'sands', 'hp_', $crit, $character);
    analysisItem($state->account, $emblem, 'goblet', 'atk_', $crit, $character); // an off-piece with the wrong main stat
    analysisItem($state->account, $cw, 'circlet', 'critRate_', $weak, $character); // weak substats

    // Better pieces in the inventory.
    analysisItem($state->account, $cw, 'circlet', 'critDMG_', [['key' => 'critRate_', 'value' => 14.0, 'rolls' => 4], ['key' => 'hp_', 'value' => 10.0, 'rolls' => 2]]);
    analysisItem($state->account, $emblem, 'goblet', 'pyro_dmg_', $crit);
});

/**
 * A build of a character's, stored as an import stores it (App\Services\Builds\BuildImport): a main DPS build
 * that says nothing, unless $fields says more. Returns its id.
 *
 * @param  array<string, mixed>  $fields
 */
function seedBuild(int $character, array $fields = []): int
{
    $now = Clock::now();

    return (int) db()->insert(
        'builds',
        $fields + [
            'game_character_id' => $character,
            'role' => 'main_dps',
            'qualifier' => '',
            'min_rank' => 0,
            'position' => 0,
            'weapons' => '[]',
            'sets' => '[]',
            'main_stats' => '{}',
            'substats' => '[]',
            'content_hash' => 'x',
            'checked_at' => $now,
            'changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    );
}

/**
 * Another build of Hu Tao's like her main one - its sets, main stats, substats and targets -, with these fields.
 *
 * @param  array<string, mixed>  $fields
 */
function anotherHuTaoBuild(int $main, array $fields): int
{
    $row = db()->one('SELECT * FROM builds WHERE id = ?', [$main]);

    return seedBuild((int) $row['game_character_id'], $fields + array_intersect_key($row, array_flip(['weapons', 'sets', 'main_stats', 'substats', 'stat_targets'])));
}

/** The character's rank (constellation) in the test's account. */
function setRank(stdClass $state, int $rank): void
{
    db()->run('UPDATE account_characters SET rank = ? WHERE account_id = ? AND game_character_id = ?', [$rank, $state->account, $state->character]);
}

function analysisItem(int $account, int $set, string $slot, string $main, array $substats, ?int $equippedOn = null): void
{
    db()->insert('account_items', [
        'account_id' => $account,
        'game_set_id' => $set,
        'slot' => $slot,
        'rarity' => 5,
        'level' => 20,
        'main_stat' => $main,
        'main_value' => null,
        'substats' => Json::encode($substats),
        'equipped_character_id' => $equippedOn,
        'source' => 'good',
    ]);
}

/**
 * What every recommendation keeps, whatever produced it: its kind is one of BuildEvaluator::KINDS and its
 * params are exactly the ones that kind names, in that order; they are flat, a number is a number (never
 * the way the sentence writes it), and every name, label and list is in the English sentence as it is in
 * the params (a label only differs in its capital). Remembers the kinds it met.
 *
 * @param  list<array<string, mixed>>  $recommendations
 */
function checkKinds(array $recommendations, stdClass $state): void
{
    $numbers = ['level', 'max_level', 'rank', 'owned_rank', 'percent', 'min'];

    foreach ($recommendations as $recommendation) {
        $kind = $recommendation['kind'] ?? null;
        check(is_string($kind) && isset(BuildEvaluator::KINDS[$kind]), 'a kind of KINDS: ' . json_encode($recommendation));
        check(is_array($recommendation['params'] ?? null), "{$kind} has params");
        same(BuildEvaluator::KINDS[$kind], array_keys($recommendation['params']), "the params of {$kind}");
        $message = $recommendation['message'];

        foreach ($recommendation['params'] as $name => $value) {
            if ($name === 'best_choose') {
                check($value === null || is_int($value), "{$kind}.best_choose is a number or null");
            } elseif (in_array($name, $numbers, true) || (in_array($kind, ['target_below', 'target_basic_below'], true) && $name === 'current')) {
                check(is_int($value) || is_float($value), "{$kind}.{$name} is a number, got " . json_encode($value));
            } else {
                check(is_string($value) && $value !== '', "{$kind}.{$name} is a string, got " . json_encode($value));
                // "stat_key" is there for the page's number format; the sentence has the stat's label.
                check($name === 'stat_key' || stripos($message, $value) !== false, "{$kind}.{$name} \"{$value}\" is what the sentence says: {$message}");
            }
        }

        $state->seen[$kind] = true;
    }
}

/**
 * The recommendations of one kind (and one slot, or one stat target) in an evaluation.
 *
 * @return list<array<string, mixed>>
 */
function recommendationsOf(array $evaluation, string $kind, ?string $slot = null): array
{
    return array_values(array_filter($evaluation['recommendations'], fn($r) => $r['kind'] === $kind && ($slot === null || ($r['slot'] ?? ($r['params']['stat_key'] ?? null)) === $slot)));
}

/** The only recommendation of a kind (and slot, or stat target) in an evaluation. */
function recommendationOf(array $evaluation, string $kind, ?string $slot = null): array
{
    $found = recommendationsOf($evaluation, $kind, $slot);
    same(1, count($found), "one {$kind}" . ($slot === null ? '' : " for {$slot}") . ' among: ' . implode(', ', array_column($evaluation['recommendations'], 'kind')));

    return $found[0];
}

/** Every evaluation made through here has its recommendations checked against KINDS. */
$detail = function (?int $account = null, string $character = 'hutao') use ($state): array {
    $accounts = T::$app->get(Accounts::class);
    $account ??= $state->account;

    $result = T::$app->make(CharacterAnalyzer::class)->detail($accounts->findOwned(1, $account), $accounts->character($account, $character));
    foreach ($result['evaluations'] as $evaluation) {
        checkKinds($evaluation['recommendations'], $state);
    }

    return $result;
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
    check(array_filter($messages, fn($m) => str_contains($m, 'You own Staff of Homa')) !== [], 'names the better weapon owned');

    // The same recommendations as a kind with its parameters, beside the unchanged sentence.
    same(['severity', 'category', 'kind', 'params', 'message', 'slot'], array_keys($evaluation['recommendations'][0]), 'the fields of a recommendation');
    same('main_stat_wrong', $evaluation['recommendations'][0]['kind'], 'first recommendation, its kind');

    $goblet = recommendationOf($evaluation, 'main_stat_wrong');
    same(['slot_label' => 'Goblet of Eonothem', 'stat' => 'ATK%', 'wanted' => 'Pyro DMG Bonus'], $goblet['params'], 'the wrong main stat');
    same('goblet', $goblet['slot'], 'slot is the key, slot_label the label');
    same('Goblet of Eonothem has ATK% as main stat; this build wants Pyro DMG Bonus.', $goblet['message'], 'the wrong main stat, in English');

    // Nobody holds the Staff of Homa, so it is the kind without "currently on".
    $weapon = recommendationOf($evaluation, 'weapon_better_owned');
    same(['owned' => 'Staff of Homa', 'refinement' => 'R1', 'owned_rank' => 1, 'name' => "Dragon's Bane", 'rank' => 2], $weapon['params'], 'the better weapon owned');
    same("You own Staff of Homa (R1) (#1), which ranks above Dragon's Bane (#2).", $weapon['message'], 'the better weapon owned, in English');
    same('low', $weapon['severity'], 'the better weapon owned, severity');
    check(!isset($weapon['slot']), 'a weapon has no slot');

    $target = recommendationOf($evaluation, 'target_below');
    same(['stat' => 'CRIT Rate', 'stat_key' => 'critRate_', 'current' => 62, 'min' => 70], $target['params'], 'the stat target');
    same('CRIT Rate is 62%; the build aims for 70%+.', $target['message'], 'the stat target, in English');

    // The circlet's flat DEF and ATK are worth nothing here; the three stats are the short labels, as in the sentence.
    $circlet = recommendationOf($evaluation, 'substats_weak', 'circlet');
    same(['slot_label' => 'Circlet of Logos', 'percent' => 0, 'stats' => 'CR, CD, EM'], $circlet['params'], 'weak substats');
    same('Your Circlet of Logos has weak substats (0% of an ideal piece). Replace it with one rolling CR, CD, EM.', $circlet['message'], 'weak substats, in English');

    // Suggestions: the Pyro goblet (fixes the main stat) and the better circlet (keeps 4pc Crimson Witch).
    $suggestions = array_column($result['suggestions'], null, 'slot');
    same('fixes_main_stat', $suggestions['goblet']['reason'], 'goblet');
    same('pyro_dmg_', $suggestions['goblet']['item']['main_stat'], 'the Pyro goblet');
    same('better_substats', $suggestions['circlet']['reason'], 'circlet');
    check(!isset($suggestions['flower']), 'nothing for the flower');
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
    check(!in_array('completes_set', array_column($result['suggestions'], 'reason'), true), 'no completes_set');
    check(!in_array('flower', array_column($result['suggestions'], 'slot'), true), 'no flower');

    // The recommendation: what is worn is a list of sets, joined as the sentence joins it.
    $sets = recommendationOf($result['evaluations'][0], 'sets_missing');
    same(
        ['set_label' => 'Artifact set', 'best' => '4pc Crimson Witch of Flames', 'best_choose' => null, 'current' => "4pc Emblem of Severed Fate + 1pc Shimenawa's Reminiscence"],
        $sets['params'],
        'no recommended set',
    );
    same("Use the recommended artifact set: 4pc Crimson Witch of Flames. Currently: 4pc Emblem of Severed Fate + 1pc Shimenawa's Reminiscence.", $sets['message'], 'no recommended set, in English');
    same('high', $sets['severity'], 'no recommended set, severity');
});

test('the account overview and the character API: the build as a variation, and nowhere it came from', function () use ($state) {
    $accounts = T::$app->get(Accounts::class);
    $overview = T::$app->make(CharacterAnalyzer::class)->overview($accounts->findOwned(1, $state->account));
    same('Hu Tao', $overview[0]['name'], 'name');
    same(
        ['build_id' => $state->build, 'chosen' => false, 'role' => 'main_dps', 'qualifier' => '', 'min_rank' => 0, 'applies' => true],
        array_intersect_key($overview[0]['build'], array_flip(['build_id', 'chosen', 'role', 'qualifier', 'min_rank', 'applies'])),
        'the best build by its variation, nobody\'s choice',
    );
    check(!array_key_exists('source', $overview[0]['build']) && !array_key_exists('variant', $overview[0]['build']), 'no source, no variant');
    check($overview[0]['build']['overall'] > 0, 'a score');

    // The top issue is the first recommendation: its sentence, and its kind with its parameters.
    $goblet = ['slot_label' => 'Goblet of Eonothem', 'stat' => 'ATK%', 'wanted' => 'Pyro DMG Bonus'];
    same('Goblet of Eonothem has ATK% as main stat; this build wants Pyro DMG Bonus.', $overview[0]['build']['top_issue'], 'the top issue, in English');
    same('main_stat_wrong', $overview[0]['build']['top_issue_kind'], 'the top issue, its kind');
    same($goblet, $overview[0]['build']['top_issue_params'], 'the top issue, its params');
    $best = $overview[0]['build'];
    checkKinds([['kind' => $best['top_issue_kind'], 'params' => $best['top_issue_params'], 'message' => $best['top_issue']]], $state);

    $client = (new Client())->asIam(1);
    $client
        ->get("/api/accounts/{$state->account}")
        ->status(200)
        ->path('characters.0.key', 'hutao')
        ->path('characters.0.build.top_issue', 'Goblet of Eonothem has ATK% as main stat; this build wants Pyro DMG Bonus.')
        ->path('characters.0.build.top_issue_kind', 'main_stat_wrong')
        ->path('characters.0.build.top_issue_params', $goblet);

    // The site's own note on the build, as no editor's save would let it be written: straight into the table.
    db()->run('UPDATE builds SET site_notes = ? WHERE id = ?', [Json::encode(['build' => ['en' => 'Enka lists none of these.', 'sk' => 'Enka nič z toho neuvádza.']]), $state->build]);
    $answer = $client
        ->get("/api/accounts/{$state->account}/characters/hutao")
        ->status(200)
        ->path('character.name', 'Hu Tao')
        ->path('builds.0.role', 'main_dps')
        ->path('builds.0.qualifier', '')
        ->path('builds.0.min_rank', 0)
        ->path('builds.0.weapons.0.name', 'Staff of Homa')
        ->path('evaluations.0.build.id', $state->build)
        ->path('evaluations.0.build.role', 'main_dps')
        ->path('evaluations.0.build.applies', true)
        ->path('evaluations.0.build.position', 0)
        // The site's own note, a site's name in it made neutral; the file's notes and texts are sent nowhere.
        ->path('builds.0.notes', ['en' => 'The source lists none of these.', 'sk' => 'The source nič z toho neuvádza.'])
        // A recommendation as it is sent: the kind and its params beside the sentence, the slot's key apart.
        ->path('evaluations.0.recommendations.0', [
            'severity' => 'high',
            'category' => 'main_stats',
            'kind' => 'main_stat_wrong',
            'params' => $goblet,
            'message' => 'Goblet of Eonothem has ATK% as main stat; this build wants Pyro DMG Bonus.',
            'slot' => 'goblet',
        ]);
    same(['id', 'role', 'qualifier', 'min_rank', 'applies', 'position', 'game_version', 'checked_at'], array_keys($answer->json('evaluations.0.build')), 'what an evaluation says of its build');
    check(!str_contains($answer->body, 'Enka'), 'the site\'s name is not sent');
    check(!array_key_exists('consensus', $answer->json()), 'no consensus between sources: there are none');

    // What arrives as JSON keeps the rule too: the params of each kind, numbers as JSON numbers.
    checkKinds($answer->json('evaluations.0.recommendations'), $state);
    check(str_contains($answer->body, '"owned_rank":1,') && str_contains($answer->body, '"current":62,"min":70}'), 'numbers are sent as numbers');

    $client->get("/api/accounts/{$state->account}/characters/nobody")->status(404);
});

test('a build\'s teams on the account\'s character page: each teammate as the catalog knows it now, and which ones the account has, with their constellation', function () use ($state) {
    $team = [
        'name' => 'Vaporize',
        'members' => [
            ['key' => 'hutao', 'name' => 'Hu Tao', 'role' => 'main_dps', 'min_rank' => 0, 'alternatives' => []],
            ['key' => 'kaedeharakazuha', 'name' => 'Kazuha', 'role' => 'support', 'min_rank' => 2, 'alternatives' => [['key' => 'traveleranemo', 'name' => 'Traveler (Anemo)']]],
            ['key' => 'xingqiu', 'name' => 'Xingqiu', 'role' => 'sub_dps', 'min_rank' => 6, 'alternatives' => []],
        ],
        'note' => 'A note of the file\'s, which the site shows nowhere.',
    ];
    // A second team with Traveler (Geo): the account's Traveler is Anemo, and the Traveler is one character in every
    // element - Geo too is the account's, its constellation unknown.
    $geo = [
        'name' => 'Geo',
        'members' => [
            ['key' => 'hutao', 'name' => 'Hu Tao', 'role' => 'main_dps', 'min_rank' => 0, 'alternatives' => []],
            ['key' => 'travelergeo', 'name' => 'Traveler (Geo)', 'role' => 'support', 'min_rank' => 0, 'alternatives' => []],
        ],
        'note' => null,
    ];
    db()->run('UPDATE builds SET teams = ? WHERE id = ?', [Json::encode([$team, $geo]), $state->build]);
    // The site's own note on the team, by its members whatever their order.
    db()->run('UPDATE builds SET site_notes = ? WHERE id = ?', [
        Json::encode(['teams' => ['hutao kaedeharakazuha xingqiu' => ['en' => 'Swirl the Pyro onto the rain.', 'sk' => 'Rozvír Pyro do dažďa.']]]),
        $state->build,
    ]);
    // Kazuha has builds of his own; the account has him at C1 and the Traveler (Anemo) who can take his place.
    $kazuha = idOf('game_characters', 'kaedeharakazuha');
    seedBuild($kazuha, ['role' => 'support', 'content_hash' => 'y']);
    db()->insert('account_characters', ['account_id' => $state->account, 'game_character_id' => $kazuha, 'level' => 90, 'rank' => 1, 'source' => 'good']);
    db()->insert('account_characters', ['account_id' => $state->account, 'game_character_id' => idOf('game_characters', 'traveleranemo'), 'level' => 70, 'rank' => 0, 'source' => 'good']);

    $client = (new Client())->asIam(1);
    $answer = $client->get("/api/accounts/{$state->account}/characters/hutao")->status(200);
    same(
        ['kaedeharakazuha' => 1, 'traveleranemo' => 0, 'travelergeo' => null],
        $answer->json('owned_teammates'),
        'the teammates the account has - a stand-in too -, each with its constellation; Hu Tao herself left out; the Traveler in another element the account\'s too, its constellation unknown',
    );

    [$own, $kazuhaMember, $xingqiu] = $answer->json('builds.0.teams.0.members');
    same(
        ['Vaporize', ['en' => 'Swirl the Pyro onto the rain.', 'sk' => 'Rozvír Pyro do dažďa.'], null],
        [$answer->json('builds.0.teams.0.name'), $answer->json('builds.0.teams.0.note'), $answer->json('builds.0.teams.1.note')],
        'the team\'s name, and the site\'s note on it - the file\'s nowhere; none on the other',
    );
    same(['hutao', true, true, true], [$own['key'], $own['own'], $own['known'], $own['has_builds']], 'the build\'s own member');
    same(
        ['Kaedehara Kazuha', false, true, true, 'support', 2],
        [$kazuhaMember['name'], $kazuhaMember['own'], $kazuhaMember['known'], $kazuhaMember['has_builds'], $kazuhaMember['role'], $kazuhaMember['min_rank']],
        'Kazuha by the catalog\'s name, with builds to link to, at the C2 the team asks of him',
    );
    same(
        ['traveleranemo', true, false],
        [$kazuhaMember['alternatives'][0]['key'], $kazuhaMember['alternatives'][0]['known'], $kazuhaMember['alternatives'][0]['has_builds']],
        'his stand-in: in the catalog, without builds',
    );
    same(
        ['Xingqiu', false, false, null],
        [$xingqiu['name'], $xingqiu['known'], $xingqiu['has_builds'], $xingqiu['icon_url']],
        'Xingqiu, whom the catalog does not know: by the file\'s name, no icon, no link',
    );

    // The catalog catches up: known at once, no import needed.
    db()->insert('game_characters', ['game' => 'genshin', 'key' => 'xingqiu', 'name' => 'Xingqiu', 'element' => 'hydro', 'rarity' => 4, 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    same(
        true,
        $client
            ->get("/api/accounts/{$state->account}/characters/hutao")
            ->status(200)
            ->json('builds.0.teams.0.members.2.known'),
        'Xingqiu known now',
    );

    // Xingqiu held elsewhere - in another account of the same person's, and in another person's - is none of this
    // account's: the marks are the account's own.
    $xingqiuId = idOf('game_characters', 'xingqiu');
    $second = seedAccount(1, 'genshin', ['nickname' => 'Second', 'uid' => '700000002']);
    seedUser(2, 'someone else');
    $theirs = seedAccount(2, 'genshin', ['nickname' => 'Theirs', 'uid' => '700000003']);
    foreach ([$second, $theirs] as $elsewhere) {
        db()->insert('account_characters', ['account_id' => $elsewhere, 'game_character_id' => $xingqiuId, 'level' => 90, 'rank' => 6, 'source' => 'good']);
    }
    same(
        ['kaedeharakazuha' => 1, 'traveleranemo' => 0, 'travelergeo' => null],
        $client
            ->get("/api/accounts/{$state->account}/characters/hutao")
            ->status(200)
            ->json('owned_teammates'),
        'Xingqiu held elsewhere: not this account\'s',
    );

    // An account with none of the teammates - no Traveler of any element either: an object still, empty.
    db()->run('DELETE FROM account_characters WHERE account_id = ? AND game_character_id <> ?', [$state->account, $state->character]);
    check(str_contains($client->get("/api/accounts/{$state->account}/characters/hutao")->status(200)->body, '"owned_teammates":{}'), 'none: {}');
});

test('a character with nothing to recommend has no top issue, no kind and no params', function () use ($detail, $state) {
    // Kazuha at level 90 against a build that says nothing the analysis could compare.
    $kazuha = idOf('game_characters', 'kaedeharakazuha');
    seedBuild($kazuha, ['role' => 'support', 'content_hash' => 'y']);
    db()->insert('account_characters', ['account_id' => $state->account, 'game_character_id' => $kazuha, 'level' => 90, 'rank' => 0, 'source' => 'good']);

    same([], $detail(null, 'kaedeharakazuha')['evaluations'][0]['recommendations'], 'nothing to recommend');

    $accounts = T::$app->get(Accounts::class);
    $overview = array_column(T::$app->make(CharacterAnalyzer::class)->overview($accounts->findOwned(1, $state->account)), null, 'key');
    $best = $overview['kaedeharakazuha']['build'];
    check(is_array($best), 'the build is still the best one');
    foreach (['top_issue', 'top_issue_kind', 'top_issue_params'] as $field) {
        check(array_key_exists($field, $best) && $best[$field] === null, "{$field} is there and null");
    }
    same(0, $best['issues'], 'no issues');
    same('main_stat_wrong', $overview['hutao']['build']['top_issue_kind'], 'Hu Tao keeps hers');

    $characters = array_column(
        (new Client())
            ->asIam(1)
            ->get("/api/accounts/{$state->account}")
            ->status(200)
            ->json('characters'),
        null,
        'key',
    );
    check(array_key_exists('top_issue_kind', $characters['kaedeharakazuha']['build']) && $characters['kaedeharakazuha']['build']['top_issue_kind'] === null, 'sent as null');
    check(array_key_exists('top_issue_params', $characters['kaedeharakazuha']['build']) && $characters['kaedeharakazuha']['build']['top_issue_params'] === null, 'sent as null');
});

test('a better weapon that another character holds is a kind of its own', function () use ($detail, $state) {
    db()->run('UPDATE account_weapons SET equipped_character_id = ? WHERE game_weapon_id = ?', [idOf('game_characters', 'kaedeharakazuha'), idOf('game_weapons', 'staffofhoma')]);

    $evaluation = $detail()['evaluations'][0];
    $weapon = recommendationOf($evaluation, 'weapon_better_owned_on');
    same(
        ['owned' => 'Staff of Homa', 'refinement' => 'R1', 'owned_rank' => 1, 'name' => "Dragon's Bane", 'rank' => 2, 'character' => 'Kaedehara Kazuha'],
        $weapon['params'],
        'who holds it is a parameter',
    );
    same("You own Staff of Homa (R1), currently on Kaedehara Kazuha (#1), which ranks above Dragon's Bane (#2).", $weapon['message'], 'in English');
    same([], recommendationsOf($evaluation, 'weapon_better_owned'), 'and not the kind without the holder');
});

test('a weapon the build does not list: alone, with a listed one owned, and with that one on somebody else', function () use ($detail, $state) {
    // Hu Tao holds a Deathmatch now; the Dragon's Bane is gone, the Staff of Homa lies unused.
    $deathmatch = seedWeapon('genshin', 'Deathmatch', ['rarity' => 4, 'weapon_type' => 'polearm']);
    $homa = idOf('game_weapons', 'staffofhoma');
    db()->run('UPDATE account_weapons SET game_weapon_id = ? WHERE equipped_character_id = ?', [$deathmatch, $state->character]);

    $base = ['name' => 'Deathmatch', 'weapon_label' => 'Weapon', 'options' => "Staff of Homa, Dragon's Bane"];
    $sentence = "Deathmatch is not on this build's weapon list. Recommended: Staff of Homa, Dragon's Bane.";

    $evaluation = $detail()['evaluations'][0];
    same(null, $evaluation['weapon']['rank'], 'not on the list');
    $owned = recommendationOf($evaluation, 'weapon_unlisted_owned');
    same($base + ['owned' => 'Staff of Homa', 'refinement' => 'R1'], $owned['params'], 'a listed weapon is owned');
    same($sentence . ' You already own Staff of Homa (R1).', $owned['message'], 'a listed weapon is owned, in English');
    same('medium', $owned['severity'], 'severity');

    db()->run('UPDATE account_weapons SET equipped_character_id = ? WHERE game_weapon_id = ?', [idOf('game_characters', 'kaedeharakazuha'), $homa]);
    $evaluation = $detail()['evaluations'][0];
    $on = recommendationOf($evaluation, 'weapon_unlisted_owned_on');
    same($base + ['owned' => 'Staff of Homa', 'refinement' => 'R1', 'character' => 'Kaedehara Kazuha'], $on['params'], 'and somebody holds it');
    same($sentence . ' You already own Staff of Homa (R1), currently on Kaedehara Kazuha.', $on['message'], 'and somebody holds it, in English');
    same([], recommendationsOf($evaluation, 'weapon_unlisted_owned'), 'one kind, not both');

    db()->run('DELETE FROM account_weapons WHERE game_weapon_id = ?', [$homa]);
    $evaluation = $detail()['evaluations'][0];
    $alone = recommendationOf($evaluation, 'weapon_unlisted');
    same($base, $alone['params'], 'no listed weapon is owned');
    same($sentence, $alone['message'], 'no listed weapon is owned, in English');
    same(['weapon_unlisted'], array_values(array_filter(array_column($evaluation['recommendations'], 'kind'), fn($kind) => str_starts_with($kind, 'weapon_'))), 'the only weapon recommendation');
});

test('no weapon, a weapon that needs levels, and a weapon far down the list', function () use ($detail, $state) {
    $bane = idOf('game_weapons', 'dragonsbane');

    db()->run('UPDATE account_weapons SET equipped_character_id = NULL WHERE game_weapon_id = ?', [$bane]);
    $none = recommendationOf($detail()['evaluations'][0], 'weapon_none');
    same(['weapon_label' => 'Weapon', 'options' => "Staff of Homa, Dragon's Bane"], $none['params'], 'no weapon');
    same("No weapon equipped. Best options: Staff of Homa, Dragon's Bane.", $none['message'], 'no weapon, in English');
    same('high', $none['severity'], 'no weapon, severity');

    db()->run('UPDATE account_weapons SET equipped_character_id = ?, level = 70 WHERE game_weapon_id = ?', [$state->character, $bane]);
    $level = recommendationOf($detail()['evaluations'][0], 'weapon_level');
    same(['name' => "Dragon's Bane", 'level' => 70, 'max_level' => 90], $level['params'], 'weapon level');
    same("Level up Dragon's Bane (level 70/90).", $level['message'], 'weapon level, in English');

    // Fourth of four, and none of the three above it is owned: only the first three are named.
    db()->run('DELETE FROM account_weapons WHERE game_weapon_id = ?', [idOf('game_weapons', 'staffofhoma')]);
    db()->run('UPDATE builds SET weapons = ? WHERE game_character_id = ?', [
        Json::encode([
            ['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1, 'refinement' => null, 'note' => null, 'known' => true],
            ['key' => 'staffofthescarletsands', 'name' => 'Staff of the Scarlet Sands', 'rank' => 2, 'refinement' => null, 'note' => null, 'known' => true],
            ['key' => 'deathmatch', 'name' => 'Deathmatch', 'rank' => 3, 'refinement' => null, 'note' => null, 'known' => true],
            ['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'rank' => 4, 'refinement' => 5, 'note' => null, 'known' => true],
        ]),
        $state->character,
    ]);
    $low = recommendationOf($detail()['evaluations'][0], 'weapon_low_rank');
    same(['name' => "Dragon's Bane", 'rank' => 4, 'options' => 'Staff of Homa, Staff of the Scarlet Sands, Deathmatch'], $low['params'], 'a low option');
    same("Dragon's Bane is option #4. Upgrades to aim for: Staff of Homa, Staff of the Scarlet Sands, Deathmatch.", $low['message'], 'a low option, in English');
});

test('a lower set option, and nothing worn at all', function () use ($detail, $state) {
    // Five Shimenawa pieces: the build's second option.
    db()->run('UPDATE account_items SET game_set_id = ? WHERE equipped_character_id = ?', [idOf('game_sets', 'shimenawasreminiscence'), $state->character]);
    $evaluation = $detail()['evaluations'][0];
    same(2, $evaluation['sets']['groups'][0]['matched_rank'], 'the second option');
    $lower = recommendationOf($evaluation, 'sets_lower_option');
    same(['set_label' => 'Artifact set', 'current' => "5pc Shimenawa's Reminiscence", 'rank' => 2, 'best' => '4pc Crimson Witch of Flames', 'best_choose' => null], $lower['params'], 'a lower option');
    same("Your artifact set (5pc Shimenawa's Reminiscence) is option #2. The top choice is 4pc Crimson Witch of Flames.", $lower['message'], 'a lower option, in English');
    same('low', $lower['severity'], 'a lower option, severity');

    // Nothing worn: a kind of its own with no "current" - the sentence's "nothing" is an English word, not a list of sets.
    db()->run('UPDATE account_items SET equipped_character_id = NULL WHERE account_id = ?', [$state->account]);
    $evaluation = $detail()['evaluations'][0];
    $empty = recommendationOf($evaluation, 'sets_missing_empty');
    same(['set_label' => 'Artifact set', 'best' => '4pc Crimson Witch of Flames', 'best_choose' => null], $empty['params'], 'nothing worn');
    same('Use the recommended artifact set: 4pc Crimson Witch of Flames. Currently: nothing.', $empty['message'], 'nothing worn, in English');
    same([], recommendationsOf($evaluation, 'sets_missing'), 'and not the kind with a list');

    // And every slot a build has a main stat for is missing its piece; several wanted stats are joined with " / ".
    $sands = recommendationOf($evaluation, 'main_stat_missing', 'sands');
    same(['slot_label' => 'Sands of Eon', 'wanted' => 'HP% / Elemental Mastery'], $sands['params'], 'no sands');
    same('No Sands of Eon equipped. Look for one with HP% / Elemental Mastery.', $sands['message'], 'no sands, in English');
    same(['slot_label' => 'Goblet of Eonothem', 'wanted' => 'Pyro DMG Bonus'], recommendationOf($evaluation, 'main_stat_missing', 'goblet')['params'], 'no goblet');
    same(['slot_label' => 'Circlet of Logos', 'wanted' => 'CRIT Rate / CRIT DMG'], recommendationOf($evaluation, 'main_stat_missing', 'circlet')['params'], 'no circlet');
});

test('a set option of which any two pieces do: the pieces and the number go out apart', function () use ($detail, $state) {
    $piece = fn(string $name, string $key) => ['label' => $name, 'count' => 2, 'keys' => [$key], 'names' => [$name]];
    $three = [$piece('Crimson Witch of Flames', 'crimsonwitchofflames'), $piece("Shimenawa's Reminiscence", 'shimenawasreminiscence'), $piece('Emblem of Severed Fate', 'emblemofseveredfate')];
    $pieces = "2pc Crimson Witch of Flames + 2pc Shimenawa's Reminiscence + 2pc Emblem of Severed Fate";

    // optionLabel() is optionPieces() plus the "(any N)" of optionChoose() - which is null unless fewer pieces are needed than listed.
    $anyTwo = ['rank' => 1, 'kind' => 'artifact', 'choose' => 2, 'note' => null, 'pieces' => $three];
    same($pieces, BuildEvaluator::optionPieces($anyTwo), 'the pieces');
    same(2, BuildEvaluator::optionChoose($anyTwo), 'any two');
    same($pieces . ' (any 2)', BuildEvaluator::optionLabel($anyTwo), 'the label, as before');
    foreach ([['choose' => null], ['choose' => 3], ['choose' => 0], []] as $all) {
        same(null, BuildEvaluator::optionChoose($all + ['pieces' => $three]), 'every piece is needed: ' . json_encode($all));
        same($pieces, BuildEvaluator::optionLabel($all + ['pieces' => $three]), 'no "(any N)": ' . json_encode($all));
    }
    same('', BuildEvaluator::optionPieces([]), 'an option without pieces');
    same(null, BuildEvaluator::optionChoose(['choose' => 1]), 'an option without pieces needs none');
    same('', BuildEvaluator::optionLabel(['choose' => 1]), 'an option without pieces, its label');

    // Hu Tao's 4pc Crimson Witch + 1pc Emblem gives one of the three 2pc bonuses, not two.
    db()->run('UPDATE builds SET sets = ? WHERE game_character_id = ?', [Json::encode([$anyTwo]), $state->character]);
    $result = $detail();
    $sets = recommendationOf($result['evaluations'][0], 'sets_missing');
    same(
        ['set_label' => 'Artifact set', 'best' => $pieces, 'best_choose' => 2, 'current' => '4pc Crimson Witch of Flames + 1pc Emblem of Severed Fate'],
        $sets['params'],
        'best is the pieces, best_choose the number',
    );
    same("Use the recommended artifact set: {$pieces} (any 2). Currently: 4pc Crimson Witch of Flames + 1pc Emblem of Severed Fate.", $sets['message'], 'in English, as before');
    same($pieces . ' (any 2)', $result['evaluations'][0]['sets']['groups'][0]['best'], 'the group keeps its label');

    // No plan is made for such an option.
    same([], $result['set_plans'], 'no set plan for an option of several pieces');

    // Two of the three worn, as the build's second option: the top option keeps its two parts there too.
    db()->run('UPDATE builds SET sets = ? WHERE game_character_id = ?', [
        Json::encode([
            ['rank' => 1, 'kind' => 'artifact', 'choose' => 2, 'note' => null, 'pieces' => [$three[1], $three[2], $piece('Gilded Dreams', 'gildeddreams')]],
            [
                'rank' => 2,
                'kind' => 'artifact',
                'choose' => null,
                'note' => null,
                'pieces' => [['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']]],
            ],
        ]),
        $state->character,
    ]);
    $lower = recommendationOf($detail()['evaluations'][0], 'sets_lower_option');
    same(
        [
            'set_label' => 'Artifact set',
            'current' => '4pc Crimson Witch of Flames + 1pc Emblem of Severed Fate',
            'rank' => 2,
            'best' => "2pc Shimenawa's Reminiscence + 2pc Emblem of Severed Fate + 2pc Gilded Dreams",
            'best_choose' => 2,
        ],
        $lower['params'],
        'a lower option under an "any two"',
    );
    same(
        "Your artifact set (4pc Crimson Witch of Flames + 1pc Emblem of Severed Fate) is option #2. The top choice is 2pc Shimenawa's Reminiscence + 2pc Emblem of Severed Fate + 2pc Gilded Dreams (any 2).",
        $lower['message'],
        'a lower option under an "any two", in English',
    );
});

test('levels, talents and stat targets: numbers go out as numbers, not as the sentence writes them', function () use ($detail, $state) {
    db()->run("UPDATE account_items SET level = 16 WHERE slot = 'sands' AND equipped_character_id = ?", [$state->character]);
    db()->run("UPDATE account_items SET level = 8 WHERE slot = 'plume' AND equipped_character_id = ?", [$state->character]);
    db()->run('UPDATE account_characters SET level = 70, talents = ?, final_stats = ? WHERE game_character_id = ?', [
        Json::encode(['normal' => 6, 'skill' => 6, 'burst' => 9]),
        '{"critRate_": 62.4, "hp": 28512.0, "critDMG_": 210.0}',
        $state->character,
    ]);
    db()->run('UPDATE builds SET talent_priority = ?, stat_targets = ? WHERE game_character_id = ?', [
        Json::encode([['talent' => 'skill', 'priority' => 1], ['talent' => 'burst', 'priority' => 1], ['talent' => 'normal', 'priority' => 2]]),
        Json::encode([
            ['stat' => 'critRate_', 'min' => 70, 'max' => null, 'text' => 'CRIT Rate 70%'],
            ['stat' => 'hp', 'min' => 30000, 'max' => null, 'text' => '30,000 HP'],
            ['stat' => 'critDMG_', 'min' => 200, 'max' => null, 'text' => 'CRIT DMG 200%'],
        ]),
        $state->character,
    ]);

    $evaluation = $detail()['evaluations'][0];

    $sands = recommendationOf($evaluation, 'item_level', 'sands');
    same(['slot_label' => 'Sands of Eon', 'level' => 16, 'max_level' => 20], $sands['params'], 'a piece to level');
    same("Level up your Sands of Eon (+16 \u{2192} +20).", $sands['message'], 'a piece to level, in English');
    same(['sands', 'low'], [$sands['slot'], $sands['severity']], 'a piece to level, slot and severity');
    $plume = recommendationOf($evaluation, 'item_level', 'plume');
    same(['slot_label' => 'Plume of Death', 'level' => 8, 'max_level' => 20], $plume['params'], 'a piece far from its level');
    same('medium', $plume['severity'], 'a piece far from its level, severity');

    $character = recommendationOf($evaluation, 'character_level');
    same(['level' => 70], $character['params'], 'the character\'s level');
    same('Level the character up (level 70); most of their stats come from character level.', $character['message'], 'the character\'s level, in English');

    // Only a first-priority talent below 8: the burst is at 9, the normal attack is second priority.
    $talent = recommendationOf($evaluation, 'talent_level');
    same(['talent' => 'Elemental Skill', 'level' => 6], $talent['params'], 'a talent');
    same('Level Elemental Skill first (priority #1, currently 6).', $talent['message'], 'a talent, in English');

    // Targets: the values as they are stored; the sentence rounds them and puts in the thousands separator and the unit.
    $rate = recommendationOf($evaluation, 'target_below', 'critRate_');
    same(['stat' => 'CRIT Rate', 'stat_key' => 'critRate_', 'current' => 62.4, 'min' => 70], $rate['params'], 'a percent target');
    same('CRIT Rate is 62.4%; the build aims for 70%+.', $rate['message'], 'a percent target, in English');
    $hp = recommendationOf($evaluation, 'target_below', 'hp');
    same(['stat' => 'HP', 'stat_key' => 'hp', 'current' => 28512.0, 'min' => 30000], $hp['params'], 'a flat target');
    same('HP is 28,512; the build aims for 30,000+.', $hp['message'], 'a flat target, in English');
    same(2, count(recommendationsOf($evaluation, 'target_below')), 'the target that is met says nothing');
});

test('the game\'s own labels: Weapon as it is, "Artifact set", R for the refinement', function () use ($detail) {
    $kazuha = idOf('game_characters', 'kaedeharakazuha');

    seedBuild($kazuha, [
        'role' => 'support',
        'weapons' => Json::encode([
            ['key' => 'staffofhoma', 'name' => 'Staff of Homa', 'rank' => 1, 'refinement' => null, 'note' => null, 'known' => true],
            ['key' => 'deathmatch', 'name' => 'Deathmatch', 'rank' => 2, 'refinement' => null, 'note' => null, 'known' => false],
        ]),
        'sets' => Json::encode([
            [
                'rank' => 1,
                'kind' => 'artifact',
                'choose' => null,
                'note' => null,
                'pieces' => [['label' => 'Crimson Witch of Flames', 'count' => 4, 'keys' => ['crimsonwitchofflames'], 'names' => ['Crimson Witch of Flames']]],
            ],
        ]),
        'content_hash' => 'z',
    ]);

    // Kazuha with no artifacts, a weapon the build does not list, and the listed one lying unused.
    $account = seedAccount(1, 'genshin', ['nickname' => 'Labels', 'has_full_inventory' => true]);
    db()->insert('account_characters', ['account_id' => $account, 'game_character_id' => $kazuha, 'level' => 90, 'rank' => 0, 'source' => 'good']);
    db()->insert('account_weapons', [
        'account_id' => $account,
        'game_weapon_id' => idOf('game_weapons', 'dragonsbane'),
        'level' => 90,
        'refinement' => 5,
        'equipped_character_id' => $kazuha,
        'source' => 'good',
    ]);
    db()->insert('account_weapons', ['account_id' => $account, 'game_weapon_id' => idOf('game_weapons', 'staffofhoma'), 'level' => 90, 'refinement' => 1, 'source' => 'good']);

    $evaluation = $detail($account, 'kaedeharakazuha')['evaluations'][0];

    // weapon_label is the label as it is; only the English sentence lower-cases it.
    $weapon = recommendationOf($evaluation, 'weapon_unlisted_owned');
    same(['name' => "Dragon's Bane", 'weapon_label' => 'Weapon', 'options' => 'Staff of Homa, Deathmatch', 'owned' => 'Staff of Homa', 'refinement' => 'R1'], $weapon['params'], 'the weapon');
    same("Dragon's Bane is not on this build's weapon list. Recommended: Staff of Homa, Deathmatch. You already own Staff of Homa (R1).", $weapon['message'], 'the weapon, in English');

    // The kind of set by the game's word for it (Game::setLabel()).
    $empty = recommendationOf($evaluation, 'sets_missing_empty');
    same(['set_label' => 'Artifact set', 'best' => '4pc Crimson Witch of Flames', 'best_choose' => null], $empty['params'], 'the set');
    same('Use the recommended artifact set: 4pc Crimson Witch of Flames. Currently: nothing.', $empty['message'], 'the set, in English');
});

test('a build the character\'s constellation does not reach is judged and marked, and never the best while one it reaches is there', function () use ($detail, $state) {
    // A second main DPS build that needs C2 and lists the weapon she wears first: it would score higher.
    $vape = anotherHuTaoBuild($state->build, [
        'qualifier' => 'Vape',
        'min_rank' => 2,
        'position' => 1,
        'weapons' => Json::encode([['key' => 'dragonsbane', 'name' => "Dragon's Bane", 'rank' => 1, 'refinement' => 5, 'note' => null, 'known' => true]]),
    ]);

    $result = $detail();
    [$main, $c2] = $result['evaluations'];
    same(
        [[$state->build, true, 0], [$vape, false, 2]],
        [[$main['build']['id'], $main['build']['applies'], $main['build']['min_rank']], [$c2['build']['id'], $c2['build']['applies'], $c2['build']['min_rank']]],
        'at C0 the first applies, the second is marked',
    );
    check($c2['score']['overall'] > $main['score']['overall'], "the C2 build scores higher ({$c2['score']['overall']} > {$main['score']['overall']})");
    same($state->build, $result['best_build_id'], 'yet the one she reaches is the best');

    // At C2 both apply: the better one is the best, and the account page names it by its variation.
    setRank($state, 2);
    $result = $detail();
    same([true, true], array_map(fn($e) => $e['build']['applies'], $result['evaluations']), 'both apply at C2');
    same($vape, $result['best_build_id'], 'the better one is the best');
    $accounts = T::$app->get(Accounts::class);
    $best = T::$app->make(CharacterAnalyzer::class)->overview($accounts->findOwned(1, $state->account))[0]['build'];
    same(
        ['build_id' => $vape, 'role' => 'main_dps', 'qualifier' => 'Vape', 'min_rank' => 2, 'applies' => true],
        array_intersect_key($best, array_flip(['build_id', 'role', 'qualifier', 'min_rank', 'applies'])),
        'the overview\'s best',
    );
});

test('of builds that score alike the earlier one is the best: the first build is the main one', function () use ($detail, $state) {
    $copy = anotherHuTaoBuild($state->build, ['qualifier' => 'Copy', 'position' => 1]);

    $result = $detail();
    same($result['evaluations'][0]['score']['overall'], $result['evaluations'][1]['score']['overall'], 'the same score');
    same($state->build, $result['best_build_id'], 'the first');

    db()->run('UPDATE builds SET position = 1 - position WHERE id IN (?, ?)', [$state->build, $copy]);
    same($copy, $detail()['best_build_id'], 'the first now');
});

test('a character below every build\'s constellation: the build that needs the lowest one is shown first, said so', function () use ($detail, $state) {
    db()->run('UPDATE builds SET min_rank = 4 WHERE id = ?', [$state->build]);
    $c2 = anotherHuTaoBuild($state->build, ['role' => 'sub_dps', 'min_rank' => 2, 'position' => 1]);

    $result = $detail();
    same($c2, $result['best_build_id'], 'the one needing C2');
    same([false, false], array_map(fn($e) => $e['build']['applies'], $result['evaluations']), 'neither applies');
    $accounts = T::$app->get(Accounts::class);
    $best = T::$app->make(CharacterAnalyzer::class)->overview($accounts->findOwned(1, $state->account))[0]['build'];
    same([$c2, false, 2], [$best['build_id'], $best['applies'], $best['min_rank']], 'the overview says it does not apply');
});

test('the build the player chose is the one the account page shows and the character page opens on; the best stays the closest match', function () use ($detail, $state) {
    // A support build like her main one: the same score, so the main one (the earlier) stays the best.
    $support = anotherHuTaoBuild($state->build, ['role' => 'support', 'position' => 1]);
    $client = (new Client())->asIam(1);
    $url = "/api/accounts/{$state->account}/characters/hutao/build";

    $client
        ->put($url, ['build_id' => $support])
        ->status(200)
        ->path('chosen_build_id', $support);
    $result = $detail();
    same([$state->build, $support, $support], [$result['best_build_id'], $result['chosen_build_id'], $result['focus_build_id']], 'the best, the choice, and the build in hand');

    $accounts = T::$app->get(Accounts::class);
    $build = T::$app->make(CharacterAnalyzer::class)->overview($accounts->findOwned(1, $state->account))[0]['build'];
    same([$support, true, 'support'], [$build['build_id'], $build['chosen'], $build['role']], 'the account page shows the build chosen, said so');
    $client
        ->get("/api/accounts/{$state->account}")
        ->status(200)
        ->path('characters.0.build.build_id', $support)
        ->path('characters.0.build.chosen', true);

    // Another build asked for is the one in hand; the choice stays.
    $other = T::$app->make(CharacterAnalyzer::class)->detail($accounts->findOwned(1, $state->account), $accounts->character($state->account, 'hutao'), $state->build);
    same([$state->build, $support], [$other['focus_build_id'], $other['chosen_build_id']], 'another build in hand, the choice kept');
    $client
        ->get("/api/accounts/{$state->account}/characters/hutao")
        ->status(200)
        ->path('chosen_build_id', $support)
        ->path('best_build_id', $state->build);

    // Back to the closest match.
    $client
        ->put($url, ['build_id' => null])
        ->status(200)
        ->path('chosen_build_id', null);
    $result = $detail();
    same([null, $state->build], [$result['chosen_build_id'], $result['focus_build_id']], 'chosen automatically again');
    same(false, T::$app->make(CharacterAnalyzer::class)->overview($accounts->findOwned(1, $state->account))[0]['build']['chosen'], 'the account page\'s too');
});

test('a chosen build the constellation does not reach is shown all the same, said so; one that goes takes the choice with it', function () use ($detail, $state) {
    $c2 = anotherHuTaoBuild($state->build, ['qualifier' => 'Vape', 'min_rank' => 2, 'position' => 1]);
    (new Client())
        ->asIam(1)
        ->put("/api/accounts/{$state->account}/characters/hutao/build", ['build_id' => $c2])
        ->status(200);

    $accounts = T::$app->get(Accounts::class);
    $build = T::$app->make(CharacterAnalyzer::class)->overview($accounts->findOwned(1, $state->account))[0]['build'];
    same([$c2, true, false, 2], [$build['build_id'], $build['chosen'], $build['applies'], $build['min_rank']], 'chosen, not reached, and said so');
    same([$state->build, $c2], [$detail()['best_build_id'], $detail()['chosen_build_id']], 'the best is still the one she reaches');

    // What an import or an editor does to a build: it goes, and the character is judged by the closest match again.
    db()->delete('builds', ['id' => $c2]);
    same(null, db()->value('SELECT chosen_build_id FROM account_characters WHERE account_id = ? AND game_character_id = ?', [$state->account, $state->character]), 'the choice went with the build');
    $result = $detail();
    same([null, $state->build], [$result['chosen_build_id'], $result['focus_build_id']], 'the closest match again');
});

test('choosing a build: only one of the character\'s own, and only for a character the account has', function () use ($state) {
    $kazuha = idOf('game_characters', 'kaedeharakazuha');
    $kazuhas = seedBuild($kazuha, ['role' => 'support', 'content_hash' => 'y']);
    $client = (new Client())->asIam(1);
    $url = "/api/accounts/{$state->account}/characters/hutao/build";
    $chosen = fn() => db()->value('SELECT chosen_build_id FROM account_characters WHERE account_id = ? AND game_character_id = ?', [$state->account, $state->character]);

    $client
        ->put($url, ['build_id' => $kazuhas])
        ->status(422)
        ->path('code', 'invalid');
    $client
        ->put($url, ['build_id' => 999999])
        ->status(422)
        ->path('code', 'invalid');
    $client
        ->put($url, ['build_id' => 'main_dps'])
        ->status(422)
        ->path('code', 'invalid');
    $client
        ->put($url, ['build_id' => ''])
        ->status(422)
        ->path('code', 'invalid');
    $client->put($url, [])->status(422)->path('code', 'invalid');
    same(null, $chosen(), 'nothing chosen');

    // Kazuha has builds, but the account has no Kazuha.
    $client->put("/api/accounts/{$state->account}/characters/kaedeharakazuha/build", ['build_id' => $kazuhas])->status(404);
    $client
        ->put($url, ['build_id' => (string) $state->build])
        ->status(200)
        ->path('chosen_build_id', $state->build);
    same($state->build, (int) $chosen(), 'a number written as text is a number too');
});

test('stat targets by constellation: of a stat\'s targets, the one from the highest constellation reached', function () use ($detail, $state) {
    db()->run('UPDATE builds SET stat_targets = ? WHERE id = ?', [
        Json::encode([
            ['stat' => 'enerRech_', 'min' => 260, 'max' => 280, 'text' => '', 'from_rank' => 0],
            ['stat' => 'critRate_', 'min' => 70, 'max' => null, 'text' => 'CRIT Rate 70%', 'from_rank' => 0],
            ['stat' => 'enerRech_', 'min' => 200, 'max' => 220, 'text' => '', 'from_rank' => 1],
            ['stat' => 'critDMG_', 'min' => 250, 'max' => null, 'text' => '', 'from_rank' => 6],
        ]),
        $state->build,
    ]);
    $targets = fn() => array_map(fn($row) => [$row['stat'], $row['min'], $row['from_rank']], $detail()['evaluations'][0]['targets']);

    same([['enerRech_', 260, 0], ['critRate_', 70, 0]], $targets(), 'C0: the C0 targets');
    setRank($state, 1);
    same([['enerRech_', 200, 1], ['critRate_', 70, 0]], $targets(), 'C1: the C1 Energy Recharge, in the place the stat first had');
    setRank($state, 6);
    same([['enerRech_', 200, 1], ['critRate_', 70, 0], ['critDMG_', 250, 6]], $targets(), 'C6: and the C6 one');
    // In-game CRIT DMG 210% falls short of the C6 target; Energy Recharge has no in-game total here.
    same(
        ['stat' => 'CRIT DMG', 'stat_key' => 'critDMG_', 'current' => 210, 'min' => 250],
        recommendationOf($detail()['evaluations'][0], 'target_below', 'critDMG_')['params'],
        'the C6 target, recommended',
    );

    same([], BuildEvaluator::targetsFor([['stat' => 'hp', 'min' => 1, 'max' => null, 'text' => '', 'from_rank' => 3]], 2), 'a stat whose targets all start later has none');
    same(
        [['from_rank' => 0, 'stat' => 'hp', 'min' => 1, 'max' => null, 'text' => '']],
        BuildEvaluator::targetsFor([['stat' => 'hp', 'min' => 1, 'max' => null, 'text' => '']], 0),
        'a target without from_rank starts at C0',
    );
});

test('a build that lists no substats is scored by what its own main stats ask for, never by another build\'s', function () use ($detail, $state) {
    db()->run("UPDATE builds SET substats = '[]' WHERE id = ?", [$state->build]);
    // Another build of hers that does list substats - DEF% alone, which nothing of the first one asks for.
    anotherHuTaoBuild($state->build, ['role' => 'support', 'position' => 1, 'substats' => Json::encode([['stat' => 'def_', 'priority' => 1]])]);

    $weights = $detail()['evaluations'][0]['substat_weights'];
    check(!array_key_exists('def_', $weights), 'nothing borrowed from the other build: ' . json_encode($weights));
    // CRIT from the CRIT circlet, HP% and Elemental Mastery from the sands, Energy Recharge where no main stat has it.
    same(['critRate_', 'critDMG_', 'hp_', 'eleMas', 'enerRech_'], array_values(array_diff(array_keys($weights), ['hp'])), 'the stats its main stats ask for');
});

test(
    'stat targets in tiers: with the catalog\'s stats the rows carry three tiers for the weapon in hand, scored by the tier reached; a stat below the basic tier is a recommendation',
    function () use ($detail, $state) {
        // Hu Tao's and Dragon's Bane's stats, as a catalog refresh keeps them (the seed has none: the cases above saw
        // the stated targets alone).
        db()->update(
            'game_characters',
            [
                'meta' => Json::encode([
                    'stats' => [
                        'base' => ['hp' => 1210.72, 'atk' => 8.29, 'def' => 68.21],
                        'lv80' => ['hp' => 14459.03, 'atk' => 98.95, 'def' => 814.56],
                        'ascension' => ['stat' => 'critDMG_', 'value' => 38.4],
                    ],
                ]),
            ],
            ['key' => 'hutao'],
        );
        db()->update('game_weapons', ['meta' => Json::encode(['stats' => ['base_atk' => 41, 'lv80_atk' => 427, 'secondary' => ['stat' => 'eleMas', 'value' => 48]]])], ['key' => 'dragonsbane']);
        // In-game totals: CRIT DMG far below what the build reaches, HP a basic set's worth, no Elemental Mastery.
        db()->update(
            'account_characters',
            ['final_stats' => Json::encode(['critRate_' => 62.0, 'critDMG_' => 90.0, 'hp' => 29000])],
            ['account_id' => $state->account, 'game_character_id' => $state->character],
        );

        $evaluation = $detail()['evaluations'][0];
        same(
            ['weapon' => "Dragon's Bane", 'sets' => '4pc Crimson Witch of Flames', 'main_stats' => ['sands' => 'hp_', 'goblet' => 'pyro_dmg_', 'circlet' => 'critRate_']],
            $evaluation['targets_basis'],
            'the weapon in hand; the build\'s top main stats, nothing worn',
        );
        $rows = [];
        foreach ($evaluation['targets'] as $row) {
            $rows[$row['stat']] = $row;
        }
        same(['critRate_', 'critDMG_', 'eleMas', 'hp'], array_keys($rows), 'the stated CRIT Rate first, then the substats by priority, HP% as the sheet\'s HP');
        same(
            [null, 70, 62, false, null],
            [$rows['critRate_']['tiers'], $rows['critRate_']['min'], $rows['critRate_']['current'], $rows['critRate_']['ok'], $rows['critRate_']['tier']],
            'the requirement: untiered, unmet',
        );
        $tiers = $rows['critDMG_']['tiers'];
        check($tiers['basic'] > 88.4 && $tiers['basic'] < $tiers['intermediate'] && $tiers['intermediate'] < $tiers['advanced'], 'three rising tiers above the fixed sources: ' . json_encode($tiers));
        same([90, false, null], [$rows['critDMG_']['current'], $rows['critDMG_']['ok'], $rows['critDMG_']['tier']], 'CRIT DMG 90: below basic');
        same([null, null, null], [$rows['eleMas']['current'], $rows['eleMas']['ok'], $rows['eleMas']['tier']], 'no in-game Elemental Mastery: unscored');
        check(in_array($rows['hp']['tier'], ['basic', 'intermediate', 'advanced'], true) && $rows['hp']['ok'] === true, 'HP 29000 reaches a tier: ' . json_encode($rows['hp']));
        same(
            ['stat' => 'CRIT DMG', 'stat_key' => 'critDMG_', 'current' => 90, 'min' => $tiers['basic']],
            recommendationOf($evaluation, 'target_basic_below', 'critDMG_')['params'],
            'below basic: a recommendation naming the basic target',
        );
        $score = $evaluation['score']['parts']['targets'];
        check(is_int($score) && $score > 0 && $score < 100, "the part scored by the rows with totals: {$score}");

        // The tier scores themselves: TIER_SCORES at a tier, in proportion between two, below basic in proportion to it.
        $tiers = ['basic' => 100, 'intermediate' => 200, 'advanced' => 300];
        same(
            [[100, 'advanced'], [93, 'intermediate'], [75, 'basic'], [33, null]],
            [BuildEvaluator::tierScore(300, $tiers), BuildEvaluator::tierScore(250, $tiers), BuildEvaluator::tierScore(150, $tiers), BuildEvaluator::tierScore(50, $tiers)],
            'the tier scores',
        );
    },
);

test(
    'the totals worked out from the gear: a character without in-game totals has its stat targets checked against them, and the page gets them; the game\'s own stand first; none without the catalog\'s stats',
    function () use ($detail, $state) {
        // Hu Tao's and Dragon's Bane's stats as a catalog refresh keeps them, Crimson Witch's 2-piece from its words;
        // no in-game totals of her.
        db()->update(
            'game_characters',
            [
                'meta' => Json::encode([
                    'stats' => [
                        'base' => ['hp' => 1210.72, 'atk' => 8.29, 'def' => 68.21],
                        'lv80' => ['hp' => 14459.03, 'atk' => 98.95, 'def' => 814.56],
                        'ascension' => ['stat' => 'critDMG_', 'value' => 38.4],
                    ],
                ]),
            ],
            ['key' => 'hutao'],
        );
        db()->update('game_weapons', ['meta' => Json::encode(['stats' => ['base_atk' => 41, 'lv80_atk' => 427, 'secondary' => ['stat' => 'eleMas', 'value' => 48]]])], ['key' => 'dragonsbane']);
        db()->update('game_sets', ['meta' => Json::encode(['bonuses' => ['Pyro DMG Bonus +15%', 'Something conditional.']])], ['key' => 'crimsonwitchofflames']);
        $owned = ['account_id' => $state->account, 'game_character_id' => $state->character];
        db()->update('account_characters', ['final_stats' => null], $owned);

        $result = $detail();
        $totals = $result['character']['computed_stats'];
        same([null, null], [$result['character']['final_stats'], $result['character']['final_stats_source']], 'no in-game totals');
        // Hu Tao at 90 with Dragon's Bane at 90: HP 15552 × (1 + the sands' 46.6 %) + the flower's 4780; ATK (106 + 454) × (1 +
        // the goblet's 46.6 %) + the plume's 311 + the circlet's 30 flat; CRIT Rate 5 + the circlet's 31.1 + four pieces' 7 each;
        // CRIT DMG 50 + 38.4 + four pieces' 14 each; Elemental Mastery the weapon's 220.5; Pyro DMG the 2-piece's 15; DEF 876
        // + 40 flat.
        same(['hp', 'atk', 'def', 'eleMas', 'critRate_', 'critDMG_', 'enerRech_', 'heal_', 'physical_dmg_', 'pyro_dmg_'], array_keys($totals), 'the sheet\'s keys, as a showcase\'s');
        check(abs($totals['hp'] - (15552.1 * 1.466 + 4780)) <= 2, "HP {$totals['hp']}");
        check(abs($totals['atk'] - ((106.4 + 454) * 1.466 + 311 + 30)) <= 2, "ATK {$totals['atk']}");
        check(abs($totals['def'] - (876.1 + 40)) <= 2, "DEF {$totals['def']}");
        same(
            [64.1, 144.4, 221.0, 100.0, 15.0],
            [$totals['critRate_'], $totals['critDMG_'], $totals['eleMas'], $totals['enerRech_'], $totals['pyro_dmg_']],
            'CRIT Rate, CRIT DMG, Elemental Mastery, Energy Recharge, Pyro DMG',
        );

        $evaluation = $result['evaluations'][0];
        $rows = [];
        foreach ($evaluation['targets'] as $row) {
            $rows[$row['stat']] = $row;
        }
        same([64.1, false], [$rows['critRate_']['current'], $rows['critRate_']['ok']], 'the requirement of 70 checked against the total worked out');
        same([144.4, $totals['hp']], [$rows['critDMG_']['current'], $rows['hp']['current']], 'CRIT DMG and HP too');
        check(is_int($evaluation['score']['parts']['targets']), 'the part scored: ' . json_encode($evaluation['score']['parts']['targets']));

        // The game's own totals, where there are some, stand first: none worked out beside them.
        db()->update('account_characters', ['final_stats' => Json::encode(['critRate_' => 62.0])], $owned);
        $result = $detail();
        same([null, 62.0], [$result['character']['computed_stats'], (float) $result['evaluations'][0]['targets'][0]['current']], 'the showcase\'s totals, none worked out');

        // Without the catalog's stats of the character: nothing worked out, the rows unscored as before.
        db()->update('account_characters', ['final_stats' => null], $owned);
        db()->update('game_characters', ['meta' => '{}'], ['key' => 'hutao']);
        $result = $detail();
        same([null, null], [$result['character']['computed_stats'], $result['evaluations'][0]['score']['parts']['targets']], 'no stats of her: no totals, the part unscored');
    },
);

test('every kind of recommendation is one the cases above produce', function () use ($state) {
    // checkKinds() held every one of them to KINDS[kind]; a kind missing here is one no data of this file reaches.
    same([], array_values(array_diff(array_keys(BuildEvaluator::KINDS), array_keys($state->seen))), 'kinds no case produced');
    same(19, count(BuildEvaluator::KINDS), 'the kinds');

    // A kind is a key's tail and a parameter a placeholder: the shapes both must keep.
    foreach (BuildEvaluator::KINDS as $kind => $params) {
        check(preg_match('/^[a-z]+(_[a-z]+)*$/D', $kind) === 1, "the kind {$kind}");
        check($params !== [] && array_is_list($params) && count(array_unique($params)) === count($params), "the params of {$kind} are a list of different names");
        foreach ($params as $name) {
            check(preg_match('/^[a-z]+(_[a-z]+)*$/D', $name) === 1, "{$kind}.{$name}");
        }
    }
});

finishTests();
