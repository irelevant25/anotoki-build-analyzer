-- ============================================================================
-- 012_stepper_and_tab_words.sql - the words the kit's stepper and tabs need, and the old stepper's word gone
-- ============================================================================
-- Version 0.4 (anotoki-lib 0.6.2: the kit's newer pieces in place of the
-- site's own). The import guide's row of steps is the family's stepper now
-- (@anotoki/lib/ui/stepper): it says itself that a step is done and which step
-- of how many is in hand (the library's anotoki.stepper.*, its 007's words),
-- and asks for a name of its own, which the site's row had none of:
--
--   importWizard.steps   the row of steps' name, for screen readers
--
-- and the word the site's own row said after a step done goes, with its
-- strings (ON DELETE CASCADE): nothing asks for it any more -
--
--   stepper.completed
--
-- A build's tab on the character page shows its score as the kit's badge
-- (anotoki-lib 0.6.0's tabs), which a screen reader hears after the tab's name
-- in the site's words - where the score was a bare number in the name:
--
--   character.tabScore   "score {score}"
--
-- In 003's form: a key with its description, its English and its informal
-- Slovak (ty) string, every insert stepping aside for a row that is already
-- there; then the delete, and the gate. Nothing else changes.
--
-- Applying it: with the other files of version 0.4, in one Apply on the admin
-- panel's Migrations page (see 007).
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

INSERT INTO translation_keys (name, description) VALUES
    ('importWizard.steps', 'The import guide: the name a screen reader hears for its row of steps (the family''s stepper - each step heard as "Step 3 of 5: Prepare", a step done as "Game, done"); not shown. No placeholders.'),
    ('character.tabScore', 'The character page, a build''s tab: what a screen reader hears after the tab''s name - the build''s score, which the tab shows as a small badge ("Source 1 · DPS, score 87"; for the build closest to the character''s setup, character.closestMatch follows it). {score}: the score out of 100, written the page''s way. Placeholders: {score}.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('importWizard.steps', 'The import''s steps'),
    ('character.tabScore', 'score {score}')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('importWizard.steps', 'Kroky importu'),
    ('character.tabScore', 'skóre {score}')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- The site's own row of steps said this after a step done; the family's stepper says it itself.
DELETE FROM translation_keys WHERE name IN ('stepper.completed');

-- ============================================================================
-- PROVE IT
--
-- Both new keys read in English and Slovak; the old stepper's word is gone with
-- its strings. And the site's own keys - the library's, under anotoki., are
-- never counted here - are exactly as many as 011 left, two more and one fewer
-- (485 + 2 - 1).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 486;
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO wrong
      FROM (VALUES ('importWizard.steps'), ('character.tabScore')) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'sk');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys without an English and a Slovak string: %', wrong;
    END IF;

    IF EXISTS (SELECT 1 FROM translation_keys WHERE name = 'stepper.completed')
        OR EXISTS (SELECT 1 FROM translations WHERE key_name = 'stepper.completed') THEN
        RAISE EXCEPTION 'The old stepper''s word is still there';
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 011 with this file leave %', found, expected_keys;
    END IF;
END $$;
