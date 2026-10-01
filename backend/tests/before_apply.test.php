<?php

/**
 * Between an upload and "Apply": the new files are on the host, and the database has not seen the migration
 * with the languages and the strings yet. A pending migration does not close this site - an admin applies it
 * on the Sources page - so everything else must work as it did, and the translation routes must say that the
 * strings are not there (503 translations_unavailable), never fail.
 *
 * The app of this file runs on a database of its own built from the first two migrations only.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\App;
use App\Auth\Role;
use App\Cli;
use App\Commands\Translations as TranslationsCommand;
use App\Db\Db;
use App\Db\Migrator;
use App\Http\HttpClient;
use App\Repositories\Translations;
use App\Support\Log;
use Tests\Support\Client;

bootTests();

$server = require __DIR__.'/../config/database.local.php';

// A folder with copies of 001 and 002, a database built from it, and the app bound to that database. From
// here on the helpers of lib.php (db(), the Client, the seeds, the reset before each test) work on it.
$before = T::$storage.DIRECTORY_SEPARATOR.'migrations-before';
@mkdir($before, 0777, true);
foreach (['001_adopt_laravel_database.sql', '002_schema.sql'] as $file) {
    copy(Migrator::directory().'/'.$file, $before.'/'.$file);
}
$pdo = scratchDatabase($server, 'before');
$built = (new Migrator($pdo, $before))->applyPending(['local_user_id' => 1]);
if ($built['failed'] !== null) {
    fwrite(STDERR, "migration {$built['failed']} failed: {$built['error']}\n");
    exit(1);
}
$database = T::$database.'_before';
T::$app = new App(testConfig(['database' => ['database' => $database, 'persistent' => false] + $server]), web: true);
T::$app->set(Db::class, new Db($pdo));
T::$app->set(HttpClient::class, T::$http);
T::$api = buildAnalyzerApi(T::$app);
beforeEach(function () use ($database): void {
    T::$app->config->set('database.database', $database);
    Log::to(null);
});

/** The migrations that write strings: once there is one, "Apply" brings the strings too. */
function stringMigrations(): array
{
    return array_values(array_filter(glob(Migrator::directory().'/*.sql') ?: [], fn (string $file): bool => (bool) preg_match('/^\s*INSERT\s+INTO\s+translation_keys\b/mi', (string) file_get_contents($file))));
}

function goodFile(): string
{
    return json_encode([
        'format' => 'GOOD',
        'version' => 3,
        'source' => 'Some Exporter',
        'characters' => [
            ['key' => 'HuTao', 'level' => 90, 'constellation' => 1, 'ascension' => 6, 'talent' => ['auto' => 10, 'skill' => 9, 'burst' => 8]],
        ],
        'weapons' => [
            ['key' => 'StaffOfHoma', 'level' => 90, 'ascension' => 6, 'refinement' => 1, 'location' => 'HuTao', 'lock' => true],
        ],
        'artifacts' => [
            ['setKey' => 'CrimsonWitchOfFlames', 'slotKey' => 'flower', 'level' => 20, 'rarity' => 5, 'mainStatKey' => 'hp', 'location' => 'HuTao', 'lock' => true,
                'substats' => [['key' => 'critRate_', 'value' => 10.5], ['key' => 'critDMG_', 'value' => 14.0]]],
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

test('the database has seen the first two migrations, and the third waits for an admin', function () {
    same(false, (bool) db()->value("SELECT to_regclass('public.translations') IS NOT NULL"), 'no translations table');
    same(false, (bool) db()->value("SELECT to_regclass('public.languages') IS NOT NULL"), 'no languages table');
    same(false, T::$app->get(Translations::class)->available(), 'the strings are not there to be read - asked without an error');

    $pending = array_values(array_diff(array_map('basename', glob(Migrator::directory().'/*.sql') ?: []), ['001_adopt_laravel_database.sql', '002_schema.sql']));
    check(in_array('003_languages_and_translations.sql', $pending, true), 'the migration with the languages and the strings is on disk');
    (new Client)->asIam(1, [Role::ADMIN])->get('/api/admin/migrations')->status(200)
        ->path('pending', $pending)->path('applied_count', 2)->path('last_applied', '002_schema.sql');
});

test('meta, the dashboard\'s data, the builds and the sources answer as they did', function () {
    seedGenshinCatalog();
    seedUser(1, 'owner');
    $account = seedAccount(1, 'genshin', ['uid' => '700000001', 'nickname' => 'Main']);

    (new Client)->get('/api/meta')->status(200)->path('auth.mode', 'iam')->path('games.0.key', 'genshin')->path('games.1.key', 'hsr');

    $member = (new Client)->asIam(1, [Role::MEMBER]);
    $member->get('/api/accounts')->status(200)->path('0.nickname', 'Main')->path('0.counts', ['characters' => 0, 'weapons' => 0, 'items' => 0]);
    $member->get("/api/accounts/{$account}")->status(200)->path('uid', '700000001')->path('characters', []);
    $member->get("/api/accounts/{$account}/imports")->status(200);
    $sources = $member->get('/api/sources')->status(200);
    check(in_array('Source 1', array_column($sources->json(), 'name'), true), 'the sources: '.$sources->body);
    $member->get('/api/builds/genshin')->status(200);
    $member->get('/api/builds/genshin/hutao')->status(200);

    $admin = (new Client)->asIam(2, [Role::ADMIN]);
    $admin->get('/api/sync')->status(200)->path('active', false);
    $admin->get('/api/sync-runs')->status(200);

    // With sign-in off too: the owner's own pages.
    T::$app->config->set('auth.mode', 'none');
    (new Client)->get('/api/accounts')->status(200)->path('0.nickname', 'Main');
});

test('an import works: one that goes through with a warning, and one that fails', function () {
    seedGenshinCatalog();
    seedUser(1, 'owner');
    $client = (new Client)->asIam(1, [Role::MEMBER]);

    $imported = $client->post('/api/imports/file', ['content' => goodFile(), 'file_name' => 'export.json'])->status(201)
        ->path('status', 'partial')->path('summary.characters', 1)->path('summary.items', 1)
        ->path('summary.warnings.0', 'Unknown set {"name":"SomeFutureSet"} was skipped.')
        ->path('summary.warning_kinds.0', ['code' => 'unknown_set', 'params' => ['ref' => 'SomeFutureSet']])
        ->path('error_code', null);
    $account = (int) $imported->json('account_id');

    // A problem the person can fix: the sentence, its code and its parameters - and the failed run is kept.
    same([
        'message' => 'This file contains Genshin Impact data, but you chose Honkai: Star Rail.',
        'code' => 'file_game_mismatch',
        'params' => ['file_game' => 'Genshin Impact', 'chosen_game' => 'Honkai: Star Rail'],
    ], $client->post('/api/imports/file', ['content' => goodFile(), 'game' => 'hsr', 'account_id' => $account, 'file_name' => 'wrong-game.json'])->status(422)->json(), 'the refusal');
    same(['message' => 'The file is not valid JSON.', 'code' => 'file_not_json', 'params' => []], $client->post('/api/imports/file', ['content' => 'not json'])->status(422)->json(), 'another');

    $history = $client->get("/api/accounts/{$account}/imports")->status(200);
    same(['failed', 'partial'], array_column($history->json(), 'status'), 'the history, newest first');
    $history->path('0.summary', null)->path('0.error_code', 'file_game_mismatch')
        ->path('0.error_params', ['file_game' => 'Genshin Impact', 'chosen_game' => 'Honkai: Star Rail'])
        ->path('0.error', 'This file contains Genshin Impact data, but you chose Honkai: Star Rail.')
        ->path('1.summary.warning_kinds.0.code', 'unknown_set');

    // The account page and a character's analysis, on what was imported.
    $client->get("/api/accounts/{$account}")->status(200)->path('characters.0.key', 'hutao');
    $client->get("/api/accounts/{$account}/characters/hutao")->status(200)->path('account.id', $account);
});

test('every translation route answers 503 translations_unavailable - never a 500, never not_set_up', function () {
    $log = T::$storage.DIRECTORY_SEPARATOR.'before-apply.log';
    @unlink($log);
    Log::to($log);
    $admin = (new Client)->asIam(1, [Role::ADMIN]);

    foreach (translationRoutes() as [$method, $url, $body]) {
        $answer = $admin->request($method, $url, $body);
        same(503, $answer->status, "{$method} {$url} ({$answer->body})");
        same(['message' => 'The site\'s words are not in the database yet. An admin applies the waiting database update on the Sources page.', 'code' => 'translations_unavailable'],
            $answer->json(), "{$method} {$url}: the whole answer");
        same('60', $answer->header('Retry-After'), "{$method} {$url}: when to ask again");
        check(str_starts_with($answer->header('Content-Type'), 'application/json'), "{$method} {$url}: JSON");
    }
    // The page's strings are asked for by everybody: signed out, too.
    (new Client)->get('/api/translations/sk')->status(503)->path('code', 'translations_unavailable');
    // An empty body, and one too large: what is said is still that the strings are not there.
    $admin->request('PUT', '/api/admin/translations', null, ['body' => ''])->status(503)->path('code', 'translations_unavailable');

    // What was refused for who asks, or for the address, is refused as ever.
    (new Client)->get('/api/admin/translations')->status(401)->path('code', 'sign_in_required');
    (new Client)->asIam(3, [Role::MEMBER])->get('/api/admin/languages')->status(403)->path('code', 'admin_only');
    (new Client)->get('/api/translations/EN')->status(404)->path('code', 'not_found');
    $admin->get('/api/admin/translations/nope/export')->status(404)->path('code', 'not_found');

    // With sign-in off: the same.
    T::$app->config->set('auth.mode', 'none');
    foreach (translationRoutes() as [$method, $url, $body]) {
        $answer = (new Client)->request($method, $url, $body);
        same([503, 'translations_unavailable'], [$answer->status, $answer->json('code')], "sign-in off: {$method} {$url}");
    }

    $logged = is_file($log) ? (string) file_get_contents($log) : '';
    check(! str_contains($logged, ' ERROR '), 'nothing failed on the way: '.$logged);
    Log::to(null);
});

test('the command line says that the strings are not there, and exits 1', function () {
    foreach ([['--status'], ['--export=sk'], ['--import=sk', T::$storage.'/nothing.json']] as $arguments) {
        T::$app->resetScope();
        Cli::capture();
        try {
            $code = TranslationsCommand::run(T::$app, ['translations.php', ...$arguments]);
        } finally {
            $out = Cli::captured();
        }
        same(1, $code, implode(' ', $arguments).": {$out}");
        same('The site\'s strings are not in the database '.T::$database.'_before yet: apply the migrations first (php migration.php).', $out, implode(' ', $arguments));
    }
});

test('nothing else the server does reads the three tables: every query on them is in the Translations repository', function () {
    $backend = str_replace('\\', '/', (string) realpath(__DIR__.'/..'));
    $tables = 'languages|translation_keys|translations';
    $found = [];
    $read = 0;
    foreach (['api', 'src'] as $folder) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$backend}/{$folder}", FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($backend) + 1);
            if (! str_ends_with($relative, '.php') || $relative === 'src/Repositories/Translations.php') {
                continue;
            }
            $read++;
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE|TRUNCATE)\s+(?:ONLY\s+)?(?:'.$tables.')\b|->(?:insert|insertMany|update|delete)\(\s*[\'"](?:'.$tables.')[\'"]|to_regclass\(\s*\'public\.(?:'.$tables.')\'/', $source, $matches)) {
                $found[] = "{$relative}: ".implode('; ', array_unique($matches[0]));
            }
        }
    }
    check($read > 80, "the sources were read ({$read} files)");
    same([], $found, 'a query on the languages or the strings outside App\Repositories\Translations');
    // And the repository's own are found by the same reading, so the expression does find them.
    check((bool) preg_match('/\bFROM\s+(?:'.$tables.')\b/', (string) file_get_contents("{$backend}/src/Repositories/Translations.php")), 'the repository queries them');
});

test('"Apply" brings the tables - and the strings with them, in one migration', function () {
    $admin = (new Client)->asIam(1, [Role::ADMIN]);
    $pending = $admin->get('/api/admin/migrations')->status(200)->json('pending');

    $applied = $admin->post('/api/admin/migrations/apply')->status(200);
    same([true, $pending, []], [$applied->json('ok'), $applied->json('applied'), $applied->json('pending')], 'applied: '.$applied->json('output'));
    same(true, (bool) db()->value("SELECT to_regclass('public.translations') IS NOT NULL"), 'the tables are there');
    same(['en', 'sk'], db()->column('SELECT code FROM languages ORDER BY sort_order'), 'with the two languages');

    // The accounts and everything else are as they were: no existing table was altered. (The admin's own
    // row of users is there from the requests above.)
    seedAccount(1, 'genshin', ['uid' => '700000001', 'nickname' => 'Main']);
    (new Client)->asIam(1, [Role::MEMBER])->get('/api/accounts')->status(200)->path('0.nickname', 'Main');

    if (stringMigrations() === []) {
        // The migration holds no string yet: the tables are there and empty, and that too is "not there".
        echo "wait  after Apply the bundle answers in English and in Slovak - until a migration inserts into translation_keys\n";
        (new Client)->get('/api/translations/sk')->status(503)->path('code', 'translations_unavailable');
        $admin->get('/api/admin/translations')->status(503)->path('code', 'translations_unavailable');

        return;
    }
    $english = (new Client)->get('/api/translations/en')->status(200)->path('language', 'en');
    check(count($english->json('values')) > 100, 'the English strings are there');
    $slovak = (new Client)->get('/api/translations/sk')->status(200)->path('language', 'sk');
    same(array_keys($english->json('values')), array_keys($slovak->json('values')), 'and the Slovak ones, key for key');
    $admin->get('/api/admin/translations')->status(200);
});

finishTests();
