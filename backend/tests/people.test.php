<?php

/**
 * The People page's routes (ADMIN): the people who have used the site with how much each has, and deleting
 * a person's data - their accounts with everything imported into them and their import runs - while the
 * syncs they started and the strings they saved stay, by nobody, and everybody else's data stays as it is.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Auth\Role;
use App\Support\Clock;
use Tests\Support\Client;

bootTests();

const ADMIN_ID = 1;

function admin(): Client
{
    return (new Client)->asIam(ADMIN_ID, [Role::ADMIN], ['preferred_username' => 'owner']);
}

/** A person with an account (two characters' worth of rows) and an import run - and a failed run without an account. */
function personWithData(int $id, string $name, string $uid): int
{
    seedUser($id, $name);
    $account = seedAccount($id, 'genshin', ['uid' => $uid, 'nickname' => $name]);
    $now = Clock::now();
    db()->insert('account_characters', ['account_id' => $account, 'game_character_id' => idOf('game_characters', 'hutao'), 'level' => 90, 'source' => 'good', 'created_at' => $now, 'updated_at' => $now]);
    db()->insert('import_runs', ['user_id' => $id, 'account_id' => $account, 'game' => 'genshin', 'source' => 'good', 'status' => 'success', 'created_at' => $now, 'updated_at' => $now]);
    db()->insert('import_runs', ['user_id' => $id, 'account_id' => null, 'game' => 'genshin', 'source' => 'file', 'status' => 'failed', 'created_at' => $now, 'updated_at' => $now]);

    return $account;
}

beforeEach(function (): void {
    seedGenshinCatalog();
});

test('the list: every person the IAM signed in here, with their accounts and imports - visitors counted apart', function () {
    personWithData(7, 'mona', '700000007');
    personWithData(8, 'Albedo', '700000008');
    seedAccount(8, 'genshin', ['uid' => '700000018', 'nickname' => 'Second']);

    $list = admin()->get('/api/admin/people')->status(200);
    same(['people', 'visitors'], array_keys($list->json()), 'the answer');
    same([[8, 'Albedo', 2, 2], [7, 'mona', 1, 2], [ADMIN_ID, 'owner', 0, 0]],
        array_map(fn ($p) => [$p['id'], $p['name'], $p['accounts'], $p['import_runs']], $list->json('people')), 'by name, with counts');
    same(['id', 'name', 'accounts', 'import_runs', 'created_at', 'last_import_at'], array_keys($list->json('people.0')), 'each person');
    check(is_string($list->json('people.0.last_import_at')) && is_string($list->json('people.0.created_at')), 'times: '.$list->body);
    same(null, $list->json('people.2.last_import_at'), 'no import yet');
    same(['count' => 0, 'accounts' => 0], $list->json('visitors'), 'no visitor');
});

test('deleting a person\'s data: their accounts, rows and runs go; what they did for the site stays, by nobody', function () {
    $mona = personWithData(7, 'mona', '700000007');
    $albedo = personWithData(8, 'albedo', '700000008');
    (new Client)->get('/api/sources')->status(200); // the sources' rows, as the Sources page makes them
    $source = (int) db()->value("SELECT min(id) FROM build_sources WHERE game = 'genshin'");
    $run = (int) db()->insert('sync_runs', ['build_source_id' => $source, 'kind' => 'builds', 'game' => 'genshin', 'status' => 'success',
        'started_by' => 7, 'started_at' => Clock::now(), 'finished_at' => Clock::now()]);
    db()->run("INSERT INTO translation_keys (name) VALUES ('test.people')");
    db()->run("INSERT INTO translations (key_name, language_code, value, updated_by) VALUES ('test.people', 'sk', 'Ľudia', 7), ('test.people', 'en', 'People', 8)");
    $others = fn () => [
        db()->all('SELECT id, uid FROM accounts WHERE user_id = 8'),
        (int) db()->value('SELECT count(*) FROM account_characters WHERE account_id = ?', [$albedo]),
        (int) db()->value('SELECT count(*) FROM import_runs WHERE user_id = 8'),
    ];
    $before = $others();

    try {
        same(['accounts' => 1, 'import_runs' => 2], admin()->delete('/api/admin/people/7')->status(200)->json(), 'what went');

        same(null, db()->value('SELECT id FROM users WHERE id = 7'), 'the row');
        same(0, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = 7'), 'the accounts');
        same(0, (int) db()->value('SELECT count(*) FROM account_characters WHERE account_id = ?', [$mona]), 'their rows');
        same(0, (int) db()->value('SELECT count(*) FROM import_runs WHERE user_id = 7'), 'the runs, a failed one too');
        same([$run, null], [(int) db()->value('SELECT id FROM sync_runs WHERE id = ?', [$run]), db()->value('SELECT started_by FROM sync_runs WHERE id = ?', [$run])], 'the sync stays, started by nobody');
        same(['Ľudia', null], [db()->value("SELECT value FROM translations WHERE key_name = 'test.people' AND language_code = 'sk'"),
            db()->value("SELECT updated_by FROM translations WHERE key_name = 'test.people' AND language_code = 'sk'")], 'the string stays, saved by nobody');
        same(8, (int) db()->value("SELECT updated_by FROM translations WHERE key_name = 'test.people' AND language_code = 'en'"), 'another person\'s string as it was');
        same($before, $others(), 'another person\'s data as it was');

        // Gone is gone; signing in again starts afresh, with nothing.
        admin()->delete('/api/admin/people/7')->status(404)->path('code', 'not_found');
        same('[]', (new Client)->asIam(7, [], ['preferred_username' => 'mona'])->get('/api/accounts')->status(200)->body, 'back, with nothing');
    } finally {
        db()->run("DELETE FROM translation_keys WHERE name = 'test.people'");
    }
});

test('an admin may delete their own data, and nobody else\'s id is touched by a wrong one', function () {
    personWithData(ADMIN_ID, 'owner', '700000001');
    personWithData(8, 'albedo', '700000008');

    admin()->delete('/api/admin/people/999')->status(404);
    admin()->delete('/api/admin/people/0')->status(404);
    same(2, (int) db()->value('SELECT count(*) FROM accounts'), 'nothing went');

    same(['accounts' => 1, 'import_runs' => 2], admin()->delete('/api/admin/people/'.ADMIN_ID)->status(200)->json(), 'their own');
    same([8], array_map('intval', db()->column('SELECT user_id FROM accounts')), 'the other person\'s stays');
    // The next request signs them in again, an admin as before.
    admin()->get('/api/admin/people')->status(200);
});

test('only an admin: an editor and a person with no role are refused, and signed out asks for sign-in', function () {
    personWithData(7, 'mona', '700000007');

    foreach ([['GET', '/api/admin/people'], ['DELETE', '/api/admin/people/7']] as [$method, $url]) {
        (new Client)->request($method, $url)->status(401)->path('code', 'sign_in_required');
        (new Client)->asIam(7, [])->request($method, $url)->status(403)->path('code', 'admin_only');
        (new Client)->asIam(4, [Role::EDITOR])->request($method, $url)->status(403)->path('code', 'admin_only');
    }
    same(1, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = 7'), 'nothing went');
});

test('a visitor is counted, never listed, and never deleted here (the 30-day clean-up takes it); its key opens nothing here', function () {
    personWithData(7, 'mona', '700000007');
    $file = json_encode(['format' => 'GOOD', 'version' => 2, 'source' => 'Some Exporter', 'characters' => [
        ['key' => 'HuTao', 'level' => 80, 'constellation' => 0, 'ascension' => 5, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]],
    ], 'weapons' => [], 'artifacts' => []]);
    $key = (new Client)->post('/api/imports/file', ['content' => $file])->status(201)->header('X-Visitor-Key');
    $visitor = (int) db()->value('SELECT id FROM users WHERE id < 0');

    $list = admin()->get('/api/admin/people')->status(200);
    same(['count' => 1, 'accounts' => 1], $list->json('visitors'), 'the visitor, counted');
    same([7, ADMIN_ID], array_column($list->json('people'), 'id'), 'and not listed');

    admin()->delete('/api/admin/people/'.$visitor)->status(404)->path('code', 'not_found');
    admin()->delete('/api/admin/people/'.rawurlencode((string) $visitor))->status(404)->path('code', 'not_found');
    same($visitor, (int) db()->value('SELECT id FROM users WHERE id < 0'), 'the visitor is still there');

    (new Client)->withHeaders(['X-Visitor-Key' => $key])->get('/api/admin/people')->status(401)->path('code', 'sign_in_required');
});

finishTests();
