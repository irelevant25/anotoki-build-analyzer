<?php

namespace App\Db;

use App\Support\Clock;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;

/**
 * The migration engine, with no opinion on who is driving.
 *
 * Reads backend/migrations/*.sql, applies the files a database has not seen, and records each in its
 * `migrations` table. It prints nothing and reads no request, so the command line (migration.php), the
 * admins' "Apply pending" on the Migrations page and the installer (setup.php) build a database the same way.
 * The same engine as the genshin site's and the IAM's, so a migration reads the same in every repository.
 *
 * The migrations are the schema: a database is what you get by applying them in order, and a file already
 * recorded as applied never runs again - so an applied file is never edited; a new one is written instead.
 * Nothing here un-applies anything.
 *
 * One thing is particular to this app: its first version was a Laravel application, whose database has a
 * `migrations` table of its own (columns `migration`, `batch`). That table is moved aside as
 * `laravel_migrations` before this engine creates its own, and 001_adopt_laravel_database.sql then brings
 * such a database forward (see there).
 */
final class Migrator
{
    /** The advisory lock every apply takes: one migration at a time in a database (an arbitrary number of its own). */
    private const APPLY_LOCK = 4_270_031_001;

    public function __construct(private readonly PDO $pdo, private readonly string $directory) {}

    public static function directory(): string
    {
        return dirname(__DIR__, 2).'/migrations';
    }

    /**
     * Splits a SQL file into statements. A semicolon only ends one outside a quoted string, a dollar-quoted
     * block (how every function and DO block is written) and a comment.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $character = $sql[$i];

            if ($character === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            if ($character === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($character === '$') {
                $j = $i + 1;
                while ($j < $length && $sql[$j] !== '$' && (ctype_alnum($sql[$j]) || $sql[$j] === '_')) {
                    $j++;
                }

                if (($sql[$j] ?? '') === '$') {
                    $tag = substr($sql, $i, $j - $i + 1);
                    $end = strpos($sql, $tag, $j + 1);

                    if ($end !== false) {
                        $current .= substr($sql, $i, $end - $i + strlen($tag));
                        $i = $end + strlen($tag);

                        continue;
                    }
                }
            }

            if ($character === "'") {
                $current .= $character;
                $i++;

                while ($i < $length) {
                    $current .= $sql[$i];

                    if ($sql[$i] === "'") {
                        if (($sql[$i + 1] ?? '') === "'") {
                            $current .= $sql[++$i];
                        } else {
                            $i++;

                            break;
                        }
                    }

                    $i++;
                }

                continue;
            }

            if ($character === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                $i++;

                continue;
            }

            $current .= $character;
            $i++;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /**
     * The files in apply order - natural order, so 100 does not sort before 11.
     *
     * @return list<string>
     */
    public function files(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }

        $files = array_map('basename', glob($this->directory.'/*.sql') ?: []);
        natsort($files);

        return array_values($files);
    }

    /**
     * Makes the database when it is not there, and answers whether it had to. On most shared hosting the
     * application's user cannot CREATE DATABASE at all; there it is made in the control panel first and
     * this finds it already there.
     *
     * @param  array{host: string, port: string|int, username: string, password?: string|null}  $config
     */
    public static function ensureDatabase(array $config, string $name): bool
    {
        $server = Db::pdo($config, 'postgres', false);
        $exists = $server->prepare('SELECT count(*) FROM pg_catalog.pg_database WHERE datname = ?');
        $exists->execute([$name]);

        if ((int) $exists->fetchColumn() > 0) {
            return false;
        }

        // The name is the installation's own configuration, never anything a request reaches, and CREATE
        // DATABASE takes no parameters: quoting it as an identifier is the whole of what can be done.
        $server->exec('CREATE DATABASE "'.str_replace('"', '""', $name).'"');

        return true;
    }

    /**
     * Creates the `migrations` table, first moving a Laravel one out of the way. Answers whether it did that.
     */
    public function ensureTable(): bool
    {
        $moved = false;
        $columns = $this->pdo->query(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'migrations'"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (in_array('batch', $columns, true) && ! in_array('filename', $columns, true)) {
            $this->pdo->beginTransaction();
            try {
                // The table's constraints (its primary key, and on PostgreSQL 18 its named NOT NULLs) and
                // sequence keep their names through a rename; they move aside too, so the new table's own
                // are named as on any other database.
                $this->pdo->exec('ALTER TABLE migrations RENAME TO laravel_migrations');
                $this->pdo->exec("DO \$\$
                    DECLARE c RECORD;
                    BEGIN
                        FOR c IN SELECT conname FROM pg_constraint
                                  WHERE conrelid = 'laravel_migrations'::regclass AND conname LIKE 'migrations\\_%' LOOP
                            EXECUTE format('ALTER TABLE laravel_migrations RENAME CONSTRAINT %I TO %I', c.conname, 'laravel_' || c.conname);
                        END LOOP;
                    END \$\$");
                $this->pdo->exec('ALTER SEQUENCE IF EXISTS migrations_id_seq RENAME TO laravel_migrations_id_seq');
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();

                throw $e;
            }
            $moved = true;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         SERIAL          PRIMARY KEY,
                filename   VARCHAR(255)    NOT NULL UNIQUE,
                applied_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
            )'
        );

        return $moved;
    }

    /**
     * The files recorded as applied. Reads only: a database without this engine's table (a new one, or one
     * of the Laravel version) has applied none.
     *
     * @return list<string>
     */
    public function applied(): array
    {
        return $this->hasTable() ? $this->pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    /** Whether this engine's `migrations` table is there (not Laravel's, not none). */
    private function hasTable(): bool
    {
        return (bool) $this->pdo->query(
            "SELECT EXISTS (SELECT 1 FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = 'migrations' AND column_name = 'filename')"
        )->fetchColumn();
    }

    /** @return list<string> */
    public function pending(): array
    {
        return array_values(array_diff($this->files(), $this->applied()));
    }

    /**
     * What the admin panel's Migrations page shows, in the IAM's shape: every file recorded as applied, in
     * the order it was, with when (ISO 8601, UTC); and the files not applied yet, in the order they will run.
     *
     * @return array{applied: list<array{name: string, applied_at: string|null}>, pending: list<array{name: string}>}
     */
    public function status(): array
    {
        $applied = [];
        if ($this->hasTable()) {
            // applied_at is a TIMESTAMP written in the session's time zone, which Db sets to UTC.
            foreach ($this->pdo->query('SELECT filename, applied_at FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $applied[] = [
                    'name' => (string) $row['filename'],
                    'applied_at' => $row['applied_at'] === null ? null
                        : Clock::iso(new DateTimeImmutable((string) $row['applied_at'], Clock::utc())),
                ];
            }
        }

        return [
            'applied' => $applied,
            'pending' => array_map(static fn (string $name): array => ['name' => $name], $this->pending()),
        ];
    }

    /**
     * Applies one file, all of it or none of it. PostgreSQL takes DDL inside a transaction, and the row
     * saying the file ran is written in the same one.
     *
     * One file at a time, whoever applies it: the transaction first takes a PostgreSQL advisory lock (held
     * until it ends), so a second run - another "Apply pending", `php migration.php`, setup.php - waits for
     * the first one's file, and then finds it recorded and runs nothing of it. The admins' `migrate` lock
     * only answers "busy" early and expires; this one is what keeps a file from running twice. Answers
     * whether this call applied the file (false: another run had).
     *
     * $settings become session settings the SQL can read with current_setting('build_analyzer.<name>', true)
     * - build_analyzer.local_user_id, whom the adoption of a Laravel database gives accounts that belonged to
     * nobody yet. Nothing passes it any more but the adoption's own test; without it the migration uses IAM
     * user 1.
     *
     * @param  array<string, scalar>  $settings
     */
    public function apply(string $filename, array $settings = []): bool
    {
        $this->ensureTable();
        $statements = self::splitStatements((string) file_get_contents($this->directory.'/'.$filename));

        $this->pdo->beginTransaction();

        try {
            $this->pdo->query('SELECT pg_advisory_xact_lock('.self::APPLY_LOCK.')');
            // A statement after the lock: it sees what the run that held it committed.
            $recorded = $this->pdo->prepare('SELECT 1 FROM migrations WHERE filename = ?');
            $recorded->execute([$filename]);
            if ($recorded->fetchColumn() !== false) {
                $this->pdo->commit();

                return false;
            }

            foreach ($settings as $name => $value) {
                $set = $this->pdo->prepare('SELECT set_config(?, ?, true)');
                $set->execute(['build_analyzer.'.$name, (string) $value]);
            }

            foreach ($statements as $index => $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (PDOException $e) {
                    throw new RuntimeException(
                        'statement '.($index + 1).' of '.count($statements).': '.$e->getMessage()
                        ."\n\n  ".implode("\n  ", array_slice(explode("\n", $statement), 0, 6))
                    );
                }
            }

            $this->pdo->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute([$filename]);
            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Every pending file, stopping at the first failure - each migration is written expecting the ones
     * before it to have run. A file another run applied meanwhile is passed over, not listed as applied.
     *
     * @param  array<string, scalar>  $settings
     * @return array{applied: list<string>, failed: string|null, error: string|null, laravel: bool}
     */
    public function applyPending(array $settings = []): array
    {
        $laravel = $this->ensureTable();
        $applied = [];

        foreach ($this->pending() as $filename) {
            try {
                if ($this->apply($filename, $settings)) {
                    $applied[] = $filename;
                }
            } catch (\Throwable $e) {
                return ['applied' => $applied, 'failed' => $filename, 'error' => $e->getMessage(), 'laravel' => $laravel];
            }
        }

        return ['applied' => $applied, 'failed' => null, 'error' => null, 'laravel' => $laravel];
    }

    /**
     * Whether a database was made by the first (Laravel) version of the app, before or after being adopted.
     */
    public function isLaravelDatabase(): bool
    {
        return (bool) $this->pdo->query("SELECT to_regclass('public.laravel_migrations') IS NOT NULL
            OR EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'migrations' AND column_name = 'batch')")->fetchColumn();
    }
}
