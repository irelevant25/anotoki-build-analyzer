-- ============================================================================
-- 006_strings_through_the_library.sql - the site's words through the anotoki library
-- ============================================================================
-- The site's words are now read and kept by the anotoki family's library
-- (anotoki-lib 0.3.0, its translations module): the same three tables, the
-- same strings, and the library's code around them - the bundle the pages
-- read, the admin Translations and Languages pages, the checks of a string,
-- the command line. Nothing in these tables changes but two keys.
--
-- The library has words of its own, under its namespace `anotoki.` - its kit's
-- and its status page's - and writes them itself, in its own migration set
-- (`anotoki_translations`, from anotoki/lib, which runs after this site's
-- files). Two of them this site had under keys of its own: the name of the
-- language button in the top bar and what is said when a language could not
-- be loaded. They move here under the library's keys, with every string they
-- have - in every language, reworded or not, with who saved it and when - so
-- that the library, which writes strings only for a key of its own that has
-- none, leaves them exactly as they are; a language whose string an editor
-- cleared on purpose stays cleared:
--
--   language.button     -> anotoki.language.button
--   language.notLoaded  -> anotoki.language.notLoaded
--
-- Their descriptions are the library's (its 002 writes them); until then they
-- say so. Every other key stays where it is, and the site's own top bar keeps
-- its own words (topbar.*, theme.*).
--
-- Applying it: export every language from the admin panel first (Translations,
-- Export - a copy of what the editors wrote), upload the new version, then press
-- Apply pending on the admin panel's Migrations page at once, signed in as an
-- ADMIN (/admin/migrations). Three files wait: this one, then the library's two
-- (anotoki_translations/001_languages_and_strings.sql, which checks the tables
-- and changes nothing here, and 002_library_words.sql, which adds the library's
-- words); one Apply applies all three, in that order. Until then the site tells
-- everybody else that it is being updated. On this computer the same, or
-- `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

-- The strings of the two keys as they are now - every language, with who saved
-- each and when: what moves, and what the gate below holds the move to. Gone at
-- the end of this file's transaction.
CREATE TEMPORARY TABLE moved_words ON COMMIT DROP AS
SELECT m.own_key, m.library_key, t.language_code, t.value, t.updated_at, t.updated_by
  FROM (VALUES
    ('language.button', 'anotoki.language.button'),
    ('language.notLoaded', 'anotoki.language.notLoaded')
  ) AS m(own_key, library_key)
  JOIN translations t ON t.key_name = m.own_key;

-- The library's keys, ahead of the library's own file: its set runs after this
-- one, and then writes their descriptions (and no string - they have theirs).
INSERT INTO translation_keys (name, description)
SELECT DISTINCT library_key, 'The anotoki library''s words: anotoki_translations/002 describes them.'
  FROM moved_words
ON CONFLICT (name) DO NOTHING;

-- Every string of the old key under the library's key: the same text, the same
-- writer, the same time.
INSERT INTO translations (key_name, language_code, value, updated_at, updated_by)
SELECT library_key, language_code, value, updated_at, updated_by
  FROM moved_words
ON CONFLICT (key_name, language_code) DO NOTHING;

-- The old keys go; their strings with them (the foreign keys cascade).
DELETE FROM translation_keys WHERE name IN ('language.button', 'language.notLoaded');

-- ============================================================================
-- PROVE IT
--
-- Every string the two keys had is under the library's key, as it was: the
-- same text in the same language, by the same person, from the same moment -
-- and no string is there that was not. The old keys are gone. Both library
-- keys read in English (what every other language falls back to). And the
-- site's own keys - the library's, under anotoki., are never counted here: the
-- library adds and words its own - are exactly as many as 005 left less the
-- two that moved (452 - 2).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 450;
    moved_keys CONSTANT TEXT[] := ARRAY['anotoki.language.button', 'anotoki.language.notLoaded'];
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT count(*) INTO found FROM moved_words;
    IF found < 2 THEN
        RAISE EXCEPTION 'The two keys had % strings to move; each has an English one at least', found;
    END IF;

    SELECT string_agg(w.library_key || ' (' || w.language_code || ')', ', ' ORDER BY w.library_key, w.language_code) INTO wrong
      FROM moved_words w
     WHERE NOT EXISTS (SELECT 1 FROM translations t
                        WHERE t.key_name = w.library_key AND t.language_code = w.language_code
                          AND t.value = w.value AND t.updated_at = w.updated_at
                          AND t.updated_by IS NOT DISTINCT FROM w.updated_by);
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Strings that did not move under the library''s keys as they were: %', wrong;
    END IF;

    SELECT string_agg(t.key_name || ' (' || t.language_code || ')', ', ' ORDER BY t.key_name, t.language_code) INTO wrong
      FROM translations t
     WHERE t.key_name = ANY (moved_keys)
       AND NOT EXISTS (SELECT 1 FROM moved_words w WHERE w.library_key = t.key_name AND w.language_code = t.language_code);
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Strings under the library''s keys that the site''s keys did not have: %', wrong;
    END IF;

    SELECT string_agg(name, ', ' ORDER BY name) INTO wrong
      FROM translation_keys
     WHERE name IN ('language.button', 'language.notLoaded');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys this file moves are still there: %', wrong;
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO wrong
      FROM unnest(moved_keys) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Library keys without an English string: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 005 with this file leave %', found, expected_keys;
    END IF;
END $$;
