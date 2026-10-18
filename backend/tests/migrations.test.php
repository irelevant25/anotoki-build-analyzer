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

require __DIR__ . '/lib.php';

use Anotoki\Lib\Admin\Schema as AdminSchema;
use Anotoki\Lib\Migrations\MigrationSet;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Migrations\Splitter;
use Anotoki\Lib\People\Schema as PeopleSchema;
use Anotoki\Lib\Translations\Schema;
use App\Auth\Role;
use App\Config;
use App\Db\Db;
use App\Db\Migrations;
use App\Support\Log;
use Tests\Support\Client;

bootTests();

$server = require __DIR__ . '/../config/database.local.php';

/** The file that moved this site's two words of the library's under the library's keys. */
const ADOPTION_FILE = '006_strings_through_the_library.sql';

/** The file of the legal pages' words (and the toasts' descriptions). */
const LEGAL_PAGES_FILE = '011_legal_pages.sql';

/** How many keys of the site's own 011 adds (the legal pages' facts, their tabs' titles, the footer's fan clause). */
const LEGAL_PAGES_KEYS = 52;

/** The file of the words the kit's stepper and tabs need (two new, the old stepper's one gone). */
const KIT_PIECES_FILE = '012_stepper_and_tab_words.sql';

/** What 012 changes in the site's own keys: two added, one taken away. */
const KIT_PIECES_KEYS = 2 - 1;

/** The file that moves the footer's words under the library's keys, the family's footer in the site's (anotoki-lib 0.6.3): 0.4's last. */
const FOOTER_FILE = '013_footer_words_through_the_library.sql';

/** How many keys of the site's own 013 takes away: the footer's three, the library's now. */
const FOOTER_KEYS = 3;

/** Every column, constraint and index of the app's tables, as comparable lines. */
function schemaOf(PDO $pdo): array
{
    $lines = [];
    foreach (
        $pdo->query("SELECT table_name, column_name, data_type, character_maximum_length, is_nullable,
            regexp_replace(COALESCE(column_default, ''), '''(\\d+)''::smallint', '\\1') AS column_default
        FROM information_schema.columns WHERE table_schema = 'public' AND table_name NOT LIKE 'laravel\\_%' AND table_name <> 'migrations'
        ORDER BY table_name, column_name")
        as $row
    ) {
        $lines[] = 'column ' . implode(' ', $row);
    }
    foreach (
        $pdo->query("SELECT c.conrelid::regclass::text AS tbl, c.conname, pg_get_constraintdef(c.oid) AS def
        FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace AND n.nspname = 'public'
        WHERE c.conrelid::regclass::text NOT LIKE 'laravel\\_%' AND c.conrelid::regclass::text <> 'migrations' AND c.contype <> 'n'
        ORDER BY 1, 2")
        as $row
    ) {
        $lines[] = 'constraint ' . implode(' ', $row);
    }
    foreach (
        $pdo->query("SELECT tablename, indexname, indexdef FROM pg_indexes WHERE schemaname = 'public'
        AND tablename NOT LIKE 'laravel\\_%' AND tablename <> 'migrations' ORDER BY 1, 2")
        as $row
    ) {
        $lines[] = 'index ' . implode(' ', $row);
    }

    return $lines;
}

/** A database as the Laravel version left it after $level migrations, with rows in it. */
function laravelDatabase(array $server, int $level): PDO
{
    $pdo = scratchDatabase($server, "laravel{$level}");
    foreach (Splitter::split((string) file_get_contents(__DIR__ . "/fixtures/laravel/schema-{$level}.sql")) as $statement) {
        $pdo->exec($statement);
    }
    // The session's zone does not matter: that version kept UTC in columns without one.
    $pdo->exec("SET TIME ZONE 'Europe/Prague'");

    $migrations = [
        '0001_01_01_000000_create_users_table',
        '0001_01_01_000001_create_cache_table',
        '0001_01_01_000002_create_jobs_table',
        '2026_09_24_000100_create_catalog_tables',
        '2026_09_24_000200_create_account_tables',
        '2026_09_24_000300_create_build_tables',
        '2026_09_27_000100_add_owners_to_accounts',
        '2026_09_27_000200_make_sync_runs_resumable',
    ];
    foreach (array_slice($migrations, 0, $level) as $index => $name) {
        $pdo->prepare('INSERT INTO migrations (migration, batch) VALUES (?, ?)')->execute([$name, $index < 6 ? 1 : $index - 4]);
    }

    $t = '2026-09-20 10:15:30';
    $insert = function (string $sql, array $params = []) use ($pdo): int {
        $statement = $pdo->prepare($sql . ' RETURNING id');
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    };

    $hutao = $insert(
        "INSERT INTO game_characters (game, key, name, element, rarity, weapon_type, external_id, icon_url, released_at, created_at, updated_at)
        VALUES ('genshin', 'hutao', 'Hu Tao', 'pyro', 5, 'polearm', '10000046', 'https://icons.example/hutao.png', '2021-03-02 00:00:00', ?, ?)",
        [$t, $t],
    );
    $homa = $insert("INSERT INTO game_weapons (game, key, name, rarity, external_id, created_at, updated_at) VALUES ('genshin', 'staffofhoma', 'Staff of Homa', 5, '13501', ?, ?)", [$t, $t]);
    $cw = $insert(
        "INSERT INTO game_sets (game, key, name, kind, external_id, meta, created_at, updated_at)
        VALUES ('genshin', 'crimsonwitchofflames', 'Crimson Witch of Flames', 'artifact', '15006', '{\"bonuses\":[\"Pyro DMG Bonus +15%\"]}', ?, ?)",
        [$t, $t],
    );

    $owners = $level >= 7;
    if ($owners) {
        $pdo->exec("INSERT INTO users (id, name, password, created_at, updated_at) VALUES (1, 'owner', NULL, '{$t}', '{$t}'), (42, 'mona', NULL, '{$t}', '{$t}')");
    } else {
        // Laravel's own login table, never used by the app - but not empty here.
        $pdo->exec("INSERT INTO users (name, email, password, created_at, updated_at) VALUES ('Test User', 'test@example.test', 'x', '{$t}', '{$t}')");
    }

    $account = $insert(
        'INSERT INTO accounts (' .
            ($owners ? 'user_id, ' : '') .
            "game, uid, nickname, level, has_full_inventory, last_import_at, meta, created_at, updated_at)
        VALUES (" .
            ($owners ? '1, ' : '') .
            "'genshin', '700000001', 'Main', 60, TRUE, '2026-09-21 12:00:00', '{\"region\":\"EU\"}', ?, ?)",
        [$t, $t],
    );
    if ($owners) {
        // Two people with the same UID, as the owners migration allows.
        $insert("INSERT INTO accounts (user_id, game, uid, nickname, created_at, updated_at) VALUES (42, 'genshin', '700000001', 'Mona main', ?, ?)", [$t, $t]);
    }
    $insert(
        "INSERT INTO account_characters (account_id, game_character_id, level, rank, talents, final_stats, source, synced_at, created_at, updated_at)
        VALUES (?, ?, 90, 1, '{\"normal\":10}', '{\"critRate_\":65.2}', 'enka', '2026-09-21 12:00:00', ?, ?)",
        [$account, $hutao, $t, $t],
    );
    $insert(
        "INSERT INTO account_weapons (account_id, game_weapon_id, level, refinement, equipped_character_id, locked, source, created_at, updated_at)
        VALUES (?, ?, 90, 1, ?, TRUE, 'good', ?, ?)",
        [$account, $homa, $hutao, $t, $t],
    );
    $insert(
        "INSERT INTO account_items (account_id, game_set_id, slot, rarity, level, main_stat, main_value, substats, equipped_character_id, source, created_at, updated_at)
        VALUES (?, ?, 'flower', 5, 20, 'hp', 4780, '[{\"key\":\"critRate_\",\"value\":10.5,\"rolls\":3}]', ?, 'good', ?, ?)",
        [$account, $cw, $hutao, $t, $t],
    );
    $insert(
        'INSERT INTO import_runs (' .
            ($owners ? 'user_id, ' : '') .
            "account_id, game, source, status, summary, created_at, updated_at)
        VALUES (" .
            ($owners ? '1, ' : '') .
            "?, 'genshin', 'good', 'success', '{\"characters\":1}', ?, ?)",
        [$account, $t, $t],
    );

    $source = $insert(
        "INSERT INTO build_sources (key, game, name, base_url, enabled, last_synced_at, last_status, created_at, updated_at)
        VALUES ('prydwen.genshin', 'genshin', 'Prydwen', 'https://www.prydwen.gg', TRUE, '2026-09-25 04:30:00', 'success', ?, ?)",
        [$t, $t],
    );
    $insert(
        "INSERT INTO builds (build_source_id, game_character_id, variant, recommended, position, weapons, sets, main_stats, substats, content_hash, fetched_at, changed_at, created_at, updated_at)
        VALUES (?, ?, 'DPS', TRUE, 0, '[]', '[]', '{\"goblet\":[{\"stat\":\"pyro_dmg_\",\"priority\":1}]}', '[]', ?, '2026-09-25 04:31:00', '2026-09-24 04:31:00', ?, ?)",
        [$source, $hutao, str_repeat('a', 64), $t, $t],
    );

    $insert(
        "INSERT INTO sync_runs (build_source_id, status, started_at, finished_at, log, created_at, updated_at)
        VALUES (?, 'success', '2026-09-25 04:30:00', '2026-09-25 04:50:00', 'Done: success.', ?, ?)",
        [$source, $t, $t],
    );
    if ($level >= 8) {
        // A stepped run in progress: it keeps its place.
        $insert(
            "INSERT INTO sync_runs (build_source_id, status, started_at, created_at, updated_at, kind, game, started_by, state, next_step_at)
            VALUES (?, 'running', '2026-09-26 04:30:00', ?, ?, 'builds', 'genshin', 1, '{\"done\":1,\"total\":3}', '2026-09-26 04:30:10')",
            [$source, $t, $t],
        );
    } else {
        // Left "running" by a queue worker that stopped: nothing to resume from.
        $insert("INSERT INTO sync_runs (build_source_id, status, started_at, created_at, updated_at) VALUES (?, 'running', '2026-09-26 04:30:00', ?, ?)", [$source, $t, $t]);
    }

    $pdo->exec("INSERT INTO cache (key, value, expiration) VALUES ('laravel_cache_x', 's:1:\"1\";', 1758866460)");
    // Laravel's queue was not empty: a failed job, one still queued, a batch of them.
    $pdo->exec("INSERT INTO failed_jobs (uuid, connection, queue, payload, exception, failed_at) VALUES ('u-1', 'database', 'default', '{}', 'RuntimeException', '{$t}')");
    $pdo->exec("INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', '{\"job\":\"sync\"}', 0, 1758530000, 1758530000)");
    $pdo->exec("INSERT INTO job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, created_at) VALUES ('b-1', 'sync', 1, 1, 0, '[]', 1758530000)");
    // Nor were its login tables: a session (its browser's network address) and, where the version still had the
    // table, a password reset asked for (an email address).
    $pdo->exec("INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, last_activity) VALUES ('s3ss10n', NULL, '203.0.113.7', 'Mozilla/5.0', 'YTowOnt9', 1758530000)");
    if ($level === 6) {
        $pdo->exec("INSERT INTO password_reset_tokens (email, token, created_at) VALUES ('test@example.test', 'x', '{$t}')");
    }
    $pdo->exec("SET TIME ZONE 'UTC'");

    return $pdo;
}

/** Every migration file there is, in the order they apply. */
function allMigrations(): array
{
    $files = array_map('basename', glob(Migrations::directory() . '/*.sql') ?: []);
    sort($files);

    return $files;
}

/** A file of the site's set, as the engine names it in its answers. */
function siteFile(string $name): array
{
    return ['set' => Migrations::SET, 'name' => $name];
}

/**
 * The files of one of the anotoki library's own sets, in the order they apply, as the engine names them in its
 * answers.
 *
 * @return list<array{set: string, name: string}>
 */
function setFiles(string $set, string $directory): array
{
    $files = array_map('basename', glob($directory . '/*.sql') ?: []);
    sort($files);

    return array_map(static fn(string $name): array => ['set' => $set, 'name' => $name], $files);
}

/** The library's admin tools' files: they apply first, before any of the site's. */
function adminFiles(): array
{
    return setFiles(AdminSchema::SET, AdminSchema::directory());
}

/** The library's translations files: after every one of the site's. */
function libraryFiles(): array
{
    return setFiles(Schema::SET, Schema::directory());
}

/** The library's people files: last. */
function peopleFiles(): array
{
    return setFiles(PeopleSchema::SET, PeopleSchema::directory());
}

/** Every file a database is built from, as the engine names them: the admin tools', the site's, the translations', the people's. */
function everyFile(): array
{
    return [...adminFiles(), ...array_map('siteFile', allMigrations()), ...libraryFiles(), ...peopleFiles()];
}

/** A file as the bookkeeping table and the command line name it: the site's by its name, the library's with its set in front. */
function recorded(array $file): string
{
    return ($file['set'] === Migrations::SET ? '' : $file['set'] . '/') . $file['name'];
}

/** The engine's answer's files, without what else it says of them (applied_at, ready, blocked). */
function filesOf(array $files): array
{
    return array_map(static fn(array $file): array => ['set' => $file['set'], 'name' => $file['name']], $files);
}

/** The bookkeeping table as a database has it: its columns and its keys. */
function bookkeepingOf(PDO $pdo): array
{
    return [
        $pdo
            ->query(
                "SELECT column_name, data_type, character_maximum_length, is_nullable, column_default FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'migrations' ORDER BY ordinal_position",
            )
            ->fetchAll(PDO::FETCH_NUM),
        $pdo
            ->query(
                "SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint
            WHERE conrelid = 'migrations'::regclass AND contype IN ('p', 'u') ORDER BY conname",
            )
            ->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
}

/** A folder of migrations of a test's own: copies of the site's files (those named), and files of its own. */
function migrationsFolder(string $name, array $copies, array $own = []): string
{
    $dir = T::$storage . DIRECTORY_SEPARATOR . $name;
    @mkdir($dir, 0777, true);
    foreach ($copies as $file) {
        copy(Migrations::directory() . '/' . $file, $dir . '/' . $file);
    }
    foreach ($own as $file => $sql) {
        file_put_contents($dir . '/' . $file, $sql);
    }

    return $dir;
}

test('a new database is built by the migrations, in the bookkeeping table this site always had', function () {
    $migrator = Migrations::migrator(db()->raw());
    $status = $migrator->status();
    same([AdminSchema::SET, Migrations::SET, Schema::SET, PeopleSchema::SET], $status['sets'], 'the library\'s admin tools\' set, the site\'s, then the library\'s translations and people');
    same(
        [[], [], everyFile()],
        [$status['pending'], $status['missing'], filesOf($status['applied'])],
        'status: nothing pending or missing, every file applied in order - the admin tools\' first, the translations\' and the people\'s after the site\'s',
    );
    same(array_map('recorded', everyFile()), db()->column('SELECT filename FROM migrations ORDER BY id'), 'recorded: the site\'s by their names, the library\'s with their set');
    same([], $migrator->applicable(), 'nothing to apply');
    foreach ($status['applied'] as $file) {
        check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $file['applied_at']) === 1, 'applied_at in UTC: ' . $file['applied_at']);
    }
    same(false, Migrations::isLaravelDatabase(db()->raw()), 'not a Laravel database');

    // The table this site's own engine made, column for column and key for key: the family's engine reads
    // and writes the shape a database has, and makes this one where there is none.
    same(
        [
            [
                ['id', 'integer', null, 'NO', "nextval('migrations_id_seq'::regclass)"],
                ['filename', 'character varying', 255, 'NO', null],
                ['applied_at', 'timestamp without time zone', null, 'YES', 'CURRENT_TIMESTAMP'],
            ],
            ['migrations_filename_key' => 'UNIQUE (filename)', 'migrations_pkey' => 'PRIMARY KEY (id)'],
        ],
        bookkeepingOf(db()->raw()),
        'migrations (id, filename, applied_at)',
    );
});

test('a failing migration applies nothing of itself, and stops there', function () use ($server) {
    $pdo = scratchDatabase($server, 'failing');
    $dir = migrationsFolder(
        'failing-migrations',
        [],
        [
            '001_good.sql' => 'CREATE TABLE good (id INT);',
            '002_bad.sql' => 'CREATE TABLE half (id INT); SELECT * FROM missing_table;',
            '003_never.sql' => 'CREATE TABLE never (id INT);',
        ],
    );

    $result = Migrations::migrator($pdo, $dir, library: false)->apply();

    same([siteFile('001_good.sql')], $result['applied'], 'applied');
    same(siteFile('002_bad.sql'), $result['failed'], 'failed');
    check(
        str_starts_with((string) $result['error'], 'statement 2 of 2: SQLSTATE[42P01]') && str_contains((string) $result['error'], "\n\n  SELECT * FROM missing_table"),
        'which statement, the database\'s error, and the statement: ' . $result['error'],
    );
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
    $log = T::$storage . DIRECTORY_SEPARATOR . 'api-failing.log';
    @unlink($log);

    $appDb = T::$app->get(Db::class);
    T::$app->set(Db::class, new Db($pdo));
    T::$app->set(Migrator::class, Migrations::migrator($pdo, $dir));
    Log::to($log);
    try {
        $admin = (new Client())->asIam(1, [Role::ADMIN]);
        (new Client())->get('/api/site-status')->status(200)->path('state', 'update_pending');
        $admin->get('/api/accounts')->status(503)->path('code', 'update_pending');
        $status = $admin->get('/api/admin/migrations')->status(200);
        same(
            [
                siteFile('900_good.sql') + ['ready' => true, 'blocked' => false],
                siteFile('901_bad.sql') + ['ready' => true, 'blocked' => false],
                siteFile('902_never.sql') + ['ready' => true, 'blocked' => false],
            ],
            $status->json('pending'),
            'pending, in the order they will run',
        );
        same(everyFile(), filesOf($status->json('applied')), 'applied, in the order they were - the library\'s too');

        $answer = $admin->post('/api/admin/migrations/apply')->status(200);
        same([[siteFile('900_good.sql')], siteFile('901_bad.sql'), null], [$answer->json('applied'), $answer->json('failed'), $answer->json('note')], 'applied before the stop, and where it stopped');
        $error = (string) $answer->json('error');
        check(str_contains($error, 'statement 2 of 2') && str_contains($error, 'SQLSTATE[42P01]') && str_contains($error, '"missing_table" does not exist'), 'the database\'s error: ' . $error);
        same(true, (bool) $pdo->query("SELECT to_regclass('public.good') IS NOT NULL")->fetchColumn(), '900 applied');
        same(false, (bool) $pdo->query("SELECT to_regclass('public.half') IS NOT NULL")->fetchColumn(), 'nothing of 901 stayed');
        same(false, (bool) $pdo->query("SELECT to_regclass('public.never') IS NOT NULL")->fetchColumn(), '902 was not run');
        same(['901_bad.sql', '902_never.sql'], array_column($admin->get('/api/admin/migrations')->status(200)->json('pending'), 'name'), 'still pending');
        $logged = (string) @file_get_contents($log);
        check(
            str_contains($logged, ' ERROR ') && str_contains($logged, 'Migration 901_bad.sql failed') && str_contains($logged, 'Migration 900_good.sql applied.'),
            'the failure and what went first are in the log: ' . $logged,
        );

        // The lock was given back: the next apply runs (and stops at the same file), it is not told "busy".
        $again = $admin->post('/api/admin/migrations/apply')->status(200);
        same([[], siteFile('901_bad.sql')], [$again->json('applied'), $again->json('failed')], 'the next apply');

        // A file's SQL for the page's View - one of the set's files, and nothing else.
        same(
            ['set' => Migrations::SET, 'name' => '900_good.sql', 'sql' => 'CREATE TABLE good (id INT);'],
            $admin->get('/api/admin/migrations/file?set=build_analyzer&name=900_good.sql')->status(200)->json(),
            'a file',
        );
        foreach (['set=build_analyzer&name=../config/database.local.php', 'set=build_analyzer&name=nothing.sql', 'set=other&name=900_good.sql', 'name=900_good.sql'] as $query) {
            $admin
                ->get('/api/admin/migrations/file?' . $query)
                ->status(404)
                ->path('code', 'not_found');
        }
    } finally {
        Log::to(null);
        T::$app->set(Db::class, $appDb);
        T::$app->forget(Migrator::class);
    }
    (new Client())->get('/api/site-status')->status(200)->path('state', 'ready');
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
    $other->query('SELECT pg_advisory_lock(' . Migrations::LOCK . ')');
    try {
        $result = Migrations::migrator($pdo, $dir, library: false)->apply();
        same([true, [], null, null], [$result['busy'], $result['applied'], $result['failed'], $result['error']], 'busy, and nothing applied');
        same(false, (bool) $pdo->query("SELECT to_regclass('public.waited') IS NOT NULL")->fetchColumn(), 'nothing ran');
        same(false, (bool) $pdo->query("SELECT to_regclass('public.migrations') IS NOT NULL")->fetchColumn(), 'not even the bookkeeping table was made');
    } finally {
        $other->query('SELECT pg_advisory_unlock(' . Migrations::LOCK . ')');
    }

    same([siteFile('001_table.sql')], Migrations::migrator($pdo, $dir, library: false)->apply()['applied'], 'once the other run is done');
});

test('a pooled connection gives back the lock an apply left held when its request died - or the site would stay closed', function () use ($server) {
    // A persistent connection holding the lock, as a request that died in the middle of an apply leaves it.
    $pooled = Db::pdo($server, T::$database, true);
    $pooled->query('SELECT pg_advisory_lock(' . Migrations::LOCK . ')');
    $pooled = null;
    $other = Db::pdo($server, T::$database, false);
    $free = fn(): bool => (bool) $other->query('SELECT pg_try_advisory_lock(' . Migrations::LOCK . ')')->fetchColumn();
    check(!$free(), 'held by the pooled connection');

    // The next request takes the same pooled connection - and finds it as a new one.
    $again = Db::pdo($server, T::$database, true);
    check($free(), 'given back as the pooled connection was taken again');
    $other->query('SELECT pg_advisory_unlock(' . Migrations::LOCK . ')');
    same('UTC', $again->query('SHOW TIME ZONE')->fetchColumn(), 'and set up as ever');
});

test('the command line builds a database from nothing, says where it stands, and finds it up to date the next time', function () use ($server) {
    // migration.php as it runs on a computer with a shell - on a throwaway database of its own, through the
    // settings folder BUILD_ANALYZER_TEST_CONFIG names (honoured for a build_analyzer_test_ database only).
    $name = T::$database . '_cli';
    $settings = T::$storage . DIRECTORY_SEPARATOR . 'cli-settings';
    @mkdir($settings . '/storage', 0777, true);
    file_put_contents($settings . '/database.local.php', '<?php return ' . var_export(['database' => $name, 'persistent' => false] + $server, true) . ";\n");
    file_put_contents($settings . '/auth.local.php', '<?php return ' . var_export(['issuer' => TEST_ISSUER, 'client_id' => TEST_CLIENT], true) . ";\n");
    register_shutdown_function(static function () use ($server, $name): void {
        try {
            Db::pdo($server, 'postgres', false)->exec('DROP DATABASE IF EXISTS "' . $name . '" WITH (FORCE)');
        } catch (Throwable $e) {
            fwrite(STDERR, "could not drop {$name}: {$e->getMessage()}\n");
        }
    });

    $run = function (string ...$arguments) use ($settings): array {
        $command = escapeshellarg(PHP_BINARY) . ' migration.php' . ($arguments === [] ? '' : ' ' . implode(' ', array_map('escapeshellarg', $arguments)));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), [Config::TEST_CONFIG_ENV => $settings] + getenv());
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        return [proc_close($process), str_replace("\r\n", "\n", (string) $output)];
    };
    // The site's files by their names, then the library's with their set in front.
    $files = array_map('recorded', everyFile());
    check(in_array('anotoki_translations/001_languages_and_strings.sql', $files, true), 'the library\'s files among them');

    [$code, $output] = $run();
    same(0, $code, 'the first run: ' . $output);
    check(str_contains($output, "== build analyzer database ({$name})\n  Database created.\n"), 'the database made: ' . $output);
    foreach ($files as $file) {
        check(str_contains($output, "  {$file} ... done\n"), "{$file} applied: {$output}");
    }
    check(strpos($output, '006_strings_through_the_library.sql ... done') < strpos($output, 'anotoki_translations/001_languages_and_strings.sql ... done'), 'the library\'s after the site\'s');

    [$code, $output] = $run('--status');
    same(0, $code, '--status: ' . $output);
    same(
        implode('', array_map(fn(string $file): string => "  v {$file}\n", $files)) . '  ' . count($files) . " applied, 0 pending\n",
        substr($output, strlen("== build analyzer database ({$name})\n")),
        'every file applied',
    );

    [$code, $output] = $run();
    same([0, "== build analyzer database ({$name})\n  Up to date - " . count($files) . " migrations.\n"], [$code, $output], 'the next run');
    same($files, Db::pdo($server, $name, false)->query('SELECT filename FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'recorded in order');
});

test('004 applies over a 003 whose strings were reworded and whose Slovak was cleared in the browser', function () use ($server) {
    // A database as the owner's is before 004: 001-003 applied, then the Translations grid used.
    $dir = migrationsFolder('migrations-to-003', array_values(array_filter(allMigrations(), fn(string $file): bool => strcmp($file, '004') < 0)));
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
    same(
        [...adminFiles(), ...array_map('siteFile', array_values(array_filter(allMigrations(), fn(string $f): bool => strcmp($f, '004') >= 0))), ...libraryFiles(), ...peopleFiles()],
        $result['applied'],
        'applied from 004 on, and the library\'s - its admin tools\' first',
    );

    $value = fn(string $key, string $code): mixed => $pdo->query("SELECT value FROM translations WHERE key_name = '{$key}' AND language_code = '{$code}'")->fetchColumn();
    same(false, $value('common.signIn', 'sk'), 'the cleared Slovak string stays cleared');
    same('Log in', $value('common.signIn', 'en'), 'the reworded English string is not written over');
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name = 'nav.dashboard'")->fetchColumn(), 'a retired key goes, reworded or not');
    same(false, $value('nav.dashboard', 'en'), 'and its strings with it');
    check($value('topbar.dashboard', 'sk') !== false, 'the new keys read in Slovak');
});

test('006 moves the two words of the library\'s under its keys with every rewording, writer and time - on a database as its owner left it', function () use ($server) {
    // A database as the owner's is before this upload: 001-005 applied (none of the library's files yet), then
    // the Translations grid and the Languages page used.
    $dir = migrationsFolder('migrations-to-005', array_values(array_filter(allMigrations(), fn(string $file): bool => strcmp($file, '006') < 0)));
    $pdo = scratchDatabase($server, 'to005');
    $built = Migrations::migrator($pdo, $dir, library: false)->apply();
    same(null, $built['error'], '001-005 applied');
    same([siteFile('005_retire_not_set_up_page.sql')], array_slice($built['applied'], -1), 'up to 005');

    // An editor (IAM user 7) reworded the English of the language button's name; another (8) the Slovak of
    // "could not be loaded"; Slovak's language button was cleared on purpose (it reads the English); German was
    // added, with a string of its own; and a string of a key that stays where it is was reworded too.
    $pdo->exec(
        "UPDATE translations SET value = 'Choose a language: {name} ({code})', updated_by = 7, updated_at = '2026-10-09 08:15:00+00' WHERE key_name = 'language.button' AND language_code = 'en'",
    );
    $pdo->exec(
        "UPDATE translations SET value = 'Jazyk sa teraz nedá načítať - skús to o chvíľu.', updated_by = 8, updated_at = '2026-10-10 18:30:00+00' WHERE key_name = 'language.notLoaded' AND language_code = 'sk'",
    );
    $pdo->exec("DELETE FROM translations WHERE key_name = 'language.button' AND language_code = 'sk'");
    $pdo->exec("INSERT INTO languages (code, name, native_name, enabled, sort_order) VALUES ('de', 'German', 'Deutsch', FALSE, 3)");
    $pdo->exec("INSERT INTO translations (key_name, language_code, value, updated_by, updated_at) VALUES ('language.button', 'de', 'Sprache: {name} ({code})', 7, '2026-10-09 09:00:00+00')");
    $pdo->exec("UPDATE translations SET value = 'Moje účty', updated_by = 8 WHERE key_name = 'topbar.dashboard' AND language_code = 'sk'");
    $row = fn(string $key, string $code): ?array => ($found = $pdo
        ->query("SELECT value, updated_by, updated_at FROM translations WHERE key_name = '{$key}' AND language_code = '{$code}'")
        ->fetch(PDO::FETCH_ASSOC)) === false
        ? null
        : $found;
    $before = [
        'button en' => $row('language.button', 'en'),
        'button de' => $row('language.button', 'de'),
        'notLoaded en' => $row('language.notLoaded', 'en'),
        'notLoaded sk' => $row('language.notLoaded', 'sk'),
        'dashboard sk' => $row('topbar.dashboard', 'sk'),
    ];
    $keys = (int) $pdo->query('SELECT count(*) FROM translation_keys')->fetchColumn();

    // The upload: 006 (and the files after it), the library's files around them - one Apply.
    $result = Migrations::migrator($pdo)->apply();
    same(null, $result['error'], 'no error');
    same(
        [...adminFiles(), ...array_map('siteFile', array_values(array_filter(allMigrations(), fn(string $f): bool => strcmp($f, '006') >= 0))), ...libraryFiles(), ...peopleFiles()],
        $result['applied'],
        '006 and the site\'s after it, then the library\'s',
    );

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
    check(
        str_starts_with((string) $pdo->query("SELECT description FROM translation_keys WHERE name = 'anotoki.language.button'")->fetchColumn(), 'The anotoki library\'s language switcher'),
        'the library\'s description',
    );
    same(
        'Jazyk sa nepodarilo uložiť do tvojho anotoki účtu - platí len počas tejto návštevy.',
        $row('anotoki.language.notSaved', 'sk')['value'] ?? null,
        'a word of the library\'s this site did not have, in Slovak',
    );
    // 006 moved two of the site's keys; 009 moved five more, retired three and added five; 010 moved twelve and retired
    // two; 011 added the legal pages' words; 012 the kit's pieces'; 013 moved the footer's three. The library's keys
    // are every key of its words file (261 with 0.6.3).
    same(
        ['the site\'s' => $keys - 2 - 8 + 5 - 14 + LEGAL_PAGES_KEYS + KIT_PIECES_KEYS - FOOTER_KEYS, 'the library\'s' => libraryKeyCount()],
        [
            'the site\'s' => (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name NOT LIKE 'anotoki.%'")->fetchColumn(),
            'the library\'s' => (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'anotoki.%'")->fetchColumn(),
        ],
        'the site\'s keys less the two that moved, and the library\'s',
    );
    same([], Migrations::migrator($pdo)->applicable(), 'nothing left to apply');
});

test('006\'s gate refuses a database whose keys are not the ones it expects - and nothing of it, nor the library\'s files after it, is applied', function () use ($server) {
    $dir = migrationsFolder('migrations-to-005-gate', array_values(array_filter(allMigrations(), fn(string $file): bool => strcmp($file, '006') < 0)));
    $pdo = scratchDatabase($server, 'to005gate');
    same(null, Migrations::migrator($pdo, $dir, library: false)->apply()['error'], '001-005 applied');
    // A key no migration of this site wrote: the running count of the site's own keys does not hold.
    $pdo->exec("INSERT INTO translation_keys (name, description) VALUES ('zz.stray', 'Not a migration''s')");
    $pdo->exec("INSERT INTO translations (key_name, language_code, value) VALUES ('zz.stray', 'en', 'Stray')");

    $result = Migrations::migrator($pdo)->apply();
    same([adminFiles(), siteFile(ADOPTION_FILE)], [$result['applied'], $result['failed']], 'stopped at 006 - the library\'s admin tools\' files, which come first, applied');
    check(str_contains((string) $result['error'], 'There are 451 keys of the site\'s own, and 005 with this file leave 450'), 'the gate says why: ' . $result['error']);
    same(2, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name IN ('language.button', 'language.notLoaded')")->fetchColumn(), 'the keys did not move');
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'anotoki.%'")->fetchColumn(), 'and the library\'s files did not run');
    same(
        [
            ADOPTION_FILE,
            '007_people.sql',
            '008_drop_laravel_leftovers.sql',
            '009_sign_in_and_feedback_words.sql',
            '010_top_bar_words_through_the_library.sql',
            LEGAL_PAGES_FILE,
            KIT_PIECES_FILE,
            FOOTER_FILE,
            ...array_column(libraryFiles(), 'name'),
            ...array_column(peopleFiles(), 'name'),
        ],
        array_column(Migrations::migrator($pdo)->status()['pending'], 'name'),
        'they all still wait',
    );
});

/**
 * Version 0.4's files of this site's: the people's column, what Laravel left behind gone, the sign-in's and feedback's
 * words, the top bar's words, the legal pages' words, the kit's pieces', the footer's words under the library's keys.
 */
const VERSION_04_FILES = [
    '007_people.sql',
    '008_drop_laravel_leftovers.sql',
    '009_sign_in_and_feedback_words.sql',
    '010_top_bar_words_through_the_library.sql',
    LEGAL_PAGES_FILE,
    KIT_PIECES_FILE,
    FOOTER_FILE,
];

/** How many keys the library's set writes in all: every key of its words file (anotoki-lib's backend/resources/library-words.json). */
function libraryKeyCount(): int
{
    $words = json_decode((string) file_get_contents(dirname(__DIR__) . '/vendor/anotoki/lib/backend/resources/library-words.json'), true);

    return count((array) ($words['keys'] ?? []));
}

/** The Laravel version's login and queue tables, as 001 kept them where they had rows - 008 takes them. */
const LARAVEL_LEFTOVERS = ['laravel_failed_jobs', 'laravel_job_batches', 'laravel_jobs', 'laravel_password_reset_tokens', 'laravel_sessions', 'laravel_users'];

/** Their sequences, as 001 renamed them: 008 takes them too. */
const LARAVEL_LEFTOVER_SEQUENCES = ['laravel_failed_jobs_id_seq', 'laravel_jobs_id_seq', 'laravel_users_id_seq'];

/** Which of these are tables (or sequences) of the database now. */
function relationsThere(PDO $pdo, array $names): array
{
    return array_values(array_filter($names, fn(string $name): bool => (bool) $pdo->query('SELECT to_regclass(' . $pdo->quote($name) . ') IS NOT NULL')->fetchColumn()));
}

/** Every table of the database with its rows, but the six that go. */
function rowsOfEveryTable(PDO $pdo): array
{
    $rows = [];
    foreach ($pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!in_array($table, LARAVEL_LEFTOVERS, true)) {
            $rows[$table] = (int) $pdo->query('SELECT count(*) FROM "' . $table . '"')->fetchColumn();
        }
    }

    return $rows;
}

/** The five keys 009 moves under the library's: the site's old key => the library's. */
const MOVED_SIGN_IN_KEYS = [
    'signIn.notCompleted' => 'anotoki.signIn.notCompleted',
    'signIn.stateMismatch' => 'anotoki.signIn.expired',
    'signIn.storageUnavailable' => 'anotoki.signIn.storageUnavailable',
    'signIn.failed' => 'anotoki.signIn.failed',
    'signIn.signingIn' => 'anotoki.signIn.signingIn',
];

/**
 * A database as the owner's is before version 0.4 goes on (anotoki-lib 0.3): this site's 001-006 and the first
 * $libraryFiles of the library's translations set applied (2: as released with 0.3; 4: its 0.4 files ran before
 * this site's 009 - a copy updated in two steps), with people, a visitor and a game account in it - and the
 * Laravel version's tables 001 kept aside on a database adopted from it, each with a row and named as 001 left
 * it: its record (laravel_migrations); its login tables - laravel_users (the addresses and password hashes, and
 * its sequence), laravel_password_reset_tokens (addresses and reset tokens) and laravel_sessions (the network
 * addresses and browsers sessions came from; its indexes keep Laravel's names, as 001 renamed only the
 * constraints); and its queue's - laravel_jobs and laravel_failed_jobs (each with its sequence) and
 * laravel_job_batches. In $pdo, an empty database the test made already (one of a collation of its own), else
 * in one scratchDatabase() makes under $name.
 */
function installedDatabase(array $server, string $name, int $libraryFiles = 2, ?PDO $pdo = null): PDO
{
    $pdo ??= scratchDatabase($server, $name);
    $site = migrationsFolder("migrations-{$name}", array_values(array_filter(allMigrations(), fn(string $file): bool => strcmp($file, '007') < 0)));
    $library = T::$storage . DIRECTORY_SEPARATOR . "library-{$name}";
    @mkdir($library, 0777, true);
    foreach (array_slice(array_column(libraryFiles(), 'name'), 0, $libraryFiles) as $file) {
        copy(Schema::directory() . '/' . $file, $library . '/' . $file);
    }
    $result = (new Migrator($pdo, [Migrations::set($site), new MigrationSet(Schema::SET, $library, library: true)], ['lock' => Migrations::LOCK]))->apply();
    same(null, $result['error'], "{$name}: the installed version's files");

    $pdo->exec("INSERT INTO users (id, name, created_at, updated_at) VALUES
        (1, 'owner', '2026-09-20 10:15:30+00', '2026-09-20 10:15:30+00'), (42, 'mona', '2026-09-27 08:00:00+00', '2026-10-01 09:30:00+00')");
    $pdo->exec(
        "INSERT INTO users (id, name, visitor_key_hash, last_seen_at, created_at, updated_at) VALUES
        (-3, 'visitor', '" .
            str_repeat('a', 64) .
            "', '2026-10-10 20:00:00+00', '2026-10-10 19:00:00+00', '2026-10-10 19:00:00+00')",
    );
    $pdo->exec("INSERT INTO accounts (user_id, game, uid, nickname, created_at, updated_at) VALUES (42, 'genshin', '700000042', 'Mona main', '2026-09-27 08:05:00+00', '2026-09-27 08:05:00+00')");

    $pdo->exec('CREATE TABLE laravel_migrations (id SERIAL PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO laravel_migrations (migration, batch) VALUES ('0001_01_01_000000_create_users_table', 1)");
    $pdo->exec('CREATE TABLE laravel_users (id BIGSERIAL, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL,
        CONSTRAINT laravel_users_pkey PRIMARY KEY (id), CONSTRAINT laravel_users_email_unique UNIQUE (email))');
    $pdo->exec("INSERT INTO laravel_users (name, email, password) VALUES ('Test User', 'test@example.test', '\$2y\$12\$abcdefghijklmnopqrstuv')");
    $pdo->exec('CREATE TABLE laravel_password_reset_tokens (email VARCHAR(255) NOT NULL, token VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE,
        CONSTRAINT laravel_password_reset_tokens_pkey PRIMARY KEY (email))');
    $pdo->exec("INSERT INTO laravel_password_reset_tokens (email, token, created_at) VALUES ('test@example.test', '\$2y\$12\$resettokenhash', '2026-09-22 08:00:00')");
    // A session pointing at its user, as a copy of Laravel's schema may have it: 008 drops the two in one statement.
    $pdo->exec('CREATE TABLE laravel_sessions (id VARCHAR(255) NOT NULL, user_id BIGINT, ip_address VARCHAR(45), user_agent TEXT, payload TEXT NOT NULL,
        last_activity INTEGER NOT NULL, CONSTRAINT laravel_sessions_pkey PRIMARY KEY (id),
        CONSTRAINT laravel_sessions_user_id_foreign FOREIGN KEY (user_id) REFERENCES laravel_users (id))');
    $pdo->exec('CREATE INDEX sessions_user_id_index ON laravel_sessions (user_id)');
    $pdo->exec('CREATE INDEX sessions_last_activity_index ON laravel_sessions (last_activity)');
    $pdo->exec("INSERT INTO laravel_sessions (id, user_id, ip_address, user_agent, payload, last_activity)
        VALUES ('s3ss10n', 1, '203.0.113.7', 'Mozilla/5.0 (Windows NT 10.0)', 'YTowOnt9', 1758530000)");
    $pdo->exec("CREATE TABLE laravel_failed_jobs (id BIGSERIAL, uuid VARCHAR(255) NOT NULL, connection TEXT NOT NULL, queue TEXT NOT NULL, payload TEXT NOT NULL,
        exception TEXT NOT NULL, failed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT laravel_failed_jobs_pkey PRIMARY KEY (id))");
    $pdo->exec("INSERT INTO laravel_failed_jobs (uuid, connection, queue, payload, exception) VALUES ('u-1', 'database', 'default', '{\"email\":\"test@example.test\"}', 'RuntimeException')");
    $pdo->exec('CREATE TABLE laravel_jobs (id BIGSERIAL, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, attempts SMALLINT NOT NULL, reserved_at INTEGER,
        available_at INTEGER NOT NULL, created_at INTEGER NOT NULL, CONSTRAINT laravel_jobs_pkey PRIMARY KEY (id))');
    $pdo->exec('CREATE INDEX jobs_queue_index ON laravel_jobs (queue)');
    $pdo->exec("INSERT INTO laravel_jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', '{\"job\":\"sync\"}', 0, 1758530000, 1758530000)");
    $pdo->exec('CREATE TABLE laravel_job_batches (id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, total_jobs INTEGER NOT NULL, pending_jobs INTEGER NOT NULL,
        failed_jobs INTEGER NOT NULL, failed_job_ids TEXT NOT NULL, options TEXT, cancelled_at INTEGER, created_at INTEGER NOT NULL, finished_at INTEGER,
        CONSTRAINT laravel_job_batches_pkey PRIMARY KEY (id))');
    $pdo->exec("INSERT INTO laravel_job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, created_at) VALUES ('b-1', 'sync', 1, 1, 0, '[]', 1758530000)");

    return $pdo;
}

/** A key's strings in every language: its words, who saved them and when. */
function stringsOf(PDO $pdo, string $key): array
{
    $statement = $pdo->prepare('SELECT language_code, value, updated_by, updated_at FROM translations WHERE key_name = ? ORDER BY language_code');
    $statement->execute([$key]);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

test(
    'version 0.4 on a database as its owner left it: 007 adds the scrub\'s column and touches no row, 008 takes what Laravel left but its record, 009 moves the sign-in\'s words with every rewording, writer and time',
    function () use ($server) {
        $pdo = installedDatabase($server, 'to006');
        // The Translations grid used: an editor (IAM user 7) reworded an English string, a Slovak one was cleared on
        // purpose (the page reads the English), and German came with a string of its own.
        $pdo->exec(
            "UPDATE translations SET value = 'That sign-in was not started in this tab.', updated_by = 7, updated_at = '2026-10-09 08:15:00+00' WHERE key_name = 'signIn.stateMismatch' AND language_code = 'en'",
        );
        $pdo->exec("DELETE FROM translations WHERE key_name = 'signIn.failed' AND language_code = 'sk'");
        $pdo->exec("INSERT INTO languages (code, name, native_name, enabled, sort_order) VALUES ('de', 'German', 'Deutsch', FALSE, 3)");
        $pdo->exec("INSERT INTO translations (key_name, language_code, value, updated_by, updated_at) VALUES ('signIn.signingIn', 'de', 'Anmeldung…', 8, '2026-10-10 18:30:00+00')");
        $before = array_map(fn(string $key): array => stringsOf($pdo, $key), array_combine(array_keys(MOVED_SIGN_IN_KEYS), array_keys(MOVED_SIGN_IN_KEYS)));
        $people = fn(): array => $pdo->query('SELECT id, name, created_at, updated_at, visitor_key_hash, last_seen_at FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $peopleBefore = $people();
        $keys = (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name NOT LIKE 'anotoki.%'")->fetchColumn();

        // The upload: one Apply - the library's admin tools' files, this site's three, the library's translations 003-004, its people's.
        $result = Migrations::migrator($pdo)->apply();
        same(null, $result['error'], 'no error');
        same([...adminFiles(), ...array_map('siteFile', VERSION_04_FILES), ...array_slice(libraryFiles(), 2), ...peopleFiles()], $result['applied'], 'in the README\'s order');

        // 007: a column, NULL everywhere; every row as it was.
        same($peopleBefore, $people(), 'the people and the visitor, as they were');
        same(0, (int) $pdo->query('SELECT count(*) FROM users WHERE anonymized_at IS NOT NULL')->fetchColumn(), 'nobody anonymized');
        same([['Mona main', 42]], array_map(fn($row) => [$row['nickname'], (int) $row['user_id']], $pdo->query('SELECT nickname, user_id FROM accounts')->fetchAll(PDO::FETCH_ASSOC)), 'their account');

        // 008: Laravel's login and queue tables and their sequences gone; its record stays, with its row.
        same([], relationsThere($pdo, [...LARAVEL_LEFTOVERS, ...LARAVEL_LEFTOVER_SEQUENCES]), 'the six tables and their sequences gone');
        same(1, (int) $pdo->query('SELECT count(*) FROM laravel_migrations')->fetchColumn(), 'laravel_migrations kept, with its row');
        same(
            0,
            (int) $pdo->query("SELECT count(*) FROM pg_indexes WHERE schemaname = 'public' AND (indexname LIKE 'sessions\\_%' OR indexname LIKE 'jobs\\_%')")->fetchColumn(),
            'the indexes that kept Laravel\'s names went with them',
        );

        // 009: every string under the library's key as it was - words, writer, time; the cleared Slovak stays cleared.
        foreach (MOVED_SIGN_IN_KEYS as $old => $new) {
            same($before[$old], stringsOf($pdo, $new), "{$old} -> {$new}");
        }
        same(['en'], array_column(stringsOf($pdo, 'anotoki.signIn.failed'), 'language_code'), 'the cleared Slovak: the library did not write its own there');
        check(
            str_starts_with((string) $pdo->query("SELECT description FROM translation_keys WHERE name = 'anotoki.signIn.expired'")->fetchColumn(), 'The anotoki library\'s sign-in page'),
            'the library\'s description',
        );
        same(['Signing in did not work', 'Prihlásenie sa nepodarilo'], array_column(stringsOf($pdo, 'anotoki.signIn.failedTitle'), 'value'), 'the library\'s own words on its other keys');
        same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'signIn.%'")->fetchColumn(), 'the old sign-in page\'s keys are gone');
        same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name LIKE 'signIn.%'")->fetchColumn(), 'with their strings');
        // The feedback button's words 009 added - under the library's key since 013, the footer the family's.
        same([['en', 'Feedback'], ['sk', 'Spätná väzba']], array_map(fn($row) => [$row['language_code'], $row['value']], stringsOf($pdo, 'anotoki.footer.feedback')), 'the feedback button\'s words');
        same(
            $keys - 8 + 5 - 14 + LEGAL_PAGES_KEYS + KIT_PIECES_KEYS - FOOTER_KEYS,
            (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name NOT LIKE 'anotoki.%'")->fetchColumn(),
            'the site\'s keys: eight gone and five new (009), fourteen gone (010), the legal pages\' words (011), the stepper\'s and tabs\' (012), the footer\'s three gone (013)',
        );
        same([], Migrations::migrator($pdo)->applicable(), 'nothing left to apply');
    },
);

test('009 where the library\'s sign-in words went in first: the site\'s words take their place - never over an owner\'s rewording of the library\'s key', function () use ($server) {
    $pdo = installedDatabase($server, 'to006lib', libraryFiles: 4);
    // The library's 003 found its keys without strings and wrote its own; then the owner reworded one of them.
    same('The sign-in failed ({code}).', $pdo->query("SELECT value FROM translations WHERE key_name = 'anotoki.signIn.failed' AND language_code = 'en'")->fetchColumn(), 'the library\'s words');
    $pdo->exec("UPDATE translations SET value = 'Signing in failed: {code}', updated_by = 9, updated_at = '2026-10-10 12:00:00+00' WHERE key_name = 'anotoki.signIn.failed' AND language_code = 'en'");
    $site = stringsOf($pdo, 'signIn.stateMismatch');

    $result = Migrations::migrator($pdo)->apply();
    same(null, $result['error'], 'no error');
    same([...adminFiles(), ...array_map('siteFile', VERSION_04_FILES), ...array_slice(libraryFiles(), 4), ...peopleFiles()], $result['applied'], 'the rest - and the library\'s later words');

    same($site, stringsOf($pdo, 'anotoki.signIn.expired'), 'the site\'s words, by whom and when, in place of the library\'s');
    same(
        [['en', 'Signing in failed: {code}', 9], ['sk', 'Prihlásenie sa nedokončilo: {code}', null]],
        array_map(fn($row) => [$row['language_code'], $row['value'], $row['updated_by'] === null ? null : (int) $row['updated_by']], stringsOf($pdo, 'anotoki.signIn.failed')),
        'the owner\'s English rewording kept; the Slovak the site\'s',
    );
});

test('009\'s gate refuses a database whose keys are not the ones it expects - nothing of it, nor the library\'s files after it, is applied', function () use ($server) {
    $pdo = installedDatabase($server, 'to006gate');
    $pdo->exec("INSERT INTO translation_keys (name, description) VALUES ('zz.stray', 'Not a migration''s')");

    $result = Migrations::migrator($pdo)->apply();
    same(
        [[...adminFiles(), siteFile('007_people.sql'), siteFile('008_drop_laravel_leftovers.sql')], siteFile('009_sign_in_and_feedback_words.sql')],
        [$result['applied'], $result['failed']],
        'stopped at 009',
    );
    check(str_contains((string) $result['error'], 'There are 448 keys of the site\'s own, and 006 with this file leave 447'), 'the gate says why: ' . $result['error']);
    same(8, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'signIn.%'")->fetchColumn(), 'the old keys stay');
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'anotoki.signIn.%' OR name LIKE 'feedback.%'")->fetchColumn(), 'nothing of 009, nor the library\'s 003');
    same(
        [
            '009_sign_in_and_feedback_words.sql',
            '010_top_bar_words_through_the_library.sql',
            LEGAL_PAGES_FILE,
            KIT_PIECES_FILE,
            FOOTER_FILE,
            ...array_column(array_slice(libraryFiles(), 2), 'name'),
            ...array_column(peopleFiles(), 'name'),
        ],
        array_column(Migrations::migrator($pdo)->status()['pending'], 'name'),
        'they still wait',
    );
});

/** The twelve keys 010 moves under the library's - the site's own then gone - by the library's key each goes under. */
const MOVED_BAR_KEYS = [
    'topbar.phoneMenu' => 'anotoki.topbar.phoneMenu',
    'topbar.menuButton' => 'anotoki.topbar.menuButton',
    'topbar.menu' => 'anotoki.topbar.menu',
    'topbar.roleAdmin' => 'anotoki.topbar.roleAdmin',
    'topbar.roleEditor' => 'anotoki.topbar.roleEditor',
    'topbar.account' => 'anotoki.topbar.account',
    'topbar.admin' => 'anotoki.topbar.admin',
    'common.signOut' => 'anotoki.topbar.signOut',
    'theme.label' => 'anotoki.theme.label',
    'theme.light' => 'anotoki.theme.light',
    'theme.dark' => 'anotoki.theme.dark',
    'theme.system' => 'anotoki.theme.auto',
];

/** A key's words, by language. */
function wordsOf(PDO $pdo, string $key): array
{
    return array_column(stringsOf($pdo, $key), 'value', 'language_code');
}

test(
    '010 on a database as its owner left it: the top bar\'s words go under the library\'s keys - the site\'s and an editor\'s in place of the library\'s own, never over an owner\'s rewording of them - and the stepper\'s two words go',
    function () use ($server) {
        // Installed with 0.3: the library's 002 wrote its own words for its keys, the bar's among them.
        $pdo = installedDatabase($server, 'to009bar');
        same(['en' => 'Automatic', 'sk' => 'Automaticky'], wordsOf($pdo, 'anotoki.theme.auto'), 'the library\'s words, before');
        // An editor (IAM user 7) reworded the Slovak light theme; German came with the theme switch's name; the owner
        // (IAM user 9) reworded one of the library's own words of the bar.
        $pdo->exec("UPDATE translations SET value = 'Svetlá', updated_by = 7, updated_at = '2026-10-09 08:15:00+00' WHERE key_name = 'theme.light' AND language_code = 'sk'");
        $pdo->exec("INSERT INTO languages (code, name, native_name, enabled, sort_order) VALUES ('de', 'German', 'Deutsch', FALSE, 3)");
        $pdo->exec("INSERT INTO translations (key_name, language_code, value, updated_by, updated_at) VALUES ('theme.label', 'de', 'Aussehen', 8, '2026-10-10 18:30:00+00')");
        $pdo->exec("UPDATE translations SET value = 'My menu', updated_by = 9, updated_at = '2026-10-10 12:00:00+00' WHERE key_name = 'anotoki.topbar.menu' AND language_code = 'en'");
        $roleAdmin = stringsOf($pdo, 'topbar.roleAdmin');
        $lightSk = stringsOf($pdo, 'theme.light')[1];
        $signIn = stringsOf($pdo, 'common.signIn');

        $result = Migrations::migrator($pdo)->apply();
        same(null, $result['error'], 'no error');
        same([...adminFiles(), ...array_map('siteFile', VERSION_04_FILES), ...array_slice(libraryFiles(), 2), ...peopleFiles()], $result['applied'], 'version 0.4 in one Apply');

        // The site's words where the library's stood as it wrote them - the Slovak role, "System" - with their writer and time.
        same($roleAdmin[1], stringsOf($pdo, 'anotoki.topbar.roleAdmin')[1], 'topbar.roleAdmin -> anotoki.topbar.roleAdmin: the Slovak "Správca" with its time, where the library said "Administrátor"');
        same(['en' => 'Administrator', 'sk' => 'Správca'], wordsOf($pdo, 'anotoki.topbar.roleAdmin'), 'the English the same as the library\'s');
        same(['en' => 'System', 'sk' => 'Systémový'], wordsOf($pdo, 'anotoki.theme.auto'), 'theme.system -> anotoki.theme.auto');
        // The editor's Slovak rewording moved, by whom and when; German moved too.
        same($lightSk, stringsOf($pdo, 'anotoki.theme.light')[1], 'the editor\'s Slovak rewording, by whom and when');
        same(['de' => 'Aussehen', 'en' => 'Theme', 'sk' => 'Vzhľad'], wordsOf($pdo, 'anotoki.theme.label'), 'German moved; the same words in English and Slovak');
        // The owner's rewording of the library's word stays; its Slovak was the site's word already.
        same(
            [['en', 'My menu', 9], ['sk', 'Tvoja ponuka', null]],
            array_map(fn($row) => [$row['language_code'], $row['value'], $row['updated_by'] === null ? null : (int) $row['updated_by']], stringsOf($pdo, 'anotoki.topbar.menu')),
            'the owner\'s rewording of the library\'s key kept',
        );
        // The visitors' Sign in stays the site's, and the bar's says the same.
        same($signIn, stringsOf($pdo, 'common.signIn'), 'common.signIn as it was');
        same(array_column($signIn, 'value', 'language_code'), wordsOf($pdo, 'anotoki.topbar.signIn'), 'the bar\'s Sign in says the same');

        // The twelve keys and the stepper's two are gone, with their strings; the step done keeps its word.
        $gone = [...array_keys(MOVED_BAR_KEYS), 'stepper.optional', 'stepper.editable'];
        $in = implode(', ', array_map(fn(string $key): string => $pdo->quote($key), $gone));
        same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name IN ({$in})")->fetchColumn(), 'the fourteen keys are gone');
        same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name IN ({$in})")->fetchColumn(), 'with their strings');
        // The step done's own word, which 010 kept, went with 012: the family's stepper says it itself.
        same([], wordsOf($pdo, 'stepper.completed'), 'the step done\'s word gone (012)');
        // The library's descriptions on its keys (its 002 ran before; 010 left them as they were).
        check(
            str_starts_with((string) $pdo->query("SELECT description FROM translation_keys WHERE name = 'anotoki.theme.auto'")->fetchColumn(), 'The anotoki library\'s theme switch'),
            'the library\'s description',
        );
        same([], Migrations::migrator($pdo)->applicable(), 'nothing left to apply');
    },
);

test('010\'s gate refuses a database whose keys are not the ones it expects - nothing of it is applied', function () use ($server) {
    $pdo = scratchDatabase($server, 'to009gate');
    $dir = migrationsFolder('migrations-to-009-gate', array_values(array_filter(allMigrations(), fn(string $file): bool => strcmp($file, '010') < 0)));
    same(null, Migrations::migrator($pdo, $dir)->apply()['error'], 'everything up to 009, and the library\'s sets');
    $pdo->exec("INSERT INTO translation_keys (name, description) VALUES ('zz.stray', 'Not a migration''s')");

    $result = Migrations::migrator($pdo)->apply();
    same([[], siteFile('010_top_bar_words_through_the_library.sql')], [$result['applied'], $result['failed']], 'stopped at 010');
    check(str_contains((string) $result['error'], 'There are 434 keys of the site\'s own, and 009 with this file leave 433'), 'the gate says why: ' . $result['error']);
    same(
        14,
        (int) $pdo
            ->query(
                "SELECT count(*) FROM translation_keys WHERE name IN ('topbar.phoneMenu', 'topbar.menuButton', 'topbar.menu', 'topbar.roleAdmin', 'topbar.roleEditor', 'topbar.account', 'topbar.admin', 'common.signOut', 'theme.label', 'theme.light', 'theme.dark', 'theme.system', 'stepper.optional', 'stepper.editable')",
            )
            ->fetchColumn(),
        'the keys stay',
    );
    same(
        ['010_top_bar_words_through_the_library.sql', LEGAL_PAGES_FILE, KIT_PIECES_FILE, FOOTER_FILE],
        array_column(Migrations::migrator($pdo)->status()['pending'], 'name'),
        'it still waits, and 011 to 013 after it',
    );
});

/** The three keys 013 moves under the library's - the site's own then gone - by the library's key each goes under. */
const MOVED_FOOTER_KEYS = [
    'footer.version' => 'anotoki.footer.version',
    'footer.feedback' => 'anotoki.footer.feedback',
    'footer.fanMade' => 'anotoki.footer.fanHoyoverse',
];

/**
 * A database as the owner's is before 013 (version 0.4.0 on anotoki-lib 0.6.2): every file of the site's up to 012,
 * the library's admin and people sets, and its translations set up to its 011 - or, with $library012, its 012 too
 * (the library's footer words in first: a copy updated in two steps).
 */
function upTo012(array $server, string $name, bool $library012 = false): PDO
{
    $pdo = scratchDatabase($server, $name);
    $site = migrationsFolder("migrations-{$name}", array_values(array_filter(allMigrations(), fn(string $file): bool => strcmp($file, '013') < 0)));
    $library = T::$storage . DIRECTORY_SEPARATOR . "library-{$name}";
    @mkdir($library, 0777, true);
    foreach (array_column(libraryFiles(), 'name') as $file) {
        if ($library012 || strcmp($file, '012') < 0) {
            copy(Schema::directory() . '/' . $file, $library . '/' . $file);
        }
    }
    $sets = [AdminSchema::migrationSet(), Migrations::set($site), new MigrationSet(Schema::SET, $library, library: true), PeopleSchema::migrationSet()];
    $result = (new Migrator($pdo, $sets, ['lock' => Migrations::LOCK]))->apply();
    same(null, $result['error'], "{$name}: the installed version's files");

    return $pdo;
}

test(
    '013 on a database as its owner left it: the footer\'s words go under the library\'s keys - an editor\'s rewording, German and a cleared Slovak as they were -, What\'s new\'s copied and the dialog\'s title kept; the three keys go',
    function () use ($server) {
        $pdo = upTo012($server, 'to012');
        // An editor (IAM user 7) reworded the Slovak Feedback; German came with the version's words; the Slovak fan clause
        // was cleared on purpose (the footer reads the English).
        $pdo->exec("UPDATE translations SET value = 'Napíš nám', updated_by = 7, updated_at = '2026-10-15 08:15:00+00' WHERE key_name = 'footer.feedback' AND language_code = 'sk'");
        $pdo->exec("INSERT INTO languages (code, name, native_name, enabled, sort_order) VALUES ('de', 'German', 'Deutsch', FALSE, 3)");
        $pdo->exec("INSERT INTO translations (key_name, language_code, value, updated_by, updated_at) VALUES ('footer.version', 'de', 'Fassung {version}', 8, '2026-10-16 18:30:00+00')");
        $pdo->exec("DELETE FROM translations WHERE key_name = 'footer.fanMade' AND language_code = 'sk'");
        $before = [];
        foreach ([...array_keys(MOVED_FOOTER_KEYS), 'whatsNew.title'] as $key) {
            $before[$key] = stringsOf($pdo, $key);
        }
        $keys = (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name NOT LIKE 'anotoki.%'")->fetchColumn();

        $result = Migrations::migrator($pdo)->apply();
        same(null, $result['error'], 'no error');
        same([siteFile(FOOTER_FILE), ...array_slice(libraryFiles(), 11)], $result['applied'], '013, then the library\'s 012 - one Apply');

        // Every string under the library's key as it was - words, writer, time; the cleared Slovak stays cleared.
        foreach (MOVED_FOOTER_KEYS as $old => $new) {
            same($before[$old], stringsOf($pdo, $new), "{$old} -> {$new}");
        }
        same(['en'], array_column(stringsOf($pdo, 'anotoki.footer.fanHoyoverse'), 'language_code'), 'the cleared Slovak: the library did not write its own there');
        // What's new: the footer's says what the dialog's title says, and the title stays the site's.
        same($before['whatsNew.title'], stringsOf($pdo, 'whatsNew.title'), 'the dialog\'s title as it was');
        same(array_column($before['whatsNew.title'], 'value', 'language_code'), wordsOf($pdo, 'anotoki.footer.whatsNew'), 'the footer\'s What\'s new says the same');

        // The three keys gone with their strings; the library's descriptions on its keys (its 012 ran after 013).
        $in = implode(', ', array_map(fn(string $key): string => $pdo->quote($key), array_keys(MOVED_FOOTER_KEYS)));
        same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name IN ({$in})")->fetchColumn(), 'the three keys are gone');
        same(0, (int) $pdo->query("SELECT count(*) FROM translations WHERE key_name IN ({$in})")->fetchColumn(), 'with their strings');
        check(
            str_starts_with((string) $pdo->query("SELECT description FROM translation_keys WHERE name = 'anotoki.footer.version'")->fetchColumn(), 'The anotoki library\'s footer'),
            'the library\'s description',
        );
        check(
            str_contains((string) $pdo->query("SELECT description FROM translation_keys WHERE name = 'whatsNew.title'")->fetchColumn(), 'anotoki.footer.whatsNew'),
            'whatsNew.title\'s description names the footer\'s word',
        );
        same($keys - FOOTER_KEYS, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name NOT LIKE 'anotoki.%'")->fetchColumn(), 'the site\'s keys less the three');
        same([], Migrations::migrator($pdo)->applicable(), 'nothing left to apply');
    },
);

test('013 where the library\'s footer words went in first: the site\'s take their place - never over an owner\'s rewording of the library\'s key', function () use ($server) {
    $pdo = upTo012($server, 'to012lib', library012: true);
    same(['en' => 'Feedback', 'sk' => 'Spätná väzba'], wordsOf($pdo, 'anotoki.footer.feedback'), 'the library\'s words, before');
    // An editor (IAM user 7) had reworded the site's English Feedback; the owner (IAM user 9) one of the library's own
    // footer words.
    $pdo->exec("UPDATE translations SET value = 'Tell us', updated_by = 7, updated_at = '2026-10-15 08:15:00+00' WHERE key_name = 'footer.feedback' AND language_code = 'en'");
    $pdo->exec("UPDATE translations SET value = 'News', updated_by = 9, updated_at = '2026-10-18 12:00:00+00' WHERE key_name = 'anotoki.footer.whatsNew' AND language_code = 'en'");
    $feedback = stringsOf($pdo, 'footer.feedback');

    $result = Migrations::migrator($pdo)->apply();
    same(null, $result['error'], 'no error');
    same([siteFile(FOOTER_FILE)], $result['applied'], '013 alone');

    same($feedback[0], stringsOf($pdo, 'anotoki.footer.feedback')[0], 'the editor\'s English, by whom and when, in place of the library\'s');
    same('Spätná väzba', wordsOf($pdo, 'anotoki.footer.feedback')['sk'], 'the Slovak, the same words');
    same(
        [['en', 'News', 9], ['sk', 'Čo je nové', null]],
        array_map(fn($row) => [$row['language_code'], $row['value'], $row['updated_by'] === null ? null : (int) $row['updated_by']], stringsOf($pdo, 'anotoki.footer.whatsNew')),
        'the owner\'s rewording of the library\'s key kept',
    );
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name IN ('footer.version', 'footer.feedback', 'footer.fanMade')")->fetchColumn(), 'the three keys are gone');
});

test('013\'s gate refuses a database whose keys are not the ones it expects - nothing of it, nor the library\'s 012 after it, is applied', function () use ($server) {
    $pdo = upTo012($server, 'to012gate');
    $pdo->exec("INSERT INTO translation_keys (name, description) VALUES ('zz.stray', 'Not a migration''s')");

    $result = Migrations::migrator($pdo)->apply();
    same([[], siteFile(FOOTER_FILE)], [$result['applied'], $result['failed']], 'stopped at 013');
    check(str_contains((string) $result['error'], 'There are 484 keys of the site\'s own, and 012 with this file leave 483'), 'the gate says why: ' . $result['error']);
    same(3, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name IN ('footer.version', 'footer.feedback', 'footer.fanMade')")->fetchColumn(), 'the keys stay');
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name LIKE 'anotoki.footer.%'")->fetchColumn(), 'nothing of 013, nor the library\'s 012');
    same([FOOTER_FILE, '012_footer_words.sql'], array_column(Migrations::migrator($pdo)->status()['pending'], 'name'), 'both still wait');
});

/**
 * This site's files up to 008 (or, in $own, files of a test's own in their place) and the library's translations
 * files of the installed version, on a database installedDatabase() made under $name: what an Apply of 007 and
 * 008 alone sees.
 */
function upTo008(PDO $pdo, string $name, array $own = []): Migrator
{
    $site = migrationsFolder("migrations-{$name}-to-008", array_values(array_filter(allMigrations(), fn(string $file): bool => strcmp($file, '009') < 0)), $own);

    return new Migrator($pdo, [Migrations::set($site), new MigrationSet(Schema::SET, T::$storage . DIRECTORY_SEPARATOR . "library-{$name}", library: true)], ['lock' => Migrations::LOCK]);
}

test('008 on a database as its owner left it: Laravel\'s login and queue tables go with their rows and sequences - every other table stays, with as many rows', function () use ($server) {
    $pdo = installedDatabase($server, 'to007');
    same(
        [LARAVEL_LEFTOVERS, LARAVEL_LEFTOVER_SEQUENCES],
        [relationsThere($pdo, LARAVEL_LEFTOVERS), relationsThere($pdo, LARAVEL_LEFTOVER_SEQUENCES)],
        'the six are there, as 001 kept them, each with a row - and their sequences',
    );
    $before = rowsOfEveryTable($pdo);

    $result = upTo008($pdo, 'to007')->apply();
    same([null, [siteFile('007_people.sql'), siteFile('008_drop_laravel_leftovers.sql')]], [$result['error'], $result['applied']], '007 and 008 applied');

    same([], relationsThere($pdo, [...LARAVEL_LEFTOVERS, ...LARAVEL_LEFTOVER_SEQUENCES]), 'the six gone, and their sequences');
    $after = rowsOfEveryTable($pdo);
    same($before['migrations'] + 2, $after['migrations'], 'the bookkeeping table: 007 and 008 recorded');
    unset($before['migrations'], $after['migrations']);
    same($before, $after, 'every other table still here, with as many rows - laravel_migrations too');
});

test('008 never takes anything along: a view on Laravel\'s sessions stops it - and nothing of it is applied', function () use ($server) {
    $pdo = installedDatabase($server, 'to007view');
    $pdo->exec('CREATE VIEW old_sessions AS SELECT id, ip_address FROM laravel_sessions');

    $result = upTo008($pdo, 'to007view')->apply();
    same([[siteFile('007_people.sql')], siteFile('008_drop_laravel_leftovers.sql')], [$result['applied'], $result['failed']], 'stopped at 008');
    // 2BP01: dependent objects still exist - DROP TABLE without CASCADE.
    check(str_contains((string) $result['error'], '2BP01'), 'the database says why: ' . $result['error']);
    same(LARAVEL_LEFTOVERS, relationsThere($pdo, LARAVEL_LEFTOVERS), 'all six still here: one statement, refused as a whole');
    same(['008_drop_laravel_leftovers.sql'], array_column(upTo008($pdo, 'to007view')->status()['pending'], 'name'), 'it still waits');
});

test('008 never takes a site table\'s reference along: a table of the site\'s pointing at laravel_users stops it - and nothing of it is applied', function () use ($server) {
    $pdo = installedDatabase($server, 'to007ref');
    // A column of the site's own, pointing at Laravel's login table (no file of this site makes one).
    $pdo->exec('ALTER TABLE accounts ADD COLUMN laravel_user_id BIGINT REFERENCES laravel_users (id)');

    $result = upTo008($pdo, 'to007ref')->apply();
    same([[siteFile('007_people.sql')], siteFile('008_drop_laravel_leftovers.sql')], [$result['applied'], $result['failed']], 'stopped at 008');
    check(str_contains((string) $result['error'], '2BP01'), 'the database says why: ' . $result['error']);
    same(
        [LARAVEL_LEFTOVERS, LARAVEL_LEFTOVER_SEQUENCES],
        [relationsThere($pdo, LARAVEL_LEFTOVERS), relationsThere($pdo, LARAVEL_LEFTOVER_SEQUENCES)],
        'all six still here, with their sequences - the sessions\' own reference to laravel_users was no obstacle, the site\'s is',
    );
    same(1, (int) $pdo->query("SELECT count(*) FROM information_schema.columns WHERE table_name = 'accounts' AND column_name = 'laravel_user_id'")->fetchColumn(), 'the site\'s column, untouched');
});

test('008\'s gate refuses a file that took more than the six - and nothing of it is applied', function () use ($server) {
    $pdo = installedDatabase($server, 'to007gate');
    // The same file with one statement more before its gate: somebody's game account gone as well.
    $sql = (string) file_get_contents(Migrations::directory() . '/008_drop_laravel_leftovers.sql');
    $sequences = 'DROP SEQUENCE IF EXISTS laravel_users_id_seq, laravel_jobs_id_seq, laravel_failed_jobs_id_seq;';
    $more = str_replace($sequences, "{$sequences}\nDELETE FROM accounts;", $sql);
    check($more !== $sql, 'the statement more is in');

    $result = upTo008($pdo, 'to007gate', ['008_drop_laravel_leftovers.sql' => $more])->apply();
    same([[siteFile('007_people.sql')], siteFile('008_drop_laravel_leftovers.sql')], [$result['applied'], $result['failed']], 'stopped at 008');
    check(str_contains((string) $result['error'], 'Tables whose rows changed: accounts (1 before, 0 now)'), 'the gate says why: ' . $result['error']);
    same(
        [LARAVEL_LEFTOVERS, 1],
        [relationsThere($pdo, LARAVEL_LEFTOVERS), (int) $pdo->query('SELECT count(*) FROM accounts')->fetchColumn()],
        'nothing of the file stays: the six tables, and the account',
    );
});

/**
 * CREATE DATABASE's options for a database that sorts text as the live one does. The live database (PostgreSQL 14,
 * collation en_US.utf8) sorts words without their punctuation, glibc's way: "accounts" before "account_weapons",
 * "builds" before "build_sources" - where byte by byte, as pg_class.relname (of the type name) always sorts, the
 * underscore comes first. An ICU collation that shifts punctuation away sorts as it does (PostgreSQL 15 and newer,
 * built with ICU). Reproduced here, never asked of the live database.
 */
const PUNCTUATION_SKIPPING = "TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER icu ICU_LOCALE 'en-US-u-ka-shifted' LOCALE 'C'";

/** The site's tables whose names sort one way with the underscore and the other way without it. */
const SORTED_APART = ['account_weapons', 'accounts', 'build_sources', 'builds'];

test(
    '008 applies on a database whose collation skips punctuation, as the live one\'s does - its gate lists the tables in one order on both sides, byte by byte (the first try on the live database refused it: two orders of the same tables)',
    function () use ($server) {
        // Where the server cannot make such a database, the test cannot be run: said, and passed over.
        $version = (int) Db::pdo($server, 'postgres', false)->query('SHOW server_version_num')->fetchColumn();
        if ($version < 150000) {
            echo '      (skipped: PostgreSQL ' . intdiv($version, 10000) . " makes no database with an ICU collation - 15 and newer do)\n";

            return;
        }
        try {
            $pdo = scratchDatabase($server, 'to007icu', PUNCTUATION_SKIPPING);
        } catch (PDOException $e) {
            // 0A000, feature not supported: "ICU is not supported in this build".
            if ($e->getCode() !== '0A000') {
                throw $e;
            }
            echo "      (skipped: this PostgreSQL is built without ICU - no database with an ICU collation)\n";

            return;
        }

        // The two orders the live database had: its collation's, and pg_class.relname's - or this test proves nothing.
        $sorted = fn(string $collate): array => json_decode(
            (string) $pdo->query("SELECT array_to_json(array_agg(n ORDER BY n{$collate})) FROM unnest(ARRAY['" . implode("', '", SORTED_APART) . "']) AS n")->fetchColumn(),
            true,
        );
        same(['accounts', 'account_weapons', 'builds', 'build_sources'], $sorted(''), 'the database\'s collation sorts the names without their underscore');
        same(SORTED_APART, $sorted(' COLLATE "C"'), 'byte by byte, the underscore first');

        // Installed with 0.3 on it: the site's tables - those four among them -, people, and the Laravel leftovers 008 drops.
        installedDatabase($server, 'to007icu', pdo: $pdo);
        same(SORTED_APART, relationsThere($pdo, SORTED_APART), 'the four tables there');
        same(
            [LARAVEL_LEFTOVERS, LARAVEL_LEFTOVER_SEQUENCES],
            [relationsThere($pdo, LARAVEL_LEFTOVERS), relationsThere($pdo, LARAVEL_LEFTOVER_SEQUENCES)],
            'and the six 008 drops, each with a row, with their sequences',
        );
        $before = rowsOfEveryTable($pdo);

        $result = upTo008($pdo, 'to007icu')->apply();
        same([null, [siteFile('007_people.sql'), siteFile('008_drop_laravel_leftovers.sql')]], [$result['error'], $result['applied']], '007 and 008 applied');
        same([], relationsThere($pdo, [...LARAVEL_LEFTOVERS, ...LARAVEL_LEFTOVER_SEQUENCES]), 'the six gone, and their sequences');
        $after = rowsOfEveryTable($pdo);
        same($before['migrations'] + 2, $after['migrations'], 'the bookkeeping table: 007 and 008 recorded');
        unset($before['migrations'], $after['migrations']);
        same($before, $after, 'every other table still here, with as many rows');

        // And the rest of version 0.4 after it, the library's sets with it, on the same collation.
        same(null, Migrations::migrator($pdo)->apply()['error'], 'the rest of the files applied too');
        same([], Migrations::migrator($pdo)->applicable(), 'nothing left to apply');
    },
);

foreach ([6 => 'the version first committed', 7 => 'with sign-in through the IAM', 8 => 'with resumable syncs'] as $level => $what) {
    test("a Laravel database ({$what}) is brought forward with all its data", function () use ($server, $level) {
        $pdo = laravelDatabase($server, $level);
        $migrator = Migrations::migrator($pdo, null, ['local_user_id' => 1]);
        $before = [];
        foreach (
            ['game_characters', 'game_weapons', 'game_sets', 'accounts', 'import_runs', 'account_characters', 'account_weapons', 'account_items', 'build_sources', 'builds', 'sync_runs']
            as $table
        ) {
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
        same(0, (int) $pdo->query('SELECT count(*) FROM cache')->fetchColumn(), 'the cache is emptied');
        // Laravel's login and queue tables with rows - an old session, a reset asked for, jobs - kept aside by 001,
        // and gone with 008 (the owner's decisions: no email address, no session's network address, no old job's
        // payload here), with their sequences.
        same(
            [],
            relationsThere($pdo, [...LARAVEL_LEFTOVERS, ...LARAVEL_LEFTOVER_SEQUENCES, 'sessions', 'password_reset_tokens', 'jobs', 'job_batches', 'failed_jobs']),
            'Laravel\'s login and queue tables, gone',
        );

        // The times are the same instants, now with their zone.
        same('2026-09-21 12:00:00+00', $pdo->query('SELECT last_import_at FROM accounts ORDER BY id LIMIT 1')->fetchColumn(), 'a time');
        same('2021-03-02 00:00:00+00', $pdo->query("SELECT released_at FROM game_characters WHERE key = 'hutao'")->fetchColumn(), 'a release date');

        // Owners.
        same(0, (int) $pdo->query('SELECT count(*) FROM accounts WHERE user_id IS NULL')->fetchColumn(), 'every account has an owner');
        if ($level === 6) {
            same([1], array_map('intval', $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN)), 'the owner was made');
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
