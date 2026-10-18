<?php

/**
 * Visitor accounts: somebody who uses the site without signing in, whose imports are kept under a row of
 * users with a negative id, opened by a key the server made (App\Auth\Visitors). Made by the first import
 * that goes through, bounded by limits per address and in all, seen at most once an hour, swept after 30
 * days unseen, and moved into a person's data at sign-in. Nothing here assumes which negative id a visitor
 * gets.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Auth\Role;
use App\Auth\Visitors;
use App\Db\Db;
use App\Models\User;
use App\Services\Import\Enka\EnkaStore;
use App\Support\Clock;
use App\Support\Log;
use App\Web\HttpError;
use Tests\Support\Answer;
use Tests\Support\Client;
use Tests\Support\FakeHttpClient;

bootTests();

const PERSON = 7;
const OTHER_PERSON = 8;
const KEY_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

beforeEach(function (): void {
    seedGenshinCatalog();
    T::$app->set(EnkaStore::class, new class extends EnkaStore
    {
        public function __construct() {}

        public function genshinAvatar(string $key): ?array
        {
            return ['SkillOrder' => [10461, 10462, 10463], 'ProudMap' => []];
        }
    });
    Log::to(null);
});

function goodFile(int $level = 80): string
{
    return (string) json_encode(['format' => 'GOOD', 'version' => 2, 'source' => 'Some Exporter', 'characters' => [
        ['key' => 'HuTao', 'level' => $level, 'constellation' => 0, 'ascension' => 5, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]],
    ], 'weapons' => [], 'artifacts' => []]);
}

function profile(string $uid, string $nickname): array
{
    return ['uid' => $uid, 'playerInfo' => ['nickname' => $nickname, 'level' => 55], 'avatarInfoList' => [[
        'avatarId' => 10000046, 'propMap' => ['4001' => ['val' => '90']], 'skillLevelMap' => [], 'equipList' => [],
    ]]];
}

/** Nobody imports a file from $ip: a new visitor. Its key, and the answer. */
function newVisitor(string $ip = '198.51.100.1', ?string $file = null): array
{
    $answer = (new Client($ip))->post('/api/imports/file', ['content' => $file ?? goodFile(), 'file_name' => 'export.json'])->status(201);
    $key = $answer->header('X-Visitor-Key');
    check(preg_match(KEY_PATTERN, $key) === 1, "a key in the answer's header: '{$key}'");

    return [$key, $answer];
}

function visitorClient(string $key, string $ip = '198.51.100.1'): Client
{
    return (new Client($ip))->withHeaders(['X-Visitor-Key' => $key]);
}

/** The visitor row of a key: [id, last_seen_at] - or null. */
function visitorRow(string $key): ?array
{
    return db()->one('SELECT id, last_seen_at FROM users WHERE visitor_key_hash = ?', [hash('sha256', $key)]);
}

function visitorCount(): int
{
    return (int) db()->value('SELECT count(*) FROM users WHERE id < 0');
}

/** A visitor written straight into the database, last seen $daysAgo days ago, with a GOOD-file account of one character. */
function oldVisitor(float $daysAgo, bool $withAccount = true): array
{
    $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $seen = Clock::now()->modify(sprintf('-%d seconds', (int) ($daysAgo * 86400)));
    $id = (int) db()->value("INSERT INTO users (id, name, visitor_key_hash, last_seen_at, created_at, updated_at)
        VALUES (nextval('visitor_ids'), 'visitor', ?, ?, ?, ?) RETURNING id", [hash('sha256', $key), $seen, $seen, $seen]);
    if ($withAccount) {
        $account = seedAccount($id, 'genshin', ['nickname' => 'Old']);
        db()->insert('account_characters', ['account_id' => $account, 'game_character_id' => idOf('game_characters', 'hutao'), 'level' => 90, 'source' => 'good']);
        db()->insert('import_runs', ['user_id' => $id, 'account_id' => $account, 'game' => 'genshin', 'source' => 'good', 'status' => 'success', 'created_at' => $seen, 'updated_at' => $seen]);
    }

    return [$id, $key];
}

/** Another connection to this file's database (a second request, holding locks). */
function secondConnection(): PDO
{
    return Db::pdo((array) T::$app->config->get('database'), T::$database, false);
}

function refusedAs(Answer $answer, int $status, string $code, string $what): void
{
    same([$status, $code], [$answer->status, $answer->json('code')], "{$what} ({$answer->body})");
}

// ── Made by an import, the key handed out once ─────────────────────────────────────────────────────

test('nobody\'s first import that goes through makes a visitor: the key once, in a header, stored nowhere', function () {
    $log = T::$storage.DIRECTORY_SEPARATOR.'visitors.log';
    @unlink($log);
    Log::to($log);

    [$key, $answer] = newVisitor();
    same('no-store', $answer->header('Cache-Control'), 'the answer that carries the key is never cached');
    check(! str_contains($answer->body, $key), 'the key is not in the body');
    same('success', $answer->json('status'), 'the import went through');

    $row = db()->one('SELECT id, name, visitor_key_hash, last_seen_at FROM users');
    check((int) $row['id'] < 0, 'a negative id: '.$row['id']);
    same(['visitor', hash('sha256', $key)], [$row['name'], $row['visitor_key_hash']], 'the row keeps the hash');
    check($row['last_seen_at'] !== null, 'seen');
    same((int) $row['id'], (int) db()->value('SELECT user_id FROM accounts'), 'the account is the visitor\'s');

    // The key itself is in no row of any table a request writes, nor in the log.
    foreach (['users', 'accounts', 'import_runs', 'cache', 'account_characters'] as $table) {
        same(0, (int) db()->value("SELECT count(*) FROM {$table} t WHERE t::text LIKE ?", ['%'.$key.'%']), "the key in {$table}");
    }
    $visitor = visitorClient($key);
    $account = (int) $visitor->get('/api/accounts')->status(200)->json('0.id');
    $visitor->get("/api/accounts/{$account}")->status(200)->path('characters.0.key', 'hutao');
    $visitor->get("/api/accounts/{$account}/imports")->status(200)->path('0.status', 'success');
    $visitor->get("/api/accounts/{$account}/characters/hutao")->status(200)->path('account.id', $account);
    $visitor->patch("/api/accounts/{$account}", ['nickname' => 'Mine'])->status(200)->path('nickname', 'Mine');
    $again = $visitor->post('/api/imports/file', ['content' => goodFile(90), 'account_id' => $account])->status(201);
    same('', $again->header('X-Visitor-Key'), 'a key is handed out only once, by the import that made the visitor');
    same(1, visitorCount(), 'still one visitor');

    // A request with the key that IS logged - a token that does not verify, sent beside the key - so the
    // check below reads a log this flow wrote to.
    visitorClient($key)->withHeaders(['Authorization' => 'Bearer not-a-token'])->get('/api/accounts')->status(401);

    $logged = is_file($log) ? (string) file_get_contents($log) : '';
    check(str_contains($logged, '[anotoki auth] token refused'), 'the flow wrote to the log: '.$logged);
    check(! str_contains($logged, $key), 'the key is not in the log');
    Log::to(null);
});

test('a first import that does not go through makes nobody - and gives its count back', function () {
    T::$app->config->set('visitors.new_per_hour_per_address', 2);

    $bad = (new Client('198.51.100.2'))->post('/api/imports/file', ['content' => 'not json'])->status(422)->path('code', 'file_not_json');
    same('', $bad->header('X-Visitor-Key'), 'no key');
    (new Client('198.51.100.2'))->post('/api/imports/file', [])->status(422)->path('code', 'invalid');
    for ($i = 0; $i < 5; $i++) {
        (new Client('198.51.100.2'))->post('/api/imports/file', ['content' => '{}'])->status(422)->path('code', 'file_unknown_format');
    }
    same([0, 0, 0], [(int) db()->value('SELECT count(*) FROM users'), (int) db()->value('SELECT count(*) FROM import_runs'), (int) db()->value('SELECT count(*) FROM accounts')],
        'no visitor, no run, no account');

    // Seven refused imports from one address, and its two new visitors an hour are still there to be had.
    newVisitor('198.51.100.2');
    newVisitor('198.51.100.2');
    refusedAs((new Client('198.51.100.2'))->post('/api/imports/file', ['content' => goodFile()]), 429, 'too_many_visitors', 'the third');
});

test('nobody\'s import is JSON or nothing: what any page could send without asking - a form, plain text, a file upload - is 415, no visitor made, nothing counted', function () {
    T::$app->config->set('visitors.new_per_hour_per_address', 1);
    $path = T::$storage.DIRECTORY_SEPARATOR.'upload.json';
    file_put_contents($path, goodFile());
    $nobody = fn (): Client => new Client('198.51.100.3');
    $sent = [
        'a form' => $nobody()->request('POST', '/api/imports/file', null, ['body' => 'content='.rawurlencode(goodFile()), 'type' => 'application/x-www-form-urlencoded']),
        'plain text' => $nobody()->request('POST', '/api/imports/showcase', null, ['body' => '{"game":"genshin","uid":"700000001"}', 'type' => 'text/plain']),
        'a file upload' => $nobody()->request('POST', '/api/imports/file', null, ['files' => ['file' => [$path, 'export.json']], 'form' => []]),
    ];
    foreach ($sent as $what => $answer) {
        refusedAs($answer, 415, 'unsupported_media_type', $what);
        same('', $answer->header('X-Visitor-Key'), "{$what}: no key");
    }
    same([0, 0], [visitorCount(), (int) db()->value("SELECT count(*) FROM cache WHERE key LIKE 'rate:%' OR key LIKE 'visitors:new%'")], 'no visitor made, nothing counted');

    // As JSON, from the same address, its one new visitor of the hour - which, with its key, may upload a file.
    [$key] = newVisitor('198.51.100.3');
    visitorClient($key, '198.51.100.3')->request('POST', '/api/imports/file', null, ['files' => ['file' => [$path, 'export.json']], 'form' => []])->status(201);
});

test('a key nobody has, or one that is no key: 401 visitor_unknown - and it never makes a new visitor', function () {
    [$key] = newVisitor();
    $unknown = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    foreach (['unknown' => $unknown, 'short' => 'abc', 'too long' => $key.'A', 'not base64url' => substr($key, 0, 42).'+', 'with a space' => substr($key, 0, 20).' '.substr($key, 21)] as $what => $sent) {
        $answer = visitorClient($sent)->get('/api/accounts');
        refusedAs($answer, 401, 'visitor_unknown', $what);
        same('This browser\'s visit has ended: what it imported was deleted after a long time unused, or moved into an account at sign-in.', $answer->json('message'), $what);
        refusedAs(visitorClient($sent)->post('/api/imports/file', ['content' => goodFile()]), 401, 'visitor_unknown', "{$what}: an import");
    }
    same(1, visitorCount(), 'only the one visitor');

    // No key and no token: sign in (or import, which makes a visitor).
    refusedAs((new Client)->get('/api/accounts'), 401, 'sign_in_required', 'nobody');
    refusedAs(visitorClient('')->get('/api/accounts'), 401, 'sign_in_required', 'an empty key is none');
});

// ── Isolation ──────────────────────────────────────────────────────────────────────────────────────

test('visitors and members each reach their own accounts and nobody else\'s', function () {
    seedUser(PERSON, 'mona');
    $persons = seedAccount(PERSON, 'genshin', ['uid' => '700000001', 'nickname' => 'Mona main']);
    [$a] = newVisitor('198.51.100.1');
    [$b] = newVisitor('198.51.100.2');
    $alice = visitorClient($a);
    $bob = visitorClient($b, '198.51.100.2');
    $as = (int) $alice->get('/api/accounts')->json('0.id');
    $bs = (int) $bob->get('/api/accounts')->json('0.id');
    check($as !== $bs, 'two accounts');

    foreach ([[$alice, $bs, 'A, B\'s'], [$alice, $persons, 'A, the person\'s'], [$bob, $as, 'B, A\'s'], [$bob, $persons, 'B, the person\'s']] as [$client, $account, $what]) {
        refusedAs($client->get("/api/accounts/{$account}"), 404, 'not_found', $what);
        refusedAs($client->get("/api/accounts/{$account}/imports"), 404, 'not_found', "{$what} imports");
        refusedAs($client->patch("/api/accounts/{$account}", ['nickname' => 'x']), 404, 'not_found', "{$what} rename");
        refusedAs($client->delete("/api/accounts/{$account}"), 404, 'not_found', "{$what} delete");
        $client->post('/api/imports/file', ['content' => goodFile(), 'account_id' => $account])->status(422)
            ->path('errors.account_id.0', 'The selected account does not exist.');
    }
    same([$as], array_column($alice->get('/api/accounts')->json(), 'id'), 'A lists its own');
    $member = (new Client)->asIam(PERSON);
    same([$persons], array_column($member->get('/api/accounts')->json(), 'id'), 'the person lists theirs');
    refusedAs($member->get("/api/accounts/{$as}"), 404, 'not_found', 'the person, A\'s');

    // A token wins over a key: the person's data, and the key is not even looked at.
    $both = (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => $a]);
    same([$persons], array_column($both->get('/api/accounts')->json(), 'id'), 'the person\'s token with A\'s key');
    $both->post('/api/imports/file', ['content' => goodFile()])->status(201);
    same(1, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = (SELECT id FROM users WHERE visitor_key_hash = ?)', [hash('sha256', $a)]), 'nothing went into A');

    // Two visitors with the same UID: two accounts.
    T::$http->fake(['enka.network/*' => FakeHttpClient::json(profile('700000002', 'Twin'))]);
    $first = $alice->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000002'])->status(201)->json('account_id');
    $second = $bob->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000002'])->status(201)->json('account_id');
    check($first !== $second, 'one account each');
    same(2, (int) db()->value("SELECT count(DISTINCT user_id) FROM accounts WHERE uid = '700000002'"), 'kept apart');
});

test('a visitor reaches nothing but its own accounts and imports: no sign-in, no role, no admin panel', function () {
    [$key] = newVisitor();
    $visitor = visitorClient($key);

    $signedInOnly = [
        ['GET', '/api/sync'], ['GET', '/api/sync-runs'], ['POST', '/api/sync/builds'], ['POST', '/api/sources/genshin-1/sync'], ['GET', '/api/sources/genshin-1/export'],
        ['PATCH', '/api/sources/genshin-1'], ['GET', '/api/admin/migrations'], ['POST', '/api/admin/migrations/apply'],
        ['GET', '/api/admin/translations'], ['PUT', '/api/admin/translations'], ['GET', '/api/admin/languages'],
        ['POST', '/api/admin/languages'], ['DELETE', '/api/admin/languages/sk'], ['GET', '/api/admin/people'],
        ['DELETE', '/api/admin/people/1'], ['POST', '/api/visitor/move'], ['GET', '/api/me/export'], ['POST', '/api/me/import'],
    ];
    foreach ($signedInOnly as [$method, $url]) {
        refusedAs($visitor->request($method, $url, $method === 'GET' || $method === 'DELETE' ? null : []), 401, 'sign_in_required', "{$method} {$url}");
    }
    // The public pages are the same for a visitor as for anybody, with nothing of a person's.
    $visitor->get('/api/builds/genshin')->status(200);
    check(! array_key_exists('started_by', $visitor->get('/api/sources')->status(200)->json('0') ?? []), 'no started_by');
    $visitor->get('/api/meta')->status(200)->path('visitors.keep_days', 30);
});

test('a token that is there but does not verify never falls back to the key: 401, and the visitor is untouched', function () {
    [$key] = newVisitor();
    $twoHoursAgo = Clock::now()->modify('-2 hours');
    db()->run('UPDATE users SET last_seen_at = ? WHERE visitor_key_hash = ?', [$twoHoursAgo, hash('sha256', $key)]);
    $before = [visitorRow($key), db()->all('SELECT * FROM accounts ORDER BY id'), db()->all('SELECT * FROM import_runs ORDER BY id')];

    $tokens = [
        'expired' => 'Bearer '.iamToken(PERSON, claims: ['exp' => time() - 120]),
        'a bad signature' => 'Bearer '.iamToken(PERSON, iam: strangerIam()),
        'garbage' => 'Bearer x',
        'empty bearer' => 'Bearer ',
    ];
    foreach ($tokens as $what => $authorization) {
        $client = (new Client)->withHeaders(['X-Visitor-Key' => $key, 'Authorization' => $authorization]);
        refusedAs($client->get('/api/accounts'), 401, 'invalid_token', "{$what}: the accounts");
        refusedAs($client->post('/api/imports/file', ['content' => goodFile(90)]), 401, 'invalid_token', "{$what}: an import");
    }
    same($before, [visitorRow($key), db()->all('SELECT * FROM accounts ORDER BY id'), db()->all('SELECT * FROM import_runs ORDER BY id')],
        'the visitor, its accounts and runs, and when it was last seen: as they were');
    same(0, (int) db()->value('SELECT count(*) FROM users WHERE id > 0'), 'nobody recorded');
});

test('last_seen_at is written at most once an hour', function () {
    [$key] = newVisitor();
    $hash = hash('sha256', $key);
    $seen = fn () => Db::time(db()->value('SELECT last_seen_at FROM users WHERE visitor_key_hash = ?', [$hash]));

    $halfAnHour = Clock::now()->modify('-30 minutes');
    db()->run('UPDATE users SET last_seen_at = ? WHERE visitor_key_hash = ?', [$halfAnHour, $hash]);
    visitorClient($key)->get('/api/accounts')->status(200);
    same($halfAnHour->getTimestamp(), $seen()->getTimestamp(), 'seen half an hour ago: not written');

    db()->run('UPDATE users SET last_seen_at = ? WHERE visitor_key_hash = ?', [Clock::now()->modify('-61 minutes'), $hash]);
    visitorClient($key)->get('/api/accounts')->status(200);
    check(abs($seen()->getTimestamp() - Clock::timestamp()) < 5, 'seen over an hour ago: now');
});

// ── Limits ─────────────────────────────────────────────────────────────────────────────────────────

test('new visitors an hour per address: IPv4 by address, IPv6 by its /64', function () {
    T::$app->config->set('visitors.new_per_hour_per_address', 2);

    newVisitor('198.51.100.7');
    newVisitor('198.51.100.7');
    $refused = (new Client('198.51.100.7'))->post('/api/imports/file', ['content' => goodFile()]);
    refusedAs($refused, 429, 'too_many_visitors', 'the third from one address');
    same('Too many new visitors from this address. Sign in to continue, or try again later.', $refused->json('message'), 'says to sign in');
    same('3600', $refused->header('Retry-After'), 'in an hour');
    same('', $refused->header('X-Visitor-Key'), 'no key');
    refusedAs((new Client('::ffff:198.51.100.7'))->post('/api/imports/file', ['content' => goodFile()]), 429, 'too_many_visitors', 'the same IPv4 address written as IPv6');
    newVisitor('198.51.100.8');
    // A signed-in person from that address is no visitor.
    (new Client('198.51.100.7'))->asIam(PERSON)->post('/api/imports/file', ['content' => goodFile()])->status(201);

    newVisitor('2001:db8:1:2::1');
    newVisitor('2001:db8:1:2:ffff::9');
    refusedAs((new Client('2001:db8:1:2::abcd'))->post('/api/imports/file', ['content' => goodFile()]), 429, 'too_many_visitors', 'a third from the same /64');
    newVisitor('2001:db8:1:3::1');
    same(6, visitorCount(), 'six visitors: three from IPv4 addresses, three from IPv6 ones');

    // Counted by a keyed hash of each address (the installation's key), never the address: the cache table
    // and every backup of it hold none.
    $keys = db()->column('SELECT key FROM cache ORDER BY key');
    same([], array_values(array_filter($keys, fn (string $key): bool => str_contains($key, '198.51.100') || str_contains($key, '2001:db8'))), 'no address in a counter: '.implode(', ', $keys));
    same(4, count(preg_grep('/^visitors:new:[0-9a-f]{32}$/', $keys) ?: []), 'one counter for each address (an IPv6 /64 as one) - by its hash');
});

test('behind a trusted proxy the proxy\'s header names the address - and from anybody else no header is believed', function () {
    T::$app->config->set('visitors.new_per_hour_per_address', 1);
    T::$app->config->set('client_address.trusted_proxies', ['10.0.0.0/8', '2001:db8:ff::/48']);

    $viaProxy = fn (string $proxy, string $visitor): Answer => (new Client($proxy))->withHeaders(['CF-Connecting-IP' => $visitor])
        ->post('/api/imports/file', ['content' => goodFile()]);
    $viaProxy('10.1.1.1', '198.51.100.20')->status(201);
    $viaProxy('10.2.2.2', '198.51.100.21')->status(201);
    $viaProxy('2001:db8:ff::1', '198.51.100.22')->status(201);
    refusedAs($viaProxy('10.3.3.3', '198.51.100.20'), 429, 'too_many_visitors', 'the same visitor through another edge of the proxy');

    // Not from the proxy: the header is whatever the sender wrote, and the connection's address is counted.
    foreach (['198.51.100.30', '198.51.100.31'] as $i => $claimed) {
        $answer = (new Client('203.0.113.5'))->withHeaders(['CF-Connecting-IP' => $claimed, 'X-Forwarded-For' => $claimed])
            ->post('/api/imports/file', ['content' => goodFile()]);
        same($i === 0 ? 201 : 429, $answer->status, "an untrusted sender claiming {$claimed}");
    }
    // X-Forwarded-For alone is never read, from a trusted proxy either: without its own header, the proxy
    // itself is the address.
    foreach (['198.51.100.40', '198.51.100.41'] as $i => $claimed) {
        $answer = (new Client('10.4.4.4'))->withHeaders(['X-Forwarded-For' => $claimed])->post('/api/imports/file', ['content' => goodFile()]);
        same($i === 0 ? 201 : 429, $answer->status, "the proxy with only X-Forwarded-For {$claimed}");
    }

    // A setting that names another header - as config/client_address.local.php once could - changes nothing: only
    // CF-Connecting-IP is ever read (the owner's rule of 2026-10-11). X-Forwarded-For names nobody even from a
    // trusted proxy, a list or one address: the proxy itself is counted.
    T::$app->config->set('client_address.client_ip_header', 'X-Forwarded-For');
    $viaHeader = fn (string $proxy, string $named): Answer => (new Client($proxy))->withHeaders(['X-Forwarded-For' => $named])
        ->post('/api/imports/file', ['content' => goodFile()]);
    $viaHeader('10.5.5.5', '198.51.100.50, 198.51.100.60')->status(201);
    refusedAs($viaHeader('10.5.5.5', '198.51.100.51, 198.51.100.61'), 429, 'too_many_visitors', 'a list names nobody: the proxy itself is counted');
    $viaHeader('10.6.6.6', '198.51.100.62')->status(201);
    refusedAs($viaHeader('10.6.6.6', '198.51.100.63'), 429, 'too_many_visitors', 'one address in it names nobody either: the proxy itself is counted');
});

test('only CF-Connecting-IP names the address, and only from Cloudflare: X-Real-IP and True-Client-IP - which Cloudflare passes through as the visitor wrote them - name nobody, whatever a setting says', function () {
    T::$app->config->set('visitors.new_per_hour_per_address', 1);
    foreach (['X-Real-IP' => '173.245.48.20', 'True-Client-IP' => '173.245.48.21'] as $header => $edge) {
        // As if config/client_address.local.php named it - the setting is not read (App\Config takes the proxies alone).
        T::$app->config->set('client_address.client_ip_header', $header);
        $claiming = fn (string $claimed): Answer => (new Client($edge))->withHeaders([$header => $claimed])
            ->post('/api/imports/file', ['content' => goodFile()]);
        $claiming('198.51.100.110')->status(201);
        refusedAs($claiming('198.51.100.111'), 429, 'too_many_visitors', "{$header} from Cloudflare's edge names nobody: the edge itself is counted");
    }
    // The admin tools' reading likewise: their header is Cloudflare's, and no setting reaches it.
    same('CF-Connecting-IP', T::$app->get(Anotoki\Lib\Admin\AdminContext::class)->clientHeader(), 'the admin tools read CF-Connecting-IP alone');
    // And an installation's own file names its proxies, and nothing else: a header it names is left out.
    $root = T::$storage.DIRECTORY_SEPARATOR.'client-address-root';
    @mkdir($root.'/config', 0777, true);
    copy(dirname(__DIR__).'/config/app.php', $root.'/config/app.php');
    file_put_contents($root.'/config/client_address.local.php', "<?php return ['trusted_proxies' => ['10.0.0.0/8'], 'client_ip_header' => 'X-Real-IP'];\n");
    try {
        $loaded = App\Config::load($root);
        same([['10.0.0.0/8'], null], [$loaded->get('client_address.trusted_proxies'), $loaded->get('client_address.client_ip_header')],
            'the proxies taken, the header left out');
    } finally {
        removeTree($root);
    }
});

test('a proxy range wider than /8 (IPv6: /16) is refused - believed, a forged CF-Connecting-IP from anywhere in it would walk past every limit; Cloudflare\'s own list passes', function () {
    $refusal = 'config/client_address.local.php trusts a proxy range wider than /8 (IPv4) or /16 (IPv6)';
    $refused = fn (array $ranges): bool => in_array($refusal, testConfig(['client_address' => ['trusted_proxies' => $ranges]])->problems(), true);

    foreach ([['0.0.0.0/0'], ['::/0'], ['10.0.0.0/8', '0.0.0.0/1'], ['2000::/3'], [' 64.0.0.0 / 2 ']] as $ranges) {
        same(true, $refused($ranges), 'refused: '.implode(', ', $ranges));
    }
    foreach ([Anotoki\Lib\Support\ClientAddress::CLOUDFLARE, [], ['10.0.0.0/8', '2001:db8:ff::/48'], ['2001:db8::/16'], ['198.51.100.7']] as $ranges) {
        same(false, $refused($ranges), 'taken: '.(implode(', ', $ranges) ?: 'none'));
    }
});

test('behind Cloudflare, as committed: a connection from one of its ranges counts the address CF-Connecting-IP names, any other is counted itself - and the address is kept nowhere, nor in a backup', function () {
    T::$app->config->set('visitors.new_per_hour_per_address', 1);
    same([Anotoki\Lib\Support\ClientAddress::CLOUDFLARE, null],
        [T::$app->config->get('client_address.trusted_proxies'), T::$app->config->get('client_address.client_ip_header')],
        'Cloudflare\'s 22 published ranges - the anotoki library\'s list of them - and no header setting: CF-Connecting-IP, always');

    $through = fn (string $connection, string $named): Answer => (new Client($connection))->withHeaders(['CF-Connecting-IP' => $named])
        ->post('/api/imports/file', ['content' => goodFile()]);
    $through('173.245.48.10', '198.51.100.70')->status(201);
    refusedAs($through('2400:cb00::1', '198.51.100.70'), 429, 'too_many_visitors', 'the same visitor through another of Cloudflare\'s edges (IPv6)');
    refusedAs($through('::ffff:173.245.48.11', '198.51.100.70'), 429, 'too_many_visitors', 'and through an IPv4 edge written as IPv6 (a server on [::])');
    $through('104.16.0.1', '198.51.100.71')->status(201);

    // From a connection that is not Cloudflare's, the header is whatever the sender wrote: the connection is counted.
    $through('203.0.113.9', '198.51.100.80')->status(201);
    refusedAs($through('203.0.113.9', '198.51.100.81'), 429, 'too_many_visitors', 'another address claimed from the same connection');
    same(3, visitorCount(), 'three visitors: two named by Cloudflare, one by its own connection');

    // Never the address itself: in the counters a keyed hash of it - and nothing of it in a backup of the database.
    $addresses = ['198.51.100.70', '198.51.100.71', '198.51.100.80', '198.51.100.81', '203.0.113.9', '173.245.48.10', '2400:cb00', '104.16.0.1'];
    $keys = implode("\n", db()->column('SELECT key FROM cache'));
    same([], array_values(array_filter($addresses, fn (string $address): bool => str_contains($keys, $address))), 'no address in the counters');
    $backups = T::$app->get(Anotoki\Lib\Backups\Backups::class);
    $made = $backups->create();
    check(is_array($made), 'a backup made');
    $folder = T::$storage.DIRECTORY_SEPARATOR.'backup-read-'.bin2hex(random_bytes(4));
    try {
        $backups->extractTo($made['id'], $folder);
        $everything = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            $everything .= (string) file_get_contents($file->getPathname());
        }
        check(str_contains($everything, 'visitors:new:'), 'the backup has the counters');
        same([], array_values(array_filter($addresses, fn (string $address): bool => str_contains($everything, $address))), 'and no address');
    } finally {
        removeTree($folder);
        removeTree(T::$storage.DIRECTORY_SEPARATOR.'backups');
    }
});

test('with the origin secret (config/cloudflare.local.php), CF-Connecting-IP from Cloudflare only on a request carrying it in X-Anotoki-Origin: without it, or with a wrong one, a new address claimed every time is the edge - for the visitors\' limit and the admin tools alike; the right one names the visitor; from anywhere else, the connection', function () {
    $secret = 'origin-'.bin2hex(random_bytes(20));
    T::$app->config->set('visitors.new_per_hour_per_address', 1);
    $through = fn (string $connection, string $named, array $headers = []): Answer => (new Client($connection))->withHeaders(['CF-Connecting-IP' => $named] + $headers)
        ->post('/api/imports/file', ['content' => goodFile()]);

    // (a) No secret (the tests' settings have no cloudflare.local.php): the visitor Cloudflare names, as before -
    // whatever X-Anotoki-Origin says.
    same(null, T::$app->config->originSecret(), 'no origin secret');
    $through('173.245.48.10', '198.51.100.120', ['X-Anotoki-Origin' => 'anything'])->status(201);
    $through('173.245.48.10', '198.51.100.121')->status(201);

    // A Worker of anybody's, writing the CF-Connecting-IP it likes: the edge it came from.
    T::$app->config->set('cloudflare.origin_secret', $secret);
    same($secret, T::$app->config->originSecret(), 'the secret');
    $through('173.245.48.11', '198.51.100.122')->status(201);
    refusedAs($through('173.245.48.11', '198.51.100.123'), 429, 'too_many_visitors', '(b) a new address claimed through the same edge without X-Anotoki-Origin: the edge, counted already');
    foreach (['another value' => 'not the secret', 'one character short' => substr($secret, 0, -1), 'one more' => $secret.'x', 'in capitals' => strtoupper($secret)] as $what => $sent) {
        refusedAs($through('173.245.48.11', '198.51.100.124', ['X-Anotoki-Origin' => $sent]), 429, 'too_many_visitors', "(c) a wrong secret ({$what}): the edge too");
    }
    // Through the owner's zone - the secret beside it - the visitor is themselves, through any edge.
    $through('173.245.48.11', '198.51.100.125', ['X-Anotoki-Origin' => $secret])->status(201);
    refusedAs($through('2400:cb00::1', '198.51.100.125', ['X-Anotoki-Origin' => $secret]), 429, 'too_many_visitors', '(d) the same visitor through another edge, with the secret: that visitor, counted already');
    // From outside Cloudflare's ranges the secret opens nothing: the connection is counted.
    $through('203.0.113.20', '198.51.100.126', ['X-Anotoki-Origin' => $secret])->status(201);
    refusedAs($through('203.0.113.20', '198.51.100.127', ['X-Anotoki-Origin' => $secret]), 429, 'too_many_visitors', '(e) another address claimed with the secret from outside the ranges: the connection, counted already');

    // The admin tools' reading (the feedback's limits, the error log's client hash): their context, made from these
    // settings as the API makes it, counts alike.
    $context = App\Admin\AdminTools::context(T::$app);
    $request = fn (string $connection, array $headers): Psr\Http\Message\ServerRequestInterface => array_reduce(
        array_keys($headers),
        fn (Psr\Http\Message\ServerRequestInterface $request, string $name) => $request->withHeader($name, $headers[$name]),
        (new Slim\Psr7\Factory\ServerRequestFactory)->createServerRequest('POST', '/api/feedback', ['REMOTE_ADDR' => $connection]),
    );
    foreach ([
        '(b) without X-Anotoki-Origin: the edge' => [$request('173.245.48.11', ['CF-Connecting-IP' => '198.51.100.130']), '173.245.48.11'],
        '(c) with a wrong one: the edge' => [$request('173.245.48.11', ['CF-Connecting-IP' => '198.51.100.130', 'X-Anotoki-Origin' => 'not the secret']), '173.245.48.11'],
        '(d) with the right one: the visitor' => [$request('173.245.48.11', ['CF-Connecting-IP' => '198.51.100.130', 'X-Anotoki-Origin' => $secret]), '198.51.100.130'],
        '(d) ...over IPv6 too, by the visitor\'s /64' => [$request('2400:cb00::1', ['CF-Connecting-IP' => '2001:db8:aa:bb::1', 'X-Anotoki-Origin' => $secret]), '2001:db8:aa:bb::/64'],
        '(e) the right one from outside the ranges: the connection' => [$request('203.0.113.20', ['CF-Connecting-IP' => '198.51.100.130', 'X-Anotoki-Origin' => $secret]), '203.0.113.20'],
    ] as $what => [$asked, $bucket]) {
        same([$bucket, $bucket], [$context->clientKey($asked), App\Web\ClientAddress::counted($asked, T::$app->config)], "the admin tools and the site's counters: {$what}");
    }
});

test('config/cloudflare.local.php: its origin_secret alone, a string with something in it - a file there that cannot be read cleanly, or names no such string, fails closed, a secret no request carries; no secret only without the file - the site set up either way (the file is optional), its value in no problem', function () {
    $secret = 'origin-'.bin2hex(random_bytes(20));
    // A secret written bare is a constant PHP does not know, whose error quotes it; two strings side by side are a
    // parse error quoting the second.
    $bare = 'secret_'.bin2hex(random_bytes(16));
    $root = T::$storage.DIRECTORY_SEPARATOR.'cloudflare-root';
    @mkdir($root.'/config', 0777, true);
    copy(dirname(__DIR__).'/config/app.php', $root.'/config/app.php');
    $load = function (?string $contents) use ($root): App\Config {
        $file = $root.'/config/cloudflare.local.php';
        $contents === null ? @unlink($file) : file_put_contents($file, $contents);

        return App\Config::load($root);
    };
    // Not set up for want of the database's and the IAM's files, as every root here - but never for this file.
    $named = fn (App\Config $config): bool => str_contains(implode("\n", $config->problems()), 'cloudflare');
    $log = ini_get('error_log');
    ini_set('error_log', $root.'/php-errors.log');
    try {
        $loaded = $load(null);
        same([null, false], [$loaded->originSecret(), $named($loaded)], 'no file: no secret, and nothing wrong');
        $loaded = $load("<?php return ['origin_secret' => \" \\t{$secret}\\n\", 'other' => 'x'];\n");
        same([$secret, null, false], [$loaded->originSecret(), $loaded->get('cloudflare.other'), $named($loaded)],
            'the secret, spaces around it no part of it - and nothing else of the file taken');
        // Read cleanly but naming no secret (2026-10-18): a slip in the file, never a way to switch the secret off -
        // a secret no request carries, never the file's value.
        foreach ([
            'an empty value' => "<?php return ['origin_secret' => ''];\n",
            'only spaces' => "<?php return ['origin_secret' => '   '];\n",
            'a number' => "<?php return ['origin_secret' => 123456789012345678];\n",
            'a list' => "<?php return ['origin_secret' => ['{$secret}']];\n",
            'another key' => "<?php return ['secret' => '{$secret}'];\n",
            'no array returned' => "<?php return '{$secret}';\n",
        ] as $what => $contents) {
            $loaded = $load($contents);
            $read = $loaded->originSecret();
            check(is_string($read) && preg_match('/^[0-9a-f]{64}$/D', $read) === 1 && $read !== $secret, "{$what}: read cleanly, no secret named - fails closed, a secret no request carries");
            same(false, $named($loaded), "{$what}: and the site keeps running - the file is optional");
        }
        foreach ([
            'a parse error' => "<?php return ['origin_secret' => 'x' '{$secret}'];\n",
            'a word without its quotes' => "<?php return ['origin_secret' => {$bare}];\n",
            // Written by hand without its first line, or saved with a BOM: PHP would print the file before every answer.
            'no <?php line' => "return ['origin_secret' => '{$secret}'];\n",
            'a BOM' => "\xEF\xBB\xBF<?php return ['origin_secret' => '{$secret}'];\n",
        ] as $what => $contents) {
            ob_start();
            try {
                $loaded = $load($contents);
            } finally {
                $printed = (string) ob_get_clean();
            }
            same('', $printed, "{$what}: nothing of the file printed");
            $read = $loaded->originSecret();
            check(is_string($read) && preg_match('/^[0-9a-f]{64}$/D', $read) === 1 && $read !== $secret, "{$what}: fails closed - a secret no request carries, never the file's, never none");
            same(false, $named($loaded), "{$what}: and the site keeps running - the file is optional");
        }
        $said = (string) @file_get_contents($root.'/php-errors.log');
        check(! str_contains($said, $secret) && ! str_contains($said, $bare), 'PHP\'s error log never holds the value: '.$said);
    } finally {
        ini_set('error_log', $log === false ? '' : $log);
        removeTree($root);
    }
});

test('a site-wide ceiling on new visitors an hour, and on visitors at once', function () {
    T::$app->config->set('visitors.new_per_hour', 3);
    newVisitor('198.51.100.1');
    newVisitor('198.51.100.2');
    newVisitor('198.51.100.3');
    $refused = (new Client('198.51.100.4'))->post('/api/imports/file', ['content' => goodFile()]);
    refusedAs($refused, 429, 'too_many_visitors', 'the fourth this hour, from anywhere');
    check(str_contains($refused->json('message'), 'Sign in'), 'says to sign in');

    T::$app->config->set('visitors.new_per_hour', 100);
    T::$app->config->set('visitors.max_visitors', 4);
    newVisitor('198.51.100.5');
    $full = (new Client('198.51.100.6'))->post('/api/imports/file', ['content' => goodFile()]);
    refusedAs($full, 429, 'too_many_visitors', 'past the cap on visitors at once');
    same('Too many people are using the site without signing in just now. Sign in to continue, or try again later.', $full->json('message'), 'the sentence');
    same(4, visitorCount(), 'four visitors');
    (new Client('198.51.100.6'))->asIam(PERSON)->post('/api/imports/file', ['content' => goodFile()])->status(201);
});

test('a visitor keeps a few game accounts and imports small files; a person neither', function () {
    T::$app->config->set('visitors.max_accounts', 2);
    T::$http->fake(['enka.network/*' => FakeHttpClient::json(profile('700000003', 'Three'))]);

    $made = (new Client)->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000003'])->status(201);
    $visitor = visitorClient($made->header('X-Visitor-Key'));
    $file = (int) $visitor->post('/api/imports/file', ['content' => goodFile()])->status(201)->json('account_id');
    $full = $visitor->post('/api/imports/file', ['content' => goodFile()]);
    refusedAs($full, 422, 'visitor_limit', 'a third account');
    same(['limit' => 'accounts', 'max' => 2], $full->json('params'), 'what was reached');
    same('Without signing in, this browser keeps at most 2 game accounts. Import into one of them, or sign in to add more.', $full->json('message'), 'the sentence');
    $visitor->post('/api/imports/file', ['content' => goodFile(), 'account_id' => $file])->status(201);
    $visitor->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000003'])->status(201);
    refusedAs($visitor->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000004']), 422, 'visitor_limit', 'another UID');
    same(2, (int) db()->value('SELECT count(*) FROM accounts'), 'two accounts');

    T::$app->config->set('visitors.file_max_kb', 1);
    $big = goodFile().str_repeat(' ', 1100);
    $tooBig = $visitor->post('/api/imports/file', ['content' => $big, 'account_id' => $file]);
    refusedAs($tooBig, 422, 'visitor_limit', 'a file past the size');
    same(['limit' => 'file_size', 'max' => 1], $tooBig->json('params'), 'what was reached');
    $upload = T::$storage.DIRECTORY_SEPARATOR.'big.json';
    file_put_contents($upload, $big);
    refusedAs($visitor->request('POST', '/api/imports/file', null, ['files' => ['file' => [$upload, 'big.json']], 'form' => ['account_id' => (string) $file]]),
        422, 'visitor_limit', 'an uploaded file past the size');
    $first = (new Client('198.51.100.9'))->post('/api/imports/file', ['content' => $big]);
    refusedAs($first, 422, 'visitor_limit', 'a first import past the size');
    same('', $first->header('X-Visitor-Key'), 'which makes nobody');

    $person = (new Client)->asIam(PERSON);
    for ($i = 0; $i < 3; $i++) {
        $person->post('/api/imports/file', ['content' => $big])->status(201);
    }
});

// ── What changed while an import was on its way ──────────────────────────────────────────────────
// A showcase import is fetched after requireOwner() found who it is for and before the import's own
// transaction: what the fakes do during the fetch is what another request did meanwhile.

/** The log of this test: forced to hold a line (a refused token), so "no ERROR" is read from a log that works. */
function watchedLog(string $name): string
{
    $log = T::$storage.DIRECTORY_SEPARATOR.$name;
    @unlink($log);
    Log::to($log);
    (new Client)->withHeaders(['Authorization' => 'Bearer not-a-token'])->get('/api/accounts')->status(401);
    check(str_contains((string) @file_get_contents($log), '[anotoki auth] token refused'), 'the log is written');

    return $log;
}

test('a visitor whose row goes while its import is on its way (moved at sign-in, swept): 401 visitor_unknown, nothing written, no error', function () {
    $log = watchedLog('gone-visitor.log');
    foreach (['into a new account' => '700000005', 'into its own account' => '700000015'] as $what => $uid) {
        $intoOwn = $uid === '700000015';
        [$key] = newVisitor();
        $id = (int) visitorRow($key)['id'];
        $account = (int) db()->value('SELECT id FROM accounts WHERE user_id = ?', [$id]);
        T::$http->fake(['enka.network/*' => function () use ($id, $uid): mixed {
            db()->run('DELETE FROM users WHERE id = ?', [$id]);

            return FakeHttpClient::json(profile($uid, 'Gone'));
        }]);

        $answer = visitorClient($key)->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => $uid] + ($intoOwn ? ['account_id' => $account] : []));
        refusedAs($answer, 401, 'visitor_unknown', $what);
        same([0, 0], [(int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [$id]), (int) db()->value('SELECT count(*) FROM import_runs WHERE user_id = ?', [$id])],
            "{$what}: nothing written under the id that went");
    }
    same(0, (int) db()->value('SELECT count(*) FROM accounts'), 'no account anywhere');
    check(! str_contains((string) file_get_contents($log), 'ERROR'), 'no error logged: '.file_get_contents($log));
    Log::to(null);
});

test('a person whose data an admin deletes while their import is on its way: 401 sign_in_required, nothing written; the next request starts afresh', function () {
    $log = watchedLog('gone-person.log');
    seedUser(PERSON, 'mona');
    $person = (new Client)->asIam(PERSON);
    T::$http->fake(['enka.network/*' => function (): mixed {
        db()->run('DELETE FROM users WHERE id = ?', [PERSON]);

        return FakeHttpClient::json(profile('700000006', 'Mona'));
    }]);

    refusedAs($person->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000006']), 401, 'sign_in_required', 'the import');
    same(0, (int) db()->value('SELECT count(*) FROM accounts'), 'nothing written');
    check(! str_contains((string) file_get_contents($log), 'ERROR'), 'no error logged: '.file_get_contents($log));

    T::$http->fake(['enka.network/*' => FakeHttpClient::json(profile('700000006', 'Mona'))]);
    $person->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000006'])->status(201);
    same(1, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [PERSON]), 'the retry starts them afresh');
    Log::to(null);
});

test('a visitor\'s account cap holds under the lock: an account added meanwhile counts', function () {
    T::$app->config->set('visitors.max_accounts', 2);
    [$key] = newVisitor();
    $id = (int) visitorRow($key)['id'];
    $runs = (int) db()->value('SELECT count(*) FROM import_runs');
    // The route counts one account and lets the import in; meanwhile another import adds the second.
    T::$http->fake(['enka.network/*' => function () use ($id): mixed {
        seedAccount($id, 'genshin', ['nickname' => 'Meanwhile']);

        return FakeHttpClient::json(profile('700000007', 'Third'));
    }]);

    $answer = visitorClient($key)->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000007']);
    refusedAs($answer, 422, 'visitor_limit', 'the account past the cap');
    same(['limit' => 'accounts', 'max' => 2], $answer->json('params'), 'what was reached');
    same(2, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [$id]), 'still two accounts');
    same($runs, (int) db()->value('SELECT count(*) FROM import_runs'), 'a refusal is not a failed import: no run');
});

test('an account deleted while an import into it is on its way: 422 account_missing, the failed run without it', function () {
    $log = watchedLog('gone-account.log');
    [$key] = newVisitor();
    $id = (int) visitorRow($key)['id'];
    $account = (int) db()->value('SELECT id FROM accounts WHERE user_id = ?', [$id]);
    T::$http->fake(['enka.network/*' => function () use ($account): mixed {
        db()->run('DELETE FROM accounts WHERE id = ?', [$account]);

        return FakeHttpClient::json(profile('700000008', 'Deleted'));
    }]);

    refusedAs(visitorClient($key)->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000008', 'account_id' => $account]), 422, 'account_missing', 'the import');
    same(0, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [$id]), 'no account made in its place');
    same([['status' => 'failed', 'account_id' => null]], db()->all("SELECT status, account_id FROM import_runs WHERE user_id = ? AND source = 'enka'", [$id]), 'the failed run, on no account');
    check(! str_contains((string) file_get_contents($log), 'ERROR'), 'no error logged: '.file_get_contents($log));
    Log::to(null);
});

test('a visitor\'s imports are counted per address, so a fresh key starts nothing over; all visitors\' showcase imports together too', function () {
    T::$app->config->set('imports.file_per_minute', 3);
    [$key] = newVisitor('198.51.100.11');
    $visitor = visitorClient($key, '198.51.100.11');
    $visitor->post('/api/imports/file', ['content' => goodFile()])->status(201);
    $visitor->post('/api/imports/file', ['content' => goodFile()])->status(201);
    refusedAs($visitor->post('/api/imports/file', ['content' => goodFile()]), 429, 'too_many_imports', 'the fourth this minute');
    refusedAs((new Client('198.51.100.11'))->post('/api/imports/file', ['content' => goodFile()]), 429, 'too_many_imports', 'a new visitor from the same address');
    same(1, visitorCount(), 'and no visitor made by it');
    newVisitor('198.51.100.12');
    (new Client('198.51.100.11'))->asIam(PERSON)->post('/api/imports/file', ['content' => goodFile()])->status(201);

    T::$app->config->set('visitors.showcase_per_minute_all', 2);
    T::$http->fake(['enka.network/*' => FakeHttpClient::status(404)]);
    foreach (['198.51.100.21', '198.51.100.22'] as $ip) {
        (new Client($ip))->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000009'])->status(422);
    }
    refusedAs((new Client('198.51.100.23'))->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000009']), 429, 'too_many_imports', 'a third visitor\'s showcase import this minute');
    refusedAs(visitorClient($key, '198.51.100.24')->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000009']), 429, 'too_many_imports', 'an existing visitor\'s too');
    (new Client)->asIam(PERSON)->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000009'])->status(422);
    same(2, visitorCount(), 'the showcase failures made nobody');
});

// ── The sweep ──────────────────────────────────────────────────────────────────────────────────────

test('visitors unseen for 30 days are swept with everything they imported; the rest stay', function () {
    seedUser(PERSON, 'mona');
    $persons = seedAccount(PERSON, 'genshin', ['nickname' => 'Old but a person\'s', 'last_import_at' => Clock::now()->modify('-400 days')]);
    [$old] = oldVisitor(31);
    [$recent] = oldVisitor(29);

    newVisitor(); // making a visitor sweeps, after its answer
    same(null, db()->value('SELECT id FROM users WHERE id = ?', [$old]), 'the visitor unseen for 31 days');
    same(0, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [$old]), 'its accounts');
    same(0, (int) db()->value('SELECT count(*) FROM import_runs WHERE user_id = ?', [$old]), 'its runs');
    same(0, (int) db()->value('SELECT count(*) FROM account_characters c JOIN accounts a ON a.id = c.account_id WHERE a.user_id = ?', [$old]), 'its characters');
    same(2, (int) db()->value('SELECT count(*) FROM account_characters'), 'the 29-day visitor\'s and the new one\'s are left');
    same($recent, (int) db()->value('SELECT id FROM users WHERE id = ?', [$recent]), 'the visitor of 29 days stays');
    same($persons, (int) db()->value('SELECT id FROM accounts WHERE user_id = ?', [PERSON]), 'a person is never swept');
    same(2, visitorCount(), 'the 29-day visitor and the new one');
});

test('a sweep takes a few at a time, and on own-data requests at most once an hour', function () {
    T::$app->config->set('visitors.sweep_batch', 2);
    seedUser(PERSON, 'mona');
    foreach ([40, 35, 31] as $days) {
        oldVisitor($days);
    }
    $member = (new Client)->asIam(PERSON);
    $member->get('/api/accounts')->status(200);
    same(1, visitorCount(), 'the two oldest went');
    $member->get('/api/accounts')->status(200);
    same(1, visitorCount(), 'not again within the hour');
    [$another] = oldVisitor(50, withAccount: false);
    $member->get('/api/accounts')->status(200);
    same(2, visitorCount(), 'still not');
    Clock::travel(3601);
    $member->get('/api/accounts')->status(200);
    same(0, visitorCount(), 'an hour later');
    // A public page never sweeps.
    Clock::travel(7300);
    oldVisitor(45);
    (new Client)->get('/api/builds/genshin')->status(200);
    same(1, visitorCount(), 'the builds browser sweeps nothing');
});

test('a sweep that fails never fails the request it runs in: logged, and the next one tries again', function () {
    T::$app->config->set('visitors.sweep_timeout_ms', 200);
    $log = T::$storage.DIRECTORY_SEPARATOR.'sweep.log';
    @unlink($log);
    Log::to($log);
    seedUser(PERSON, 'mona');
    [$old] = oldVisitor(31);

    // Another request holds the accounts so that nothing can be deleted from them: the sweep's statement
    // runs out of time.
    $other = secondConnection();
    $other->beginTransaction();
    $other->exec('LOCK TABLE accounts IN SHARE MODE');
    try {
        (new Client)->asIam(PERSON)->get('/api/accounts')->status(200);
    } finally {
        $other->rollBack();
    }
    same($old, (int) db()->value('SELECT id FROM users WHERE id = ?', [$old]), 'nothing swept');
    $logged = (string) @file_get_contents($log);
    check(str_contains($logged, 'After the answer') && str_contains($logged, 'statement timeout'), 'the failure is in the log: '.$logged);

    T::$app->get(App\Cache\Cache::class)->forget('visitors:swept');
    (new Client)->asIam(PERSON)->get('/api/accounts')->status(200);
    same(null, db()->value('SELECT id FROM users WHERE id = ?', [$old]), 'swept the next time');
    Log::to(null);
});

// ── The move at sign-in ────────────────────────────────────────────────────────────────────────────

test('the move: the person\'s copy of an account both have stays, everything else becomes theirs, the visitor goes', function () {
    seedUser(PERSON, 'mona');
    seedUser(OTHER_PERSON, 'eda');
    $personsX = seedAccount(PERSON, 'genshin', ['uid' => '700000001', 'nickname' => 'Mona main', 'level' => 60]);
    db()->insert('account_characters', ['account_id' => $personsX, 'game_character_id' => idOf('game_characters', 'hutao'), 'level' => 70, 'source' => 'enka']);
    $others = seedAccount(OTHER_PERSON, 'genshin', ['uid' => '700000002', 'nickname' => 'Eda']);

    T::$http->fake(['enka.network/*' => FakeHttpClient::json(profile('700000001', 'Visitor X'))]);
    $key = (new Client)->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000001'])->status(201)->header('X-Visitor-Key');
    $visitor = visitorClient($key);
    T::$http->fake(['enka.network/*' => FakeHttpClient::json(profile('700000002', 'Visitor Y'))]);
    $visitorsY = (int) $visitor->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000002'])->status(201)->json('account_id');
    $visitorsFile = (int) $visitor->post('/api/imports/file', ['content' => goodFile()])->status(201)->json('account_id');
    $visitor->post('/api/imports/file', ['content' => 'not json'])->status(422); // a failed run, no account
    $visitorId = (int) visitorRow($key)['id'];
    $visitorsX = (int) db()->value("SELECT id FROM accounts WHERE user_id = ? AND uid = '700000001'", [$visitorId]);

    $snapshot = fn (int $account): array => [
        db()->one('SELECT * FROM accounts WHERE id = ?', [$account]),
        db()->all('SELECT * FROM account_characters WHERE account_id = ? ORDER BY id', [$account]),
        db()->all('SELECT * FROM import_runs WHERE account_id = ? ORDER BY id', [$account]),
    ];
    $before = $snapshot($personsX);
    $othersBefore = [$snapshot($others), db()->all('SELECT * FROM users WHERE id = ?', [OTHER_PERSON])];
    $runsMoving = (int) db()->value('SELECT count(*) FROM import_runs WHERE user_id = ? AND (account_id IS NULL OR account_id <> ?)', [$visitorId, $visitorsX]);

    $person = (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => $key]);
    $moved = $person->post('/api/visitor/move')->status(200);
    same(['found' => true, 'moved' => 2, 'kept' => 1, 'imports' => $runsMoving, 'held' => false], $moved->json(), 'the answer');
    same(3, $runsMoving, 'Y\'s, the file\'s and the failed run');

    same($before, $snapshot($personsX), 'the person\'s copy of X, row for row');
    same(null, db()->value('SELECT id FROM accounts WHERE id = ?', [$visitorsX]), 'the visitor\'s copy of X is gone');
    same(0, (int) db()->value('SELECT count(*) FROM import_runs WHERE account_id = ?', [$visitorsX]), 'with its runs');
    same([PERSON, PERSON], array_map('intval', db()->column('SELECT user_id FROM accounts WHERE id IN (?, ?)', [$visitorsY, $visitorsFile])), 'Y and the file\'s account are the person\'s');
    same(1, (int) db()->value('SELECT count(*) FROM import_runs WHERE user_id = ? AND account_id IS NULL AND status = \'failed\'', [PERSON]), 'the failed run moved too');
    same(null, visitorRow($key), 'the visitor is gone');
    same(0, visitorCount(), 'no visitor left');
    same($othersBefore, [$snapshot($others), db()->all('SELECT * FROM users WHERE id = ?', [OTHER_PERSON])], 'another person\'s data untouched');
    same(3, count($person->get('/api/accounts')->json()), 'the person lists all three');
    refusedAs(visitorClient($key)->get('/api/accounts'), 401, 'visitor_unknown', 'the key opens nothing any more');

    // Again (another tab, a retry): nothing to move, and no error.
    $person->post('/api/visitor/move')->status(200)->path('found', false)->path('moved', 0);
});

test('the move asks for both a token and a key, and is safe to ask again', function () {
    seedUser(PERSON, 'mona');
    [$key] = newVisitor();

    refusedAs(visitorClient($key)->post('/api/visitor/move'), 401, 'sign_in_required', 'the key alone');
    refusedAs((new Client)->asIam(PERSON)->post('/api/visitor/move'), 422, 'invalid', 'the token alone');
    (new Client)->asIam(PERSON)->post('/api/visitor/move')->status(422)->path('errors.visitor_key.0', 'The X-Visitor-Key header is required.');
    (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => 'abc'])->post('/api/visitor/move')->status(422)
        ->path('errors.visitor_key.0', 'The X-Visitor-Key header is not a visitor key.');
    $bad = (new Client)->withHeaders(['X-Visitor-Key' => $key, 'Authorization' => 'Bearer '.iamToken(PERSON, claims: ['exp' => time() - 120])]);
    refusedAs($bad->post('/api/visitor/move'), 401, 'invalid_token', 'an expired token with the key');
    same(1, visitorCount(), 'the visitor is still there');

    $unknown = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => $unknown])->post('/api/visitor/move')->status(200)
        ->path('found', false)->path('moved', 0)->path('kept', 0)->path('imports', 0);

    $person = (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => $key]);
    $person->post('/api/visitor/move')->status(200)->path('found', true)->path('moved', 1);
    $person->post('/api/visitor/move')->status(200)->path('found', false)->path('moved', 0);
    (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => $key])->post('/api/visitor/move', ['silent' => 'perhaps'])->status(422)
        ->path('errors.silent.0', 'The silent field must be true or false.');
});

test('the move after a sign-in nobody clicked (the IAM\'s hint): into an account with nothing here yet, as ever - never into one with data: the visitor stays, its key still its own', function () {
    // Silent, an account new here (its first time, after playing as a visitor): it takes the visitor's data in.
    seedUser(PERSON, 'mona');
    [$key] = newVisitor();
    same(['found' => true, 'moved' => 1, 'kept' => 0, 'imports' => 1, 'held' => false],
        (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => $key])->post('/api/visitor/move', ['silent' => true])->status(200)->json(), 'moved: nothing here before');
    same([0, 1], [visitorCount(), (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [PERSON])], 'the visitor gone, the account the person\'s');

    // Silent, an account with data here: nothing moves - the visitor and what it imported stay as they were.
    [$key] = newVisitor('198.51.100.2', goodFile(70));
    $visitorId = (int) visitorRow($key)['id'];
    $visitorsData = fn (): array => [db()->all('SELECT id, user_id, nickname FROM accounts WHERE user_id = ? ORDER BY id', [$visitorId]),
        db()->all('SELECT id, user_id, status FROM import_runs WHERE user_id = ? ORDER BY id', [$visitorId])];
    $before = [$visitorsData(), db()->all('SELECT id FROM accounts WHERE user_id = ? ORDER BY id', [PERSON])];
    same(['found' => true, 'moved' => 0, 'kept' => 0, 'imports' => 0, 'held' => true],
        (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => $key])->post('/api/visitor/move', ['silent' => true])->status(200)->json(), 'held: the person has data here');
    same($before, [$visitorsData(), db()->all('SELECT id FROM accounts WHERE user_id = ? ORDER BY id', [PERSON])], 'the visitor\'s data and the person\'s, as they were');
    same(1, count(visitorClient($key, '198.51.100.2')->get('/api/accounts')->status(200)->json()), 'the key still opens the visitor\'s account on this browser');

    // A failed import is data here too: a person whose one run failed never takes in a visitor silently either.
    seedUser(OTHER_PERSON, 'eda');
    db()->insert('import_runs', ['user_id' => OTHER_PERSON, 'game' => 'genshin', 'source' => 'file', 'status' => 'failed', 'created_at' => Clock::now(), 'updated_at' => Clock::now()]);
    (new Client)->asIam(OTHER_PERSON)->withHeaders(['X-Visitor-Key' => $key])->post('/api/visitor/move', ['silent' => true])->status(200)->path('held', true);

    // Clicked - the same account with data: moved, as always.
    same(['found' => true, 'moved' => 1, 'kept' => 0, 'imports' => 1, 'held' => false],
        (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => $key])->post('/api/visitor/move', ['silent' => false])->status(200)->json(), 'a sign-in the person clicked: moved');
    same([0, 2], [visitorCount(), (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [PERSON])], 'the visitor gone, both accounts the person\'s');
});

test('the move while the person is importing: it waits, and says "again" rather than half-moving - then the person\'s copy stays', function () {
    T::$app->config->set('visitors.move_lock_timeout_ms', 200);
    seedUser(PERSON, 'mona');
    T::$http->fake(['enka.network/*' => FakeHttpClient::json(profile('700000005', 'Both'))]);
    $key = (new Client)->post('/api/imports/showcase', ['game' => 'genshin', 'uid' => '700000005'])->status(201)->header('X-Visitor-Key');
    $accountsBefore = db()->all('SELECT * FROM accounts ORDER BY id');

    // The person's own import of the same UID, still writing in another request.
    $other = secondConnection();
    $other->beginTransaction();
    $now = Clock::now()->format('Y-m-d H:i:sP');
    $other->prepare("INSERT INTO accounts (user_id, game, uid, nickname, created_at, updated_at) VALUES (?, 'genshin', '700000005', 'Theirs', ?, ?)")
        ->execute([PERSON, $now, $now]);

    $person = (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => $key]);
    try {
        $retry = $person->post('/api/visitor/move');
    } finally {
        $other->commit();
    }
    refusedAs($retry, 503, 'visitor_move_retry', 'while the import writes');
    same('2', $retry->header('Retry-After'), 'ask again soon');
    same(1, visitorCount(), 'nothing moved: the visitor is there');
    same($accountsBefore, db()->all('SELECT * FROM accounts WHERE user_id < 0 ORDER BY id'), 'with its account');

    $person->post('/api/visitor/move')->status(200)->path('found', true)->path('moved', 0)->path('kept', 1);
    same(['Theirs'], db()->column("SELECT nickname FROM accounts WHERE uid = '700000005'"), 'the person\'s copy stays');
});

test('the move into a person whose data an admin deleted meanwhile: 401 sign_in_required, nothing moved, no error', function () {
    $log = watchedLog('gone-mover.log');
    seedUser(PERSON, 'mona');
    [$key] = newVisitor();
    $before = [visitorRow($key), db()->all('SELECT * FROM accounts ORDER BY id'), db()->all('SELECT * FROM import_runs ORDER BY id')];
    // The request was let in as the person; then the People page deleted their row, before the move's lock.
    db()->run('DELETE FROM users WHERE id = ?', [PERSON]);

    try {
        T::$app->get(Visitors::class)->moveInto(new User(PERSON, 'mona'), $key);
        check(false, 'the move answered as if it had moved');
    } catch (HttpError $e) {
        same([401, 'sign_in_required', 'Bearer'], [$e->status, $e->extra['code'] ?? null, $e->headers['WWW-Authenticate'] ?? null], 'as every other writer of a person\'s accounts');
    }
    same($before, [visitorRow($key), db()->all('SELECT * FROM accounts ORDER BY id'), db()->all('SELECT * FROM import_runs ORDER BY id')], 'the visitor and what it imported, untouched');
    check(! str_contains((string) file_get_contents($log), 'ERROR'), 'no error logged: '.file_get_contents($log));
    Log::to(null);

    // The page's retry (a fresh token) starts the person afresh, and the move goes through.
    (new Client)->asIam(PERSON)->withHeaders(['X-Visitor-Key' => $key])->post('/api/visitor/move')->status(200)->path('found', true)->path('moved', 1);
    same(0, visitorCount(), 'the visitor is gone');
});

// ── When the IAM requires sign-in ──────────────────────────────────────────────────────────────────

test('sign-in required: nobody\'s import is refused before anything - no visitor made, nothing counted, nothing swept', function () {
    [$old] = oldVisitor(40, withAccount: false);
    iamRequiresSignIn(true);
    $before = db()->all('SELECT key, value FROM cache ORDER BY key');

    foreach (['/api/imports/file' => ['content' => goodFile(), 'file_name' => 'export.json'], '/api/imports/showcase' => ['game' => 'genshin', 'uid' => '700000005']] as $url => $body) {
        $answer = (new Client('198.51.100.1'))->post($url, $body);
        refusedAs($answer, 401, 'sign_in_required', $url);
        same(['', 'Bearer realm="'.TEST_CLIENT.'"'], [$answer->header('X-Visitor-Key'), $answer->header('WWW-Authenticate')], "{$url}: no key, a sign-in asked for");
    }
    same([$old], array_map('intval', db()->column('SELECT id FROM users')), 'no visitor made, and the 40-day one not swept by a refused request');
    same($before, db()->all('SELECT key, value FROM cache ORDER BY key'), 'no limit counted: not the address\'s, not the site\'s, not the imports\'');
    same([], T::$http->sent, 'nothing fetched');
});

test('sign-in required: a visitor\'s key is refused unread - 401 sign_in_required, never visitor_unknown - and the visitor is untouched', function () {
    [$key] = newVisitor();
    $twoHoursAgo = Clock::now()->modify('-2 hours');
    db()->run('UPDATE users SET last_seen_at = ? WHERE visitor_key_hash = ?', [$twoHoursAgo, hash('sha256', $key)]);
    $before = [visitorRow($key), db()->all('SELECT * FROM accounts ORDER BY id')];
    $account = (int) db()->value('SELECT id FROM accounts');
    iamRequiresSignIn(true);

    $unknown = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    foreach (['its key' => $key, 'a key nobody has' => $unknown, 'no key at all' => 'not a key'] as $what => $sent) {
        $visitor = visitorClient($sent);
        refusedAs($visitor->get('/api/accounts'), 401, 'sign_in_required', "{$what}: the accounts");
        refusedAs($visitor->get("/api/accounts/{$account}"), 401, 'sign_in_required', "{$what}: an account");
        refusedAs($visitor->patch("/api/accounts/{$account}", ['nickname' => 'Renamed']), 401, 'sign_in_required', "{$what}: a rename");
        refusedAs($visitor->post('/api/imports/file', ['content' => goodFile(90)]), 401, 'sign_in_required', "{$what}: an import");
        refusedAs($visitor->get('/api/builds/genshin'), 401, 'sign_in_required', "{$what}: the builds");
    }
    same($before, [visitorRow($key), db()->all('SELECT * FROM accounts ORDER BY id')], 'the visitor, its account and when it was last seen: as they were');

    // A person goes on as ever; and when the IAM opens the site again, the same key is that visitor again.
    seedUser(PERSON, 'mona');
    (new Client)->asIam(PERSON, ['MEMBER'])->get('/api/accounts')->status(200);
    iamRequiresSignIn(false);
    same(1, count(visitorClient($key)->get('/api/accounts')->status(200)->json()), 'the key works again');
});

test('sign-in required: the move at sign-in still takes the key in with the token, and brings what the visitor imported', function () {
    seedUser(PERSON, 'mona');
    [$key] = newVisitor();
    iamRequiresSignIn(true);
    refusedAs(visitorClient($key)->post('/api/visitor/move'), 401, 'sign_in_required', 'the key alone');

    (new Client)->asIam(PERSON, ['MEMBER'])->withHeaders(['X-Visitor-Key' => $key])->post('/api/visitor/move')->status(200)
        ->path('found', true)->path('moved', 1);
    same(1, (int) db()->value('SELECT count(*) FROM accounts WHERE user_id = ?', [PERSON]), 'the account is the person\'s');
    same(0, visitorCount(), 'the visitor is gone');
});

test('"Basic" credentials (a host behind a password file) are none: a visitor behind such a host keeps its key, and nobody imports', function () {
    $basic = ['Authorization' => 'Basic b3duZXI6b3duZXI='];
    $made = (new Client('198.51.100.1'))->withHeaders($basic)->post('/api/imports/file', ['content' => goodFile(), 'file_name' => 'export.json'])->status(201);
    $key = $made->header('X-Visitor-Key');
    check(preg_match(KEY_PATTERN, $key) === 1, 'a visitor made with Basic sent along');
    same(1, count(visitorClient($key)->withHeaders($basic)->get('/api/accounts')->status(200)->json()), 'its key opens its accounts');

    iamRequiresSignIn(true);
    refusedAs(visitorClient($key)->withHeaders($basic)->get('/api/accounts'), 401, 'sign_in_required', 'Basic is no token when sign-in is required');
});

// ── The rows themselves ────────────────────────────────────────────────────────────────────────────

test('the database keeps visitors and people apart', function () {
    $refused = function (string $sql, array $params = []): string {
        try {
            db()->transaction(fn () => db()->run($sql, $params));
        } catch (PDOException $e) {
            return $e->getMessage();
        }

        return 'nothing refused';
    };
    $hash = hash('sha256', 'x');
    $now = Clock::now();
    check(str_contains($refused('INSERT INTO users (id, name, last_seen_at) VALUES (-5, \'v\', ?)', [$now]), 'users_visitor_check'), 'a negative id without a key');
    check(str_contains($refused('INSERT INTO users (id, name, visitor_key_hash) VALUES (5, \'p\', ?)', [$hash]), 'users_visitor_check'), 'a person with a key');
    check(str_contains($refused('INSERT INTO users (id, name, visitor_key_hash) VALUES (-5, \'v\', ?)', [$hash]), 'users_visitor_check'), 'a visitor never seen');
    check(str_contains($refused('INSERT INTO users (id, name, visitor_key_hash, last_seen_at) VALUES (-5, \'v\', ?, ?)', [strtoupper($hash), $now]), 'users_visitor_key_hash_check'), 'a hash in capitals');
    db()->run('INSERT INTO users (id, name, visitor_key_hash, last_seen_at) VALUES (-5, \'v\', ?, ?)', [$hash, $now]);
    check(str_contains($refused('INSERT INTO users (id, name, visitor_key_hash, last_seen_at) VALUES (-6, \'v\', ?, ?)', [$hash, $now]), 'users_visitor_key_hash_unique'), 'one key, one visitor');
    check(str_starts_with((string) db()->value("SELECT nextval('visitor_ids')"), '-'), 'the sequence gives negative ids');
});

finishTests();
