-- ============================================================================
-- 001_adopt_laravel_database.sql - bring a database of the Laravel version forward
-- ============================================================================
-- The first version of the build analyzer was a Laravel application, on the
-- owner's computer and possibly on a host. Its database is adopted here with
-- every row it holds; on a new, empty database this file does nothing and 002
-- creates the schema.
--
-- The migration engine has already moved Laravel's own `migrations` table aside
-- as `laravel_migrations` (it stays, as the record of what the old version ran);
-- that is how a Laravel database is recognised. How far that version got is read
-- from the tables themselves:
--
--   the base schema (2026-09-24)           catalog, accounts, builds, sync runs,
--                                          and Laravel's framework tables
--   + owners (2026-09-27_000100)           users are anotoki IAM users; accounts
--                                          and import runs belong to one
--   + resumable syncs (2026-09-27_000200)  sync runs keep their place between
--                                          requests
--
-- Whatever of the last two is missing is done here the way Laravel would have
-- done it: every existing account and import run goes to the owner - the user id
-- in auth.local.php (local_user_id), which the engine passes in as
-- build_analyzer.local_user_id - and runs left "running" by the old queue worker
-- are closed as failed, since they have no position to resume from.
--
-- Then the database moves on to this version:
--
--   - every timestamp becomes TIMESTAMPTZ. The Laravel app kept UTC in columns
--     without a zone, and that is how they are read.
--   - users lose their password column: the IAM owns passwords, and it was
--     always empty.
--   - the cache and its locks are emptied: they only held Laravel's serialized
--     entries, which mean nothing to this version.
--   - Laravel's framework tables - jobs, job_batches, failed_jobs, sessions,
--     password_reset_tokens, and its login `users` table from before sign-in -
--     are dropped when empty and kept, renamed laravel_*, when they are not.
--
-- The gate at the end compares every table's row count, and the sum of all its
-- timestamps, with what they were before, and fails loudly - rolling all of this
-- back - if anything differs.
-- ============================================================================

DO $$
DECLARE
    owner_id     BIGINT := COALESCE(NULLIF(current_setting('build_analyzer.local_user_id', true), '')::BIGINT, 1);
    data_tables  TEXT[] := ARRAY['game_characters', 'game_weapons', 'game_sets', 'accounts', 'import_runs',
                                 'account_characters', 'account_weapons', 'account_items', 'build_sources',
                                 'builds', 'sync_runs', 'users'];
    framework    TEXT[] := ARRAY['jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens'];
    t            TEXT;
    col          RECORD;
    n            BIGINT;
    s            NUMERIC;
    part         NUMERIC;
    total        NUMERIC;
    has_rows     BOOLEAN;
BEGIN
    IF to_regclass('public.laravel_migrations') IS NULL THEN
        RETURN; -- a new database: nothing to bring forward
    END IF;

    -- ── What there is now ──────────────────────────────────────────────────
    CREATE TEMP TABLE adoption_rows (tbl TEXT PRIMARY KEY, row_count BIGINT NOT NULL) ON COMMIT DROP;
    FOREACH t IN ARRAY data_tables LOOP
        CONTINUE WHEN t = 'users'; -- replaced below when it is Laravel's login table
        IF to_regclass('public.' || t) IS NOT NULL THEN
            EXECUTE format('SELECT count(*) FROM %I', t) INTO n;
            INSERT INTO adoption_rows VALUES (t, n);
        END IF;
    END LOOP;

    -- ── Laravel's login table makes way for IAM users ──────────────────────
    -- (000100). The app never had a login of its own, so this table was unused.
    -- Like the framework tables below, it is dropped when empty and set aside
    -- (with its constraints and sequence) when not.
    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'email') THEN
        EXECUTE 'SELECT EXISTS (SELECT 1 FROM users)' INTO has_rows;
        IF has_rows THEN
            ALTER TABLE users RENAME TO laravel_users;
            FOR col IN SELECT conname FROM pg_constraint
                        WHERE conrelid = 'laravel_users'::regclass AND conname LIKE 'users\_%' LOOP
                EXECUTE format('ALTER TABLE laravel_users RENAME CONSTRAINT %I TO %I', col.conname, 'laravel_' || col.conname);
            END LOOP;
            ALTER SEQUENCE IF EXISTS users_id_seq RENAME TO laravel_users_id_seq;
        ELSE
            DROP TABLE users;
        END IF;
    END IF;

    IF to_regclass('public.users') IS NULL THEN
        CREATE TABLE users (
            id          BIGINT          NOT NULL,
            name        VARCHAR(100)    NOT NULL,
            created_at  TIMESTAMPTZ,
            updated_at  TIMESTAMPTZ,
            CONSTRAINT users_pkey PRIMARY KEY (id)
        );
    END IF;

    -- ── Owners (000100) ─────────────────────────────────────────────────────
    IF to_regclass('public.accounts') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns
                        WHERE table_schema = current_schema() AND table_name = 'accounts' AND column_name = 'user_id') THEN

        IF EXISTS (SELECT 1 FROM accounts) OR EXISTS (SELECT 1 FROM import_runs) THEN
            INSERT INTO users (id, name, created_at, updated_at) VALUES (owner_id, 'owner', now(), now())
            ON CONFLICT (id) DO NOTHING;
        END IF;

        ALTER TABLE accounts ADD COLUMN user_id BIGINT;
        UPDATE accounts SET user_id = owner_id;
        ALTER TABLE accounts ALTER COLUMN user_id SET NOT NULL;
        ALTER TABLE accounts ADD CONSTRAINT accounts_user_id_foreign
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
        -- Two people may import the same UID, each into an account of their own.
        ALTER TABLE accounts DROP CONSTRAINT IF EXISTS accounts_game_uid_unique;
        ALTER TABLE accounts ADD CONSTRAINT accounts_user_id_game_uid_unique UNIQUE (user_id, game, uid);

        ALTER TABLE import_runs ADD COLUMN user_id BIGINT;
        UPDATE import_runs SET user_id = owner_id;
        ALTER TABLE import_runs ALTER COLUMN user_id SET NOT NULL;
        ALTER TABLE import_runs ADD CONSTRAINT import_runs_user_id_foreign
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
    END IF;

    -- ── Resumable sync runs (000200) ────────────────────────────────────────
    IF to_regclass('public.sync_runs') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns
                        WHERE table_schema = current_schema() AND table_name = 'sync_runs' AND column_name = 'kind') THEN

        ALTER TABLE sync_runs ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'builds';
        ALTER TABLE sync_runs ADD COLUMN game VARCHAR(16);
        ALTER TABLE sync_runs ALTER COLUMN build_source_id DROP NOT NULL;
        ALTER TABLE sync_runs ADD COLUMN started_by BIGINT;
        ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_started_by_foreign
            FOREIGN KEY (started_by) REFERENCES users (id) ON DELETE SET NULL;
        ALTER TABLE sync_runs ADD COLUMN state JSON;
        ALTER TABLE sync_runs ADD COLUMN next_step_at TIMESTAMP(0) WITHOUT TIME ZONE;
        ALTER TABLE sync_runs ADD COLUMN last_step_at TIMESTAMP(0) WITHOUT TIME ZONE;
        CREATE INDEX sync_runs_status_kind_index ON sync_runs (status, kind);

        UPDATE sync_runs SET game = (SELECT game FROM build_sources WHERE build_sources.id = sync_runs.build_source_id);
        UPDATE sync_runs SET status = 'failed', finished_at = (now() AT TIME ZONE 'UTC') WHERE status = 'running';
    END IF;

    -- ── This version: no password, an empty cache, no framework tables ─────
    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'password') THEN
        ALTER TABLE users DROP COLUMN password;
    END IF;

    IF to_regclass('public.cache') IS NOT NULL THEN
        DELETE FROM cache;
    END IF;
    IF to_regclass('public.cache_locks') IS NOT NULL THEN
        DELETE FROM cache_locks;
    END IF;

    FOREACH t IN ARRAY framework LOOP
        IF to_regclass('public.' || t) IS NOT NULL THEN
            EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I)', t) INTO has_rows;
            IF has_rows THEN
                EXECUTE format('ALTER TABLE %I RENAME TO %I', t, 'laravel_' || t);
                FOR col IN SELECT conname FROM pg_constraint
                            WHERE conrelid = ('laravel_' || t)::regclass AND conname LIKE t || '\_%' LOOP
                    EXECUTE format('ALTER TABLE %I RENAME CONSTRAINT %I TO %I', 'laravel_' || t, col.conname, 'laravel_' || col.conname);
                END LOOP;
                IF to_regclass('public.' || t || '_id_seq') IS NOT NULL THEN
                    EXECUTE format('ALTER SEQUENCE %I RENAME TO %I', t || '_id_seq', 'laravel_' || t || '_id_seq');
                END IF;
            ELSE
                EXECUTE format('DROP TABLE %I', t);
            END IF;
        END IF;
    END LOOP;

    -- ── Every timestamp in UTC, with its zone ──────────────────────────────
    CREATE TEMP TABLE adoption_times (tbl TEXT PRIMARY KEY, time_sum NUMERIC NOT NULL) ON COMMIT DROP;
    FOREACH t IN ARRAY data_tables LOOP
        IF to_regclass('public.' || t) IS NOT NULL THEN
            total := 0;
            FOR col IN SELECT column_name, data_type FROM information_schema.columns
                        WHERE table_schema = current_schema() AND table_name = t AND data_type LIKE 'timestamp%' LOOP
                IF col.data_type = 'timestamp without time zone' THEN
                    EXECUTE format('SELECT COALESCE(sum(extract(epoch FROM (%I AT TIME ZONE ''UTC''))), 0) FROM %I', col.column_name, t) INTO s;
                ELSE
                    EXECUTE format('SELECT COALESCE(sum(extract(epoch FROM %I)), 0) FROM %I', col.column_name, t) INTO s;
                END IF;
                total := total + s;
            END LOOP;
            INSERT INTO adoption_times VALUES (t, total);

            FOR col IN SELECT column_name FROM information_schema.columns
                        WHERE table_schema = current_schema() AND table_name = t AND data_type = 'timestamp without time zone' LOOP
                EXECUTE format('ALTER TABLE %I ALTER COLUMN %I TYPE TIMESTAMPTZ USING %I AT TIME ZONE ''UTC''',
                               t, col.column_name, col.column_name);
            END LOOP;
        END IF;
    END LOOP;

    -- ── Verification gate ──────────────────────────────────────────────────
    FOR t, n IN SELECT tbl, row_count FROM adoption_rows LOOP
        EXECUTE format('SELECT count(*) FROM %I', t) INTO s;
        IF s <> n THEN
            RAISE EXCEPTION 'Bringing the Laravel database forward changed the rows of %: % before, % after.', t, n, s;
        END IF;
    END LOOP;

    FOR t, s IN SELECT tbl, time_sum FROM adoption_times LOOP
        total := 0;
        FOR col IN SELECT column_name FROM information_schema.columns
                    WHERE table_schema = current_schema() AND table_name = t AND data_type LIKE 'timestamp%' LOOP
            EXECUTE format('SELECT COALESCE(sum(extract(epoch FROM %I)), 0) FROM %I', col.column_name, t) INTO part;
            total := total + part;
        END LOOP;
        IF total <> s THEN
            RAISE EXCEPTION 'Bringing the Laravel database forward changed the times in %: checksum % before, % after.', t, s, total;
        END IF;
    END LOOP;

    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_schema = current_schema() AND table_name = ANY (data_tables)
                  AND data_type = 'timestamp without time zone') THEN
        RAISE EXCEPTION 'Bringing the Laravel database forward left a timestamp without its time zone.';
    END IF;

    IF to_regclass('public.accounts') IS NOT NULL
       AND EXISTS (SELECT 1 FROM accounts a LEFT JOIN users u ON u.id = a.user_id WHERE u.id IS NULL) THEN
        RAISE EXCEPTION 'Bringing the Laravel database forward left an account without its owner.';
    END IF;
END $$;
