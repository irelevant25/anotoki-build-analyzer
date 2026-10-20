-- ============================================================================
-- 016_builds_without_sources.sql - a character's builds as variations, brought in from a file
-- ============================================================================
-- The site no longer syncs builds (the owner's decision of 2026-10-20,
-- docs/decisions.md): they are put together elsewhere and an editor imports
-- them as one file per game on the admin panel's Builds page. A build is no
-- longer a source's: it is a variation of a character's -
--
--   role        main_dps, sub_dps or support (App\Enums\Game::roles())
--   qualifier   a short word telling two builds of one role apart ("Melt"),
--               empty for most; compared without regard to case
--   min_rank    the constellation the build needs (0-6); a player below it
--               sees the build, marked, and it is never their best
--   position    the character's builds in the file's order: the first is
--               the main one
--
-- (character, role, qualifier, min_rank) is a build's identity: an import
-- keeps the id of a build it finds again, so a link to it (?build=ID) stays.
-- Stat targets can start from a constellation (stat_targets[].from_rank,
-- inside the JSON). checked_at is when the file that last carried the build
-- was made, changed_at when one first carried it as it is now.
--
-- Gone with the syncs: the build sources and their builds, the sync runs (the
-- builds' and the catalog's) and the locks that kept one sync step at a time.
-- None of it is carried over - the site had one user when this was decided,
-- the owner, and the builds come back with the first import. Every import is
-- a row of build_imports: the game, when its file was made, what it did, and
-- who imported it (nobody's once their data is deleted).
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations),
-- and import the builds on the Builds page. Until it is applied the site tells
-- everybody else that it is being updated. On this computer the same, or
-- `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

-- What referred to the sources first, then the sources.
DROP TABLE builds;
DROP TABLE sync_runs;
DROP TABLE build_sources;
DROP TABLE cache_locks;

CREATE TABLE builds (
    id                  BIGSERIAL,
    game_character_id   BIGINT          NOT NULL,
    role                VARCHAR(16)     NOT NULL,
    qualifier           VARCHAR(40)     NOT NULL DEFAULT '',
    min_rank            SMALLINT        NOT NULL DEFAULT 0,
    "position"          SMALLINT        NOT NULL DEFAULT 0,
    weapons             JSON            NOT NULL,
    sets                JSON            NOT NULL,
    main_stats          JSON            NOT NULL,
    substats            JSON            NOT NULL,
    talent_priority     JSON,
    stat_targets        JSON,
    notes               TEXT,
    game_version        VARCHAR(20),
    content_hash        VARCHAR(64)     NOT NULL,
    checked_at          TIMESTAMPTZ     NOT NULL,
    changed_at          TIMESTAMPTZ     NOT NULL,
    created_at          TIMESTAMPTZ     NOT NULL,
    updated_at          TIMESTAMPTZ     NOT NULL,
    CONSTRAINT builds_pkey PRIMARY KEY (id),
    CONSTRAINT builds_game_character_id_foreign FOREIGN KEY (game_character_id) REFERENCES game_characters (id) ON DELETE CASCADE,
    CONSTRAINT builds_role_check CHECK (role IN ('main_dps', 'sub_dps', 'support')),
    CONSTRAINT builds_qualifier_check CHECK (qualifier = btrim(qualifier)),
    CONSTRAINT builds_min_rank_check CHECK (min_rank BETWEEN 0 AND 6),
    CONSTRAINT builds_position_check CHECK ("position" >= 0)
);
-- The host's PostgreSQL 14 has no NULLS NOT DISTINCT: an empty qualifier is '' rather than NULL.
CREATE UNIQUE INDEX builds_identity_unique ON builds (game_character_id, role, lower(qualifier), min_rank);

CREATE TABLE build_imports (
    id                  BIGSERIAL,
    game                VARCHAR(16)     NOT NULL,
    file_exported_at    TIMESTAMPTZ     NOT NULL,
    summary             JSON            NOT NULL,
    imported_by         BIGINT,
    created_at          TIMESTAMPTZ     NOT NULL,
    CONSTRAINT build_imports_pkey PRIMARY KEY (id),
    CONSTRAINT build_imports_game_check CHECK (game IN ('genshin')),
    CONSTRAINT build_imports_imported_by_foreign FOREIGN KEY (imported_by) REFERENCES users (id) ON DELETE SET NULL,
    -- A person, never a visitor (visitors' rows have negative ids).
    CONSTRAINT build_imports_imported_by_check CHECK (imported_by > 0)
);
CREATE INDEX build_imports_game_created_at_index ON build_imports (game, created_at);

-- ── Verification ────────────────────────────────────────────────────────────
DO $$
DECLARE
    wrong TEXT;
BEGIN
    SELECT string_agg(t, ', ' ORDER BY t COLLATE "C") INTO wrong
      FROM unnest(ARRAY['build_sources', 'sync_runs', 'cache_locks']) AS t
     WHERE to_regclass(t) IS NOT NULL;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Tables still there: %', wrong;
    END IF;

    WITH expected (tbl, col, typ, nullable) AS (VALUES
        ('builds', 'id', 'bigint', FALSE), ('builds', 'game_character_id', 'bigint', FALSE),
        ('builds', 'role', 'varchar(16)', FALSE), ('builds', 'qualifier', 'varchar(40)', FALSE),
        ('builds', 'min_rank', 'smallint', FALSE), ('builds', 'position', 'smallint', FALSE),
        ('builds', 'weapons', 'json', FALSE), ('builds', 'sets', 'json', FALSE),
        ('builds', 'main_stats', 'json', FALSE), ('builds', 'substats', 'json', FALSE),
        ('builds', 'talent_priority', 'json', TRUE), ('builds', 'stat_targets', 'json', TRUE),
        ('builds', 'notes', 'text', TRUE), ('builds', 'game_version', 'varchar(20)', TRUE),
        ('builds', 'content_hash', 'varchar(64)', FALSE), ('builds', 'checked_at', 'timestamptz', FALSE),
        ('builds', 'changed_at', 'timestamptz', FALSE), ('builds', 'created_at', 'timestamptz', FALSE),
        ('builds', 'updated_at', 'timestamptz', FALSE),
        ('build_imports', 'id', 'bigint', FALSE), ('build_imports', 'game', 'varchar(16)', FALSE),
        ('build_imports', 'file_exported_at', 'timestamptz', FALSE), ('build_imports', 'summary', 'json', FALSE),
        ('build_imports', 'imported_by', 'bigint', TRUE), ('build_imports', 'created_at', 'timestamptz', FALSE)
    ),
    actual AS (
        SELECT table_name::TEXT AS tbl, column_name::TEXT AS col,
               CASE data_type
                   WHEN 'character varying' THEN 'varchar(' || character_maximum_length || ')'
                   WHEN 'timestamp with time zone' THEN 'timestamptz'
                   ELSE data_type
               END AS typ,
               is_nullable = 'YES' AS nullable
          FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name IN ('builds', 'build_imports')
    )
    SELECT string_agg(COALESCE(e.tbl, a.tbl) || '.' || COALESCE(e.col, a.col), ', ') INTO wrong
      FROM expected e
      FULL JOIN actual a ON a.tbl = e.tbl AND a.col = e.col
     WHERE a.col IS NULL OR e.col IS NULL OR a.typ <> e.typ OR a.nullable <> e.nullable;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'The builds tables are not what 016 makes: %', wrong;
    END IF;

    WITH expected (name, kind, action) AS (VALUES
        ('builds_pkey', 'p', NULL), ('builds_game_character_id_foreign', 'f', 'c'), ('builds_role_check', 'c', NULL),
        ('builds_qualifier_check', 'c', NULL), ('builds_min_rank_check', 'c', NULL), ('builds_position_check', 'c', NULL),
        ('build_imports_pkey', 'p', NULL), ('build_imports_game_check', 'c', NULL),
        ('build_imports_imported_by_foreign', 'f', 'n'), ('build_imports_imported_by_check', 'c', NULL)
    )
    SELECT string_agg(e.name, ', ') INTO wrong
      FROM expected e
     WHERE NOT EXISTS (SELECT 1 FROM pg_constraint c
                        JOIN pg_namespace n ON n.oid = c.connamespace AND n.nspname = current_schema()
                       WHERE c.conname = e.name AND c.contype::TEXT = e.kind
                         AND (e.action IS NULL OR c.confdeltype::TEXT = e.action));
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Constraints missing or not as 016 makes them: %', wrong;
    END IF;

    SELECT string_agg(i, ', ') INTO wrong
      FROM unnest(ARRAY['builds_identity_unique', 'build_imports_game_created_at_index']) AS i
     WHERE to_regclass(i) IS NULL;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Indexes missing: %', wrong;
    END IF;
END $$;
