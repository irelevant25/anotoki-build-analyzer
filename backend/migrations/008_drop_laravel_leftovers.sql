-- ============================================================================
-- 008_drop_laravel_leftovers.sql - what the Laravel version left behind goes
-- ============================================================================
-- Version 0.4. The first version of the build analyzer was a Laravel
-- application, and Laravel comes with tables of its own. Where one was not
-- empty, 001 kept it, renamed laravel_*:
--
--   its login                laravel_users                  an email address and a
--                                                           password hash a row
--                            laravel_password_reset_tokens  an email address and a
--                                                           reset token a row
--                            laravel_sessions               a browser's session: the
--                                                           network address it came
--                                                           from, its browser, its
--                                                           user, what it held
--   its queue                laravel_jobs                   queued jobs, their payloads
--                            laravel_job_batches            batches of them
--                            laravel_failed_jobs            jobs that failed: payload
--                                                           and exception
--
-- (with the sequences of laravel_users, laravel_jobs and laravel_failed_jobs,
-- laravel_*_id_seq; the other three have none). The app never used the login
-- (people sign in through the anotoki IAM) and nothing of this version reads
-- the queue's tables (it has no queue: syncs run in steps).
--
-- The owner's decisions (2026-10-11): no anotoki site holds an email address -
-- the IAM is the only holder, and of password hashes too - nor the addresses
-- old sessions came from, nor old job payloads, which can hold personal data.
-- So the six go, with every row they hold and their sequences, where they are;
-- where none is (every database that was not adopted from the first Laravel
-- version) this file does nothing. Nothing else changes: the people the IAM
-- signed in here are this site's own `users`, untouched, and laravel_migrations
-- stays - the record of what that version ran, nobody's data. Nothing points
-- at the six; if anything did, this file would fail rather than take it along
-- (no CASCADE).
--
-- Applying it: with the other files of version 0.4, in one Apply on the admin
-- panel's Migrations page (see 007). A backup of the database as it was is
-- made first - with these tables in it: the owner deletes that backup (the
-- admin panel's Backups page) once the update is known to be good, so that no
-- copy of them stays behind.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

-- What there is now, for the gate below: every table of this schema, with its rows.
CREATE TEMPORARY TABLE laravel_leftovers_before (name TEXT PRIMARY KEY, row_count BIGINT NOT NULL) ON COMMIT DROP;

DO $$
DECLARE
    t TEXT;
    n BIGINT;
BEGIN
    FOR t IN SELECT c.relname::TEXT FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace
              WHERE ns.nspname = current_schema() AND c.relkind IN ('r', 'p') LOOP
        EXECUTE format('SELECT count(*) FROM %I', t) INTO n;
        INSERT INTO laravel_leftovers_before VALUES (t, n);
    END LOOP;
END $$;

-- One statement: the six go together, so that one of them pointing at another (a session at its user) is no
-- obstacle - while anything else pointing at one of them stops it all (no CASCADE).
DROP TABLE IF EXISTS laravel_users, laravel_password_reset_tokens, laravel_sessions,
    laravel_jobs, laravel_job_batches, laravel_failed_jobs;
-- Each sequence went with its table where the table owned it, as Laravel's did; one left on its own goes too.
DROP SEQUENCE IF EXISTS laravel_users_id_seq, laravel_jobs_id_seq, laravel_failed_jobs_id_seq;

-- ============================================================================
-- PROVE IT
--
-- None of the six is here, nor any of their sequences; every other table that
-- was here is still here, with as many rows, and nothing is new. The one
-- exception is the audit log's row count, which may only have grown: the admin
-- tools that stay open while an update waits (the error log's Clear, a backup
-- made or deleted) write to it, and may have done so meanwhile.
-- ============================================================================
DO $$
DECLARE
    gone CONSTANT TEXT[] := ARRAY['laravel_failed_jobs', 'laravel_job_batches', 'laravel_jobs',
        'laravel_password_reset_tokens', 'laravel_sessions', 'laravel_users'];
    sequences CONSTANT TEXT[] := ARRAY['laravel_failed_jobs_id_seq', 'laravel_jobs_id_seq', 'laravel_users_id_seq'];
    still_here TEXT;
    now_tables TEXT[];
    kept_tables TEXT[];
    other RECORD;
    n BIGINT;
    changed TEXT;
BEGIN
    SELECT string_agg(g, ', ' ORDER BY g) INTO still_here
      FROM unnest(gone || sequences) AS g
     WHERE to_regclass(g) IS NOT NULL;
    IF still_here IS NOT NULL THEN
        RAISE EXCEPTION 'Still here: %', still_here;
    END IF;

    SELECT array_agg(c.relname::TEXT ORDER BY c.relname) INTO now_tables
      FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace
     WHERE ns.nspname = current_schema() AND c.relkind IN ('r', 'p');
    SELECT array_agg(name ORDER BY name) INTO kept_tables
      FROM laravel_leftovers_before
     WHERE name <> ALL (gone);
    IF now_tables IS DISTINCT FROM kept_tables THEN
        RAISE EXCEPTION 'The tables are not the ones that were here less the six: % before, % now', kept_tables, now_tables;
    END IF;

    FOR other IN SELECT name, row_count FROM laravel_leftovers_before WHERE name <> ALL (gone) ORDER BY name LOOP
        EXECUTE format('SELECT count(*) FROM %I', other.name) INTO n;
        IF n < other.row_count OR (n > other.row_count AND other.name <> 'anotoki_audit_log') THEN
            changed := concat_ws(', ', changed, format('%s (%s before, %s now)', other.name, other.row_count, n));
        END IF;
    END LOOP;
    IF changed IS NOT NULL THEN
        RAISE EXCEPTION 'Tables whose rows changed: %', changed;
    END IF;
END $$;
