<?php

namespace App\Db;

use Anotoki\Lib\Admin\Schema as AdminSchema;
use Anotoki\Lib\Migrations\MigrationSet;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\People\Schema as PeopleSchema;
use Anotoki\Lib\Translations\Schema as TranslationsSchema;
use Closure;
use PDO;

/**
 * This site's migrations on the anotoki family's engine (anotoki/lib: Anotoki\Lib\Migrations\Migrator), which
 * reads backend/migrations/*.sql, applies the files a database has not seen - each in a transaction of its
 * own with its record, stopping at the first that fails - and records them in the `migrations` table. Here is
 * only what is this site's own; the command line (migration.php), the admins' "Apply pending" on the
 * Migrations page, the installer (setup.php) and the tests all take their Migrator from migrator(), so a
 * database built any of these ways is the same database.
 *
 * The migrations are the schema: a database is what you get by applying them in order, and a file already
 * recorded as applied never runs again - so an applied file is never edited; a new one is written instead.
 * Nothing un-applies anything.
 *
 * - Four sets of files, in this order (the anotoki library's README gives it): the library's admin tools'
 *   (`anotoki_admin`, anotoki/lib's backend/migrations/admin: the audit log's and feedback's tables, which no file
 *   of this site's makes - first, as in every anotoki site, so a site's own file may build on them); this
 *   site's own (`build_analyzer`, backend/migrations); the library's translations set (`anotoki_translations`:
 *   its 001 proves the shape of the three tables of the site's words, which this site's 003 made, and its
 *   later files add the library's own words under `anotoki.`); and its people set (`anotoki_people`: where
 *   the scrub of the IAM's deleted accounts stands, after this site's 007 gave users their anonymized_at). The
 *   translations' and the people's come after this site's because this site's files make the tables they
 *   build on. All are recorded in `migrations (id, filename, applied_at)` - the table this site's own engine
 *   made, which the library reads and writes as it is: this site's files by their names, the library's with
 *   their set in front (`anotoki_admin/001_audit_log.sql`, `anotoki_translations/001_languages_and_strings.sql`),
 *   wherever the set stands in the list.
 * - The first version of the app was a Laravel application, whose database has a `migrations` table of its
 *   own (columns `migration`, `batch`). The `prepare` hook moves that table aside as `laravel_migrations`
 *   before the engine makes its own, and 001_adopt_laravel_database.sql then brings such a database forward.
 *   Until then the library reads a table with no file column as nothing applied.
 * - Settings a migration reads with current_setting('build_analyzer.<name>', true), set in each file's
 *   transaction (`beforeFile`). Only the adoption reads one - build_analyzer.local_user_id, whom accounts that
 *   belonged to nobody yet go to (IAM user 1 without it) - and nothing but its own test passes it.
 * - The advisory lock every apply takes (one apply at a time; another is told it is busy): the number this
 *   site's own engine took, so an apply by an older copy of the site and one by this one never run together.
 * - A backup of the database as it is before an Apply (`beforeApply`: the library's backups, once for each list
 *   of pending files) - given by the API's Migrator and the command line's, never by a test's own world.
 */
final class Migrations
{
    /** The set's name: what the Migrations page and the admin routes' answers call this site's files. */
    public const SET = 'build_analyzer';

    /** The advisory lock every apply takes (see the class comment). */
    public const LOCK = 4_270_031_001;

    public static function directory(): string
    {
        return dirname(__DIR__, 2).'/migrations';
    }

    /** This site's own set of files: backend/migrations, or a folder of a test's own. */
    public static function set(?string $directory = null): MigrationSet
    {
        return new MigrationSet(self::SET, $directory ?? self::directory());
    }

    /**
     * Every set, in the order they run: the library's admin tools', this site's own, the library's translations
     * and people sets.
     *
     * @param  string|null  $directory  the site's migrations folder; a test gives one of its own
     * @return list<MigrationSet>
     */
    public static function sets(?string $directory = null, bool $library = true): array
    {
        return $library
            ? [AdminSchema::migrationSet(), self::set($directory), TranslationsSchema::migrationSet(), PeopleSchema::migrationSet()]
            : [self::set($directory)];
    }

    /**
     * The site's Migrator on this connection.
     *
     * @param  string|null  $directory  the site's migrations folder; a test gives one of its own
     * @param  array<string, scalar>  $settings  session settings for the migrations (build_analyzer.<name>)
     * @param  bool  $library  with the library's sets around the site's - always, but in a test that builds a world
     *                         of its own: a database as it was before the library's files were released, or a
     *                         folder of files that are the test's alone
     * @param  (Closure(PDO, list<array{set: string, name: string}>): ?string)|null  $beforeApply  run once before an
     *                         Apply applies anything (the library's backup before an update); its words are the
     *                         Apply's note, and a throw stops it with nothing applied
     */
    public static function migrator(PDO $pdo, ?string $directory = null, array $settings = [], bool $library = true, ?Closure $beforeApply = null): Migrator
    {
        return new Migrator($pdo, self::sets($directory, $library), [
            'lock' => self::LOCK,
            ...($beforeApply === null ? [] : ['beforeApply' => $beforeApply]),
            'prepare' => static function (PDO $pdo): void {
                self::moveLaravelTableAside($pdo);
            },
            'beforeFile' => static function (PDO $pdo) use ($settings): void {
                foreach ($settings as $name => $value) {
                    // Local to the file's transaction (the third argument): nothing outlives it.
                    $pdo->prepare('SELECT set_config(?, ?, true)')->execute(['build_analyzer.'.$name, (string) $value]);
                }
            },
        ]);
    }

    /**
     * Moves the Laravel version's `migrations` table out of the way as `laravel_migrations` - with its
     * constraints and sequence, which keep their names through a rename, so that the engine's new table gets
     * the names it has on any other database. Answers whether there was one to move.
     */
    public static function moveLaravelTableAside(PDO $pdo): bool
    {
        $columns = $pdo->query(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'migrations'"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (! in_array('batch', $columns, true) || in_array('filename', $columns, true)) {
            return false;
        }

        $pdo->beginTransaction();
        try {
            // The table's constraints (its primary key, and on PostgreSQL 18 its named NOT NULLs) and sequence
            // keep their names through a rename; they move aside too.
            $pdo->exec('ALTER TABLE migrations RENAME TO laravel_migrations');
            $pdo->exec("DO \$\$
                DECLARE c RECORD;
                BEGIN
                    FOR c IN SELECT conname FROM pg_constraint
                              WHERE conrelid = 'laravel_migrations'::regclass AND conname LIKE 'migrations\\_%' LOOP
                        EXECUTE format('ALTER TABLE laravel_migrations RENAME CONSTRAINT %I TO %I', c.conname, 'laravel_' || c.conname);
                    END LOOP;
                END \$\$");
            $pdo->exec('ALTER SEQUENCE IF EXISTS migrations_id_seq RENAME TO laravel_migrations_id_seq');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * Whether a database was made by the first (Laravel) version of the app, before or after being adopted.
     * Reads only.
     */
    public static function isLaravelDatabase(PDO $pdo): bool
    {
        return (bool) $pdo->query("SELECT to_regclass('public.laravel_migrations') IS NOT NULL
            OR EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'migrations' AND column_name = 'batch')")->fetchColumn();
    }
}
