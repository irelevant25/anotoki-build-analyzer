-- ============================================================================
-- 009_sign_in_and_feedback_words.sql - the sign-in's words under the library's keys, and the feedback form's
-- ============================================================================
-- Version 0.4 (anotoki-lib 0.4.1). Signing in is the anotoki library's now (its
-- auth module): the page shown for a moment while a sign-in is finished, and
-- the screen of a sign-in that came back without one - "Signing in did not
-- work", why, Try again and Back - are the library's, with words of its own
-- under its namespace (anotoki.signIn.*, which its set anotoki_translations
-- writes in 003, after this site's files). Five of them this site had under
-- keys of its own; they move here under the library's keys, with every string
-- they have - in every language, reworded or not, with who saved it and when -
-- so the site keeps saying what it said (the library writes a string only for
-- a key of its own that has none):
--
--   signIn.notCompleted        -> anotoki.signIn.notCompleted
--   signIn.stateMismatch       -> anotoki.signIn.expired
--   signIn.storageUnavailable  -> anotoki.signIn.storageUnavailable
--   signIn.failed              -> anotoki.signIn.failed        ({code} both)
--   signIn.signingIn           -> anotoki.signIn.signingIn
--
-- Where the library's 003 ran first and wrote its own words there, the site's
-- take their place - only where the library's words are there as it wrote
-- them: an owner's rewording of a library key is kept. Three keys of the old
-- sign-in page go, with their strings: its introduction (signIn.intro) and the
-- two ways it said that signing in cannot be offered (signIn.notConfigured,
-- signIn.unreachable) - the library's one screen says that now, in its own
-- words (anotoki.signIn.unavailable). Their descriptions are the library's
-- (its 003 writes them); until then they say so.
--
-- And the visitors' feedback form (the library's too: its words are
-- anotoki.feedback.*, its 004's): five new keys of this site's own - the button
-- at the foot of every page that opens it, and the four places of the site a
-- message can be about (the admin panel's Feedback page shows those by their
-- English names: Dashboard, Import, Accounts, Builds).
--
-- Applying it: with the other files of version 0.4, in one Apply on the admin
-- panel's Migrations page (see 007). Export every language from the admin
-- panel's Translations page first: a copy of what the editors wrote.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

-- What moves: the strings of the five keys as they are now - every language, with who saved each and when -
-- and the library's own words for each key and language, as its 003 writes them. Gone at the end of this file's
-- transaction.
CREATE TEMPORARY TABLE moved_words ON COMMIT DROP AS
SELECT m.own_key, m.library_key, t.language_code, t.value, t.updated_at, t.updated_by,
       CASE t.language_code WHEN 'en' THEN m.library_en WHEN 'sk' THEN m.library_sk END AS library_value
  FROM (VALUES
    ('signIn.notCompleted', 'anotoki.signIn.notCompleted',
        'The sign-in was not completed.', 'Prihlásenie sa nedokončilo.'),
    ('signIn.stateMismatch', 'anotoki.signIn.expired',
        'The sign-in took too long or did not start on this page. Try again.',
        'Prihlásenie trvalo príliš dlho alebo sa nezačalo na tejto stránke. Skús to znova.'),
    ('signIn.storageUnavailable', 'anotoki.signIn.storageUnavailable',
        'This browser does not let the site remember a sign-in. Allow the site''s storage, then try again.',
        'Tento prehliadač nedovolí stránke zapamätať si prihlásenie. Povoľ jej ukladanie údajov a skús to znova.'),
    ('signIn.failed', 'anotoki.signIn.failed',
        'The sign-in failed ({code}).', 'Prihlásenie zlyhalo ({code}).'),
    ('signIn.signingIn', 'anotoki.signIn.signingIn',
        'Signing you in…', 'Prihlasujeme ťa…')
  ) AS m(own_key, library_key, library_en, library_sk)
  JOIN translations t ON t.key_name = m.own_key;

-- The library's keys, ahead of the library's own file where it has not run yet: its 003 then writes their
-- descriptions (and no string - they have theirs).
INSERT INTO translation_keys (name, description)
SELECT DISTINCT library_key, 'The anotoki library''s words: anotoki_translations/003 describes them.'
  FROM moved_words
ON CONFLICT (name) DO NOTHING;

-- Every string of the old key under the library's key: the same text, the same writer, the same time...
INSERT INTO translations (key_name, language_code, value, updated_at, updated_by)
SELECT library_key, language_code, value, updated_at, updated_by
  FROM moved_words
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ...also where the library's 003 wrote its own words there first - but never over an owner's rewording.
UPDATE translations t
   SET value = w.value, updated_at = w.updated_at, updated_by = w.updated_by
  FROM moved_words w
 WHERE t.key_name = w.library_key AND t.language_code = w.language_code
   AND t.value = w.library_value AND t.value <> w.value;

-- The old sign-in page's keys go; their strings with them (the foreign keys cascade).
DELETE FROM translation_keys WHERE name IN (
    'signIn.notCompleted', 'signIn.stateMismatch', 'signIn.storageUnavailable', 'signIn.failed', 'signIn.signingIn',
    'signIn.intro', 'signIn.notConfigured', 'signIn.unreachable'
);

-- ── The feedback form: the site's own words ──────────────────────────────────
-- 003's three statements (keys, English, Slovak), in the same form - the tests
-- read them the same way (tests/translation_catalogue.test.php) - and inserts
-- that step aside for a row already there, so nothing reworded in the browser
-- is written over. The English is, key for key, the English compiled into the
-- app (frontend/src/app/core/i18n/en/). Slovak speaks to the reader informally
-- ("ty"), as in 003.

INSERT INTO translation_keys (name, description) VALUES
    ('footer.feedback', 'The foot of every page, beside the version: the button that opens the form for telling the site something - something that does not work, an idea, a question. No placeholders.'),
    ('feedback.section.dashboard', 'The feedback form (the anotoki library''s, opened from the foot of every page), its list "Where on the site?": the start page with the person''s game accounts - the same page as topbar.dashboard. No placeholders.'),
    ('feedback.section.import', 'The feedback form, its list "Where on the site?": the import guide, where game accounts are imported - the same page as topbar.import. No placeholders.'),
    ('feedback.section.accounts', 'The feedback form, its list "Where on the site?": a game account''s page and its characters'' analysis. No placeholders.'),
    ('feedback.section.builds', 'The feedback form, its list "Where on the site?": the recommended builds of every character - the same pages as topbar.builds. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('footer.feedback', 'Feedback'),
    ('feedback.section.dashboard', 'Dashboard'),
    ('feedback.section.import', 'Importing an account'),
    ('feedback.section.accounts', 'Your accounts and characters'),
    ('feedback.section.builds', 'Builds')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('footer.feedback', 'Spätná väzba'),
    ('feedback.section.dashboard', 'Prehľad'),
    ('feedback.section.import', 'Import účtu'),
    ('feedback.section.accounts', 'Tvoje účty a postavy'),
    ('feedback.section.builds', 'Buildy')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ============================================================================
-- PROVE IT
--
-- Every string the five keys had is under the library's key, as it was: the
-- same text in the same language, by the same person, from the same moment -
-- or an owner's rewording of the library's key is there, which this file does
-- not write over; never the library's own words where the site had its own.
-- The library's keys read in English. The old keys are gone with their
-- strings; the feedback form's keys read in English and Slovak. And the
-- site's own keys - the library's, under anotoki., are never counted here -
-- are exactly as many as 006 left, less the eight that went, and the five new
-- ones (450 - 8 + 5).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 447;
    moved_keys CONSTANT TEXT[] := ARRAY['anotoki.signIn.notCompleted', 'anotoki.signIn.expired', 'anotoki.signIn.storageUnavailable',
        'anotoki.signIn.failed', 'anotoki.signIn.signingIn'];
    new_keys CONSTANT TEXT[] := ARRAY['footer.feedback', 'feedback.section.dashboard', 'feedback.section.import',
        'feedback.section.accounts', 'feedback.section.builds'];
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT count(DISTINCT library_key) INTO found FROM moved_words WHERE language_code = 'en';
    IF found <> 5 THEN
        RAISE EXCEPTION 'The five keys had % English strings to move; each has one', found;
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
      FROM unnest(moved_keys) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Library keys without an English string: %', wrong;
    END IF;

    SELECT string_agg(name, ', ' ORDER BY name) INTO wrong
      FROM translation_keys
     WHERE name LIKE 'signIn.%';
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys of the old sign-in page are still there: %', wrong;
    END IF;

    SELECT string_agg(k.name || ' (' || l.code || ')', ', ' ORDER BY k.name, l.code) INTO wrong
      FROM unnest(new_keys) AS k(name)
     CROSS JOIN (VALUES ('en'), ('sk')) AS l(code)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = l.code);
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'The feedback form''s keys without a string: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 006 with this file leave %', found, expected_keys;
    END IF;
END $$;
