-- ============================================================================
-- 014_star_rail_removed.sql - the site offers Genshin Impact alone
-- ============================================================================
-- Honkai: Star Rail goes (the owner's decision of 2026-10-20, docs/decisions.md:
-- Genshin only for now, the code kept ready for another game). Every row of
-- the other game is deleted - its game accounts with everything in them (the
-- foreign keys cascade: characters, weapons, relics, import runs), its failed
-- import attempts that found no account, its build sources with their builds
-- and sync runs, its catalog runs and its catalog - and the closed sets that
-- listed it are narrowed to what the code knows: the games of App\Enums\Game
-- (genshin) and the kinds of sets of Game::setKinds() (artifact). A game added
-- later widens them in a migration of its own.
--
-- The site had one user when this was decided, the owner, whose own Star Rail
-- account goes with it; the backup made before an update keeps the database as
-- it was (only offline: a backup of the older schema is not restored in place).
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- Until it is applied the site tells everybody else that it is being updated.
-- On this computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

-- What must not change: every row of Genshin Impact, and the people.
CREATE TEMPORARY TABLE genshin_rows ON COMMIT DROP AS
SELECT 'game_characters' AS what, count(*) AS rows FROM game_characters WHERE game = 'genshin'
UNION ALL SELECT 'game_weapons', count(*) FROM game_weapons WHERE game = 'genshin'
UNION ALL SELECT 'game_sets', count(*) FROM game_sets WHERE game = 'genshin'
UNION ALL SELECT 'accounts', count(*) FROM accounts WHERE game = 'genshin'
UNION ALL SELECT 'import_runs', count(*) FROM import_runs WHERE game = 'genshin'
UNION ALL SELECT 'build_sources', count(*) FROM build_sources WHERE game = 'genshin'
UNION ALL SELECT 'sync_runs', count(*) FROM sync_runs WHERE game IS DISTINCT FROM 'hsr'
UNION ALL SELECT 'account_characters', count(*) FROM account_characters c JOIN accounts a ON a.id = c.account_id WHERE a.game = 'genshin'
UNION ALL SELECT 'account_weapons', count(*) FROM account_weapons w JOIN accounts a ON a.id = w.account_id WHERE a.game = 'genshin'
UNION ALL SELECT 'account_items', count(*) FROM account_items i JOIN accounts a ON a.id = i.account_id WHERE a.game = 'genshin'
UNION ALL SELECT 'builds', count(*) FROM builds b JOIN build_sources s ON s.id = b.build_source_id WHERE s.game = 'genshin'
UNION ALL SELECT 'users', count(*) FROM users;

-- The other game's rows. Its accounts first (their characters, weapons, items and
-- import runs go with them), then what no account holds.
DELETE FROM accounts WHERE game = 'hsr';
DELETE FROM import_runs WHERE game = 'hsr';
DELETE FROM sync_runs WHERE game = 'hsr';
DELETE FROM build_sources WHERE game = 'hsr';
DELETE FROM game_characters WHERE game = 'hsr';
DELETE FROM game_weapons WHERE game = 'hsr';
DELETE FROM game_sets WHERE game = 'hsr';
DELETE FROM cache WHERE key LIKE 'showcase:hsr:%';

-- The closed sets, narrowed to the code's.
DO $$
DECLARE
    t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['game_characters', 'game_weapons', 'game_sets', 'accounts', 'import_runs', 'build_sources', 'sync_runs'] LOOP
        EXECUTE format('ALTER TABLE %I DROP CONSTRAINT %I', t, t || '_game_check');
        EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I CHECK (game IN (''genshin''))', t, t || '_game_check');
    END LOOP;
END $$;

ALTER TABLE game_sets DROP CONSTRAINT game_sets_kind_check;
ALTER TABLE game_sets ADD CONSTRAINT game_sets_kind_check CHECK (kind IN ('artifact'));

-- ============================================================================
-- PROVE IT
--
-- No row of another game and no set of another kind is left, every row of
-- Genshin Impact and every person is still there, and each of the eight
-- constraints names what the code knows and nothing of the other game.
-- ============================================================================
DO $$
DECLARE
    t TEXT;
    found BIGINT;
    wrong TEXT;
    definition TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY['game_characters', 'game_weapons', 'game_sets', 'accounts', 'import_runs', 'build_sources', 'sync_runs'] LOOP
        EXECUTE format('SELECT count(*) FROM %I WHERE game IS NOT NULL AND game <> ''genshin''', t) INTO found;
        IF found <> 0 THEN
            RAISE EXCEPTION '% rows of another game are still in %', found, t;
        END IF;

        SELECT pg_get_constraintdef(oid) INTO definition FROM pg_constraint WHERE conname = t || '_game_check';
        IF definition IS NULL OR definition NOT LIKE '%''genshin''%' OR definition LIKE '%hsr%' THEN
            RAISE EXCEPTION 'The constraint %_game_check reads %', t, coalesce(definition, '(nothing: it is missing)');
        END IF;
    END LOOP;

    SELECT count(*) INTO found FROM game_sets WHERE kind <> 'artifact';
    IF found <> 0 THEN
        RAISE EXCEPTION '% sets of another kind are still there', found;
    END IF;
    SELECT pg_get_constraintdef(oid) INTO definition FROM pg_constraint WHERE conname = 'game_sets_kind_check';
    IF definition IS NULL OR definition NOT LIKE '%''artifact''%' OR definition LIKE '%relic%' OR definition LIKE '%planar%' THEN
        RAISE EXCEPTION 'The constraint game_sets_kind_check reads %', coalesce(definition, '(nothing: it is missing)');
    END IF;

    WITH now_there AS (
        SELECT 'game_characters' AS what, count(*) AS rows FROM game_characters
        UNION ALL SELECT 'game_weapons', count(*) FROM game_weapons
        UNION ALL SELECT 'game_sets', count(*) FROM game_sets
        UNION ALL SELECT 'accounts', count(*) FROM accounts
        UNION ALL SELECT 'import_runs', count(*) FROM import_runs
        UNION ALL SELECT 'build_sources', count(*) FROM build_sources
        UNION ALL SELECT 'sync_runs', count(*) FROM sync_runs
        UNION ALL SELECT 'account_characters', count(*) FROM account_characters
        UNION ALL SELECT 'account_weapons', count(*) FROM account_weapons
        UNION ALL SELECT 'account_items', count(*) FROM account_items
        UNION ALL SELECT 'builds', count(*) FROM builds
        UNION ALL SELECT 'users', count(*) FROM users
    )
    SELECT string_agg(b.what || ' ' || b.rows || ' -> ' || coalesce(n.rows::TEXT, '?'), ', ' ORDER BY b.what COLLATE "C") INTO wrong
      FROM genshin_rows b
      LEFT JOIN now_there n ON n.what = b.what
     WHERE n.rows IS DISTINCT FROM b.rows;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Rows of Genshin Impact or people changed: %', wrong;
    END IF;
END $$;
