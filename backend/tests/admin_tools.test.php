<?php

/**
 * The anotoki family's admin tools on this site (anotoki/lib 0.5, App\Admin\AdminTools; api/routes/admin_tools.php
 * and feedback.php): for the site's ADMIN alone - nobody, a person with no role, MEMBER and EDITOR are refused
 * every route, in this site's words - the error log (what failed, written without a body, a header, a query or a
 * token; an answer that says a state - an update or a table waiting - not written, a 503 that is a failure written;
 * the pages' own errors from the browser, scrubbed, capped), the audit log (what
 * was done in the admin panel), feedback (the visitors' form - who from the token alone, nothing of where it came
 * from kept, a robots' field, limits - and the admin side with its unread count, the panel's badge), backups
 * (made, listed with their makers' names, downloaded by a single-use ticket, deleted, restored - each behind a
 * sign-in of the last ten minutes -, sealed: only a backup made here restored here; a restore does again what was
 * deleted since; the day's after an answer), and where a request comes from as the tools read it (Cloudflare's
 * CF-Connecting-IP, the site's own rule).
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use Anotoki\Lib\ErrorLog\ErrorLog;
use Anotoki\Lib\Migrations\Migrator;
use App\Auth\Role;
use App\Db\Migrations;
use App\Support\Clock;
use Tests\Support\Answer;
use Tests\Support\Client;

bootTests();

const ADMIN = 1;
const EDITOR = 4;
const MEMBER = 7;
const SOME_BACKUP = '20261011-120000Z-manual-0123456789abcdef0123456789abcdef';
/** A restore's after_restore_error, in the library's fixed words (anotoki-lib 0.6.0): the error log says what failed. */
const AFTER_RESTORE_FAILED = 'What this site does after a restore did not all go through. The error log says what.';

function admin(array $claims = []): Client
{
    return (new Client)->asIam(ADMIN, [Role::ADMIN], ['preferred_username' => 'ada'] + $claims);
}

/** Every route of the admin tools - each the site's ADMIN's alone. */
const TOOL_ROUTES = [
    ['GET', '/api/admin/errors', null],
    ['GET', '/api/admin/errors/0123456789ab', null],
    ['DELETE', '/api/admin/errors', null],
    ['GET', '/api/admin/audit', null],
    ['GET', '/api/admin/audit/facets', null],
    ['GET', '/api/admin/feedback', null],
    ['GET', '/api/admin/feedback/facets', null],
    ['GET', '/api/admin/feedback/unread', null],
    ['GET', '/api/admin/feedback/1', null],
    ['PUT', '/api/admin/feedback/1/status', ['status' => 'read']],
    ['DELETE', '/api/admin/feedback/1', null],
    ['GET', '/api/admin/backups', null],
    ['GET', '/api/admin/backups/proof', null],
    ['POST', '/api/admin/backups', []],
    ['GET', '/api/admin/backups/'.SOME_BACKUP, null],
    ['DELETE', '/api/admin/backups/'.SOME_BACKUP, null],
    ['POST', '/api/admin/backups/'.SOME_BACKUP.'/ticket', []],
    ['GET', '/api/admin/backups/'.SOME_BACKUP.'/preview', null],
    ['POST', '/api/admin/backups/'.SOME_BACKUP.'/restore', ['confirm' => 'abcdef']],
];

/** The audit log's entries of an action, oldest first. */
function entries(string $action): array
{
    return db()->all('SELECT actor_id, target_type, target_id, details FROM anotoki_audit_log WHERE action = ? ORDER BY id', [$action]);
}

function feedback(array $body, ?Client $client = null, string $from = '198.51.100.1'): Answer
{
    return ($client ?? new Client($from))->post('/api/feedback', $body);
}

/** A write as the pages send it: its body a JSON object - {} when there is nothing in it (the test client sends [] for an empty array). */
function send(Client $client, string $method, string $url, array $body = []): Answer
{
    return $client->request($method, $url, null, ['body' => (string) json_encode((object) $body)]);
}

/** A route of TOOL_ROUTES as $client asks it. */
function ask(Client $client, string $method, string $url, ?array $body): Answer
{
    return $body === null ? $client->request($method, $url) : send($client, $method, $url, $body);
}

/** Every line of the error log's files, oldest first. */
function errorLines(): array
{
    $lines = [];
    foreach (glob(T::$storage.'/logs/errors-*.jsonl') ?: [] as $file) {
        foreach (preg_split('/\R/', trim((string) file_get_contents($file))) ?: [] as $line) {
            if ($line !== '') {
                $lines[] = json_decode($line, true);
            }
        }
    }

    return $lines;
}

/** $body while an update waits: the app's Migrator sees one more file of the site's on disk (as tests/site_gate.test.php). */
function whileAnUpdateWaits(callable $body): void
{
    $dir = T::$storage.DIRECTORY_SEPARATOR.'one-more';
    @mkdir($dir, 0777, true);
    foreach (glob(Migrations::directory().'/*.sql') ?: [] as $file) {
        copy($file, $dir.'/'.basename($file));
    }
    file_put_contents($dir.'/900_waiting.sql', 'CREATE TABLE waiting (id INT);');
    T::$app->set(Migrator::class, Migrations::migrator(db()->raw(), $dir));
    try {
        $body();
    } finally {
        T::$app->forget(Migrator::class);
        removeTree($dir);
    }
}

beforeEach(function (): void {
    removeTree(T::$storage.DIRECTORY_SEPARATOR.'logs');
    removeTree(T::$storage.DIRECTORY_SEPARATOR.'backups');
});

// ── Whose they are ───────────────────────────────────────────────────────────────────────────────

test('every route of the admin tools is the site\'s ADMIN\'s alone: nobody, no role, MEMBER and EDITOR are refused in this site\'s words', function () {
    $people = [
        'nobody' => [new Client, 401, 'sign_in_required', 'Sign in to continue.'],
        'a person with no role' => [(new Client)->asIam(MEMBER, []), 403, 'admin_only', 'Only an admin can do this.'],
        'MEMBER' => [(new Client)->asIam(MEMBER, ['MEMBER']), 403, 'admin_only', 'Only an admin can do this.'],
        'an EDITOR' => [(new Client)->asIam(EDITOR, [Role::EDITOR]), 403, 'admin_only', 'Only an admin can do this.'],
        'a token that does not verify' => [(new Client)->withToken('x'), 401, 'invalid_token', 'Your sign-in has run out. Please sign in again.'],
    ];
    foreach (TOOL_ROUTES as [$method, $url, $body]) {
        foreach ($people as $who => [$client, $status, $code, $message]) {
            $answer = ask($client, $method, $url, $body);
            same([$status, ['message' => $message, 'code' => $code]], [$answer->status, $answer->json()], "{$method} {$url}: {$who}");
        }
        $answer = ask(admin(), $method, $url, $body);
        check(! in_array($answer->status, [401, 403], true), "{$method} {$url}: the ADMIN is let through ({$answer->status} {$answer->body})");
    }
    // A role in the address or a header of the request is nobody's role.
    (new Client)->asIam(EDITOR, [Role::EDITOR])->withHeaders(['X-Roles' => 'ADMIN'])->get('/api/admin/errors?role=ADMIN')->status(403)->path('code', 'admin_only');
    same([0, 0], [(int) db()->value('SELECT count(*) FROM users WHERE id = ?', [EDITOR]), (int) db()->value('SELECT count(*) FROM users WHERE id = ?', [MEMBER])],
        'nobody refused got a row of users');
});

// ── The error log ────────────────────────────────────────────────────────────────────────────────

test('the error log: an exception answered 500 is written - its route, who, its trace - never the request\'s query, body, header or token', function () {
    T::$app->set(App\Services\Import\ImportService::class, new class extends App\Services\Import\ImportService
    {
        public function __construct() {}

        public function fromFile(string $contents, App\Models\User $owner, ?App\Enums\Game $expectedGame = null, ?App\Models\Account $target = null, ?string $fileName = null): App\Models\ImportRun
        {
            throw new LogicException('The importer broke for mona@example.test');
        }
    });
    $token = iamToken(MEMBER, [], ['preferred_username' => 'mona']);
    try {
        (new Client)->withToken($token)->withHeaders(['X-Secret' => 'header-secret-1'])
            ->post('/api/imports/file?password=query-secret-2', ['content' => 'body-secret-3'])->status(500)->path('code', 'server_error');
    } finally {
        T::$app->forget(App\Services\Import\ImportService::class);
    }

    $files = glob(T::$storage.'/logs/errors-*.jsonl') ?: [];
    same(1, count($files), 'one day\'s file: '.json_encode($files));
    $written = (string) file_get_contents($files[0]);
    $lines = errorLines();
    same(1, count($lines), 'one line: '.$written);
    $line = $lines[0];
    same(['server', 'error', 500, 'LogicException', 'server_error', 'POST', '/api/imports/file', '/api/imports/file', MEMBER],
        [$line['source'], $line['level'], $line['status'], $line['type'], $line['code'], $line['method'], $line['route'], $line['path'], $line['user_id']], 'the line');
    same('The importer broke for {email}', $line['message'], 'the message, an address taken out');
    foreach (['header-secret-1', 'query-secret-2', 'body-secret-3', 'mona@example.test', $token] as $secret) {
        check(! str_contains($written, $secret), "never in the log: {$secret}");
    }
    check(is_array($line['trace']) && $line['trace'] !== [], 'a trace');
    check(preg_match('/^[0-9a-f]{12}$/', (string) $line['client']) === 1, 'the address only as a keyed hash');

    // The page's report: one group, and its occurrences.
    $report = admin()->get('/api/admin/errors')->status(200);
    same([1, 1], [$report->json('total'), $report->json('errors')], 'counted');
    $fingerprint = (string) $report->json('groups.0.fingerprint');
    same(1, count(admin()->get('/api/admin/errors/'.$fingerprint)->status(200)->json()), 'its occurrence');
});

test('the error log: an answer worth seeing is written as it went out (404, 405) - an update waiting (a state) never, nor the log\'s own routes', function () {
    (new Client)->get('/api/nothing-here')->status(404);
    (new Client)->request('DELETE', '/api/meta')->status(405);
    admin()->get('/api/admin/errors/ffffffffffff')->status(404)->path('code', 'not_found');
    whileAnUpdateWaits(function (): void {
        (new Client)->get('/api/builds/genshin')->status(503)->path('code', 'update_pending');
    });

    same([[404, 'not_found', 'Response', '/api/nothing-here'], [405, 'method_not_allowed', 'Response', '/api/meta']],
        array_map(fn ($line) => [$line['status'], $line['code'], $line['type'], $line['path']], errorLines()), 'the two answers - not the log\'s own 404, not the update waiting');
    $log = T::$app->get(ErrorLog::class);
    same([true, true, true, true, true, true, true, false, false, false, false],
        array_map(fn (int $status) => $log->logsStatus($status), [500, 502, 404, 405, 413, 429, 503, 401, 403, 409, 422]), 'every 5xx - a 503 too, by its code -, and 404, 405, 413, 415, 429');
});

test('the error log tells a state from a failure by the answer\'s code: a table or an update waiting is left out, a 503 that is a failure is written', function () {
    // States, not failures (AdminTools::EXPECTED_ANSWERS): an admin tool whose table is not there - here moved aside
    // for a moment, as between an upload and its Apply - answers 503 feature_unavailable on every visit.
    db()->run('ALTER TABLE anotoki_feedback RENAME TO anotoki_feedback_away');
    try {
        admin()->get('/api/admin/feedback')->status(503)->path('code', 'feature_unavailable');
        feedback(['kind' => 'idea', 'message' => 'While the table is away'])->status(503)->path('code', 'feature_unavailable');
    } finally {
        db()->run('ALTER TABLE anotoki_feedback_away RENAME TO anotoki_feedback');
    }
    // This site's own states of the kind (a waiting migration's table), as the log hears an answer - beside a 503
    // that says something could not be done (the move's lock wait): written.
    $log = T::$app->get(ErrorLog::class);
    $request = (new Slim\Psr7\Factory\ServerRequestFactory)->createServerRequest('POST', 'http://localhost/api/visitor/move');
    foreach (['update_pending', 'not_set_up', 'visitors_unavailable', 'translations_unavailable', 'people_unavailable', 'visitor_move_retry'] as $code) {
        $log->recordResponse($request, App\Web\Json::message((new Slim\Psr7\Factory\ResponseFactory)->createResponse(), 503, 'Not now.', ['code' => $code]));
    }
    // A failure: feedback's limits cannot be counted (where their file belongs there is a folder) - 503
    // storage_unavailable, answered to the sender and written for the admin.
    $limits = T::$storage.'/cache/anotoki-feedback-limits.json';
    @mkdir($limits, 0777, true);
    try {
        feedback(['kind' => 'idea', 'message' => 'Nowhere to count it'])->status(503)->path('code', 'storage_unavailable');
    } finally {
        @rmdir($limits);
    }

    same([[503, 'visitor_move_retry', '/api/visitor/move'], [503, 'storage_unavailable', '/api/feedback']],
        array_map(fn ($line) => [$line['status'], $line['code'], $line['path']], errorLines()), 'the two failures - none of the states');
    same('error', errorLines()[1]['level'], 'a failure: an error, as every 5xx');
});

test('the error log is cleared by an admin - everything, or the days before one - and that is in the audit log', function () {
    (new Client)->get('/api/nothing-here')->status(404);
    check(glob(T::$storage.'/logs/errors-*.jsonl') !== [], 'a line');
    admin()->request('DELETE', '/api/admin/errors?before=yesterday')->status(422)->path('code', 'date_invalid');
    same(['deleted' => 1], admin()->request('DELETE', '/api/admin/errors')->status(200)->json(), 'cleared');
    same([], glob(T::$storage.'/logs/errors-*.jsonl') ?: [], 'no file left');
    same([[ADMIN, 'error_log']], array_map(fn ($row) => [(int) $row['actor_id'], $row['target_type']], entries('errors.cleared')), 'in the audit log');
});

// ── The audit log ────────────────────────────────────────────────────────────────────────────────

test('the audit log: what was done in the admin panel, by whom - sources, syncs, languages - read with filters, an ADMIN\'s', function () {
    seedGenshinCatalog();
    admin()->patch('/api/sources/genshin-1', ['enabled' => false])->status(200);
    admin()->patch('/api/sources/genshin-1', ['enabled' => false])->status(200); // no change: nothing recorded
    admin()->patch('/api/sources/genshin-1', ['enabled' => true])->status(200);
    admin()->post('/api/admin/languages', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch'])->status(201);
    $source = (int) db()->value("SELECT min(id) FROM build_sources WHERE game = 'genshin'");
    $run = (int) db()->insert('sync_runs', ['build_source_id' => $source, 'kind' => 'builds', 'game' => 'genshin', 'status' => 'running',
        'started_by' => ADMIN, 'started_at' => Clock::now()]);
    (new Client)->asIam(EDITOR, [Role::EDITOR], ['preferred_username' => 'eddie'])->post("/api/sync-runs/{$run}/cancel")->status(200);

    $page = admin()->get('/api/admin/audit')->status(200);
    same(['total', 'page', 'pageSize', 'items'], array_keys($page->json()), 'the answer');
    same([['sync.cancelled', 'eddie', 'sync_run', (string) $run], ['language.created', 'ada', 'language', 'de'], ['source.enabled', 'ada', 'source', 'genshin-1'], ['source.disabled', 'ada', 'source', 'genshin-1']],
        array_map(fn ($item) => [$item['action'], $item['actor_username'], $item['target_type'], $item['target_id']], $page->json('items')), 'newest first, each by whom');
    same([null], array_values(array_unique(array_column($page->json('items'), 'ip'))), 'no address kept');
    same(['source.enabled', 'source.disabled'], array_column(admin()->get('/api/admin/audit?action=source.')->status(200)->json('items'), 'action'), 'filtered by an action\'s beginning');
    same(['sync.cancelled'], array_column(admin()->get('/api/admin/audit?actor=eddie')->status(200)->json('items'), 'action'), 'by a name');
    $facets = admin()->get('/api/admin/audit/facets')->status(200)->json();
    same(['language.created', 'source.disabled', 'source.enabled', 'sync.cancelled'], $facets['actions'], 'the facets\' actions');
    same([['id' => ADMIN, 'username' => 'ada'], ['id' => EDITOR, 'username' => 'eddie']], $facets['actors'], 'and who');
});

// ── Feedback ─────────────────────────────────────────────────────────────────────────────────────

test('the feedback form: anybody may send, who is from the token alone, nothing of where it came from is kept, and no id comes back', function () {
    same(['ok' => true], feedback(['kind' => 'idea', 'section' => 'Builds', 'subject' => 'More sources', 'message' => 'Please add more.', 'page' => '/builds/genshin?x=1', 'language' => 'sk'])->status(201)->json(), 'nobody');
    feedback(['kind' => 'bug', 'message' => 'It broke.', 'email' => 'reply@example.test', 'user_id' => 99], (new Client('198.51.100.2'))->asIam(MEMBER, [], ['email' => 'mona@example.test']))->status(201);
    feedback(['kind' => 'other', 'message' => 'Hello', 'anonymous' => true], (new Client('198.51.100.3'))->asIam(EDITOR, [Role::EDITOR]))->status(201);
    // The page writes the account's address into the field; a sender who clears it sends none - and none is kept,
    // whatever address the token carries (their choice, the owner's decision).
    feedback(['kind' => 'bug', 'message' => 'No address given.'], (new Client('198.51.100.4'))->asIam(MEMBER, [], ['email' => 'mona@example.test']))->status(201);
    feedback(['kind' => 'bug', 'message' => 'Cleared the field.', 'email' => ''], (new Client('198.51.100.5'))->asIam(MEMBER, [], ['email' => 'mona@example.test']))->status(201);

    same([
        ['idea', 'Builds', 'More sources', null, null, '/builds/genshin', 'sk', 'new'],
        ['bug', null, null, 'reply@example.test', MEMBER, null, null, 'new'],
        ['other', null, null, null, null, null, null, 'new'],
        ['bug', null, null, null, MEMBER, null, null, 'new'],
        ['bug', null, null, null, MEMBER, null, null, 'new'],
    ], array_map(fn ($row) => [$row['kind'], $row['section'], $row['subject'], $row['contact_email'], $row['user_id'] === null ? null : (int) $row['user_id'], $row['page'], $row['language'], $row['status']],
        db()->all('SELECT * FROM anotoki_feedback ORDER BY id')), 'kept: the token\'s person (never the body\'s id), the address the sender left in the field and no other, nobody\'s when sent without a name, the page without its query');
    same(0, (int) db()->value("SELECT count(*) FROM anotoki_feedback WHERE contact_email = 'mona@example.test'"), 'the account\'s address is never filled in by the server');
    $columns = db()->column("SELECT column_name FROM information_schema.columns WHERE table_name = 'anotoki_feedback'");
    same([], array_values(array_intersect($columns, ['ip', 'client', 'client_hash', 'address', 'submitter_hash'])), 'no column for where it came from');

    // Refused before anything is kept.
    feedback(['kind' => 'complaint', 'message' => 'x'])->status(422)->path('code', 'kind_unknown');
    feedback(['kind' => 'idea', 'message' => '  '])->status(422)->path('code', 'message_required');
    feedback(['kind' => 'idea', 'message' => 'x', 'section' => 'Elsewhere'])->status(422)->path('code', 'section_unknown');
    feedback(['kind' => 'idea', 'message' => 'x', 'email' => 'not an address'])->status(422)->path('code', 'email_invalid');
    (new Client)->request('POST', '/api/feedback', null, ['body' => 'kind=idea&message=x', 'type' => 'application/x-www-form-urlencoded'])->status(415);
    // The robots' field: the same answer, nothing kept.
    same(['ok' => true], feedback(['kind' => 'idea', 'message' => 'Buy now', 'website' => 'https://spam.example'])->status(201)->json(), 'the honeypot\'s answer');
    same(5, (int) db()->value('SELECT count(*) FROM anotoki_feedback'), 'nothing more kept');
    // A token that does not verify is refused, never taken for nobody.
    (new Client)->withToken('x')->post('/api/feedback', ['kind' => 'idea', 'message' => 'x'])->status(401)->path('code', 'invalid_token');
});

test('the feedback form while the IAM requires sign-in here: nobody is refused, a signed-in person sends', function () {
    iamRequiresSignIn(true);
    feedback(['kind' => 'idea', 'message' => 'From nobody'])->status(401)->path('code', 'sign_in_required');
    feedback(['kind' => 'idea', 'message' => 'From mona'], (new Client)->asIam(MEMBER, ['MEMBER']))->status(201);
    same(['From mona'], db()->column('SELECT message FROM anotoki_feedback'), 'kept');
});

test('the feedback form\'s limits: five an hour from one address, the rest of the world unaffected', function () {
    foreach (range(1, 5) as $n) {
        feedback(['kind' => 'idea', 'message' => "Idea {$n}"], from: '203.0.113.7')->status(201);
    }
    $refused = feedback(['kind' => 'idea', 'message' => 'Idea 6'], from: '203.0.113.7')->status(429);
    same('too_many', $refused->json('code'), 'too many');
    check((int) $refused->header('Retry-After') > 0, 'Retry-After: '.$refused->header('Retry-After'));
    feedback(['kind' => 'idea', 'message' => 'From elsewhere'], from: '203.0.113.8')->status(201);
    same(6, (int) db()->value('SELECT count(*) FROM anotoki_feedback'), 'kept');
    check(is_file(T::$storage.'/cache/anotoki-feedback-limits.json'), 'counted apart, in storage');
    check(! str_contains((string) file_get_contents(T::$storage.'/cache/anotoki-feedback-limits.json'), '203.0.113'), 'the addresses only as keyed hashes');
});

test('feedback on the admin side: the list, the unread count (the badge), a message opened is read, marked and deleted - each audited', function () {
    feedback(['kind' => 'bug', 'section' => 'Import', 'message' => 'The wizard stops.'], (new Client('198.51.100.5'))->asIam(MEMBER, [], ['preferred_username' => 'mona', 'email' => 'mona@example.test']))->status(201);
    feedback(['kind' => 'idea', 'message' => 'Dark mode everywhere.'], from: '198.51.100.6')->status(201);

    same(['unread' => 2], admin()->get('/api/admin/feedback/unread')->status(200)->json(), 'two new');
    $list = admin()->get('/api/admin/feedback')->status(200);
    same([2, 25], [$list->json('total'), $list->json('pageSize')], 'the list');
    $mona = (int) db()->value("SELECT id FROM anotoki_feedback WHERE kind = 'bug'");
    $opened = admin()->get("/api/admin/feedback/{$mona}")->status(200);
    same(['The wizard stops.', 'mona', 'read'], [$opened->json('message.message'), $opened->json('message.user_name'), $opened->json('message.status')], 'opened: who sent it, read now');
    same(['unread' => 1], admin()->get('/api/admin/feedback/unread')->status(200)->json(), 'one new');

    admin()->put("/api/admin/feedback/{$mona}/status", ['status' => 'resolved'])->status(200)->path('message.status', 'resolved');
    admin()->put("/api/admin/feedback/{$mona}/status", ['status' => 'sorted'])->status(422)->path('code', 'status_unknown');
    same([[ADMIN, 'feedback', (string) $mona]], array_map(fn ($row) => [(int) $row['actor_id'], $row['target_type'], $row['target_id']], entries('feedback.status_changed')), 'the change is audited');
    admin()->delete("/api/admin/feedback/{$mona}")->status(200)->path('deleted', true);
    admin()->get("/api/admin/feedback/{$mona}")->status(404);
    same(1, count(entries('feedback.deleted')), 'the delete is audited');
    $facets = admin()->get('/api/admin/feedback/facets')->status(200)->json();
    same([['bug', 'idea', 'other'], ['Dashboard', 'Import', 'Accounts', 'Builds'], ['bug' => 0, 'idea' => 1, 'other' => 0]],
        [$facets['kinds'], $facets['sections'], $facets['byKind']], 'the facets: this site\'s sections');
});

// ── Backups ──────────────────────────────────────────────────────────────────────────────────────

test('a backup: made, listed, downloaded once by its ticket, deleted - each behind a sign-in of the last ten minutes, each audited', function () {
    seedGenshinCatalog();
    seedUser(MEMBER, 'mona');
    seedAccount(MEMBER, 'genshin', ['uid' => '700000007', 'nickname' => 'Main']);

    // A sign-in of more than ten minutes ago is no proof: the page offers "Sign in again".
    $stale = admin(['auth_time' => time() - 3600]);
    send($stale, 'POST', '/api/admin/backups')->status(403)->path('code', 'reauth_required');
    same(['kind' => 'recent_sign_in'], admin()->get('/api/admin/backups/proof')->status(200)->json(), 'what the page asks for');

    $made = admin()->post('/api/admin/backups', ['description' => 'Before the big sync'])->status(201)->json('backup');
    check(preg_match('/^\d{8}-\d{6}Z-manual-[0-9a-f]{32}$/', (string) $made['id']) === 1, 'its name: '.$made['id']);
    same(['manual', 'Before the big sync'], [$made['kind'], $made['description']], 'a manual backup, described');
    check(is_file(T::$storage.'/backups/'.$made['id'].'.anotoki-backup'), 'the file in storage/backups');

    $list = admin()->get('/api/admin/backups')->status(200);
    same([$made['id']], array_column($list->json('backups'), 'id'), 'listed');
    same([true, true, false, []], [$list->json('status.copy_available'), $list->json('status.restore_enabled'), $list->json('status.encrypted'), $list->json('status.problems')],
        'COPY works here, restores may run, not encrypted, nothing in the way');
    check($list->json('backups.0.restorable') === true, 'restorable here: '.json_encode($list->json('backups.0')));

    // A download: a single-use ticket, the file by it with no token - once.
    send($stale, 'POST', "/api/admin/backups/{$made['id']}/ticket")->status(403)->path('code', 'reauth_required');
    $ticket = send(admin(), 'POST', "/api/admin/backups/{$made['id']}/ticket")->status(200);
    check(str_starts_with((string) $ticket->json('url'), "/api/admin/backups/{$made['id']}/file?ticket="), 'its url: '.$ticket->json('url'));
    check($ticket->json('expires_in') > 0 && $ticket->json('expires_in') <= 300, 'a short while: '.$ticket->json('expires_in'));
    $file = (new Client)->get((string) $ticket->json('url'))->status(200);
    same(['application/octet-stream', 'attachment; filename="build-analyzer-'.$made['id'].'.anotoki-backup"', 'no-store'],
        [$file->header('Content-Type'), $file->header('Content-Disposition'), $file->header('Cache-Control')], 'the file, as an attachment');
    check(str_starts_with($file->body, "ANOTOKI-BACKUP 1\n"), 'the backup itself');
    (new Client)->get((string) $ticket->json('url'))->status(403)->path('code', 'ticket_invalid');
    (new Client)->get("/api/admin/backups/{$made['id']}/file?ticket=made-up")->status(403)->path('code', 'ticket_invalid');
    (new Client)->get("/api/admin/backups/{$made['id']}/file")->status(403)->path('code', 'ticket_invalid');

    $stale->delete("/api/admin/backups/{$made['id']}")->status(403)->path('code', 'reauth_required');
    admin()->delete("/api/admin/backups/{$made['id']}")->status(200)->path('deleted', true);
    same([], admin()->get('/api/admin/backups')->status(200)->json('backups'), 'gone');
    same(['backup.created', 'backup.downloaded', 'backup.deleted'], db()->column("SELECT action FROM anotoki_audit_log WHERE action LIKE 'backup.%' ORDER BY id"), 'each audited');
    same([ADMIN], array_map('intval', array_values(array_unique(db()->column("SELECT actor_id FROM anotoki_audit_log WHERE action LIKE 'backup.%'")))), 'by the admin');
});

test('a restore puts the database back as the backup has it - but the audit log and the counters - after a backup of it as it was', function () {
    seedGenshinCatalog();
    seedUser(MEMBER, 'mona');
    $main = seedAccount(MEMBER, 'genshin', ['uid' => '700000007', 'nickname' => 'Main']);
    $made = send(admin(), 'POST', '/api/admin/backups')->status(201)->json('backup');

    // After the backup: an account renamed, one added, one person more.
    db()->run('UPDATE accounts SET nickname = ? WHERE id = ?', ['Renamed', $main]);
    seedAccount(MEMBER, 'genshin', ['uid' => '700000017', 'nickname' => 'Second']);
    seedUser(8, 'albedo');

    $preview = admin()->get("/api/admin/backups/{$made['id']}/preview")->status(200);
    same([true, true], [$preview->json('restorable'), $preview->json('lost') >= 2], 'what a restore would take away: '.substr($preview->body, 0, 300));

    admin()->post("/api/admin/backups/{$made['id']}/restore", ['confirm' => 'wrong!'])->status(422)->path('code', 'confirm_mismatch');
    admin(['auth_time' => time() - 3600])->post("/api/admin/backups/{$made['id']}/restore", ['confirm' => substr($made['id'], -6)])->status(403)->path('code', 'reauth_required');
    $entriesBefore = (int) db()->value('SELECT count(*) FROM anotoki_audit_log');

    admin()->post("/api/admin/backups/{$made['id']}/restore", ['confirm' => substr($made['id'], -6)])->status(200);
    same(['Main'], db()->column('SELECT nickname FROM accounts ORDER BY id'), 'the accounts as they were');
    same(['mona'], db()->column('SELECT name FROM users ORDER BY name'), 'the people as they were');
    check((int) db()->value('SELECT count(*) FROM anotoki_audit_log') > $entriesBefore, 'the audit log kept, and the restore in it');
    same(1, count(entries('backup.restored')), 'audited');
    same(1, count(glob(T::$storage.'/backups/*-before-restore-*.anotoki-backup') ?: []), 'a backup of the database as it was, first');
});

test('a restore checks the IAM\'s deleted accounts again at once: whoever it deleted since the backup is nobody\'s again - and out of reach, the restore says so', function () {
    seedUser(9, 'klee');
    $made = send(admin(), 'POST', '/api/admin/backups')->status(201)->json('backup');
    $restore = fn (): Answer => admin()->post("/api/admin/backups/{$made['id']}/restore", ['confirm' => substr($made['id'], -6)])->status(200);

    // After the backup the IAM deleted klee's account, and the scrub made her nobody's here.
    T::$iam->anonymized(['9' => '2026-11-06T10:00:00Z']);
    admin()->post('/api/admin/people/sync')->status(200);
    same('deleted-9', db()->value('SELECT name FROM users WHERE id = 9'), 'scrubbed');

    // The restore brings back her name - and the scrub, run at once, takes it again.
    $restored = $restore();
    same([null, 'deleted-9'], [$restored->json('after_restore_error'), db()->value('SELECT name FROM users WHERE id = 9')], 'restored, and nobody\'s again at once');

    // The IAM out of reach: the restore is done, and says that the check was not; the hourly check is due again.
    T::$iam->anonymized(null);
    $stamp = T::$app->get(Anotoki\Lib\People\People::class)->iam->cacheDirectory().'/'.Anotoki\Lib\People\Http\PeopleUpkeep::STAMP;
    @mkdir(dirname($stamp), 0777, true);
    file_put_contents($stamp, (string) time());
    $unchecked = $restore();
    // The answer says that it did not all go through, in the library's fixed words (anotoki-lib 0.6.0: no answer
    // carries a failure's own words); what did not, the error log says.
    same(AFTER_RESTORE_FAILED, $unchecked->json('after_restore_error'), 'the restore says so');
    same('klee', db()->value('SELECT name FROM users WHERE id = 9'), 'named again, until the next check');
    check(! is_file($stamp), 'the hour no longer claimed: the next request checks again');
    $said = array_values(array_filter(errorLines(), fn (array $line): bool => str_contains((string) $line['message'], 'The accounts the IAM deleted were not checked again')
        && str_contains((string) $line['message'], 'the check runs again with the next request')));
    same(1, count($said), 'the error log says what: '.json_encode(array_column(errorLines(), 'message')));
    T::$iam->anonymized(['9' => '2026-11-06T10:00:00Z']);
    admin()->post('/api/admin/people/sync')->status(200);
    same('deleted-9', db()->value('SELECT name FROM users WHERE id = 9'), 'and the next check takes it');
});

test('a restore on the command line that cannot check the IAM\'s deleted accounts says so - and gives the hour back when it ends, so the next request checks, as it says', function () {
    // php backup.php as it runs on a computer with a shell - in a process of its own, on this file's throwaway database,
    // through a settings folder of its own (BUILD_ANALYZER_TEST_CONFIG, honoured for a build_analyzer_test_ database
    // only), whose storage holds its backups, its key and the IAM's cache; the IAM's list of deleted accounts out of
    // reach (its file not there).
    $settings = T::$storage.DIRECTORY_SEPARATOR.'cli-settings';
    @mkdir($settings.'/storage', 0777, true);
    $server = require dirname(__DIR__).'/config/database.local.php';
    file_put_contents($settings.'/database.local.php', '<?php return '.var_export(['database' => T::$database, 'persistent' => false] + $server, true).";\n");
    file_put_contents($settings.'/auth.local.php', '<?php return '.var_export(['issuer' => TEST_ISSUER, 'client_id' => TEST_CLIENT,
        'anonymized_file' => $settings.'/no-such-list.json'], true).";\n");
    $backupPhp = function (string ...$arguments) use ($settings): array {
        $command = escapeshellarg(PHP_BINARY).' backup.php '.implode(' ', array_map('escapeshellarg', $arguments));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), [App\Config::TEST_CONFIG_ENV => $settings] + getenv());
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

        return [proc_close($process), str_replace("\r\n", "\n", (string) $output)];
    };
    try {
        seedUser(9, 'klee');
        [$code, $made] = $backupPhp('--create');
        check($code === 0 && preg_match('/^Made (\S+) /', $made, $id) === 1, 'a backup made: '.$made);

        // The hour claimed, as an ordinary request's upkeep claims it before it checks.
        $stamp = $settings.'/storage/cache/iam/'.Anotoki\Lib\People\Http\PeopleUpkeep::STAMP;
        @mkdir(dirname($stamp), 0777, true);
        file_put_contents($stamp, (string) time());
        [$code, $output] = $backupPhp('--restore='.$id[1], '--confirm='.substr($id[1], -6));
        same(1, $code, 'restored, and the work after it not all done: '.$output);
        check(str_contains($output, 'Restored ') && str_contains($output, "The site's work after the restore failed: ".AFTER_RESTORE_FAILED), 'it says so: '.$output);
        // What did not go through is the error log's - in the settings folder's storage, where that command writes it.
        $logged = '';
        foreach (glob($settings.'/storage/logs/errors-*.jsonl') ?: [] as $file) {
            $logged .= (string) file_get_contents($file);
        }
        check(str_contains($logged, 'The accounts the IAM deleted were not checked again') && str_contains($logged, 'the check runs again with the next request'),
            'and the error log says what: '.substr($logged, 0, 600));
        check(! is_file($stamp), 'and it is so: the hour given back once the command ended (what it left for "after the answer" ran)');
        same('klee', db()->value('SELECT name FROM users WHERE id = 9'), 'the database as the backup has it');
    } finally {
        removeTree($settings);
    }
});

test('a restore does again what was deleted since its backup - a person\'s data on the People page, a feedback message - and completes', function () {
    seedGenshinCatalog();
    seedUser(7, 'mona');
    seedAccount(7, 'genshin', ['uid' => '700000007', 'nickname' => 'Main']);
    seedUser(8, 'albedo');
    seedAccount(8, 'genshin', ['uid' => '700000008', 'nickname' => 'Chalk']);
    db()->run("INSERT INTO anotoki_feedback (kind, message, contact_email, user_id, user_agent) VALUES
        ('bug', 'From mona', 'mona@example.test', 7, 'Firefox'), ('idea', 'Spam, really', NULL, NULL, NULL), ('idea', 'Keep me', NULL, NULL, NULL)");
    $spam = (int) db()->value("SELECT id FROM anotoki_feedback WHERE message = 'Spam, really'");
    $made = send(admin(), 'POST', '/api/admin/backups')->status(201)->json('backup');

    // After the backup: mona's data deleted on the People page, a message deleted on the Feedback page - each in the
    // audit log, written in its delete's own transaction - and an account renamed, which a restore takes back.
    same(['deleted' => ['Game accounts' => 1, 'Imports' => 0]], admin()->delete('/api/admin/people/7')->status(200)->json(), 'deleted');
    admin()->delete("/api/admin/feedback/{$spam}")->status(200)->path('deleted', true);
    db()->run('UPDATE accounts SET nickname = ? WHERE user_id = 8', ['Renamed']);

    $restored = admin()->post("/api/admin/backups/{$made['id']}/restore", ['confirm' => substr($made['id'], -6)])->status(200);
    same(null, $restored->json('after_restore_error'), 'complete, nothing left to say: '.substr($restored->body, 0, 300));
    same([null, 0], [db()->value('SELECT id FROM users WHERE id = 7'), (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = 7')],
        'mona\'s data deleted again, inside the restore - never back for anybody to see');
    same([['From mona', null, null], ['Keep me', null, null]],
        array_map('array_values', db()->all('SELECT message, contact_email, user_agent FROM anotoki_feedback ORDER BY id')),
        'the deleted message deleted again; her message without the address and browser it was sent with; the rest as the backup has it');
    same(['Chalk'], db()->column('SELECT nickname FROM accounts WHERE user_id = 8'), 'everything else as the backup has it');
    same(1, count(entries('backup.restored')), 'audited');
    same([], array_values(array_filter(errorLines(), fn (array $line): bool => $line['level'] === 'error')), 'and nothing failed');
});

test('only a backup made here is restored here: each sealed under this installation\'s key, its maker named - one without the seal (made before 0.5.0, or brought from elsewhere) only offline', function () {
    seedUser(ADMIN, 'ada');
    $made = send(admin(), 'POST', '/api/admin/backups')->status(201)->json('backup');
    $listed = admin()->get('/api/admin/backups')->status(200)->json('backups.0');
    same([$made['id'], true, true, ADMIN, 'ada'], [$listed['id'], $listed['sealed'], $listed['restorable'], $listed['created_by'], $listed['created_by_name']],
        'sealed, restorable here, made by the admin - named (AdminContext\'s names)');

    // Its sidecar without the seal, as a plain backup of 0.4 has it (or one put into the folder from another host's).
    $sidecar = T::$storage.'/backups/'.$made['id'].'.anotoki-backup.json';
    $info = json_decode((string) file_get_contents($sidecar), true);
    check(is_string($info['seal'] ?? null) && $info['seal'] !== '', 'the seal is in the sidecar');
    unset($info['seal']);
    file_put_contents($sidecar, json_encode($info));
    $unsealed = admin()->get('/api/admin/backups')->status(200)->json('backups.0');
    same([false, false], [$unsealed['sealed'], $unsealed['restorable']], 'listed, unsealed, not restorable here');
    admin()->post("/api/admin/backups/{$made['id']}/restore", ['confirm' => substr($made['id'], -6)])->status(422)->path('code', 'archive_invalid');
    // A seal of another installation's key is none either.
    $info['seal'] = hash_hmac('sha256', $made['id']."\n".$info['sha256'], str_repeat('k', 32));
    file_put_contents($sidecar, json_encode($info));
    same(false, admin()->get('/api/admin/backups')->status(200)->json('backups.0.sealed'), 'another key\'s seal');
    admin()->post("/api/admin/backups/{$made['id']}/restore", ['confirm' => substr($made['id'], -6)])->status(422)->path('code', 'archive_invalid');

    // Offline it still opens: the command line's --extract checks it against its own SHA-256.
    $folder = T::$storage.DIRECTORY_SEPARATOR.'extracted-'.bin2hex(random_bytes(4));
    try {
        $manifest = T::$app->get(Anotoki\Lib\Backups\Backups::class)->extractTo($made['id'], $folder);
        same('build-analyzer', $manifest['site'] ?? null, 'read offline: '.json_encode(array_keys($manifest)));
        check(is_file($folder.'/manifest.json'), 'its manifest written out');
    } finally {
        removeTree($folder);
    }
});

// ── The pages' own errors ────────────────────────────────────────────────────────────────────────

test('the errors the pages meet go into the error log as the browser\'s: scrubbed again, who from the token alone - and refused before anything is written', function () {
    $token = iamToken(MEMBER, [], ['preferred_username' => 'mona']);
    (new Client('198.51.100.60'))->withToken($token)->post('/api/client-errors', [
        'type' => 'TypeError',
        'message' => "Cannot read x of mona@example.test with {$token}",
        'page' => '/accounts/12',
        'stack' => ['TypeError: Cannot read x', 'at render (https://builder.example.test/main.js:3:4)'],
        'user_id' => 99,
    ])->status(204);
    (new Client('198.51.100.61'))->post('/api/client-errors', ['message' => 'A visitor\'s page failed: password=hunter2'])->status(204);

    $lines = errorLines();
    same(2, count($lines), 'two lines: '.json_encode($lines));
    [$person, $visitor] = $lines;
    same(['client', 'error', 'TypeError', '/accounts/12', MEMBER], [$person['source'], $person['level'], $person['type'], $person['path'], $person['user_id']],
        'the person\'s, from the token - never the body\'s id');
    same('Cannot read x of {email} with {token}', $person['message'], 'scrubbed again on the server');
    same(['at render (https://builder.example.test/main.js:3:4)'], $person['trace'], 'its frames, the error\'s own line once');
    same(['client', null], [$visitor['source'], $visitor['user_id']], 'a visitor is nobody');
    check(preg_match('/^[0-9a-f]{12}$/', (string) $visitor['client']) === 1, 'where from: a keyed hash, never the address');
    $written = (string) json_encode($lines);
    foreach (['mona@example.test', $token, 'hunter2', '198.51.100.6'] as $secret) {
        check(! str_contains($written, $secret), "never written: {$secret}");
    }

    (new Client)->post('/api/client-errors', ['message' => ' '])->status(422)->path('code', 'message_required');
    (new Client)->request('POST', '/api/client-errors', null, ['body' => 'message=x', 'type' => 'application/x-www-form-urlencoded'])->status(415);
    (new Client)->request('POST', '/api/client-errors', null, ['body' => (string) json_encode(['message' => str_repeat('x', 9000)])])->status(413);
    (new Client)->withToken('x')->post('/api/client-errors', ['message' => 'x'])->status(401)->path('code', 'invalid_token');
    // While the IAM requires sign-in here, a public route is a signed-in person's alone (optionalSignIn()).
    iamRequiresSignIn(true);
    (new Client)->post('/api/client-errors', ['message' => 'From nobody'])->status(401)->path('code', 'sign_in_required');
    (new Client)->asIam(MEMBER)->post('/api/client-errors', ['message' => 'From mona, signed in'])->status(204);
    same(['Cannot read x of {email} with {token}', 'A visitor\'s page failed: password={…}', 'From mona, signed in'], array_column(errorLines(), 'message'),
        'nothing else written - not the refusals, not the route\'s own answers');
});

test('the pages\' errors are capped: twenty an hour from one sender - a visitor by their address, a person by their account, each apart', function () {
    foreach (range(1, 20) as $n) {
        (new Client('198.51.100.70'))->post('/api/client-errors', ['message' => "Failure {$n}"])->status(204);
    }
    $refused = (new Client('198.51.100.70'))->post('/api/client-errors', ['message' => 'Failure 21'])->status(429);
    same('too_many', $refused->json('code'), 'too many');
    check((int) $refused->header('Retry-After') > 0 && (int) $refused->json('retry_after') > 0, 'how long to wait: '.$refused->header('Retry-After'));
    // Another address, and a person signed in at the same one, are other senders.
    (new Client('198.51.100.71'))->post('/api/client-errors', ['message' => 'From elsewhere'])->status(204);
    foreach (range(1, 20) as $n) {
        (new Client('198.51.100.70'))->asIam(MEMBER)->post('/api/client-errors', ['message' => "Mona's failure {$n}"])->status(204);
    }
    (new Client('198.51.100.72'))->asIam(MEMBER)->post('/api/client-errors', ['message' => 'Mona elsewhere'])->status(429)->path('code', 'too_many');
    same(41, count(errorLines()), 'written: twenty, one from elsewhere, twenty by the person - never the ones refused');
    $counts = (string) file_get_contents(T::$storage.'/logs/client-errors.json');
    check(! str_contains($counts, '198.51.100') && ! str_contains($counts, '"'.MEMBER.'"'), 'counted by keyed hashes, never an address or an id: '.$counts);
});

// ── Where a request comes from ───────────────────────────────────────────────────────────────────

test('the admin tools read where a request comes from by the site\'s rule: through Cloudflare the visitor\'s address, never the edge\'s', function () {
    same(Anotoki\Lib\Support\ClientAddress::CLOUDFLARE, T::$app->get(Anotoki\Lib\Admin\AdminContext::class)->trustedProxies(), 'Cloudflare\'s ranges, as committed');
    same('CF-Connecting-IP', T::$app->get(Anotoki\Lib\Admin\AdminContext::class)->clientHeader(), 'and its header');
    $through = fn (string $edge, string $visitor): Client => (new Client($edge))->withHeaders(['CF-Connecting-IP' => $visitor]);

    // Feedback's limits, five an hour from one sender: one visitor through two edges is one sender, two visitors
    // through one edge are two.
    foreach (range(1, 5) as $n) {
        feedback(['kind' => 'idea', 'message' => "Idea {$n}"], $through('173.245.48.10', '198.51.100.90'))->status(201);
    }
    feedback(['kind' => 'idea', 'message' => 'Idea 6'], $through('2400:cb00::1', '198.51.100.90'))->status(429)->path('code', 'too_many');
    feedback(['kind' => 'idea', 'message' => 'Another visitor'], $through('173.245.48.10', '198.51.100.91'))->status(201);
    // Not Cloudflare's connection: the header is whatever the sender wrote, and the connection is counted.
    foreach (range(1, 5) as $n) {
        feedback(['kind' => 'idea', 'message' => "Claimed {$n}"], $through('203.0.113.50', "198.51.100.{$n}"))->status(201);
    }
    feedback(['kind' => 'idea', 'message' => 'Claimed 6'], $through('203.0.113.50', '198.51.100.6'))->status(429)->path('code', 'too_many');

    // The error log's client hash: the visitor's, whichever edge - an IPv4 edge written as IPv6 too.
    $through('173.245.48.10', '198.51.100.92')->get('/api/nothing-1')->status(404);
    $through('::ffff:104.16.0.1', '198.51.100.92')->get('/api/nothing-2')->status(404);
    $through('173.245.48.10', '198.51.100.93')->get('/api/nothing-3')->status(404);
    $clients = array_column(array_values(array_filter(errorLines(), fn (array $line): bool => $line['status'] === 404)), 'client');
    same(3, count($clients), 'three lines (beside the two 429s above, also worth seeing)');
    same($clients[0], $clients[1], 'the same visitor through two of Cloudflare\'s edges: one client');
    check($clients[0] !== $clients[2], 'another visitor through the same edge: another client');
});

test('the day\'s backup, after an answer: once a UTC day, by nobody', function () {
    (new Client)->get('/api/meta')->status(200);
    same([], glob(T::$storage.'/backups/*-daily-*.anotoki-backup') ?: [], 'switched off (config upkeep.daily_backup): none');

    T::$app->config->set('upkeep.daily_backup', true);
    (new Client)->get('/api/meta')->status(200);
    same(1, count(glob(T::$storage.'/backups/*-daily-*.anotoki-backup') ?: []), 'the day\'s, after that answer');
    (new Client)->get('/api/meta')->status(200);
    same(1, count(glob(T::$storage.'/backups/*-daily-*.anotoki-backup') ?: []), 'and only one a day');
    same([null], array_values(array_unique(db()->column("SELECT actor_id FROM anotoki_audit_log WHERE action = 'backup.created'"))), 'made by nobody');
});

test('while an update waits the day\'s backup waits too, and the error log and the backups still answer an ADMIN - but a restore', function () {
    T::$app->config->set('upkeep.daily_backup', true);
    whileAnUpdateWaits(function (): void {
        (new Client)->get('/api/meta')->status(200);
        same([], glob(T::$storage.'/backups/*-daily-*.anotoki-backup') ?: [], 'no day\'s backup while the site is not ready');
        admin()->get('/api/admin/errors')->status(200);
        admin()->get('/api/admin/backups')->status(200);
        $made = send(admin(), 'POST', '/api/admin/backups')->status(201)->json('backup');
        admin()->post("/api/admin/backups/{$made['id']}/restore", ['confirm' => substr($made['id'], -6)])->status(503)->path('code', 'update_pending');
        admin()->get('/api/admin/feedback/unread')->status(503)->path('code', 'update_pending');
    });
});

finishTests();
