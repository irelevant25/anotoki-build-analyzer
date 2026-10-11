<?php

/**
 * The site's migrations on the anotoki family's engine (anotoki/lib, tested there in itself): a database built
 * by it in the bookkeeping table this site always had, a file that fails, one apply at a time, "Apply pending"
 * on the Migrations page, the command line building a database from nothing - and the adoption of a database
 * made by the first (Laravel) version of the app (App\Db\Migrations: its `prepare`).
 *
 * The Laravel schemas are fixtures (tests/fixtures/laravel/schema-{6,7,8}.sql): exactly what that version's
 * `artisan migrate` made on PostgreSQL after its first 6 migrations (the version first committed), 7 (sign-in
 * through the IAM, accounts with owners) and 8 (resumable syncs). Each is loaded into a throwaway database,
 * filled the way that version wrote its rows, brought forward - and then compared with a new database, table
 * by table, and read back row by row.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Migrations\Splitter;
use Anotoki\Lib\Translations\Schema;
use App\Auth\Role;
use App\Config;
use App\Db\Db;
use App\Db\Migrations;
use App\Support\Log;
use Tests\Support\Client;

bootTests();

$server = require __DIR__.'/../config/database.local.php';

/** The file that moved this site's two words of the library's under the library's keys. */
const ADOPTION_FILE = '006_strings_through_the_library.sql';

/** Every column, constraint and index of the app's tables, as comparable lines. */
function schemaOf(PDO $pdo): array
{
    $lines = [];
    foreach ($pdo->query("SELECT table_name, column_name, data_type, character_maximum_length, is_nullable,
            regexp_replace(COALESCE(column_default, ''), '''(\\d+)''::smallint', '\\1') AS column_default
        FROM information_schema.columns WHERE table_schema = 'public' AND table_name NOT LIKE 'laravel\\_%' AND table_name <> 'migrations'
        ORDER BY table_name, column_name") as $row) {
        $lines[] = 'column '.implode(' ', $row);
    }
    foreach ($pdo->query("SELECT c.conrelid::regclass::text AS tbl, c.conname, pg_get_constraintdef(c.oid) AS def
        FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace AND n.nspname = 'public'
        WHERE c.conrelid::regclass::text NOT LIKE 'laravel\\_%' AND c.conrelid::regclass::text <> 'migrations' AND c.contype <> 'n'
        ORDER BY 1, 2") as $row) {
        $lines[] = 'constraint '.implode(' ', $row);
    }
    foreach ($pdo->query("SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = 'public'
        AND tablename NOT LIKE 'laravel\\_%' AND tablename <> 'migrations' ORDER BY 1, 2") as $row) {
        $lines[] = 'index '.implode(' ', $row);
    }

    return $lines;
}

/** A database as the Laravel version left it after $level migrations, with rows in it. */
function laravelDatabase(array $server, int $level): PDO
{
    $pdo = scratchDatabase($server, "laravel{$level}");
    foreach (Splitter::split((string) file_get_contents(__DIR__."/fixtures/laravel/schema-{$level}.sql")) as $statement) {
        $pdo->exec($statement);
    }
    // The session's zone does not matter: that version kept UTC in columns without one.
    $pdo->exec("SET TIME ZONE 'Europe/Prague'");

    $migrations = ['0001_01_01_000000_create_users_table', '0001_01_01_000001_create_cache_table', '0001_01_01_000002_create_jobs_table',
        '2026_09_24_000100_create_catalog_tables', '2026_09_24_000200_create_account_tables', '2026_09_24_000300_create_build_tables',
        '2026_09_27_000100_add_owners_to_accounts', '2026_09_27_000200_make_sync_runs_resumable'];
    foreach (array_slice($migrations, 0, $level) as $index => $name) {
        $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)')->execute([$name, $index < 6 ? 1 : $index - 4]);
    }

    $t = '2026-09-20 10:15:30';
    $insert = function (string $sql, array $params = []) use ($pdo): int {
        $statement = $pdo->prepare($sql.' RETURNING id');
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    };

    $hutao = $insert("INSERT INTO game_characters (game, key, name, element, rarity, weapon_type, external_id, icon_url, released_at, created_at, updated_at)
        VALUES ('genshin', 'hutao', 'Hu Tao', 'pyro', 5, 'polearm', '10000046', 'https://icons.example/hutao.png', '2021-03-02 00:00:00', ?, ?)", [$t, $t]);
    $homa = $insert("INSERT INTO game_weapons (game, key, name, rarity, external_id, created_at, updated_at) VALUES ('genshin', 'staffofhoma', 'Staff of Homa', 5, '13501', ?, ?)", [$t, $t]);
    $cw = $insert("INSERT INTO game_sets (game, key, name, kind, external_id, meta, created_at, updated_at)
        VALUES ('genshin', 'crimsonwitchofflames', 'Crimson Witch of Flames', 'artifact', '15006', '{\"bonuses\":[\"Pyro DMG Bonus +15%\"]}', ?, ?)", [$t, $t]);

    $owners = $level >= 7;
    if ($owners) {
        $pdo->exec("INSERT INTO users (id, name, password, created_at, updated_at) VALUES (1, 'owner', NULL, '{$t}', '{$t}'), (42, 'mona', NULL, '{$t}', '{$t}')");
    } else {
        // Laravel's own login table, never used by the app - but not empty here.
        $pdo->exec("INSERT INTO users (name, email, password, created_at, updated_at) VALUES ('Test User', 'test@example.test', 'x', '{$t}', '{$t}')");
    }

    $account = $insert('INSERT INTO accounts ('.($owners ? 'user_id, ' : '')."game, uid, nickname, level, has_full_inventory, last_import_at, meta, created_at, updated_at)
        VALUES (".($owners ? '1, ' : '')."'genshin', '700000001', 'Main', 60, TRUE, '2026-09-21 12:00:00', '{\"region\":\"EU\"}', ?, ?)", [$t, $t]);
    if ($owners) {
        // Two people with the same UID, as the owners migration allows.
        $insert("INSERT INTO accounts (user_id, game, uid, nickname, created_at, updated_at) VALUES (42, 'genshin', '700000001', 'Mona main', ?, ?)", [$t, $t]);
    }
    $insert("INSERT INTO account_characters (account_id, game_character_id, level, rank, talents, final_stats, source, synced_at, created_at, updated_at)
        VALUES (?, ?, 90, 1, '{\"normal\":10}', '{\"critRate_\":65.2}', 'enka', '2026-09-21 12:00:00', ?, ?)", [$account, $hutao, $t, $t]);
    $insert("INSERT INTO account_weapons (account_id, game_weapon_id, level, refinement, equipped_character_id, locked, source, created_at, updated_at)
        VALUES (?, ?, 90, 1, ?, TRUE, 'good', ?, ?)", [$account, $homa, $hutao, $t, $t]);
    $insert("INSERT INTO account_items (account_id, game_set_id, slot, rarity, level, main_stat, main_value, substats, equipped_character_id, source, created_at, updated_at)
        VALUES (?, ?, 'flower', 5, 20, 'hp', 4780, '[{\"key\":\"critRate_\",\"value\":10.5,\"rolls\":3}]', ?, 'good', ?, ?)", [$account, $cw, $hutao, $t, $t]);
    $insert('INSERT INTO import_runs ('.($owners ? 'user_id, ' : '')."account_id, game, source, status, summary, created_at, updated_at)
        VALUES (".($owners ? '1, ' : '')."?, 'genshin', 'good', 'success', '{\"characters\":1}', ?, ?)", [$account, $t, $t]);

    $source = $insert("INSERT INTO build_sources (key, game, name, base_url, enabled, last_synced_at, last_status, created_at, updated_at)
        VALUES ('prydwen.genshin', 'genshin', 'Prydwen', 'https://www.prydwen.gg', TRUE, '2026-09-25 04:30:00', 'success', ?, ?)", [$t, $t]);
    $insert("INSERT INTO builds (build_source_id, game_character_id, variant, recommended, position, weapons, sets, main_stats, substats, content_hash, fetched_at, changed_at, created_at, updated_at)
        VALUES (?, ?, 'DPS', TRUE, 0, '[]', '[]', '{\"goblet\":[{\"stat\":\"pyro_dmg_\",\"priority\":1}]}', '[]', ?, '2026-09-25 04:31:00', '2026-09-24 04:31:00', ?, ?)",
        [$source, $hutao, str_repeat('a', 64), $t, $t]);

    $insert("INSERT INTO sync_runs (build_source_id, status, started_at, finished_at, log, created_at, updated_at)
        VALUES (?, 'success', '2026-09-25 04:30:00', '2026-09-25 04:50:00', 'Done: success.', ?, ?)", [$source, $t, $t]);
    if ($level >= 8) {
        // A stepped run in progress: it keeps its place.
        $insert("INSERT INTO sync_runs (build_source_id, status, started_at, created_at, updated_at, kind, game, started_by, state, next_step_at)
            VALUES (?, 'running', '2026-09-26 04:30:00', ?, ?, 'builds', 'genshin', 1, '{\"done\":1,\"total\":3}', '2026-09-26 04:30:10')", [$source, $t, $t]);
    } else {
        // Left "running" by a queue worker that stopped: nothing to resume from.
        $insert("INSERT INTO sync_runs (build_source_id, status, started_at, created_at, updated_at) VALUES (?, 'running', '2026-09-26 04:30:00', ?, ?)", [$source, $t, $t]);
    }

    $pdo->exec("INSERT INTO cache (key, value, expiration) VALUES ('laravel_cache_x', 's:1:\"1\";', 1758866460)");
    $pdo->exec("INSERT INTO failed_jobs (uuid, connection, queue, payload, exception, failed_at) VALUES ('u-1', 'database', 'default', '{}', 'RuntimeException', '{$t}')");
    $pdo->exec("SET TIME ZONE 'UTC'");

    return $pdo;
}

/** Every migration file there is, in the order they apply. */
function allMigrations(): array
{
    $files = array_map('basename', glob(Migrations::directory().'/*.sql') ?: []);
    sort($files);

    return $files;
}

/** A file of the site's set, as the engine names it in its answers. */
function siteFile(string $name): array
{
    return ['set' => Migrations::SET, 'name' => $name];
}

/**
 * The anotoki library's own files (its translations set), in the order they apply - after every one of the
 * site's - as the engine names them in its answers.
 *
 * @return list<array{set: string, name: string}>
 */
function libraryFiles(): array
{
    $files = array_map('basename', glob(Schema::directory().'/*.sql') ?: []);
    sort($files);

    return array_map(static fn (string $name): array => ['set' => Schema::SET, 'name' => $name], $files);
}

/** Every file a database is built from, as the engine names them: the site's, then the library's. */
function everyFile(): array
{
    return [...array_map('siteFile', allMigrations()), ...libraryFiles()];
}

/** A file as the bookkeeping table and the command line name it: the site's by its name, the library's with its set in front. */
function recorded(array $file): string
{
    return ($file['set'] === Migrations::SET ? '' : $file['set'].'/').$file['name'];
}

/** The engine's answer's files, without what else it says of them (applied_at, ready, blocked). */
function filesOf(array $files): array
{
    return array_map(static fn (array $file): array => ['set' => $file['set'], 'name' => $file['name']], $files);
}

/** The bookkeeping table as a database has it: its columns and its keys. */
function bookkeepingOf(PDO $pdo): array
{
    return [
        $pdo->query("SELECT column_name, data_type, character_maximum_length, is_nullable, column_default FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'migrations' ORDER BY ordinal_position")->fetchAll(PDO::FETCH_NUM),
        $pdo->query("SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint
            WHERE conrelid = 'migrations'::regclass AND contype IN ('p', 'u') ORDER BY conname")->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
}

/** A folder of migrations of a test's own: copies of the site's files (those named), and files of its own. */
function migrationsFolder(string $name, array $copies, array $own = []): string
{
    $dir = T::$storage.DIRECTORY_SEPARATOR.$name;
    @mkdir($dir, 0777, true);
    foreach ($copies as $file) {
        copy(Migrations::directory().'/'.$file, $dir.'/'.$file);
    }
    foreach ($own as $file => $sql) {
        file_put_contents($dir.'/'.$file, $sql);
    }

    return $dir;
}

test('a new database is built by the migrations, in the bookkeeping table this site always had', function () {
    $migrator = Migrations::migrator(db()->raw());
    $status = $migrator->status();
    same([Migrations::SET, Schema::SET], $status['sets'], 'the site\'s set of files, then the library\'s');
    same([[], [], everyFile()], [$status['pending'], $status['missing'], filesOf($status['applied'])],
        'status: nothing pending or missing, every file applied in order - the library\'s after the site\'s');
    same(array_map('recorded', everyFile()), db()->column('SELECT filename FROM migrations ORDER BY id'), 'recorded: the site\'s by their names, the library\'s with their set');
    same([], $migrator->applicable(), 'nothing to apply');
    foreach ($status['applied'] as $file) {
        check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $file['applied_at']) === 1, 'applied_at in UTC: '.$file['applied_at']);
    }
    same(false, Migrations::isLaravelDatabase(db()->raw()), 'not a Laravel database');

    // The table this site's own engine made, column for column and key for key: the family's engine reads
    // and writes the shape a database has, and makes this one where there is none.
    same([
        [
            ['id', 'integer', null, 'NO', "nextval('migrations_id_seq'::regclass)"],
            ['filename', 'character varying', 255, 'NO', null],
            ['applied_at', 'timestamp without time zone', null, 'YES', 'CURRENT_TIMESTAMP'],
        ],
        ['migrations_filename_key' => 'UNIQUE (filename)', 'migrations_pkey' => 'PRIMARY KEY (id)'],
    ], bookkeepingOf(db()->raw()), 'migrations (id, filename, applied_at)');
});

test('a failing migration applies nothing of itself, and stops there', function () use ($server) {
    $pdo = scratchDatabase($server, 'failing');
    $dir = migrationsFolder('failing-migrations', [], [
        '001_good.sql' => 'CREATE TABLE good (id INT);',
        '002_bad.sql' => 'CREATE TABLE half (id INT); SELECT * FROM missing_table;',
        '003_never.sql' => 'CREATE TABLE never (id INT);',
    ]);

    $result = Migrations::migrator($pdo, $dir, library: false)->apply();

    same([siteFile('001_good.sql')], $result['applied'], 'applied');
    same(siteFile('002_bad.sql'), $result['failed'], 'failed');
    check(str_starts_with((string) $result['error'], 'statement 2 of 2: SQLSTATE[42P01]') && str_contains((string) $result['error'], "\n\n  SELECT * FROM missing_table"),
        'which statement, the database\'s error, and the statement: '.$result['error']);
    same(false, (bool) $pdo->query("SELECT to_regclass('public.half') IS NOT NULL")->fetchColumn(), 'nothing of 002 stayed');
    same(false, (bool) $pdo->query("SELECT to_regclass('public.never') IS NOT NULL")->fetchColumn(), '003 was not run');
    same(['001_good.sql'], $pdo->query('SELECT filename FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'recorded');
});

test('"Apply pending" on the Migrations page that stops at a failing file: 200, what ran first, the file and the error, the log', function () use ($server) {
    // A database the app's migrations built, with three more files after them: a good one, a bad one, and
    // one the stop keeps from running. The app works on that database for this test - and while they wait
    // the site is closed but for its open paths, the Migrations page's API among them.
    $pdo = scratchDatabase($server, 'api_failing');
    same(null, Migrations::migrator($pdo)->apply()['error'], 'the app\'s migrations');
    $dir = migrationsFolder('api-failing-migrations', allMigrations(), [
        '900_good.sql' => 'CREATE TABLE good (id INT);',
        '901_bad.sql' => 'CREATE TABLE half (id INT); SELECT * FROM missing_table;',
        '902_never.sql' => 'CREATE TABLE never (id INT);',
    ]);
    $log = T::$storage.DIRECTORY_SEPARATOR.'api-failing.log';
    @unlink($log);

    $appDb = T::$app->get(Db::class);
    T::$app->set(Db::class, new Db($pdo));
    T::$app->set(Migrator::class, Migrations::migrator($pdo, $dir));
    Log::to($log);
    try {
        $admin = (new Client)->asIam(1, [Role::ADMIN]);
        (new Client)->get('/api/site-status')->status(200)->path('state', 'update_pending');
        $admin->get('/api/accounts')->status(503)->path('code', 'update_pending');
        $status = $admin->get('/api/admin/migrations')->status(200);
        same([siteFile('900_good.sql') + ['ready' => true, 'blocked' => false], siteFile('901_bad.sql') + ['ready' => true, 'blocked' => false],
            siteFile('902_never.sql') + ['ready' => true, 'blocked' => false]], $status->json('pending'), 'pending, in the order they will run');
        same(everyFile(), filesOf($status->json('applied')), 'applied, in the order they were - the library\'s too');

        $answer = $admin->post('/api/admin/migrations/apply')->status(200);
        same([[siteFile('900_good.sql')], siteFile('901_bad.sql'), null], [$answer->json('applied'), $answer->json('failed'), $answer->json('note')], 'applied before the stop, and where it stopped');
        $error = (string) $answer->json('error');
        check(str_contains($error, 'statement 2 of 2') && str_contains($error, 'SQLSTATE[42P01]') && str_contains($error, '"missing_table" does not exist'), 'the database\'s error: '.$error);
        same(true, (bool) $pdo->query("SELECT to_regclass('public.good') IS NOT NULL")->fetchColumn(), '900 applied');
        same(false, (bool) $pdo->query("SELECT to_regclass('public.half') IS NOT NULL")->fetchColumn(), 'nothing of 901 stayed');
        same(false, (bool) $pdo->query("SELECT to_regclass('public.never') IS NOT NULL")->fetchColumn(), '902 was not run');
        same(['901_bad.sql', '902_never.sql'], array_column($admin->get('/api/admin/migrations')->status(200)->json('pending'), 'name'), 'still pending');
        $logged = (string) @file_get_contents($log);
        check(str_contains($logged, ' ERROR ') && str_contains($logged, 'Migration 901_bad.sql failed') && str_contains($logged, 'Migration 900_good.sql applied.'),
            'the failure and what went first are in the log: '.$logged);

        // The lock was given back: the next apply runs (and stops at the same file), it is not told "busy".
        $again = $admin->post('/api/admin/migrations/apply')->status(200);
        same([[], siteFile('901_bad.sql')], [$again->json('applied'), $again->json('failed')], 'the next apply');

        // A file's SQL for the page's View - one of the set's files, and nothing else.
        same(['set' => Migrations::SET, 'name' => '900_good.sql', 'sql' => 'CREATE TABLE good (id INT);'],
            $admin->get('/api/admin/migrations/file?set=build_analyzer&name=900_good.sql')->status(200)->json(), 'a file');
        foreach (['set=build_analyzer&name=../config/database.local.php', 'set=build_analyzer&name=nothing.sql', 'set=other&name=900_good.sql', 'name=900_good.sql'] as $query) {
            $admin->get('/api/admin/migrations/file?'.$query)->status(404)->path('code', 'not_found');
        }
    } finally {
        Log::to(null);
        T::$app->set(Db::class, $appDb);
        T::$app->forget(Migrator::class);
    }
    (new Client)->get('/api/site-status')->status(200)->path('state', 'ready');
});

test('a second run finds nothing left to do: a data migration is never applied twice', function () use ($server) {
    $pdo = scratchDatabase($server, 'twice');
    $dir = migrationsFolder('twice-migrations', [], ['001_table.sql' => 'CREATE TABLE counted (n INT);', '002_data.sql' => 'INSERT INTO counted VALUES (1);']);

    $first = Migrations::migrator($pdo, $dir, library: false);
    $second = Migrations::migrator($pdo, $dir, library: false);
    same([siteFile('001_table.sql'), siteFile('002_data.sql')], $second->applicable(), 'both pending for both runs');
    same([siteFile('001_table.sql'), siteFile('002_data.sql')], $first->apply()['applied'], 'the first run');

    $again = $second->apply();
    same([[], null], [$again['applied'], $again['error']], 'the second run passes over them');
    same(1, (int) $pdo->query('SELECT count(*) FROM counted')->fetchColumn(), 'its row written once');
    same(['001_table.sql', '002_data.sql'], $pdo->query('SELECT filename FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'each recorded once');
});

test('one apply at a time, whoever starts it: while another holds the lock, an apply does nothing and says so', function () use ($server) {
    $pdo = scratchDatabase($server, 'busy');
    $dir = migrationsFolder('busy-migrations', [], ['001_table.sql' => 'CREATE TABLE waited (n INT);']);

    // Another apply under way - the Migrations page, setup.php, migration.php - on a connection of its own.
    $other = Db::pdo($server, (string) $pdo->query('SELECT current_database()')->fetchColumn(), false);
    $other->query('SELECT pg_advisory_lock('.Migrations::LOCK.')');
    try {
        $result = Migrations::migrator($pdo, $dir, library: false)->apply();
        same([true, [], null, null], [$result['busy'], $result['applied'], $result['failed'], $result['error']], 'busy, and nothing applied');
        same(false, (bool) $pdo->query("SELECT to_regclass('public.waited') IS NOT NULL")->fetchColumn(), 'nothing ran');
        same(false, (bool) $pdo->query("SELECT to_regclass('public.migrations') IS NOT NULL")->fetchColumn(), 'not even the bookkeeping table was made');
    } finally {
        $other->query('SELECT pg_advisory_unlock('.Migrations::LOCK.')');
    }

    same([siteFile('001_table.sql')], Migrations::migrator($pdo, $dir, library: false)->apply()['applied'], 'once the other run is done');
});

test('a pooled connection gives back the lock an apply left held when its request died - or the site would stay closed', function () use ($server) {
    // A persistent connection holding the lock, as a request that died in the middle of an apply leaves it.
    $pooled = Db::pdo($server, T::$database, true);
    $pooled->query('SELECT pg_advisory_lock('.Migrations::LOCK.')');
    $pooled = null;
    $other = Db::pdo($server, T::$database, false);
    $free = fn (): bool => (bool) $other->query('SELECT pg_try_advisory_lock('.Migrations::LOCK.')')->fetchColumn();
    check(! $free(), 'held by the pooled connection');

    // The next request takes the same pooled connection - and finds it as a new one.
    $again = Db::pdo($server, T::$database, true);
    check($free(), 'given back as the pooled connection was taken again');
    $other->query('SELECT pg_advisory_unlock('.Migrations::LOCK.')');
    same('UTC', $again->query('SHOW TIME ZONE')->fetchColumn(), 'and set up as ever');
});

test('the command line builds a database from nothing, says where it stands, and finds it up to date the next time', function () use ($server) {
    // migration.php as it runs on a computer with a shell - on a throwaway database of its own, through the
    // settings folder BUILD_ANALYZER_TEST_CONFIG names (honoured for a build_analyzer_test_ database only).
    $name = T::$database.'_cli';
    $settings = T::$storage.DIRECTORY_SEPARATOR.'cli-settings';
    @mkdir($settings.'/storage', 0777, true);
    file_put_contents($settings.'/database.local.php', '<?php return '.var_export(['database' => $name, 'persistent' => false] + $server, true).";\n");
    file_put_contents($settings.'/auth.local.php', '<?php return '.var_export(['issuer' => TEST_ISSUER, 'client_id' => TEST_CLIENT], true).";\n");
    register_shutdown_function(static function () use ($server, $name): void {
        try {
            Db::pdo($server, 'postgres', false)->exec('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
        } catch (Throwable $e) {
            fwrite(STDERR, "could not drop {$name}: {$e->getMessage()}\n");
        }
    });

    $run = function (string ...$arguments) use ($settings): array {
        $command = escapeshellarg(PHP_BINARY).' migration.php'.($arguments === [] ? '' : ' '.implode(' ', array_map('escapeshellarg', $arguments)));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), [Config::TEST_CONFIG_ENV => $settings] + getenv());
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

        return [proc_close($process), str_replace("\r\n", "\n", (string) $output)];
    };
    // The site's files by their names, then the library's with their set in front.
    $files = array_map('recorded', everyFile());
    check(in_array('anotoki_translations/001_languages_and_strings.sql', $files, true), 'the library\'s files among them');

    [$code, $output] = $run();
    same(0, $code, 'the first run: '.$output);
    check(str_contains($output, "== build analyzer database ({$name})\n  Database created.\n"), 'the database made: '.$output);
    foreach ($files as $file) {
        check(str_contains($output, "  {$file} ... done\n"), "{$file} applied: {$output}");
    }
    check(strpos($output, '006_strings_through_the_library.sql ... done') < strpos($output, 'anotoki_translations/001_languages_and_strings.sql ... done'), 'the library\'s after the site\'s');

    [$code, $output] = $run('--status');
    same(0, $code, '--status: '.$output);
    same(implode('', array_map(fn (string $file): string => "  v {$file}\n", $files)).'  '.count($files)." applied, 0 pending\n",
        substr($output, strlen("== build analyzer database ({$name})\n")), 'every file applied');

    [$code, $output] = $run();
    same([0, "== build analyzer database ({$name})\n  Up to date - ".count($files)." migrations.\n"], [$code, $output], 'the next run');
    same($files, Db::pdo($server, $name, false)->query('SELECT filename FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'recorded in order');
});

test('004 applies over a 003 whose strings were reworded and whose Slovak was cleared in the browser', function () use ($server) {
    // A database as the owner's is before 004: 001-003 applied, then the Translations grid used.
    $dir = migrationsFolder('migrations-to-003', array_values(array_filter(allMigrations(), fn (string $file): bool => strcmp($file, '004') < 0)));
    $pdo = scratchDatabase($server, 'to003');
    $built = Migrations::migrator($pdo, $dir, library: false)->apply();
    same(null, $built['error'], '001-003 applied');
    same([siteFile('003_languages_and_translations.sql')], array_slice($built['applied'], -1), 'up to 003');

    // An editor cleared a Slovak string (an empty cell deletes the row: the page reads the English), and
    // reworded an English one of a key 004 keeps and one of a key 004 retires.
    $pdo->exec("DELETE FROM translations WHERE key_name = 'common.signIn' AND language_code = 'sk'");
    $pdo->exec("UPDATE translations SET value = 'Log in', updated_by = 1 WHERE key_name = 'common.signIn' AND language_code = 'en'");
    $pdo->exec("UPDATE translations SET value = 'Home', updated_by = 1 WHERE key_name = 'nav.dashboard' AND language_code = 'en'");

    $result = Migrations::migrator($pdo)->apply();
    same(null, $result['error'], 'no error');
    same([...array_map('siteFile', array_values(array_filter(allMigrations(), fn (string $f): bool => strcmp($f, '004') >= 0))), ...libraryFiles()], $result['applied'], 'applied from 004 on, and the library\'s');

    $value = fn (string $key, string $code): mixed => $pdo->query("SELECT value FROM translations WHERE key_name = '{$key}' AND language_code = '{$code}'")->fetchColumn();
    same(false, $value('common.signIn', 'sk'), 'the cleared Slovak string stays cleared');
    same('Log in', $value('common.signIn', 'en'), 'the reworded English string is not written over');
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name = 'nav.dashboard'")->fetchColumn(), 'a retired key goes, reworded or not');
    same(false, $value('nav.dashboard', 'en'), 'and its strings with it');
    check($value('topbar.dashboard', 'sk') !== false, 'the new keys read in Slovak');
});

test('006 moves the two words of the library\'s under its keys with every rewording, writer and time - on a database as its owner left it', function () use ($server) {
    // A database as the owner's is before this upload: 001-005 applied (none of the library's files yet), then
    // the Translations grid and the Languages page used.
    $dir = migrationsFolder('migrations-to-005', array_values(array_filter(allMigrations(), fn (string $file): bool => strcmp($file, '006') < 0)));
    $pdo = scratchDatabase($server, 'to005');
    $built = Migrations::migrator($pdo, $dir, library: false)->apply();
    same(null, $built['error'], '001-005 applied');
    same([siteFile('005_retire_not_set_up_page.sql')], array_slice($built['applied'], -1), 'up to 005');

    // An editor (IAM user 7) reworded the English of the language button's name; another (8) the Slovak of
    // "could not be loaded"; Slovak's language button was cleared on purpose (it reads the English); German was
    // added, with a string of its own; and a string of a key that stays where it is was reworded too.
    $pdo->exec("UPDATE translations SET value = 'Choose a language: {name} ({code})', updated_by = 7, updated_at = '2026-10-09 08:15:00+00' WHERE key_name = 'language.button' AND language_code = 'en'");
    $pdo->exec("UPDATE translations SET value = 'Jazyk sa teraz nedá načítať - skús to o chvíľu.', updated_by = 8, updated_at = '2026-10-10 18:30:00+00' WHERE key_name = 'language.notLoaded' AND language_code = 'sk'");
    $pdo->exec("DELETE FROM translations WHERE key_name = 'language.button' AND language_code = 'sk'");
    $pdo->exec("INSERT INTO languages (code, name, native_name, enabled, sort_order) VALUES ('de', 'German', 'Deutsch', FALSE, 3)");
    $pdo->exec("INSERT INTO translations (key_name, language_code, value, updated_by, updated_at) VALUES ('language.button', 'de', 'Sprache: {name} ({code})', 7, '2026-10-09 09:00:00+00')");
    $pdo->exec("UPDATE translations SET value = 'Moje účty', updated_by = 8 WHERE key_name = 'topbar.dashboard' AND language_code = 'sk'");
    $row = fn (string $key, string $code): ?array => ($found = $pdo->query("SELECT value, updated_by, updated_at FROM translations WHERE key_name = '{$key}' AND language_code = '{$code}'")->fetch(PDO::FETCH_ASSOC)) === false ? null : $found;
    $before = [
        'button en' => $row('language.button', 'en'), 'button de' => $row('language.button', 'de'),
        'notLoaded en' => $row('language.notLoaded', 'en'), 'notLoaded sk' => $row('language.notLoaded', 'sk'),
        'dashboard sk' => $row('topbar.dashboard', 'sk'),
    ];
    $keys = (int) $pdo->query('SELECT count(*) FROM translation_keys')->fetchColumn();

    // The upload: 006, then the library's two files - one Apply.
    $result = Migrations::migrator($pdo)->apply();
    same(null, $result['error'], 'no error');
    same([siteFile(ADOPTION_FILE), ...libraryFiles()], $result['applied'], '006, then the library\'s');

    // Every string moved as it was - its words, its writer, its time - and the cleared one stayed cleared:
    // the library writes strings only for a key of its own that has none.
    same($before['button en'], $row('anotoki.language.button', 'en'), 'the English rewording, by whom and when');
    same(null, $row('anotoki.language.button', 'sk'), 'the cleared Slovak stays cleared - the library did not write its own words there');
    same($before['button de'], $row('anotoki.language.button', 'de'), 'German moved too');
    same($before['notLoaded sk'], $row('anotoki.language.notLoaded', 'sk'), 'the Slovak rewording');
    same($before['notLoaded en'], $row('anotoki.language.notLoaded', 'en'), 'the English as it was seeded, by nobody');
    same($before['dashboard sk'], $row('topbar.dashboard', 'sk'), 'a string of a key that stays: as the owner left it');
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name IN ('language.button', 'language.notLoaded')")->fetchColumn(), 'the old keys are gone');
    same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name IN ('language.button', 'language.notLoaded')")->fetchColumn(), 'and their strings');

    // The library's own words came with its 002: its descriptions on the moved keys, its words on its other keys.
    check(str_starts_with((string) $pdo->query("SELECT description FROM translation_keys WHERE name = 'anotoki.language.button'")->fetchColumn(), 'The anotoki library\'s language switcher'), 'the library\'s description');
    same('Jazyk sa nepodarilo uložiť do tvojho anotoki účtu - platí len počas tejto návštevy.', $row('anotoki.language.notSaved', 'sk')['value'] ?? null, 'a word of the library\'s this site did not have, in Slovak');
    same(['the site\'s' => $keys - 2, 'the library\'s' => 54], [
        'the site\'s' => (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name NOT LIKE 'anotoki.%'")->fetchColumn(),
        'the library\'s' => (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'anotoki.%'")->fetchColumn(),
    ], 'the site\'s keys less the two that moved, and the library\'s');
    same([], Migrations::migrator($pdo)->applicable(), 'nothing left to apply');
});

test('006\'s gate refuses a database whose keys are not the ones it expects - and nothing of it, nor the library\'s files after it, is applied', function () use ($server) {
    $dir = migrationsFolder('migrations-to-005-gate', array_values(array_filter(allMigrations(), fn (string $file): bool => strcmp($file, '006') < 0)));
    $pdo = scratchDatabase($server, 'to005gate');
    same(null, Migrations::migrator($pdo, $dir, library: false)->apply()['error'], '001-005 applied');
    // A key no migration of this site wrote: the running count of the site's own keys does not hold.
    $pdo->exec("INSERT INTO translation_keys (name, description) VALUES ('zz.stray', 'Not a migration''s')");
    $pdo->exec("INSERT INTO translations (key_name, language_code, value) VALUES ('zz.stray', 'en', 'Stray')");

    $result = Migrations::migrator($pdo)->apply();
    same([[], siteFile(ADOPTION_FILE)], [$result['applied'], $result['failed']], 'stopped at 006');
    check(str_contains((string) $result['error'], 'There are 451 keys of the site\'s own, and 005 with this file leave 450'), 'the gate says why: '.$result['error']);
    same(2, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name IN ('language.button', 'language.notLoaded')")->fetchColumn(), 'the keys did not move');
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'anotoki.%'")->fetchColumn(), 'and the library\'s files did not run');
    same([ADOPTION_FILE, ...array_column(libraryFiles(), 'name')], array_column(Migrations::migrator($pdo)->status()['pending'], 'name'), 'all three still wait');
});

foreach ([6 => 'the version first committed', 7 => 'with sign-in through the IAM', 8 => 'with resumable syncs'] as $level => $what) {
    test("a Laravel database ({$what}) is brought forward with all its data", function () use ($server, $level) {
        $pdo = laravelDatabase($server, $level);
        $migrator = Migrations::migrator($pdo, null, ['local_user_id' => 1]);
        $before = [];
        foreach (['game_characters', 'game_weapons', 'game_sets', 'accounts', 'import_runs', 'account_characters', 'account_weapons', 'account_items', 'build_sources', 'builds', 'sync_runs'] as $table) {
            $before[$table] = (int) $pdo->query("SELECT count(*) FROM {$table}")->fetchColumn();
        }

        // Looking does not change anything: Laravel's table has no file column, so nothing counts as applied.
        check(Migrations::isLaravelDatabase($pdo), 'recognised');
        $status = $migrator->status();
        same([everyFile(), [], []], [filesOf($status['pending']), $status['applied'], $status['missing']], 'pending, and nothing applied');
        same(everyFile(), $migrator->applicable(), 'every file to apply - the library\'s last');
        same(1, (int) $pdo->query("SELECT count(*) FROM information_schema.columns WHERE table_name = 'migrations' AND column_name = 'batch'")->fetchColumn(), 'still Laravel\'s table after looking');

        $result = $migrator->apply();
        same(null, $result['error'], 'no error');
        same(everyFile(), $result['applied'], 'every file applied');
        same(true, (bool) $pdo->query("SELECT to_regclass('public.laravel_migrations') IS NOT NULL")->fetchColumn(), 'Laravel\'s table moved aside');
        check(Migrations::isLaravelDatabase($pdo), 'and still known for what it was');

        // Nothing lost.
        foreach ($before as $table => $count) {
            same($count, (int) $pdo->query("SELECT count(*) FROM {$table}")->fetchColumn(), "rows of {$table}");
        }
        same($level, (int) $pdo->query('SELECT count(*) FROM laravel_migrations')->fetchColumn(), 'Laravel\'s record, kept aside');
        same(1, (int) $pdo->query('SELECT count(*) FROM laravel_failed_jobs')->fetchColumn(), 'a framework table with rows is kept aside');
        same(0, (int) $pdo->query('SELECT count(*) FROM cache')->fetchColumn(), 'the cache is emptied');

        // The times are the same instants, now with their zone.
        same('2026-09-21 12:00:00+00', $pdo->query('SELECT last_import_at FROM accounts ORDER BY id LIMIT 1')->fetchColumn(), 'a time');
        same('2021-03-02 00:00:00+00', $pdo->query("SELECT released_at FROM game_characters WHERE key = 'hutao'")->fetchColumn(), 'a release date');

        // Owners.
        same(0, (int) $pdo->query('SELECT count(*) FROM accounts WHERE user_id IS NULL')->fetchColumn(), 'every account has an owner');
        if ($level === 6) {
            same([1], array_map('intval', $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN)), 'the owner was made');
            same(1, (int) $pdo->query('SELECT count(*) FROM laravel_users')->fetchColumn(), 'Laravel\'s login table, kept aside');
            same('failed', $pdo->query('SELECT status FROM sync_runs ORDER BY id DESC LIMIT 1')->fetchColumn(), 'a queue run left running is closed');
        } else {
            same([1, 42], array_map('intval', $pdo->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)), 'the IAM users');
            same(2, (int) $pdo->query("SELECT count(*) FROM accounts WHERE uid = '700000001'")->fetchColumn(), 'two people, one UID');
        }
        if ($level === 8) {
            same('running', $pdo->query('SELECT status FROM sync_runs ORDER BY id DESC LIMIT 1')->fetchColumn(), 'a stepped run keeps going');
            same('2026-09-26 04:30:10+00', $pdo->query('SELECT next_step_at FROM sync_runs ORDER BY id DESC LIMIT 1')->fetchColumn(), 'with its place');
        }

        // The same database as a new one, down to the names of its constraints and indexes - the bookkeeping
        // table's too (Laravel's keys moved aside with their table, so the new one's have their usual names).
        same(schemaOf(db()->raw()), schemaOf($pdo), 'the schema of a new database');
        same(bookkeepingOf(db()->raw()), bookkeepingOf($pdo), 'the bookkeeping table of a new database');

        // The IAM people are still IAM people: no key, never seen as a visitor (migration 004).
        same(0, (int) $pdo->query('SELECT count(*) FROM users WHERE id < 1 OR visitor_key_hash IS NOT NULL OR last_seen_at IS NOT NULL')->fetchColumn(), 'the people, as they were');

        // And migrating again finds nothing to do.
        same([], Migrations::migrator($pdo)->applicable(), 'nothing pending');
    });
}

finishTests();
