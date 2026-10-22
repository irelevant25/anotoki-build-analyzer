-- ============================================================================
-- 020_build_teams.sql - the teams a build is played in
-- ============================================================================
-- Version 0.6.0 (the owner's round of 2026-10-22, docs/decisions.md): every
-- build lists the teams it is played in - from the builds file, format
-- version 3 (App\Services\Builds\BuildFile, docs/builds.md):
--
--   teams JSON   [{name, members: [{key, name, role, min_rank,
--                alternatives: [{key, name}]}], note}], best first: each team's
--                members in its own order, the build's own character among
--                them, the constellation the team asks of each, who can take a
--                member's place. NULL for a build without teams - never '[]'.
--
-- Nothing else changes, and no row: a nullable column without a default, NULL
-- on every build until the first builds file of version 3 is imported. A
-- build's content hash covers its teams only when it has some, so that import
-- changes only the builds that gained teams.
--
-- Applying it: upload the new version and press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations),
-- then import a builds file of version 3 on the Builds page (a file of version
-- 2 is refused from now on). On this computer the same, or `php migration.php`
-- from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

-- The builds as they are, for the gate below.
CREATE TEMPORARY TABLE builds_before_020 ON COMMIT DROP AS
SELECT id, content_hash FROM builds;

ALTER TABLE builds ADD COLUMN IF NOT EXISTS teams JSON;

-- ============================================================================
-- PROVE IT
--
-- The column is there, a JSON that may be NULL and has no default, and NULL on
-- every row; the builds are the builds that were here, each with the content
-- hash it had; and the rule that a build's identity is there once still holds.
-- ============================================================================
DO $$
DECLARE
    found TEXT;
    changed BIGINT;
BEGIN
    SELECT format_type(a.atttypid, a.atttypmod) || CASE WHEN a.attnotnull THEN ' NOT NULL' ELSE '' END ||
           CASE WHEN a.atthasdef THEN ' DEFAULT' ELSE '' END INTO found
      FROM pg_attribute a
     WHERE a.attrelid = 'builds'::regclass AND a.attname = 'teams' AND NOT a.attisdropped;
    IF found IS DISTINCT FROM 'json' THEN
        RAISE EXCEPTION 'builds.teams is %, not a JSON that may be NULL, without a default', COALESCE(found, 'missing');
    END IF;

    IF EXISTS (SELECT 1 FROM builds WHERE teams IS NOT NULL) THEN
        RAISE EXCEPTION 'A build has teams before any builds file of version 3 was imported';
    END IF;

    SELECT count(*) INTO changed
      FROM builds_before_020 b FULL JOIN builds n ON n.id = b.id
     WHERE b.id IS NULL OR n.id IS NULL OR n.content_hash IS DISTINCT FROM b.content_hash;
    IF changed > 0 THEN
        RAISE EXCEPTION 'The builds are not the ones that were here: % of them came, went or changed their content hash', changed;
    END IF;

    IF to_regclass('builds_identity_unique') IS NULL THEN
        RAISE EXCEPTION 'The index builds_identity_unique is missing';
    END IF;
END $$;
