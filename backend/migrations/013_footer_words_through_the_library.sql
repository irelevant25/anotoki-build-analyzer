-- ============================================================================
-- 013_footer_words_through_the_library.sql - the footer's words under the library's keys
-- ============================================================================
-- anotoki-lib 0.6.3 (the owner's choice of 2026-10-18: "I like the footer of
-- builder the most. The order, texts and the top border."): the foot of every
-- page, and of the admin panel, is the anotoki family's footer now
-- (<anotoki-site-footer>, @anotoki/lib/footer) - the library's copy of this
-- site's own. It says the library's words, under its namespace
-- (anotoki.footer.*, which its set anotoki_translations writes in its 012,
-- after this site's files in the same Apply). Three of them this site had under
-- keys of its own, for the footer it drew itself; they move here under the
-- library's keys, with every string they have - in every language, reworded or
-- not, with who saved it and when - so the footer keeps saying what it said
-- (the library writes a string only for a key of its own that has none):
--
--   footer.version   -> anotoki.footer.version        ({version} both)
--   footer.feedback  -> anotoki.footer.feedback
--   footer.fanMade   -> anotoki.footer.fanHoyoverse
--
-- One more is copied and stays the site's too: whatsNew.title, the What's new
-- dialog's title (shared/changelog-dialog), which the footer's button said as
-- well, goes under anotoki.footer.whatsNew, the footer's, so the two say the
-- same.
--
-- Where the library's 012 ran first and wrote its own words there, the site's
-- take their place - only where the library's words are there as it wrote
-- them: an owner's rewording of a library key is kept. The three keys go, with
-- their strings; the two descriptions that named them say what reads the words
-- now. The library keys' descriptions are the library's (its 012 writes them);
-- until then they say so.
--
-- Applying it: on the admin panel's Migrations page at once after the upload -
-- one Apply runs it and the library's anotoki_translations/012.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

-- What moves: the strings of the four keys as they are now - every language, with who saved each and when - and
-- the library's own words for each key and language, as its 012 writes them. Gone at the end of this file's
-- transaction.
CREATE TEMPORARY TABLE moved_words ON COMMIT DROP AS
SELECT m.own_key, m.library_key, t.language_code, t.value, t.updated_at, t.updated_by,
       CASE t.language_code WHEN 'en' THEN m.library_en WHEN 'sk' THEN m.library_sk END AS library_value
  FROM (VALUES
    ('footer.version', 'anotoki.footer.version', 'Version {version}', 'Verzia {version}'),
    ('footer.feedback', 'anotoki.footer.feedback', 'Feedback', 'Spätná väzba'),
    ('footer.fanMade', 'anotoki.footer.fanHoyoverse', 'This site is a fan-made project and is not affiliated with or endorsed by HoYoverse.',
        'Táto stránka je fanúšikovský projekt a nie je prepojená so spoločnosťou HoYoverse ani ňou schválená.'),
    ('whatsNew.title', 'anotoki.footer.whatsNew', 'What''s new', 'Čo je nové')
  ) AS m(own_key, library_key, library_en, library_sk)
  JOIN translations t ON t.key_name = m.own_key;

-- The library's keys, ahead of the library's own file where it has not run yet: its 012 then writes their
-- descriptions (and no string - they have theirs).
INSERT INTO translation_keys (name, description)
SELECT DISTINCT library_key, 'The anotoki library''s words: anotoki_translations/012 describes them.'
  FROM moved_words
ON CONFLICT (name) DO NOTHING;

-- Every string of the old key under the library's key: the same text, the same writer, the same time...
INSERT INTO translations (key_name, language_code, value, updated_at, updated_by)
SELECT library_key, language_code, value, updated_at, updated_by
  FROM moved_words
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ...also where the library's 012 wrote its own words there first - but never over an owner's rewording.
UPDATE translations t
   SET value = w.value, updated_at = w.updated_at, updated_by = w.updated_by
  FROM moved_words w
 WHERE t.key_name = w.library_key AND t.language_code = w.language_code
   AND t.value = w.library_value AND t.value <> w.value;

-- The three keys whose words moved go, their strings with them (the foreign keys cascade). whatsNew.title stays.
DELETE FROM translation_keys WHERE name IN ('footer.version', 'footer.feedback', 'footer.fanMade');

-- Descriptions that named what is gone, or told of the footer's button as the site's.
UPDATE translation_keys SET description = 'The What''s-new dialog: its title. (The button at the foot of every page that opens it is the anotoki library''s footer''s, anotoki.footer.whatsNew, which 013 gave these words.) The release notes inside the dialog are not strings: they stay in English. No placeholders.'
 WHERE name = 'whatsNew.title';
UPDATE translation_keys SET description = 'Terms of use and Contact: the fan clause, read in place of the library''s anotoki.legal.fanHoyoverse, whose second sentence names Genshin Impact alone - this site is about Honkai: Star Rail too. HoYoverse and the game names are never translated. The footer says its first sentence alone (the library''s anotoki.footer.fanHoyoverse). No placeholders.'
 WHERE name = 'legal.fanClause';

-- ============================================================================
-- PROVE IT
--
-- Every string the four keys had is under the library's key, as it was: the
-- same text in the same language, by the same person, from the same moment -
-- or an owner's rewording of the library's key is there, which this file does
-- not write over; never the library's own words where the site had its own.
-- The library's keys read in English. The three keys are gone with their
-- strings; whatsNew.title is there. And the site's own keys - the library's,
-- under anotoki., are never counted here - are exactly as many as 012 left,
-- less the three that went (486 - 3).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 483;
    library_keys CONSTANT TEXT[] := ARRAY['anotoki.footer.version', 'anotoki.footer.feedback', 'anotoki.footer.fanHoyoverse',
        'anotoki.footer.whatsNew'];
    gone_keys CONSTANT TEXT[] := ARRAY['footer.version', 'footer.feedback', 'footer.fanMade'];
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT count(DISTINCT library_key) INTO found FROM moved_words WHERE language_code = 'en';
    IF found <> 4 THEN
        RAISE EXCEPTION 'The four keys had % English strings to move; each has one', found;
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

    IF NOT EXISTS (SELECT 1 FROM translation_keys WHERE name = 'whatsNew.title') THEN
        RAISE EXCEPTION 'whatsNew.title, the What''s new dialog''s title, is gone';
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 012 with this file leave %', found, expected_keys;
    END IF;
END $$;
