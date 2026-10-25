<?php

/**
 * Between an upload and "Apply": the new files are on the host, and the database has not seen the migration
 * with the languages and the strings yet - nor the one with the visitors' columns (004), nor anything after.
 * While they wait the site is closed (the site gate, anotoki/lib - the owner's decision of 2026-10-07): every
 * API path answers 503 update_pending, but /api/meta, the strings of a language and the Migrations page's
 * API, so that the pages start, people can sign in, and an ADMIN applies what waits. Those few answer on the
 * old database: the strings say they are not there yet (503 translations_unavailable), the Migrations page
 * lists what waits, and Apply brings it all.
 *
 * Behind the gate the routes that need the new tables still answer 503s of their own - translations_unavailable,
 * visitors_unavailable, never a 500 - for whatever reaches them with the tables missing: the second line. The
 * tests of it open the gate with a Migrator that sees only 001 and 002 (behindTheGate()).
 *
 * The app of this file runs on a database of its own built from the first two migrations only - from before the
 * anotoki library's own files were released, too: they wait as well - its admin tools' set before this site's,
 * its translations and people sets after it.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Admin\Schema as AdminSchema;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\People\Schema as PeopleSchema;
use Anotoki\Lib\Translations\Schema;
use Anotoki\Lib\Translations\Translations;
use App\App;
use App\Auth\Role;
use App\Commands\Translations as TranslationsCommand;
use App\Db\Db;
use App\Db\Migrations;
use App\Http\HttpClient;
use App\Support\Log;
use Tests\Support\Client;

bootTests();

$server = require __DIR__ . '/../config/database.local.php';

// A folder with copies of 001 and 002, a database built from it, and the app bound to that database. From
// here on the helpers of lib.php (db(), the Client, the seeds, the reset before each test) work on it.
$before = T::$storage . DIRECTORY_SEPARATOR . 'migrations-before';
@mkdir($before, 0777, true);
foreach (['001_adopt_laravel_database.sql', '002_schema.sql'] as $file) {
    copy(Migrations::directory() . '/' . $file, $before . '/' . $file);
}
$pdo = scratchDatabase($server, 'before');
$built = Migrations::migrator($pdo, $before, library: false)->apply();
if ($built['busy'] || $built['error'] !== null) {
    fwrite(STDERR, $built['busy'] ? "the migrations' lock is held: nothing was applied\n" : 'migration ' . ($built['failed']['name'] ?? '') . " failed: {$built['error']}\n");
    exit(1);
}
define('BEFORE_FOLDER', $before);
$database = T::$database . '_before';
T::$app = new App(testConfig(['database' => ['database' => $database, 'persistent' => false] + $server]), web: true);
T::$app->set(Db::class, new Db($pdo));
T::$app->set(HttpClient::class, T::$http);
T::$api = buildAnalyzerApi(T::$app);
beforeEach(function () use ($database): void {
    T::$app->config->set('database.database', $database);
    Log::to(null);
});

/**
 * The files that wait, in the order they run: the library's admin tools' first, every one of the site's but 001
 * and 002, then the library's translations and people.
 *
 * @return list<array{set: string, name: string}>
 */
function waiting(): array
{
    $set = static function (string $set, string $directory): array {
        $names = array_map('basename', glob($directory . '/*.sql') ?: []);
        sort($names);

        return array_map(static fn(string $name): array => ['set' => $set, 'name' => $name], $names);
    };
    $own = array_values(
        array_filter($set(Migrations::SET, Migrations::directory()), static fn(array $file): bool => !in_array($file['name'], ['001_adopt_laravel_database.sql', '002_schema.sql'], true)),
    );

    return [...$set(AdminSchema::SET, AdminSchema::directory()), ...$own, ...$set(Schema::SET, Schema::directory()), ...$set(PeopleSchema::SET, PeopleSchema::directory())];
}

/**
 * $body with the gate open: the site's Migrator sees only 001 and 002 (and not the library's files), so nothing
 * waits and every route is reached - with the tables of 003 and 004 still missing. What answers then is the
 * second line.
 */
function behindTheGate(callable $body): void
{
    T::$app->set(Migrator::class, Migrations::migrator(db()->raw(), BEFORE_FOLDER, library: false));
    try {
        same('ready', (new Client())->get('/api/site-status')->json('state'), 'the gate open');
        $body();
    } finally {
        T::$app->forget(Migrator::class);
    }
}

/** A log file of the test's own: whatever fails on the way is written there. */
function logTo(string $name): string
{
    $log = T::$storage . DIRECTORY_SEPARATOR . $name;
    @unlink($log);
    Log::to($log);

    return $log;
}

function nothingFailed(string $log): void
{
    $logged = is_file($log) ? (string) file_get_contents($log) : '';
    check(!str_contains($logged, ' ERROR '), 'nothing failed on the way: ' . $logged);
    Log::to(null);
}

function goodFile(): string
{
    return json_encode([
        'format' => 'GOOD',
        'version' => 3,
        'source' => 'Some Exporter',
        'characters' => [['key' => 'HuTao', 'level' => 90, 'constellation' => 1, 'ascension' => 6, 'talent' => ['auto' => 10, 'skill' => 9, 'burst' => 8]]],
        'weapons' => [['key' => 'StaffOfHoma', 'level' => 90, 'ascension' => 6, 'refinement' => 1, 'location' => 'HuTao', 'lock' => true]],
        'artifacts' => [
            [
                'setKey' => 'CrimsonWitchOfFlames',
                'slotKey' => 'flower',
                'level' => 20,
                'rarity' => 5,
                'mainStatKey' => 'hp',
                'location' => 'HuTao',
                'lock' => true,
                'substats' => [['key' => 'critRate_', 'value' => 10.5], ['key' => 'critDMG_', 'value' => 14.0]],
            ],
            ['setKey' => 'SomeFutureSet', 'slotKey' => 'goblet', 'level' => 20, 'rarity' => 5, 'mainStatKey' => 'pyro_dmg_', 'location' => '', 'lock' => false, 'substats' => []],
        ],
    ]);
}

/** Every translation route, with a body that would be taken. */
function translationRoutes(): array
{
    return [
        ['GET', '/api/translations/en', null],
        ['GET', '/api/translations/sk', null],
        ['GET', '/api/translations/sk-sk', null],
        ['GET', '/api/admin/languages', null],
        ['POST', '/api/admin/languages', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch']],
        ['PUT', '/api/admin/languages/sk', ['enabled' => false]],
        ['DELETE', '/api/admin/languages/sk', null],
        ['GET', '/api/admin/translations', null],
        ['PUT', '/api/admin/translations', ['values' => ['dashboard.title' => ['sk' => 'Účty']]]],
        ['GET', '/api/admin/translations/sk/export', null],
        ['PUT', '/api/admin/translations/sk/import', ['dashboard.title' => 'Účty']],
    ];
}

test('the database has seen the first two migrations, and the rest wait for an admin', function () {
    same(false, (bool) db()->value("SELECT to_regclass('public.translations') IS NOT NULL"), 'no translations table');
    same(false, (bool) db()->value("SELECT to_regclass('public.languages') IS NOT NULL"), 'no languages table');
    same(false, T::$app->get(Translations::class)->ready(), 'the strings are not there to be read - asked without an error');

    check(in_array(['set' => Migrations::SET, 'name' => '003_languages_and_translations.sql'], waiting(), true), 'the migration with the languages and the strings is on disk');
    same(['state' => 'update_pending', 'installed' => false], (new Client())->get('/api/site-status')->status(200)->json(), 'the site says an update waits');
    $status = (new Client())
        ->asIam(1, [Role::ADMIN])
        ->get('/api/admin/migrations')
        ->status(200);
    same([AdminSchema::SET, Migrations::SET, Schema::SET, PeopleSchema::SET], $status->json('sets'), 'the library\'s admin tools\' set, the site\'s, then the library\'s translations and people');
    same(array_map(fn(array $file): array => $file + ['ready' => true, 'blocked' => false, 'duplicates' => []], waiting()), $status->json('pending'), 'pending, in the order they will run');
    same(['001_adopt_laravel_database.sql', '002_schema.sql'], array_column($status->json('applied'), 'name'), 'applied, in the order they were');
    same([], $status->json('missing'), 'nothing missing');
    foreach ($status->json('applied') as $file) {
        check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $file['applied_at']) === 1, 'applied_at in UTC: ' . $file['applied_at']);
    }
});

test('while they wait the site is closed - 503 update_pending - but meta, the strings and the Migrations page', function () {
    $log = logTo('closed.log');
    seedGenshinCatalog();
    seedUser(1, 'owner');
    $account = seedAccount(1, 'genshin', ['uid' => '700000001', 'nickname' => 'Main']);
    $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $closed = fn(Tests\Support\Answer $answer, string $what) => same(
        [503, ['code' => 'update_pending', 'message' => 'The site is being updated. Try again in a few minutes.'], '60'],
        [$answer->status, $answer->json(), $answer->header('Retry-After')],
        $what,
    );

    // What the pages load first: where to sign in and the game metadata - and the strings, whose route says
    // itself that they are not there yet (the pages then read the English compiled into them).
    (new Client())
        ->get('/api/meta')
        ->status(200)
        ->path('auth.issuer', TEST_ISSUER)
        ->path('games.0.key', 'genshin')
        ->path('visitors.keep_days', 30);
    foreach (['/api/translations/en', '/api/translations/sk'] as $url) {
        (new Client())->get($url)->status(503)->path('code', 'translations_unavailable');
    }

    $member = (new Client())->asIam(1, []);
    foreach (['/api/accounts', "/api/accounts/{$account}", "/api/accounts/{$account}/imports", '/api/builds', '/api/builds/genshin', '/api/builds/genshin/hutao', '/api/me/export'] as $url) {
        $closed($member->get($url), "a member: {$url}");
        $closed((new Client())->get($url), "nobody: {$url}");
    }
    $closed($member->post('/api/imports/file', ['content' => goodFile()]), 'a member\'s import');
    $admin = (new Client())->asIam(2, [Role::ADMIN]);
    foreach (['/api/admin/builds', '/api/admin/builds/genshin/export', '/api/admin/people'] as $url) {
        $closed($admin->get($url), "an admin: {$url}");
    }
    foreach (translationRoutes() as [$method, $url, $body]) {
        if (!str_starts_with($url, '/api/translations/')) {
            $closed($admin->request($method, $url, $body), "an admin: {$method} {$url}");
        }
    }

    // Nobody's import makes no visitor, and a key opens nothing.
    $first = (new Client())->post('/api/imports/file', ['content' => goodFile()]);
    $closed($first, 'nobody\'s import');
    same('', $first->header('X-Visitor-Key'), 'no key');
    $closed((new Client())->withHeaders(['X-Visitor-Key' => $key])->get('/api/accounts'), 'a visitor key');
    $closed($member->withHeaders(['X-Visitor-Key' => $key])->post('/api/visitor/move'), 'the move at sign-in');
    same(1, (int) db()->value('SELECT count(*) FROM users'), 'nobody was made or recorded');

    // The Migrations page answers its ADMIN, and refuses everybody else as ever.
    (new Client())
        ->asIam(2, [Role::ADMIN])
        ->get('/api/admin/migrations')
        ->status(200);
    (new Client())
        ->asIam(3, [Role::EDITOR])
        ->get('/api/admin/migrations')
        ->status(403)
        ->path('code', 'admin_only');
    (new Client())->get('/api/admin/migrations')->status(401)->path('code', 'sign_in_required');
    nothingFailed($log);
});

test('the command line says that the strings are not there, and exits 1', function () {
    foreach ([['--status'], ['--export=sk'], ['--import=sk', T::$storage . '/nothing.json']] as $arguments) {
        T::$app->resetScope();
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = TranslationsCommand::run(T::$app, ['translations.php', ...$arguments], null, $out, $err);
        rewind($out);
        rewind($err);
        $said = [stream_get_contents($out), stream_get_contents($err)];
        same(1, $code, implode(' ', $arguments) . ': ' . implode(' | ', $said));
        same(['', "This database has no strings yet: apply the migrations first (php migration.php).\n"], $said, implode(' ', $arguments));
    }
});

test('behind the gate: meta and the dashboard\'s data answer as they did', function () {
    behindTheGate(function (): void {
        seedGenshinCatalog();
        seedUser(1, 'owner');
        $account = seedAccount(1, 'genshin', ['uid' => '700000001', 'nickname' => 'Main']);

        (new Client())
            ->get('/api/meta')
            ->status(200)
            ->path('auth.issuer', TEST_ISSUER)
            ->path('games.0.key', 'genshin')
            ->path('games.1', null);

        $member = (new Client())->asIam(1, []);
        $member
            ->get('/api/accounts')
            ->status(200)
            ->path('0.nickname', 'Main')
            ->path('0.counts', ['characters' => 0, 'weapons' => 0, 'items' => 0]);
        $member
            ->get("/api/accounts/{$account}")
            ->status(200)
            ->path('uid', '700000001')
            ->path('characters', []);
        $member->get("/api/accounts/{$account}/imports")->status(200);
        // Not the builds: their routes read the tables 016 makes, and only the gate keeps them from a database
        // without those - they have no second line.
    });
});

test('behind the gate: an import works - one that goes through with a warning, and one that fails', function () {
    behindTheGate(function (): void {
        seedGenshinCatalog();
        seedUser(1, 'owner');
        $client = (new Client())->asIam(1, []);

        $imported = $client
            ->post('/api/imports/file', ['content' => goodFile(), 'file_name' => 'export.json'])
            ->status(201)
            ->path('status', 'partial')
            ->path('summary.characters', 1)
            ->path('summary.items', 1)
            ->path('summary.warnings.0', 'Unknown set {"name":"SomeFutureSet"} was skipped.')
            ->path('summary.warning_kinds.0', ['code' => 'unknown_set', 'params' => ['ref' => 'SomeFutureSet']])
            ->path('error_code', null);
        $account = (int) $imported->json('account_id');

        // A problem the person can fix: the sentence, its code and its parameters - and the failed run is kept.
        same(
            [
                'message' => 'The GOOD file contains no characters or artifacts. Check the exporter settings.',
                'code' => 'good_empty',
                'params' => [],
            ],
            $client
                ->post('/api/imports/file', ['content' => '{"format":"GOOD","weapons":[]}', 'account_id' => $account, 'file_name' => 'empty.json'])
                ->status(422)
                ->json(),
            'the refusal',
        );
        same(
            ['message' => 'The file is not valid JSON.', 'code' => 'file_not_json', 'params' => []],
            $client
                ->post('/api/imports/file', ['content' => 'not json'])
                ->status(422)
                ->json(),
            'another',
        );

        $history = $client->get("/api/accounts/{$account}/imports")->status(200);
        same(['failed', 'partial'], array_column($history->json(), 'status'), 'the history, newest first');
        $history
            ->path('0.summary', null)
            ->path('0.error_code', 'good_empty')
            ->path('0.error_params', [])
            ->path('0.error', 'The GOOD file contains no characters or artifacts. Check the exporter settings.')
            ->path('1.summary.warning_kinds.0.code', 'unknown_set');

        // The account page and a character's analysis, on what was imported.
        $client
            ->get("/api/accounts/{$account}")
            ->status(200)
            ->path('characters.0.key', 'hutao');
        $client
            ->get("/api/accounts/{$account}/characters/hutao")
            ->status(200)
            ->path('account.id', $account);
    });
});

test('behind the gate: every translation route answers 503 translations_unavailable - never a 500, never not_set_up', function () {
    behindTheGate(function (): void {
        $log = logTo('before-apply.log');
        $admin = (new Client())->asIam(1, [Role::ADMIN]);

        foreach (translationRoutes() as [$method, $url, $body]) {
            $answer = $admin->request($method, $url, $body);
            same(503, $answer->status, "{$method} {$url} ({$answer->body})");
            same(
                ['message' => 'The words are not in the database yet. An administrator applies the waiting database update on the Migrations page.', 'code' => 'translations_unavailable'],
                $answer->json(),
                "{$method} {$url}: the whole answer",
            );
            same('60', $answer->header('Retry-After'), "{$method} {$url}: when to ask again");
            check(str_starts_with($answer->header('Content-Type'), 'application/json'), "{$method} {$url}: JSON");
        }
        // The page's strings are asked for by everybody: signed out, too.
        (new Client())->get('/api/translations/sk')->status(503)->path('code', 'translations_unavailable');
        // A body is read before the tables are looked for: an empty one is said to be empty, and nothing else is asked.
        $admin
            ->request('PUT', '/api/admin/translations', null, ['body' => ''])
            ->status(422)
            ->path('code', 'empty_body');

        // What was refused for who asks, or for the address, is refused as ever.
        (new Client())->get('/api/admin/translations')->status(401)->path('code', 'sign_in_required');
        (new Client())->asIam(3, [])->get('/api/admin/languages')->status(403)->path('code', 'editor_only');
        (new Client())
            ->asIam(3, [Role::EDITOR])
            ->post('/api/admin/languages', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch'])
            ->status(403)
            ->path('code', 'admin_only');
        (new Client())->get('/api/translations/EN')->status(404)->path('code', 'not_found');
        $admin->get('/api/admin/translations/nope/export')->status(404)->path('code', 'not_found');

        // An editor's strings routes: the same.
        $editor = (new Client())->asIam(5, [Role::EDITOR]);
        foreach (translationRoutes() as [$method, $url, $body]) {
            if ($method !== 'GET' && str_starts_with($url, '/api/admin/languages')) {
                continue; // adding, renaming, switching, deleting a language: an admin's (above)
            }
            $answer = $editor->request($method, $url, $body);
            same([503, 'translations_unavailable'], [$answer->status, $answer->json('code')], "an editor: {$method} {$url}");
        }
        nothingFailed($log);
    });
});

test('nothing else the server does reads the three tables: every query on them is the anotoki library\'s', function () {
    $backend = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    $tables = 'languages|translation_keys|translations';
    $query =
        '/\b(?:FROM|JOIN|INTO|UPDATE|TABLE|TRUNCATE)\s+(?:ONLY\s+)?(?:' .
        $tables .
        ')\b|->(?:insert|insertMany|update|delete)\(\s*[\'"](?:' .
        $tables .
        ')[\'"]|to_regclass\(\s*\'(?:public\.)?(?:' .
        $tables .
        ')\'/';
    $found = [];
    $read = 0;
    foreach (['api', 'src'] as $folder) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$backend}/{$folder}", FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($backend) + 1);
            if (!str_ends_with($relative, '.php')) {
                continue;
            }
            $read++;
            if (preg_match_all($query, (string) file_get_contents($file->getPathname()), $matches)) {
                $found[] = "{$relative}: " . implode('; ', array_unique($matches[0]));
            }
        }
    }
    check($read > 80, "the sources were read ({$read} files)");
    same([], $found, 'a query on the languages or the strings outside the library (anotoki/lib: Anotoki\Lib\Translations)');
    // And the library's own are found by the same reading, so the expression does find them.
    $library = (new ReflectionClass(Translations::class))->getFileName();
    check((bool) preg_match($query, (string) file_get_contents((string) $library)), 'the library queries them: ' . $library);
});

test('behind the gate, before 004: every visitor path answers 503 visitors_unavailable - never a 500 - and a member\'s requests work and sweep nothing', function () {
    behindTheGate(function (): void {
        $log = logTo('before-visitors.log');
        seedGenshinCatalog();
        same(false, (bool) db()->value("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'visitor_key_hash')"), 'no visitor columns yet');

        $unavailable = 'Using the site without signing in is not ready yet: an admin applies the waiting database update on the Migrations page. Sign in to continue.';
        $first = (new Client())->post('/api/imports/file', ['content' => goodFile()]);
        same([503, ['message' => $unavailable, 'code' => 'visitors_unavailable']], [$first->status, $first->json()], 'nobody\'s import');
        same(['60', ''], [$first->header('Retry-After'), $first->header('X-Visitor-Key')], 'ask again later; no key');
        same(0, (int) db()->value('SELECT count(*) FROM users'), 'nobody made');

        $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $visitor = (new Client())->withHeaders(['X-Visitor-Key' => $key]);
        $visitor->get('/api/accounts')->status(503)->path('code', 'visitors_unavailable');
        $visitor
            ->post('/api/imports/file', ['content' => goodFile()])
            ->status(503)
            ->path('code', 'visitors_unavailable');
        (new Client())
            ->withHeaders(['X-Visitor-Key' => 'not a key'])
            ->get('/api/accounts')
            ->status(401)
            ->path('code', 'visitor_unknown');
        (new Client())
            ->asIam(1, [])
            ->withHeaders(['X-Visitor-Key' => $key])
            ->post('/api/visitor/move')
            ->status(503)
            ->path('code', 'visitors_unavailable');
        (new Client())->get('/api/meta')->status(200)->path('visitors.keep_days', 30);

        // A member is no visitor: their own data as ever, and the hourly clean-up finds nothing to do.
        $member = (new Client())->asIam(1, []);
        $member->post('/api/imports/file', ['content' => goodFile()])->status(201);
        $member->get('/api/accounts')->status(200);
        // ... and their accounts file, out and back.
        $file = $member->get('/api/me/export')->status(200)->body;
        $member
            ->request('POST', '/api/me/import', null, ['body' => $file])
            ->status(200)
            ->path('kept', 1);
        (new Client())
            ->asIam(2, [Role::ADMIN])
            ->get('/api/admin/people')
            ->status(200)
            ->path('visitors', ['count' => 0, 'accounts' => 0]);
        nothingFailed($log);
    });
});

test('"Apply" brings the tables - and the strings with them - and opens the site again', function () {
    $admin = (new Client())->asIam(1, [Role::ADMIN]);
    $pending = array_map(static fn(array $file): array => ['set' => $file['set'], 'name' => $file['name']], $admin->get('/api/admin/migrations')->status(200)->json('pending'));
    same(waiting(), $pending, 'what waits: the library\'s admin tools\', the site\'s files, then the library\'s translations and people');

    $applied = $admin->post('/api/admin/migrations/apply')->status(200);
    same([$pending, null, null], [$applied->json('applied'), $applied->json('failed'), $applied->json('error')], 'applied, in that order: ' . $applied->json('error'));
    same(
        array_map(static fn(array $file): string => $file['set'] . '/' . $file['name'], array_values(array_filter(waiting(), static fn(array $file): bool => $file['set'] !== Migrations::SET))),
        db()->column("SELECT filename FROM migrations WHERE filename LIKE 'anotoki\\_%' ORDER BY id"),
        'the library\'s files recorded in this site\'s table, with their set',
    );
    // A backup of the database as it was, before anything was applied (the library's backups: before-migration).
    check(str_starts_with((string) $applied->json('note'), 'A backup of the database as it was is '), 'the Apply\'s note: ' . $applied->json('note'));
    same(1, count(glob(T::$storage . '/backups/*-before-migration-*.anotoki-backup') ?: []), 'one before-migration backup');
    same([], $admin->get('/api/admin/migrations')->status(200)->json('pending'), 'nothing pending after');
    same(['state' => 'ready', 'installed' => false], (new Client())->get('/api/site-status')->status(200)->json(), 'the site is open again');
    same(true, (bool) db()->value("SELECT to_regclass('public.translations') IS NOT NULL"), 'the tables are there');
    same(['en', 'sk'], db()->column('SELECT code FROM languages ORDER BY sort_order'), 'with the two languages');

    // The accounts and everything else are as they were: no existing table was altered.
    seedUser(1, 'owner');
    seedAccount(1, 'genshin', ['uid' => '700000001', 'nickname' => 'Main']);
    (new Client())->asIam(1, [])->get('/api/accounts')->status(200)->path('0.nickname', 'Main');
    // And visitors with them (004): nobody's import makes one.
    seedGenshinCatalog();
    $key = (new Client())
        ->post('/api/imports/file', ['content' => goodFile()])
        ->status(201)
        ->header('X-Visitor-Key');
    (new Client())
        ->withHeaders(['X-Visitor-Key' => $key])
        ->get('/api/accounts')
        ->status(200)
        ->path('0.characters_count', null);
    same(1, (int) db()->value('SELECT count(*) FROM users WHERE id < 0'), 'a visitor');

    $english = (new Client())->get('/api/translations/en')->status(200)->path('language', 'en');
    check(count($english->json('values')) > 100, 'the English strings are there');
    $slovak = (new Client())->get('/api/translations/sk')->status(200)->path('language', 'sk');
    same(array_keys($english->json('values')), array_keys($slovak->json('values')), 'and the Slovak ones, key for key');
    // The library's words with them - the two this site had of them (006) under the library's keys.
    same('Jazyk: {name} ({code})', $slovak->json('values')['anotoki.language.button'] ?? null, 'the language button\'s name');
    check(isset($slovak->json('values')['anotoki.siteStatus.updatingTitle']), 'the status page\'s words');
    check(!isset($english->json('values')['language.button']), 'the site\'s own key of it is gone');
    $admin->get('/api/admin/translations')->status(200);
});

finishTests();
