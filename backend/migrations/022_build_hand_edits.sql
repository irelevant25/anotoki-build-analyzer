-- ============================================================================
-- 022_build_hand_edits.sql - builds edited by hand, beside what the file said
-- ============================================================================
-- Version 0.7.0 (the owner's round of 2026-10-22, task 9, docs/decisions.md):
-- editors change builds by hand in the admin panel, write the site's own notes
-- on a build and its teams, make builds of their own and remove others - and a
-- later import of a builds file keeps all of it, asking per build only where
-- the file and an edit changed the same part (docs/builds.md, "Hand edits").
--
--   builds.file_build   JSON   the build as the last builds file brought it -
--                              its role, qualifier, min_rank, position and
--                              content, the columns' JSON as stored -: what an
--                              import compares the file's new version and the
--                              edits with, part by part. NULL: a build made
--                              here, which no file carries.
--   builds.site_notes   JSON   the site's notes, written in the editor:
--                              {build: {en, sk}, teams: {"<members' keys,
--                              sorted>": {en, sk}}}, English required, Slovak
--                              optional. NULL: none. Never in a builds file,
--                              never changed by an import.
--   builds.edited_at    TIMESTAMPTZ   the last save in the editor that changed
--                              the build (shown, never compared).
--   builds.checked_at   may be NULL from now on: a build made here was in no
--                              file.
--   build_removals             the builds of a file an editor removed: their
--                              identity, what the file said of them then, their
--                              notes - so an import never brings one back
--                              unasked. No row shares its identity with a build.
--   build_orders               the characters whose builds an editor put in an
--                              order of their own: an import keeps it, the
--                              file's new builds after them, until an editor
--                              gives the order back to the file. Said, never
--                              guessed from the builds' places.
--
-- Every build there is came from a file: its file_build is its columns as they
-- are, so the first import after this one compares as the last one did.
-- Nothing else changes: no build's content, place or content hash.
--
-- Applying it: upload the new version and press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- On this computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

-- The builds as they are, for the gate below.
CREATE TEMPORARY TABLE builds_before_022 ON COMMIT DROP AS
SELECT id, content_hash, "position" FROM builds;

ALTER TABLE builds ADD COLUMN IF NOT EXISTS file_build JSON;
ALTER TABLE builds ADD COLUMN IF NOT EXISTS site_notes JSON;
ALTER TABLE builds ADD COLUMN IF NOT EXISTS edited_at TIMESTAMPTZ;
ALTER TABLE builds ALTER COLUMN checked_at DROP NOT NULL;

CREATE TABLE build_removals (
    id                  BIGSERIAL,
    game_character_id   BIGINT          NOT NULL,
    role                VARCHAR(16)     NOT NULL,
    qualifier           VARCHAR(40)     NOT NULL DEFAULT '',
    min_rank            SMALLINT        NOT NULL DEFAULT 0,
    file_build          JSON            NOT NULL,
    site_notes          JSON,
    removed_at          TIMESTAMPTZ     NOT NULL,
    CONSTRAINT build_removals_pkey PRIMARY KEY (id),
    CONSTRAINT build_removals_game_character_id_foreign FOREIGN KEY (game_character_id) REFERENCES game_characters (id) ON DELETE CASCADE,
    CONSTRAINT build_removals_role_check CHECK (role IN ('main_dps', 'sub_dps', 'support')),
    CONSTRAINT build_removals_qualifier_check CHECK (qualifier = btrim(qualifier)),
    CONSTRAINT build_removals_min_rank_check CHECK (min_rank BETWEEN 0 AND 6)
);
-- A build's identity, as builds_identity_unique has it (016).
CREATE UNIQUE INDEX build_removals_identity_unique ON build_removals (game_character_id, role, lower(qualifier), min_rank);

CREATE TABLE build_orders (
    game_character_id   BIGINT          NOT NULL,
    ordered_at          TIMESTAMPTZ     NOT NULL,
    CONSTRAINT build_orders_pkey PRIMARY KEY (game_character_id),
    CONSTRAINT build_orders_game_character_id_foreign FOREIGN KEY (game_character_id) REFERENCES game_characters (id) ON DELETE CASCADE
);

UPDATE builds
   SET file_build = json_build_object(
        'role', role, 'qualifier', qualifier, 'min_rank', min_rank, 'position', "position",
        'weapons', weapons, 'sets', sets, 'main_stats', main_stats, 'substats', substats,
        'talent_priority', talent_priority, 'stat_targets', stat_targets, 'teams', teams,
        'notes', notes, 'game_version', game_version)
 WHERE file_build IS NULL;

-- ============================================================================
-- PROVE IT
--
-- The three columns are there, each a JSON or a time that may be NULL and has
-- no default, and checked_at may be NULL; the removals' and the orders' tables
-- are there with their columns, the removals' identity index too; every build
-- has its columns as its file's version, no notes and no edit yet, and no
-- character an order of an editor's; the builds are the builds that were here,
-- each in its place with the content hash it had; and a build's identity is
-- still there once.
-- ============================================================================
DO $$
DECLARE
    wrong TEXT;
    changed BIGINT;
BEGIN
    WITH expected (tbl, col, typ) AS (VALUES
        ('builds', 'file_build', 'json'), ('builds', 'site_notes', 'json'),
        ('builds', 'edited_at', 'timestamp with time zone'), ('builds', 'checked_at', 'timestamp with time zone'),
        ('build_removals', 'id', 'bigint NOT NULL DEFAULT'), ('build_removals', 'game_character_id', 'bigint NOT NULL'),
        ('build_removals', 'role', 'character varying(16) NOT NULL'),
        ('build_removals', 'qualifier', 'character varying(40) NOT NULL DEFAULT'),
        ('build_removals', 'min_rank', 'smallint NOT NULL DEFAULT'), ('build_removals', 'file_build', 'json NOT NULL'),
        ('build_removals', 'site_notes', 'json'), ('build_removals', 'removed_at', 'timestamp with time zone NOT NULL'),
        ('build_orders', 'game_character_id', 'bigint NOT NULL'), ('build_orders', 'ordered_at', 'timestamp with time zone NOT NULL')
    ), found AS (
        SELECT c.relname::text AS tbl, a.attname::text AS col,
               format_type(a.atttypid, a.atttypmod) || CASE WHEN a.attnotnull THEN ' NOT NULL' ELSE '' END ||
               CASE WHEN a.atthasdef THEN ' DEFAULT' ELSE '' END AS typ
          FROM pg_attribute a
          JOIN pg_class c ON c.oid = a.attrelid
          JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = current_schema()
         WHERE c.relname IN ('builds', 'build_removals', 'build_orders') AND c.relkind = 'r' AND a.attnum > 0 AND NOT a.attisdropped
    )
    SELECT string_agg(e.tbl || '.' || e.col || ' is ' || COALESCE(f.typ, 'missing') || ', not ' || e.typ, '; ' ORDER BY e.tbl COLLATE "C", e.col COLLATE "C") INTO wrong
      FROM expected e LEFT JOIN found f ON f.tbl = e.tbl AND f.col = e.col
     WHERE f.typ IS DISTINCT FROM e.typ;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Columns not as this migration makes them: %', wrong;
    END IF;

    IF to_regclass('build_removals_identity_unique') IS NULL OR to_regclass('build_orders_pkey') IS NULL THEN
        RAISE EXCEPTION 'The index build_removals_identity_unique or build_orders_pkey is missing';
    END IF;

    -- As text: the same expression writes the same text, and a text the stored JSON holds (a NUL escaped) cannot
    -- make a cast to jsonb refuse it.
    SELECT count(*) INTO changed
      FROM builds
     WHERE file_build IS NULL OR site_notes IS NOT NULL OR edited_at IS NOT NULL
        OR file_build::text IS DISTINCT FROM json_build_object(
            'role', role, 'qualifier', qualifier, 'min_rank', min_rank, 'position', "position",
            'weapons', weapons, 'sets', sets, 'main_stats', main_stats, 'substats', substats,
            'talent_priority', talent_priority, 'stat_targets', stat_targets, 'teams', teams,
            'notes', notes, 'game_version', game_version)::text;
    IF changed > 0 THEN
        RAISE EXCEPTION '% builds have no file''s version as they are, or notes or an edit already', changed;
    END IF;

    SELECT count(*) INTO changed
      FROM builds_before_022 b FULL JOIN builds n ON n.id = b.id
     WHERE b.id IS NULL OR n.id IS NULL OR n.content_hash IS DISTINCT FROM b.content_hash OR n."position" IS DISTINCT FROM b."position";
    IF changed > 0 THEN
        RAISE EXCEPTION 'The builds are not the ones that were here: % of them came, went, moved or changed their content hash', changed;
    END IF;

    IF EXISTS (SELECT 1 FROM build_removals) OR EXISTS (SELECT 1 FROM build_orders) THEN
        RAISE EXCEPTION 'A build is removed, or a character''s builds ordered, before anybody could';
    END IF;

    IF to_regclass('builds_identity_unique') IS NULL THEN
        RAISE EXCEPTION 'The index builds_identity_unique is missing';
    END IF;
END $$;
