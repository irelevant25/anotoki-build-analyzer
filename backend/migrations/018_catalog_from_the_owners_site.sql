-- ============================================================================
-- 018_catalog_from_the_owners_site.sql - the catalog from genshin.anotoki.eu
-- ============================================================================
-- Genshin Impact's catalog comes from the owner's own genshin.anotoki.eu from
-- now on (the owner's decision of 2026-10-20, docs/decisions.md: its public
-- catalog lists, App\Services\Catalog\Providers\GenshinCatalog), no longer from
-- a third-party game data provider. That site knows more of a character, which
-- the builds browser filters by:
--
--   region         the character's region ("Liyue"), none for some
--   version        the game version the character came with, as the game
--                  numbers it ("6.1")
--   version_name   the name the game gave that version, where it gave one
--                  ("Luna II")
--
-- The old provider's icon addresses are cleared, so nothing is fetched from it
-- any more (the next refresh writes the new site's), and where each list stood
-- with it - its refresh state, the imports' claims to refresh one - is
-- forgotten: the first refresh reads every list in full.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations),
-- then Refresh catalog on the Builds page. On this computer the same, or
-- `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

ALTER TABLE game_characters ADD COLUMN region VARCHAR(50);
ALTER TABLE game_characters ADD COLUMN version VARCHAR(10);
ALTER TABLE game_characters ADD COLUMN version_name VARCHAR(40);
ALTER TABLE game_characters ADD CONSTRAINT game_characters_version_check CHECK (version ~ '^[0-9]{1,2}\.[0-9]{1,2}$');

UPDATE game_characters SET icon_url = NULL WHERE icon_url LIKE 'https://gi.yatta.moe/%';
UPDATE game_weapons SET icon_url = NULL WHERE icon_url LIKE 'https://gi.yatta.moe/%';
UPDATE game_sets SET icon_url = NULL WHERE icon_url LIKE 'https://gi.yatta.moe/%';

DELETE FROM cache WHERE key LIKE 'catalog-state:%' OR key LIKE 'catalog-import-refresh:%';

-- ── Verification ────────────────────────────────────────────────────────────
DO $$
DECLARE
    wrong TEXT;
BEGIN
    WITH expected (col, typ) AS (VALUES
        ('region', 'character varying(50)'), ('version', 'character varying(10)'), ('version_name', 'character varying(40)')
    )
    SELECT string_agg(e.col, ', ') INTO wrong
      FROM expected e
     WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.columns c
         WHERE c.table_schema = current_schema() AND c.table_name = 'game_characters' AND c.column_name = e.col
           AND c.data_type || '(' || c.character_maximum_length || ')' = e.typ AND c.is_nullable = 'YES'
     );
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Columns of game_characters missing or not as 018 makes them: %', wrong;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'game_characters_version_check' AND contype = 'c') THEN
        RAISE EXCEPTION 'The check of a character''s version is missing';
    END IF;

    SELECT string_agg(t, ', ') INTO wrong FROM (
        SELECT 'game_characters' AS t WHERE EXISTS (SELECT 1 FROM game_characters WHERE icon_url LIKE 'https://gi.yatta.moe/%')
        UNION ALL SELECT 'game_weapons' WHERE EXISTS (SELECT 1 FROM game_weapons WHERE icon_url LIKE 'https://gi.yatta.moe/%')
        UNION ALL SELECT 'game_sets' WHERE EXISTS (SELECT 1 FROM game_sets WHERE icon_url LIKE 'https://gi.yatta.moe/%')
    ) AS old;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Icons of the old provider are still there: %', wrong;
    END IF;

    IF EXISTS (SELECT 1 FROM cache WHERE key LIKE 'catalog-state:%' OR key LIKE 'catalog-import-refresh:%') THEN
        RAISE EXCEPTION 'The old catalog state is still there';
    END IF;
END $$;
