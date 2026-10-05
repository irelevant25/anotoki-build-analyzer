<?php

/**
 * The migration engine, and the adoption of a database made by the first (Laravel) version of the app.
 *
 * The Laravel schemas are fixtures (tests/fixtures/laravel/schema-{6,7,8}.sql): exactly what that version's
 * `artisan migrate` made on PostgreSQL after its first 6 migrations (the version first committed), 7 (sign-in
 * through the IAM, accounts with owners) and 8 (resumable syncs). Each is loaded into a throwaway database,
 * filled the way that version wrote its rows, brought forward - and then compared with a new database, table
 * by table, and read back row by row.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Auth\Role;
use App\Db\Db;
use App\Db\Migrator;
use App\Support\Log;
use Tests\Support\Client;

bootTests();

$server = require __DIR__.'/../config/database.local.php';

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
    foreach (Migrator::splitStatements((string) file_get_contents(__DIR__."/fixtures/laravel/schema-{$level}.sql")) as $statement) {
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
    $files = array_map('basename', glob(Migrator::directory().'/*.sql') ?: []);
    sort($files);

    return $files;
}

test('a new database is built by the migrations', function () {
    $migrator = new Migrator(db()->raw(), Migrator::directory());
    $files = allMigrations();
    $status = $migrator->status();
    same([[], $files], [$status['pending'], array_column($status['applied'], 'name')], 'status: nothing pending, every file applied in order');
    same(false, $migrator->isLaravelDatabase(), 'not a Laravel database');
});

test('SQL is split on semicolons outside strings, comments and dollar-quoted blocks', function () {
    $sql = "-- a comment; with a semicolon\nCREATE TABLE a (x TEXT DEFAULT 'a;b');\n/* block; comment */\n"
        ."DO \$\$ BEGIN PERFORM 1; PERFORM 2; END \$\$;\nINSERT INTO a VALUES ('it''s; fine');";
    same([
        "CREATE TABLE a (x TEXT DEFAULT 'a;b')",
        'DO $$ BEGIN PERFORM 1; PERFORM 2; END $$',
        "INSERT INTO a VALUES ('it''s; fine')",
    ], Migrator::splitStatements($sql), 'statements');
});

test('a failing migration applies nothing of itself, and stops there', function () use ($server) {
    $pdo = scratchDatabase($server, 'failing');
    $dir = T::$storage.DIRECTORY_SEPARATOR.'failing-migrations';
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/001_good.sql', 'CREATE TABLE good (id INT);');
    file_put_contents($dir.'/002_bad.sql', 'CREATE TABLE half (id INT); SELECT * FROM missing_table;');
    file_put_contents($dir.'/003_never.sql', 'CREATE TABLE never (id INT);');

    $result = (new Migrator($pdo, $dir))->applyPending();

    same(['001_good.sql'], $result['applied'], 'applied');
    same('002_bad.sql', $result['failed'], 'failed');
    same(false, (bool) $pdo->query("SELECT to_regclass('public.half') IS NOT NULL")->fetchColumn(), 'nothing of 002 stayed');
    same(false, (bool) $pdo->query("SELECT to_regclass('public.never') IS NOT NULL")->fetchColumn(), '003 was not run');
    same(['001_good.sql'], (new Migrator($pdo, $dir))->applied(), 'recorded');
});

test('"Apply pending" that stops at a failing file: 200, what ran first, the file and the error, the log - and the lock given back', function () use ($server) {
    // A database the app's migrations built, with three more files after them: a good one, a bad one, and
    // one the stop keeps from running. The app works on that database for this test.
    $pdo = scratchDatabase($server, 'api_failing');
    same(null, (new Migrator($pdo, Migrator::directory()))->applyPending()['error'], 'the app\'s migrations');
    $dir = T::$storage.DIRECTORY_SEPARATOR.'api-failing-migrations';
    @mkdir($dir, 0777, true);
    foreach (allMigrations() as $file) {
        copy(Migrator::directory().'/'.$file, $dir.'/'.$file);
    }
    file_put_contents($dir.'/900_good.sql', 'CREATE TABLE good (id INT);');
    file_put_contents($dir.'/901_bad.sql', 'CREATE TABLE half (id INT); SELECT * FROM missing_table;');
    file_put_contents($dir.'/902_never.sql', 'CREATE TABLE never (id INT);');
    $log = T::$storage.DIRECTORY_SEPARATOR.'api-failing.log';
    @unlink($log);

    $appDb = T::$app->get(Db::class);
    T::$app->set(Db::class, new Db($pdo));
    T::$app->set(Migrator::class, new Migrator($pdo, $dir));
    Log::to($log);
    try {
        $admin = (new Client)->asIam(1, [Role::ADMIN]);
        same(['900_good.sql', '901_bad.sql', '902_never.sql'], array_column($admin->get('/api/admin/migrations')->status(200)->json('pending'), 'name'), 'pending');

        $answer = $admin->post('/api/admin/migrations/apply')->status(200);
        same(['900_good.sql'], $answer->json('applied'), 'applied before the stop');
        same('901_bad.sql', $answer->json('failed'), 'where it stopped');
        $error = (string) $answer->json('error');
        check(str_contains($error, 'SQLSTATE[42P01]') && str_contains($error, '"missing_table" does not exist'), 'the database\'s error: '.$error);
        same(true, (bool) $pdo->query("SELECT to_regclass('public.good') IS NOT NULL")->fetchColumn(), '900 applied');
        same(false, (bool) $pdo->query("SELECT to_regclass('public.half') IS NOT NULL")->fetchColumn(), 'nothing of 901 stayed');
        same(false, (bool) $pdo->query("SELECT to_regclass('public.never') IS NOT NULL")->fetchColumn(), '902 was not run');
        same(['901_bad.sql', '902_never.sql'], array_column($admin->get('/api/admin/migrations')->status(200)->json('pending'), 'name'), 'still pending');
        $logged = (string) @file_get_contents($log);
        check(str_contains($logged, ' ERROR ') && str_contains($logged, 'Migration 901_bad.sql failed'), 'the failure is in the log: '.$logged);

        // The lock was given back: the next apply runs (and stops at the same file), it is not told "busy".
        $again = $admin->post('/api/admin/migrations/apply')->status(200);
        same([[], '901_bad.sql'], [$again->json('applied'), $again->json('failed')], 'the next apply');
    } finally {
        Log::to(null);
        T::$app->set(Db::class, $appDb);
        T::$app->forget(Migrator::class);
    }
});

test('a file another run applied meanwhile is not run again (a data migration would be applied twice)', function () use ($server) {
    $pdo = scratchDatabase($server, 'twice');
    $dir = T::$storage.DIRECTORY_SEPARATOR.'twice-migrations';
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/001_table.sql', 'CREATE TABLE counted (n INT);');
    file_put_contents($dir.'/002_data.sql', 'INSERT INTO counted VALUES (1);');

    // Two runs read the same pending list; the first applies both files before the second gets to them.
    $second = new Migrator($pdo, $dir);
    same(['001_table.sql', '002_data.sql'], $second->pending(), 'both pending for both runs');
    same(['001_table.sql', '002_data.sql'], (new Migrator($pdo, $dir))->applyPending()['applied'], 'the first run');

    same(false, $second->apply('002_data.sql'), 'the second run passes over it');
    same(1, (int) $pdo->query('SELECT count(*) FROM counted')->fetchColumn(), 'its row written once');
    same(['001_table.sql', '002_data.sql'], $second->applied(), 'each recorded once');
});

test('an apply waits for the one under way: every file takes the same advisory lock', function () use ($server) {
    $pdo = scratchDatabase($server, 'waits');
    $dir = T::$storage.DIRECTORY_SEPARATOR.'waits-migrations';
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/001_table.sql', 'CREATE TABLE waited (n INT);');
    $key = (new ReflectionClassConstant(Migrator::class, 'APPLY_LOCK'))->getValue();

    // Another run, on a connection of its own, in the middle of a file.
    $other = Db::pdo($server, (string) $pdo->query('SELECT current_database()')->fetchColumn(), false);
    $other->beginTransaction();
    $other->query("SELECT pg_advisory_xact_lock({$key})");
    $pdo->exec("SET lock_timeout = '300ms'");
    try {
        $result = (new Migrator($pdo, $dir))->applyPending();
        same([[], '001_table.sql'], [$result['applied'], $result['failed']], 'it waited (and here gave up)');
        check(str_contains((string) $result['error'], 'lock timeout'), 'on the lock: '.$result['error']);
        same(false, (bool) $pdo->query("SELECT to_regclass('public.waited') IS NOT NULL")->fetchColumn(), 'nothing ran meanwhile');
    } finally {
        $other->rollBack();
        $pdo->exec('RESET lock_timeout');
    }

    same(['001_table.sql'], (new Migrator($pdo, $dir))->applyPending()['applied'], 'once the other run is done');
});

test('004 applies over a 003 whose strings were reworded and whose Slovak was cleared in the browser', function () use ($server) {
    // A database as the owner's is before 004: 001-003 applied, then the Translations grid used.
    $dir = T::$storage.DIRECTORY_SEPARATOR.'migrations-to-003';
    @mkdir($dir, 0777, true);
    foreach (allMigrations() as $file) {
        if (strcmp($file, '004') < 0) {
            copy(Migrator::directory().'/'.$file, $dir.'/'.$file);
        }
    }
    $pdo = scratchDatabase($server, 'to003');
    $built = (new Migrator($pdo, $dir))->applyPending();
    same(null, $built['error'], '001-003 applied');
    same(['003_languages_and_translations.sql'], array_slice($built['applied'], -1), 'up to 003');

    // An editor cleared a Slovak string (an empty cell deletes the row: the page reads the English), and
    // reworded an English one of a key 004 keeps and one of a key 004 retires.
    $pdo->exec("DELETE FROM translations WHERE key_name = 'common.signIn' AND language_code = 'sk'");
    $pdo->exec("UPDATE translations SET value = 'Log in', updated_by = 1 WHERE key_name = 'common.signIn' AND language_code = 'en'");
    $pdo->exec("UPDATE translations SET value = 'Home', updated_by = 1 WHERE key_name = 'nav.dashboard' AND language_code = 'en'");

    $result = (new Migrator($pdo, Migrator::directory()))->applyPending();
    same(null, $result['error'], 'no error');
    same(array_values(array_filter(allMigrations(), fn (string $f): bool => strcmp($f, '004') >= 0)), $result['applied'], 'applied from 004 on');

    $value = fn (string $key, string $code): mixed => $pdo->query("SELECT value FROM translations WHERE key_name = '{$key}' AND language_code = '{$code}'")->fetchColumn();
    same(false, $value('common.signIn', 'sk'), 'the cleared Slovak string stays cleared');
    same('Log in', $value('common.signIn', 'en'), 'the reworded English string is not written over');
    same(0, (int) $pdo->query("SELECT count(*) FROM translation_keys WHERE name = 'nav.dashboard'")->fetchColumn(), 'a retired key goes, reworded or not');
    same(false, $value('nav.dashboard', 'en'), 'and its strings with it');
    check($value('topbar.dashboard', 'sk') !== false, 'the new keys read in Slovak');
});

foreach ([6 => 'the version first committed', 7 => 'with sign-in through the IAM', 8 => 'with resumable syncs'] as $level => $what) {
    test("a Laravel database ({$what}) is brought forward with all its data", function () use ($server, $level) {
        $pdo = laravelDatabase($server, $level);
        $migrator = new Migrator($pdo, Migrator::directory());
        $before = [];
        foreach (['game_characters', 'game_weapons', 'game_sets', 'accounts', 'import_runs', 'account_characters', 'account_weapons', 'account_items', 'build_sources', 'builds', 'sync_runs'] as $table) {
            $before[$table] = (int) $pdo->query("SELECT count(*) FROM {$table}")->fetchColumn();
        }

        // Looking does not change anything.
        check($migrator->isLaravelDatabase(), 'recognised');
        same([allMigrations(), []], [array_column($migrator->status()['pending'], 'name'), $migrator->status()['applied']], 'pending, and nothing applied');
        same(1, (int) $pdo->query("SELECT count(*) FROM information_schema.columns WHERE table_name = 'migrations' AND column_name = 'batch'")->fetchColumn(), 'still Laravel\'s table after looking');

        $result = $migrator->applyPending(['local_user_id' => 1]);
        same(null, $result['error'], 'no error');
        same(true, $result['laravel'], 'reported as a Laravel database');

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

        // The same database as a new one, down to the names of its constraints and indexes.
        same(schemaOf(db()->raw()), schemaOf($pdo), 'the schema of a new database');

        // The IAM people are still IAM people: no key, never seen as a visitor (migration 004).
        same(0, (int) $pdo->query('SELECT count(*) FROM users WHERE id < 1 OR visitor_key_hash IS NOT NULL OR last_seen_at IS NOT NULL')->fetchColumn(), 'the people, as they were');

        // And migrating again finds nothing to do.
        same([], (new Migrator($pdo, Migrator::directory()))->pending(), 'nothing pending');
    });
}

finishTests();
