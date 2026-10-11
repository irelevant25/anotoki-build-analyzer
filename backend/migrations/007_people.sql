-- ============================================================================
-- 007_people.sql - the people the IAM signed in here, as the anotoki library keeps them
-- ============================================================================
-- Version 0.4 (anotoki-lib 0.4.1, its people module - the owner's decision of
-- 2026-10-07). The library keeps this site's own `users` table as it is - one
-- row per person the anotoki IAM signed in here, the IAM's id as its id, and
-- the visitors' rows with negative ids, which it never reads or writes - and
-- adds the one column every anotoki site adds:
--
--   anonymized_at TIMESTAMPTZ   when the IAM deleted the account (thirty days
--                               after its owner asked). From then on the row is
--                               nobody's: its name is the IAM's placeholder
--                               deleted-{id}, a sync's log says "a deleted
--                               person" where it named them, and their game
--                               accounts and imports stay, nobody's now (the
--                               owner's rule: the data stays). Written by the
--                               library's scrub only - after an answer, at most
--                               once an hour - and NULL for everybody until then.
--
-- Nothing else changes, and no row: a nullable column without a default. The
-- library's own two tables (where the scrub stands, and the ids it scrubbed)
-- come with its migration set anotoki_people, which runs after this site's
-- files; until both are there the scrub does nothing. A person's last_seen_at,
-- which only visitors had, is written from now on too (by the library, at most
-- every five minutes): users_visitor_check has always allowed it.
--
-- Applying it: on the host, upload the new version and press Apply pending on
-- the admin panel's Migrations page at once, signed in as an ADMIN
-- (/admin/migrations); every file waiting - the library's anotoki_admin first,
-- then this site's, then the library's translations and people sets - goes in
-- one Apply, in that order, after a backup of the database as it was. Until
-- then the site tells everybody else that it is being updated. On this
-- computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

-- What there is now, for the gate below.
CREATE TEMPORARY TABLE people_before ON COMMIT DROP AS
SELECT count(*) AS everybody,
       count(*) FILTER (WHERE id > 0) AS people,
       count(*) FILTER (WHERE id < 0) AS visitors,
       COALESCE(sum(length(name)), 0) AS names
  FROM users;

ALTER TABLE users ADD COLUMN IF NOT EXISTS anonymized_at TIMESTAMPTZ;

-- ============================================================================
-- PROVE IT
--
-- The column is there, a TIMESTAMPTZ that may be NULL, and NULL on every row;
-- the rows are the rows that were here - as many people, as many visitors,
-- their names as they were - and the rule that holds people and visitors apart
-- is still there.
-- ============================================================================
DO $$
DECLARE
    before RECORD;
    found TEXT;
BEGIN
    SELECT format_type(a.atttypid, a.atttypmod) || CASE WHEN a.attnotnull THEN ' NOT NULL' ELSE '' END INTO found
      FROM pg_attribute a
     WHERE a.attrelid = 'users'::regclass AND a.attname = 'anonymized_at' AND NOT a.attisdropped;
    IF found IS DISTINCT FROM 'timestamp with time zone' THEN
        RAISE EXCEPTION 'users.anonymized_at is %, not a TIMESTAMPTZ that may be NULL', COALESCE(found, 'missing');
    END IF;

    IF EXISTS (SELECT 1 FROM users WHERE anonymized_at IS NOT NULL) THEN
        RAISE EXCEPTION 'A row of users says its account was deleted at the IAM before the scrub ever ran';
    END IF;

    SELECT * INTO before FROM people_before;
    IF (SELECT count(*) FROM users) <> before.everybody
       OR (SELECT count(*) FROM users WHERE id > 0) <> before.people
       OR (SELECT count(*) FROM users WHERE id < 0) <> before.visitors
       OR (SELECT COALESCE(sum(length(name)), 0) FROM users) <> before.names THEN
        RAISE EXCEPTION 'The rows of users are not the ones that were here: % (% people, % visitors) before',
            before.everybody, before.people, before.visitors;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'users'::regclass AND conname = 'users_visitor_check' AND contype = 'c') THEN
        RAISE EXCEPTION 'The constraint users_visitor_check is missing';
    END IF;
END $$;
