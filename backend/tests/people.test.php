<?php

/**
 * The people - the anotoki library's people module on this site's users (App\People\SitePeople): the People
 * page's routes (ADMIN) - the people who have used the site with how much each has, found by a name or an id,
 * a page at a time, and deleting a person's data (their accounts with everything imported into them and their
 * import runs, while the syncs they started and the strings they saved stay, by nobody, and everybody else's
 * data stays as it is - all of it on the connection the library's transaction is on, as a restore does it again)
 * - each person's row kept from the token (the library's RecordPerson), and the scrub of
 * the accounts the IAM deleted: their rows nobody's, a sync's log naming "a deleted person", the copies the
 * admin tools keep of them blanked, their data kept.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\People\People;
use App\Auth\Role;
use App\Db\Db;
use App\People\SitePeople;
use App\Support\Clock;
use Tests\Support\Client;

bootTests();

const ADMIN_ID = 1;

function admin(): Client
{
    return (new Client())->asIam(ADMIN_ID, [Role::ADMIN], ['preferred_username' => 'owner']);
}

/** A person with an account (two characters' worth of rows) and an import run - and a failed run without an account. */
function personWithData(int $id, string $name, string $uid, ?string $seen = null): int
{
    seedUser($id, $name);
    if ($seen !== null) {
        db()->run('UPDATE users SET last_seen_at = ? WHERE id = ?', [$seen, $id]);
    }
    $account = seedAccount($id, 'genshin', ['uid' => $uid, 'nickname' => $name]);
    $now = Clock::now();
    db()->insert('account_characters', [
        'account_id' => $account,
        'game_character_id' => idOf('game_characters', 'hutao'),
        'level' => 90,
        'source' => 'good',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    db()->insert('import_runs', [
        'user_id' => $id,
        'account_id' => $account,
        'game' => 'genshin',
        'source' => 'good',
        'status' => 'success',
        'created_at' => '2026-10-01 10:00:00+00',
        'updated_at' => $now,
    ]);
    db()->insert('import_runs', [
        'user_id' => $id,
        'account_id' => null,
        'game' => 'genshin',
        'source' => 'file',
        'status' => 'failed',
        'created_at' => '2026-10-02 11:30:00+00',
        'updated_at' => $now,
    ]);

    return $account;
}

/** The audit log's entries of an action, oldest first: who, what about, and the details (their keys in order: JSONB keeps its own). */
function audited(string $action): array
{
    $sorted = static function (mixed $value) use (&$sorted): mixed {
        if (is_array($value)) {
            ksort($value);

            return array_map($sorted, $value);
        }

        return $value;
    };

    return array_map(
        static fn(array $row): array => [
            'actor' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
            'target' => [$row['target_type'], $row['target_id']],
            'details' => $sorted(json_decode((string) $row['details'], true)),
        ],
        db()->all('SELECT actor_id, target_type, target_id, details FROM anotoki_audit_log WHERE action = ? ORDER BY id', [$action]),
    );
}

beforeEach(function (): void {
    seedGenshinCatalog();
});

test('the list: every person the IAM signed in here, the most recently seen first, with their accounts and imports - visitors counted apart', function () {
    personWithData(7, 'mona', '700000007', '2026-10-01 09:00:00+00');
    personWithData(8, 'Albedo', '700000008', '2026-10-02 09:00:00+00');
    seedAccount(8, 'genshin', ['uid' => '700000018', 'nickname' => 'Second']);
    seedUser(9, 'never seen');

    $list = admin()->get('/api/admin/people')->status(200);
    same(['people', 'columns', 'total', 'page', 'page_size', 'visitors', 'sync', 'ready', 'problems'], array_keys($list->json()), 'the answer');
    // The admin was seen just now (their own request: the library's RecordPerson), then Albedo, then mona; then
    // whoever was never seen, the newest first.
    same(
        [[(string) ADMIN_ID, 'owner', 0, 0], ['8', 'Albedo', 2, 2], ['7', 'mona', 1, 2], ['9', 'never seen', 0, 0]],
        array_map(fn($p) => [$p['sub'], $p['name'], $p['accounts'], $p['imports']], $list->json('people')),
        'newest seen first, with counts',
    );
    same(['sub', 'name', 'first_seen_at', 'last_seen_at', 'anonymized_at', 'iam_url', 'accounts', 'imports', 'last_import'], array_keys($list->json('people.1')), 'each person');
    same(
        ['2026-10-02T09:00:00Z', null, TEST_ISSUER . '/admin/users/8', '2026-10-02T11:30:00Z'],
        [$list->json('people.1.last_seen_at'), $list->json('people.1.anonymized_at'), $list->json('people.1.iam_url'), $list->json('people.1.last_import')],
        'times in UTC, the account at the IAM, the last import',
    );
    same(null, $list->json('people.3.last_import'), 'no import yet');
    same(
        [
            ['key' => 'accounts', 'label' => 'Game accounts', 'kind' => 'count'],
            ['key' => 'imports', 'label' => 'Imports', 'kind' => 'count'],
            ['key' => 'last_import', 'label' => 'Last import', 'kind' => 'time'],
        ],
        $list->json('columns'),
        'the site\'s own columns',
    );
    same(
        [4, 1, 50, ['count' => 0, 'accounts' => 0], true, []],
        [$list->json('total'), $list->json('page'), $list->json('page_size'), $list->json('visitors'), $list->json('ready'), $list->json('problems')],
        'how many, the page, no visitor, nothing missing',
    );
    same(['since' => null, 'checked_at' => null, 'ok_at' => null, 'error' => null, 'scrubbed' => 0], $list->json('sync'), 'the scrub never ran');
});

test('a search finds a name (any case, anywhere in it) or an IAM id; fifty a page', function () {
    personWithData(7, 'Mona Megistus', '700000007');
    personWithData(8, 'Albedo', '700000008');
    $find = fn(string $q): array => array_column(
        admin()
            ->get('/api/admin/people?q=' . rawurlencode($q))
            ->status(200)
            ->json('people'),
        'sub',
    );
    same(['7'], $find('megist'), 'a part of a name, any case');
    same(['8'], $find('8'), 'an id');
    same([], $find('nobody here'), 'nobody');
    admin()
        ->get('/api/admin/people?q=' . rawurlencode("\xff"))
        ->status(422)
        ->path('code', 'invalid_query');
    admin()
        ->get('/api/admin/people?q=' . str_repeat('a', 201))
        ->status(422)
        ->path('code', 'invalid_query');

    foreach (range(100, 159) as $id) {
        seedUser($id, "player {$id}");
    }
    $first = admin()->get('/api/admin/people')->status(200);
    same([63, 50], [$first->json('total'), count($first->json('people'))], 'the first page');
    same(13, count(admin()->get('/api/admin/people?page=2')->status(200)->json('people')), 'the second page');
});

test('deleting a person\'s data: their accounts, rows and runs go; what they did for the site stays, by nobody - and it is in the audit log', function () {
    $mona = personWithData(7, 'mona', '700000007');
    $albedo = personWithData(8, 'albedo', '700000008');
    (new Client())->get('/api/sources')->status(200); // the sources' rows, as the Sources page makes them
    $source = (int) db()->value("SELECT min(id) FROM build_sources WHERE game = 'genshin'");
    $run = (int) db()->insert('sync_runs', [
        'build_source_id' => $source,
        'kind' => 'builds',
        'game' => 'genshin',
        'status' => 'success',
        'started_by' => 7,
        'started_at' => Clock::now(),
        'finished_at' => Clock::now(),
        'log' => "Started by mona.\nCancelled by mona after 1 of 3.",
    ]);
    $albedosRun = (int) db()->insert('sync_runs', [
        'build_source_id' => $source,
        'kind' => 'builds',
        'game' => 'genshin',
        'status' => 'success',
        'started_by' => 8,
        'started_at' => Clock::now(),
        'finished_at' => Clock::now(),
        'log' => "Started by albedo.\nDone.",
    ]);
    // What they sent through the feedback form: its words are the site's, the address and browser theirs.
    db()->run("INSERT INTO anotoki_feedback (kind, message, contact_email, user_id, user_agent) VALUES
        ('bug', 'From mona', 'mona@example.test', 7, 'Mona''s browser'), ('idea', 'From albedo', 'albedo@example.test', 8, 'Albedo''s browser')");
    db()->run("INSERT INTO translation_keys (name) VALUES ('test.people')");
    db()->run("INSERT INTO translations (key_name, language_code, value, updated_by) VALUES ('test.people', 'sk', 'Ľudia', 7), ('test.people', 'en', 'People', 8)");
    $others = fn() => [
        db()->all('SELECT id, uid FROM accounts WHERE user_id = 8'),
        (int) db()->value('SELECT count(*) FROM account_characters WHERE account_id = ?', [$albedo]),
        (int) db()->value('SELECT count(*) FROM import_runs WHERE user_id = 8'),
    ];
    $before = $others();

    try {
        same(['deleted' => ['Game accounts' => 1, 'Imports' => 2]], admin()->delete('/api/admin/people/7')->status(200)->json(), 'what went');

        same(null, db()->value('SELECT id FROM users WHERE id = 7'), 'the row');
        same(0, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = 7'), 'the accounts');
        same(0, (int) db()->value('SELECT count(*) FROM account_characters WHERE account_id = ?', [$mona]), 'their rows');
        same(0, (int) db()->value('SELECT count(*) FROM import_runs WHERE user_id = 7'), 'the runs, a failed one too');
        same(
            [$run, null],
            [(int) db()->value('SELECT id FROM sync_runs WHERE id = ?', [$run]), db()->value('SELECT started_by FROM sync_runs WHERE id = ?', [$run])],
            'the sync stays, started by nobody',
        );
        same(
            ["Started by a deleted person.\nCancelled by a deleted person after 1 of 3.", "Started by albedo.\nDone."],
            [db()->value('SELECT log FROM sync_runs WHERE id = ?', [$run]), db()->value('SELECT log FROM sync_runs WHERE id = ?', [$albedosRun])],
            'its log no longer names them (as the scrub writes it); another person\'s as it was',
        );
        same(
            [['From mona', null, null, 7], ['From albedo', 'albedo@example.test', 'Albedo\'s browser', 8]],
            array_map(
                fn($row) => [$row['message'], $row['contact_email'], $row['user_agent'], (int) $row['user_id']],
                db()->all('SELECT message, contact_email, user_agent, user_id FROM anotoki_feedback ORDER BY id'),
            ),
            'their feedback stays, without the address and browser it was sent with; another person\'s as it was',
        );
        same(
            ['Ľudia', null],
            [
                db()->value("SELECT value FROM translations WHERE key_name = 'test.people' AND language_code = 'sk'"),
                db()->value("SELECT updated_by FROM translations WHERE key_name = 'test.people' AND language_code = 'sk'"),
            ],
            'the string stays, saved by nobody',
        );
        same(8, (int) db()->value("SELECT updated_by FROM translations WHERE key_name = 'test.people' AND language_code = 'en'"), 'another person\'s string as it was');
        same($before, $others(), 'another person\'s data as it was');
        // The owner's decision of 2026-10-11: the page's question is all it asks - and every delete is recorded, in the
        // delete's own transaction, which the entry names (_txid: a restore matches it to its backup by it).
        $entries = audited('person.data_deleted');
        check(preg_match('/^[0-9]+$/', (string) ($entries[0]['details']['_txid'] ?? '')) === 1, 'the entry names its transaction: ' . json_encode($entries));
        unset($entries[0]['details']['_txid']);
        same([['actor' => ADMIN_ID, 'target' => ['user', '7'], 'details' => ['deleted' => ['Game accounts' => 1, 'Imports' => 2]]]], $entries, 'in the audit log');

        // Gone is gone; signing in again starts afresh, with nothing.
        admin()->delete('/api/admin/people/7')->status(404)->path('code', 'not_found');
        same(1, count(audited('person.data_deleted')), 'a delete of nothing is not recorded');
        same(
            '[]',
            (new Client())
                ->asIam(7, [], ['preferred_username' => 'mona'])
                ->get('/api/accounts')
                ->status(200)->body,
            'back, with nothing',
        );
    } finally {
        db()->run("DELETE FROM translation_keys WHERE name = 'test.people'");
    }
});

test('an admin may delete their own data, and nobody else\'s id is touched by a wrong one', function () {
    personWithData(ADMIN_ID, 'owner', '700000001');
    personWithData(8, 'albedo', '700000008');

    foreach (['999', '0', '-1', 'abc', '1.0', '01'] as $id) {
        admin()
            ->delete('/api/admin/people/' . $id)
            ->status(404)
            ->path('code', 'not_found');
    }
    same(2, (int) db()->value('SELECT count(*) FROM accounts'), 'nothing went');

    same(
        ['deleted' => ['Game accounts' => 1, 'Imports' => 2]],
        admin()
            ->delete('/api/admin/people/' . ADMIN_ID)
            ->status(200)
            ->json(),
        'their own',
    );
    same([8], array_map('intval', db()->column('SELECT user_id FROM accounts')), 'the other person\'s stays');
    // The next request signs them in again, an admin as before.
    admin()->get('/api/admin/people')->status(200);
});

test('the delete does all its work on the connection it is given, inside its transaction - as a restore runs it, every table locked', function () {
    personWithData(7, 'mona', '700000007');
    personWithData(8, 'albedo', '700000008');
    (new Client())->get('/api/sources')->status(200);
    $source = (int) db()->value("SELECT min(id) FROM build_sources WHERE game = 'genshin'");
    $run = (int) db()->insert('sync_runs', [
        'build_source_id' => $source,
        'kind' => 'builds',
        'game' => 'genshin',
        'status' => 'success',
        'started_by' => 7,
        'started_at' => Clock::now(),
        'finished_at' => Clock::now(),
        'log' => 'Started by mona.',
    ]);
    db()->run("INSERT INTO anotoki_feedback (kind, message, contact_email, user_id, user_agent) VALUES ('bug', 'From mona', 'mona@example.test', 7, 'Firefox')");
    db()->run("INSERT INTO translation_keys (name) VALUES ('test.people')");
    db()->run("INSERT INTO translations (key_name, language_code, value, updated_by) VALUES ('test.people', 'sk', 'Ľudia', 7)");

    // Another connection, holding every table the delete touches as a restore holds the tables it put back (ACCESS
    // EXCLUSIVE, until it commits): a hook that wrote on the site's own connection would wait on those locks while
    // the restore waits on it - here for two seconds, then it fails; in a restore, until PostgreSQL ends it.
    $restore = Db::pdo((array) T::$app->config->get('database'), T::$database, false);
    $restore->beginTransaction();
    $restore->exec('LOCK TABLE users, accounts, account_characters, account_weapons, account_items, import_runs, sync_runs,
        translations, anotoki_feedback IN ACCESS EXCLUSIVE MODE');
    db()->run("SET lock_timeout = '2s'");
    try {
        $deleted = T::$app->get(People::class)->delete($restore, 7, SitePeople::deleteHook());
        same(['deleted' => ['Game accounts' => 1, 'Imports' => 2]], $deleted, 'what went, all of it on the locked connection');
        check($restore->inTransaction(), 'the transaction it was given, still open: it began, committed and rolled back none');
        $restore->commit();
    } finally {
        if ($restore->inTransaction()) {
            $restore->rollBack();
        }
        db()->run('SET lock_timeout = 0');
        db()->run("DELETE FROM translation_keys WHERE name = 'test.people'");
    }

    same(
        [null, 0, 0],
        [
            db()->value('SELECT id FROM users WHERE id = 7'),
            (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = 7'),
            (int) db()->value('SELECT count(*) FROM import_runs WHERE user_id = 7'),
        ],
        'the person, their accounts and imports',
    );
    same(
        ['Started by a deleted person.', null],
        [db()->value('SELECT log FROM sync_runs WHERE id = ?', [$run]), db()->value('SELECT started_by FROM sync_runs WHERE id = ?', [$run])],
        'the sync stays, by nobody, its log no longer naming them',
    );
    same(
        [null, null, 'From mona'],
        array_values(db()->one('SELECT contact_email, user_agent, message FROM anotoki_feedback') ?? []),
        'their feedback forgotten in the same transaction (the library\'s), its words kept',
    );
    same(1, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = 8'), 'nobody else\'s');
    same([], audited('person.data_deleted'), 'no entry: the route writes it, a replay none');
});

test('only an admin: an editor and a person with no role are refused, and signed out asks for sign-in', function () {
    personWithData(7, 'mona', '700000007');

    foreach ([['GET', '/api/admin/people'], ['DELETE', '/api/admin/people/7'], ['POST', '/api/admin/people/sync']] as [$method, $url]) {
        (new Client())->request($method, $url)->status(401)->path('code', 'sign_in_required');
        (new Client())->asIam(7, [])->request($method, $url)->status(403)->path('code', 'admin_only');
        (new Client())
            ->asIam(7, ['MEMBER'])
            ->request($method, $url)
            ->status(403)
            ->path('code', 'admin_only');
        (new Client())
            ->asIam(4, [Role::EDITOR])
            ->request($method, $url)
            ->status(403)
            ->path('code', 'admin_only');
        (new Client())->withToken('x')->request($method, $url)->status(401)->path('code', 'invalid_token');
    }
    same(1, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = 7'), 'nothing went');
    same([], audited('person.data_deleted'), 'nothing recorded');
});

test('a visitor is counted, never listed, and never deleted here (the 30-day clean-up takes it); its key opens nothing here', function () {
    personWithData(7, 'mona', '700000007');
    $file = json_encode([
        'format' => 'GOOD',
        'version' => 2,
        'source' => 'Some Exporter',
        'characters' => [['key' => 'HuTao', 'level' => 80, 'constellation' => 0, 'ascension' => 5, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]]],
        'weapons' => [],
        'artifacts' => [],
    ]);
    $key = (new Client())
        ->post('/api/imports/file', ['content' => $file])
        ->status(201)
        ->header('X-Visitor-Key');
    $visitor = (int) db()->value('SELECT id FROM users WHERE id < 0');

    $list = admin()->get('/api/admin/people')->status(200);
    same(['count' => 1, 'accounts' => 1], $list->json('visitors'), 'the visitor, counted');
    same([(string) ADMIN_ID, '7'], array_column($list->json('people'), 'sub'), 'and not listed');

    admin()
        ->delete('/api/admin/people/' . $visitor)
        ->status(404)
        ->path('code', 'not_found');
    admin()
        ->delete('/api/admin/people/' . rawurlencode((string) $visitor))
        ->status(404)
        ->path('code', 'not_found');
    same($visitor, (int) db()->value('SELECT id FROM users WHERE id < 0'), 'the visitor is still there');

    (new Client())
        ->withHeaders(['X-Visitor-Key' => $key])
        ->get('/api/admin/people')
        ->status(401)
        ->path('code', 'sign_in_required');
});

test('each person\'s row as their token says: made on a first request, the name kept to the token\'s, seen - at most every five minutes', function () {
    (new Client())
        ->asIam(7, [], ['preferred_username' => 'mona'])
        ->get('/api/accounts')
        ->status(200);
    $row = fn(): array => db()->one('SELECT name, created_at, updated_at, last_seen_at FROM users WHERE id = 7') ?? [];
    $first = $row();
    same('mona', $first['name'], 'made, named');
    check($first['last_seen_at'] !== null, 'seen');

    // Within five minutes, and the same name: nothing is written.
    (new Client())
        ->asIam(7, [], ['preferred_username' => 'mona'])
        ->get('/api/accounts')
        ->status(200);
    same($first, $row(), 'unchanged');
    // Another name in the token: kept to it.
    (new Client())
        ->asIam(7, [], ['preferred_username' => 'mona_megistus'])
        ->get('/api/accounts')
        ->status(200);
    same('mona_megistus', $row()['name'], 'renamed');
    // A token without a name keeps the stored one.
    (new Client())
        ->asIam(7, [], ['preferred_username' => null])
        ->get('/api/accounts')
        ->status(200);
    same('mona_megistus', $row()['name'], 'the name kept');
    // Five minutes later, seen again.
    db()->run("UPDATE users SET last_seen_at = last_seen_at - interval '6 minutes' WHERE id = 7");
    $old = $row()['last_seen_at'];
    (new Client())
        ->asIam(7, [], ['preferred_username' => 'mona_megistus'])
        ->get('/api/builds/genshin')
        ->status(200);
    check($row()['last_seen_at'] !== $old, 'seen again: ' . $row()['last_seen_at']);
    // A new person without a name in the token: "user {id}".
    (new Client())
        ->asIam(9, [], ['preferred_username' => null])
        ->get('/api/accounts')
        ->status(200);
    same('user 9', db()->value('SELECT name FROM users WHERE id = 9'), 'the fallback name');
    // Never a row for nobody, a visitor or a token that does not verify.
    (new Client())->withToken('x')->get('/api/accounts')->status(401);
    same(['7', '9'], db()->column('SELECT id::text FROM users ORDER BY id'), 'two people');
});

// ── The scrub: the accounts the IAM deleted ───────────────────────────────────────────────────────

test('the scrub: an account the IAM deleted is nobody\'s here - its name, its sync log lines, the admin tools\' copies - and its data stays', function () {
    $mona = personWithData(7, 'mona', '700000007');
    personWithData(8, 'albedo', '700000008');
    (new Client())->get('/api/sources')->status(200);
    $source = (int) db()->value("SELECT min(id) FROM build_sources WHERE game = 'genshin'");
    $runs = [
        db()->insert('sync_runs', [
            'build_source_id' => $source,
            'kind' => 'builds',
            'game' => 'genshin',
            'status' => 'success',
            'started_by' => 7,
            'started_at' => Clock::now(),
            'finished_at' => Clock::now(),
            'log' => "Started by mona.\nDone: 3 builds.",
        ]),
        db()->insert('sync_runs', [
            'build_source_id' => $source,
            'kind' => 'builds',
            'game' => 'genshin',
            'status' => 'cancelled',
            'started_by' => 8,
            'started_at' => Clock::now(),
            'finished_at' => Clock::now(),
            'log' => "Started by albedo.\nCancelled by mona after 1 of 3.",
        ]),
        db()->insert('sync_runs', [
            'build_source_id' => $source,
            'kind' => 'builds',
            'game' => 'genshin',
            'status' => 'cancelled',
            'started_by' => 8,
            'started_at' => Clock::now(),
            'finished_at' => Clock::now(),
            'log' => "Started by albedo.\nCancelled by mona.",
        ]),
        db()->insert('sync_runs', [
            'build_source_id' => $source,
            'kind' => 'builds',
            'game' => 'genshin',
            'status' => 'success',
            'started_by' => 8,
            'started_at' => Clock::now(),
            'finished_at' => Clock::now(),
            'log' => "Started by monalisa.\nDone.",
        ]),
    ];
    // What the admin tools keep of them: a feedback message they sent (and one of Albedo's), an entry naming them.
    db()->run("INSERT INTO anotoki_feedback (kind, message, contact_email, user_id, user_agent) VALUES
        ('idea', 'More sources', 'mona@example.test', 7, 'Firefox'), ('bug', 'A typo', 'albedo@example.test', 8, 'Edge')");
    db()->run("INSERT INTO anotoki_audit_log (actor_id, action, target_type, target_id, details) VALUES
        (1, 'person.renamed', 'user', '7', '{\"from\": \"mona\", \"to\": \"mona2\", \"kept\": 1}')");

    T::$iam->anonymized(['7' => '2026-11-06T10:00:00Z', '99' => '2026-11-06T11:00:00Z']);
    $synced = admin()->post('/api/admin/people/sync')->status(200);
    same(['done', 2, 1, null], [$synced->json('run.state'), $synced->json('run.handled'), $synced->json('run.scrubbed'), $synced->json('run.error')], 'the run');
    same(['2026-11-06T11:00:00Z', 1, null], [$synced->json('sync.since'), $synced->json('sync.scrubbed'), $synced->json('sync.error')], 'where it stands');

    same(['deleted-7', '2026-11-06 10:00:00+00'], array_values(db()->one('SELECT name, anonymized_at FROM users WHERE id = 7') ?? []), 'the row: nobody\'s');
    same('albedo', db()->value('SELECT name FROM users WHERE id = 8'), 'nobody else\'s');
    same(
        [$mona, 1, 2],
        [
            (int) db()->value('SELECT id FROM accounts WHERE user_id = 7'),
            (int) db()->value('SELECT count(*) FROM account_characters WHERE account_id = ?', [$mona]),
            (int) db()->value('SELECT count(*) FROM import_runs WHERE user_id = 7'),
        ],
        'the data stays',
    );
    same(
        [
            "Started by a deleted person.\nDone: 3 builds.",
            "Started by albedo.\nCancelled by a deleted person after 1 of 3.",
            "Started by albedo.\nCancelled by a deleted person.",
            "Started by monalisa.\nDone.",
        ],
        array_map(fn($id) => db()->value('SELECT log FROM sync_runs WHERE id = ?', [$id]), $runs),
        'the syncs\' logs name a deleted person - and nobody else',
    );
    same(
        [[null, null, 'More sources'], ['albedo@example.test', 'Edge', 'A typo']],
        array_map('array_values', db()->all('SELECT contact_email, user_agent, message FROM anotoki_feedback ORDER BY id')),
        'their feedback\'s address and browser gone, the words kept',
    );
    same(['kept' => 1], json_decode((string) db()->value("SELECT details FROM anotoki_audit_log WHERE action = 'person.renamed'"), true), 'the names gone from an entry about them');
    same([['actor' => ADMIN_ID, 'target' => [null, null], 'details' => ['handled' => 2, 'scrubbed' => 1, 'state' => 'done']]], audited('people.synced'), 'the asked-for scrub is in the audit log');

    // The People page says so; the person's row is never written again from a token.
    $list = admin()->get('/api/admin/people?q=deleted-7')->status(200);
    same(['deleted-7', '2026-11-06T10:00:00Z'], [$list->json('people.0.name'), $list->json('people.0.anonymized_at')], 'deleted at the IAM');
    (new Client())
        ->asIam(7, [], ['preferred_username' => 'mona'])
        ->get('/api/accounts')
        ->status(200);
    same('deleted-7', db()->value('SELECT name FROM users WHERE id = 7'), 'never revived by a token');

    // Again: nothing more to do - and nothing is written twice.
    $again = admin()->post('/api/admin/people/sync')->status(200);
    same(['done', 0], [$again->json('run.state'), $again->json('run.scrubbed')], 'the second run');
    same("Started by a deleted person.\nDone: 3 builds.", db()->value('SELECT log FROM sync_runs WHERE id = ?', [$runs[0]]), 'the log as the first run left it');
});

test('the scrub after an answer: at most once an hour, while the site is ready, carried by ordinary traffic', function () {
    personWithData(7, 'mona', '700000007');
    T::$iam->anonymized(['7' => '2026-11-06T10:00:00Z']);
    $stamp = T::$storage . '/cache/iam/anotoki-people.stamp';

    (new Client())->get('/api/builds/genshin')->status(200);
    same('mona', db()->value('SELECT name FROM users WHERE id = 7'), 'switched off (config upkeep.people): nothing');
    same(false, is_file($stamp), 'no stamp');

    T::$app->config->set('upkeep.people', true);
    (new Client())->get('/api/builds/genshin')->status(200);
    same('deleted-7', db()->value('SELECT name FROM users WHERE id = 7'), 'the scrub ran after that answer');
    check(is_file($stamp), 'the hour claimed');

    T::$iam->anonymized(['7' => '2026-11-06T10:00:00Z', '8' => '2026-11-06T12:00:00Z']);
    personWithData(8, 'albedo', '700000008');
    (new Client())->get('/api/builds/genshin')->status(200);
    same('albedo', db()->value('SELECT name FROM users WHERE id = 8'), 'not again within the hour');
    touch($stamp, time() - 3601);
    (new Client())->get('/api/builds/genshin')->status(200);
    same('deleted-8', db()->value('SELECT name FROM users WHERE id = 8'), 'the hour after');
});

test('the scrub while the IAM does not answer: nothing changes, and the People page says why', function () {
    personWithData(7, 'mona', '700000007');
    T::$iam->anonymized(null);
    $run = admin()->post('/api/admin/people/sync')->status(200);
    same('unreachable', $run->json('run.state'), 'the IAM out of reach');
    check(is_string($run->json('sync.error')) && $run->json('sync.error') !== '', 'why, for the page: ' . $run->json('sync.error'));
    same('mona', db()->value('SELECT name FROM users WHERE id = 7'), 'nothing changed');
    check(is_string(admin()->get('/api/admin/people')->status(200)->json('sync.error')), 'the list says it too');
});

finishTests();
