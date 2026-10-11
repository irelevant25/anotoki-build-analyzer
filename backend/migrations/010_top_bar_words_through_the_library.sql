-- ============================================================================
-- 010_top_bar_words_through_the_library.sql - the top bar's words under the library's keys
-- ============================================================================
-- Version 0.4 (the end of the owner's round of 2026-10-07: the site's own pages
-- on the anotoki family's kit, and Angular Material gone). The top bar is the
-- family's own now (anotoki-lib's <anotoki-top-bar>), with its theme switch and
-- its menus: they say the library's words, under its namespace
-- (anotoki.topbar.*, anotoki.theme.*, which its set anotoki_translations writes
-- in its 002 - after this site's files on a new installation, before them on
-- one installed with version 0.3). Twelve of them this site had under keys of
-- its own, for the bar it drew itself; they move here under the library's
-- keys, with every string they have - in every language, reworded or not, with
-- who saved it and when - so the bar keeps saying what it said (the library
-- writes a string only for a key of its own that has none):
--
--   topbar.phoneMenu   -> anotoki.topbar.phoneMenu
--   topbar.menuButton  -> anotoki.topbar.menuButton    ({name} both)
--   topbar.menu        -> anotoki.topbar.menu
--   topbar.roleAdmin   -> anotoki.topbar.roleAdmin     (Slovak "Správca" - the library's says "Administrátor")
--   topbar.roleEditor  -> anotoki.topbar.roleEditor
--   topbar.account     -> anotoki.topbar.account
--   topbar.admin       -> anotoki.topbar.admin
--   common.signOut     -> anotoki.topbar.signOut
--   theme.label        -> anotoki.theme.label
--   theme.light        -> anotoki.theme.light
--   theme.dark         -> anotoki.theme.dark
--   theme.system       -> anotoki.theme.auto           ("System" - the library's says "Automatic")
--
-- One more is copied and stays the site's too: common.signIn, the start page's
-- and the visitors' note's Sign in, goes under anotoki.topbar.signIn, the
-- bar's, so the two say the same.
--
-- Where the library's 002 ran first and wrote its own words there, the site's
-- take their place - only where the library's words are there as it wrote them:
-- an owner's rewording of a library key is kept. The twelve keys go, with their
-- strings; so do two words of the import wizard's row of steps that only Angular
-- Material's stepper said (stepper.optional: a step that may be skipped;
-- stepper.editable: a step done that can be gone back to) - the wizard's own row
-- of steps has no step to skip, and says of a step done only that it is done
-- (stepper.completed, which stays). The library keys' descriptions are the
-- library's (its 002 writes them); until then they say so.
--
-- Applying it: with the other files of version 0.4, in one Apply on the admin
-- panel's Migrations page (see 007). Export every language from the admin
-- panel's Translations page first: a copy of what the editors wrote.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

-- What moves: the strings of the thirteen keys as they are now - every language, with who saved each and when -
-- the library's own words for each key and language, as its 002 writes them, and whether the site's key stays.
-- Gone at the end of this file's transaction.
CREATE TEMPORARY TABLE moved_words ON COMMIT DROP AS
SELECT m.own_key, m.library_key, m.stays, t.language_code, t.value, t.updated_at, t.updated_by,
       CASE t.language_code WHEN 'en' THEN m.library_en WHEN 'sk' THEN m.library_sk END AS library_value
  FROM (VALUES
    ('topbar.phoneMenu', 'anotoki.topbar.phoneMenu', FALSE, 'Menu', 'Ponuka'),
    ('topbar.menuButton', 'anotoki.topbar.menuButton', FALSE, 'Your menu: {name}', 'Tvoja ponuka: {name}'),
    ('topbar.menu', 'anotoki.topbar.menu', FALSE, 'Your menu', 'Tvoja ponuka'),
    ('topbar.roleAdmin', 'anotoki.topbar.roleAdmin', FALSE, 'Administrator', 'Administrátor'),
    ('topbar.roleEditor', 'anotoki.topbar.roleEditor', FALSE, 'Editor', 'Editor'),
    ('topbar.account', 'anotoki.topbar.account', FALSE, 'Your anotoki account', 'Tvoj anotoki účet'),
    ('topbar.admin', 'anotoki.topbar.admin', FALSE, 'Admin panel', 'Administrátorský panel'),
    ('common.signOut', 'anotoki.topbar.signOut', FALSE, 'Sign out', 'Odhlásiť sa'),
    ('theme.label', 'anotoki.theme.label', FALSE, 'Theme', 'Vzhľad'),
    ('theme.light', 'anotoki.theme.light', FALSE, 'Light', 'Svetlý'),
    ('theme.dark', 'anotoki.theme.dark', FALSE, 'Dark', 'Tmavý'),
    ('theme.system', 'anotoki.theme.auto', FALSE, 'Automatic', 'Automaticky'),
    ('common.signIn', 'anotoki.topbar.signIn', TRUE, 'Sign in', 'Prihlásiť sa')
  ) AS m(own_key, library_key, stays, library_en, library_sk)
  JOIN translations t ON t.key_name = m.own_key;

-- The library's keys, ahead of the library's own file where it has not run yet: its 002 then writes their
-- descriptions (and no string - they have theirs).
INSERT INTO translation_keys (name, description)
SELECT DISTINCT library_key, 'The anotoki library''s words: anotoki_translations/002 describes them.'
  FROM moved_words
ON CONFLICT (name) DO NOTHING;

-- Every string of the old key under the library's key: the same text, the same writer, the same time...
INSERT INTO translations (key_name, language_code, value, updated_at, updated_by)
SELECT library_key, language_code, value, updated_at, updated_by
  FROM moved_words
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ...also where the library's 002 wrote its own words there first - but never over an owner's rewording.
UPDATE translations t
   SET value = w.value, updated_at = w.updated_at, updated_by = w.updated_by
  FROM moved_words w
 WHERE t.key_name = w.library_key AND t.language_code = w.language_code
   AND t.value = w.library_value AND t.value <> w.value;

-- The twelve keys whose words moved go, and the stepper's two words of its own; their strings with them (the
-- foreign keys cascade). common.signIn and stepper.completed stay.
DELETE FROM translation_keys WHERE name IN (
    'topbar.phoneMenu', 'topbar.menuButton', 'topbar.menu', 'topbar.roleAdmin', 'topbar.roleEditor', 'topbar.account',
    'topbar.admin', 'common.signOut', 'theme.label', 'theme.light', 'theme.dark', 'theme.system',
    'stepper.optional', 'stepper.editable'
);

-- Descriptions that named what is gone (Angular Material's stepper, the bar's own Sign in).
UPDATE translation_keys SET description = 'The import guide''s row of steps, for screen readers only: said after the name of a step that is done (each step done is a button back to it). No placeholders.'
 WHERE name = 'stepper.completed';
UPDATE translation_keys SET description = 'The button that goes to the sign-in: on the start page for somebody new, and in the note to somebody who uses the site without signing in. (The top bar''s own Sign in is the anotoki library''s anotoki.topbar.signIn, which 010 gave these words.) No placeholders.'
 WHERE name = 'common.signIn';

-- ============================================================================
-- PROVE IT
--
-- Every string the thirteen keys had is under the library's key, as it was: the
-- same text in the same language, by the same person, from the same moment -
-- or an owner's rewording of the library's key is there, which this file does
-- not write over; never the library's own words where the site had its own.
-- The library's keys read in English. The fourteen keys are gone with their
-- strings; common.signIn and stepper.completed are there. And the site's own keys - the library's, under anotoki., are never
-- counted here - are exactly as many as 009 left, less the fourteen that went
-- (447 - 14).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 433;
    library_keys CONSTANT TEXT[] := ARRAY['anotoki.topbar.phoneMenu', 'anotoki.topbar.menuButton', 'anotoki.topbar.menu',
        'anotoki.topbar.roleAdmin', 'anotoki.topbar.roleEditor', 'anotoki.topbar.account', 'anotoki.topbar.admin',
        'anotoki.topbar.signOut', 'anotoki.topbar.signIn', 'anotoki.theme.label', 'anotoki.theme.light',
        'anotoki.theme.dark', 'anotoki.theme.auto'];
    gone_keys CONSTANT TEXT[] := ARRAY['topbar.phoneMenu', 'topbar.menuButton', 'topbar.menu', 'topbar.roleAdmin',
        'topbar.roleEditor', 'topbar.account', 'topbar.admin', 'common.signOut', 'theme.label', 'theme.light',
        'theme.dark', 'theme.system', 'stepper.optional', 'stepper.editable'];
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT count(DISTINCT library_key) INTO found FROM moved_words WHERE language_code = 'en';
    IF found <> 13 THEN
        RAISE EXCEPTION 'The thirteen keys had % English strings to move; each has one', found;
    END IF;

    -- Moved as it was - or an owner's rewording of the library's key kept; never the library's words in its place.
    SELECT string_agg(w.library_key || ' (' || w.language_code || ')', ', ' ORDER BY w.library_key, w.language_code) INTO wrong
      FROM moved_words w
      LEFT JOIN translations t ON t.key_name = w.library_key AND t.language_code = w.language_code
     WHERE t.key_name IS NULL
        OR (t.value IS DISTINCT FROM w.value AND t.value = w.library_value)
        OR (t.value = w.value AND (t.updated_at IS DISTINCT FROM w.updated_at OR t.updated_by IS DISTINCT FROM w.updated_by)
            AND t.value <> w.library_value);
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Strings that did not move under the library''s keys as they were: %', wrong;
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO wrong
      FROM unnest(library_keys) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Library keys without an English string: %', wrong;
    END IF;

    SELECT string_agg(name, ', ' ORDER BY name) INTO wrong
      FROM translation_keys
     WHERE name = ANY (gone_keys);
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys this file takes away are still there: %', wrong;
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO wrong
      FROM (VALUES ('common.signIn'), ('stepper.completed')) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name);
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys that stay are gone: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 009 with this file leave %', found, expected_keys;
    END IF;
END $$;
