-- ============================================================================
-- 023_builds_editor_words.sql - the words of the builds editor
-- ============================================================================
-- Version 0.7.0 (the owner's round of 2026-10-22, docs/decisions.md): editors
-- change builds by hand in the admin panel's builds editor, and write the
-- site's own notes on a build and its teams - in English, and in Slovak where
-- they want (022, builds.site_notes). The admin panel reads English, its words
-- written in its code; what members read follows:
--
--   - new, in English and Slovak - 1 key: the link on a character's builds page
--     that takes an editor or an admin to that character's builds editor
--     (buildDetail.edit);
--   - reworded only where it still reads as 017 left it, so a rewording made in
--     the browser is kept: the Privacy page's sentence on what the audit log
--     keeps, which now names builds edited by hand (legal.auditLog.what - the
--     audit log records who saved which character's builds, builds.edited; no
--     name is stored with a build);
--   - described anew: the panels that open a build's notes and a team's
--     (buildDetail.notes, buildDetail.teamNotes) - the notes are the site's own
--     now, shown in the reader's language where an editor wrote it, else in
--     English, no longer the builds file's English.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- On this computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

INSERT INTO translation_keys (name, description) VALUES
    ('buildDetail.edit', 'A character''s builds page, for an editor or an admin alone (nobody else sees it): the link to the admin panel''s builds editor of that character, where its builds are changed by hand. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('buildDetail.edit', 'Edit builds')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('buildDetail.edit', 'Uprav buildy')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ── The Privacy page's audit log, where it reads as 017 left it ─────────────
UPDATE translations t
   SET value = v.value
  FROM (VALUES
    ('legal.auditLog.what', 'en',
     'What the site''s editors and administrators do in its admin panel - builds imported from a file and the game catalog refreshed, the words and languages saved, a person''s data deleted, an update applied, the backups, the error log and feedback handled: who, what and when. Never an IP address.',
     'What the site''s editors and administrators do in its admin panel - builds imported from a file or edited by hand, the game catalog refreshed, the words and languages saved, a person''s data deleted, an update applied, the backups, the error log and feedback handled: who, what and when. Never an IP address.'),
    ('legal.auditLog.what', 'sk',
     'Čo robia editori a správcovia stránky v administrátorskom paneli - buildy importované zo súboru a obnovený herný katalóg, uložené slová a jazyky, zmazané údaje človeka, použitá aktualizácia, zálohy, záznam chýb a spätná väzba: kto, čo a kedy. Nikdy nie IP adresa.',
     'Čo robia editori a správcovia stránky v administrátorskom paneli - buildy importované zo súboru alebo upravené ručne, obnovený herný katalóg, uložené slová a jazyky, zmazané údaje človeka, použitá aktualizácia, zálohy, záznam chýb a spätná väzba: kto, čo a kedy. Nikdy nie IP adresa.')
  ) AS v(key_name, language_code, seeded, value)
 WHERE t.key_name = v.key_name AND t.language_code = v.language_code AND t.value = v.seeded;

-- ── The notes' panels: the site's own notes ─────────────────────────────────
UPDATE translation_keys k
   SET description = v.description
  FROM (VALUES
    ('buildDetail.notes', 'A build''s detail: the title of the panel that opens the build''s notes - the site''s own, written by its editors: shown in the reader''s language where they wrote it, else in English. No placeholders.'),
    ('buildDetail.teamNotes', 'A build''s detail, under a team: the title of the panel that opens the team''s note - the site''s own, written by its editors: shown in the reader''s language where they wrote it, else in English. {team}: the team''s name as the builds file has it, in English ("Vaporize") - no word has to agree with it.')
  ) AS v(name, description)
 WHERE k.name = v.name;

-- ── Verification ────────────────────────────────────────────────────────────
-- The new key has its English and its Slovak; the reworded key and the two
-- described anew are there with their English - not their Slovak, which an
-- editor may have deleted (a blank saved), and which is no business of this
-- file's: refusing for it would keep the site closed with no way to put it
-- back while the update waits. The site's own keys are 021's with 1 more
-- (439 + 1; 022 added none).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 440;
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM unnest(ARRAY['buildDetail.edit']) AS k(name)
     WHERE (SELECT count(*) FROM translations t WHERE t.key_name = k.name AND t.language_code IN ('en', 'sk')) <> 2;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'New keys of the builds editor without their English and Slovak: %', wrong;
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM unnest(ARRAY['legal.auditLog.what', 'buildDetail.notes', 'buildDetail.teamNotes']) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys this file rewords or describes, missing or without their English: %', wrong;
    END IF;

    SELECT string_agg(name, ', ' ORDER BY name COLLATE "C") INTO wrong
      FROM translation_keys
     WHERE name IN ('buildDetail.notes', 'buildDetail.teamNotes') AND description LIKE '%as written, in English%';
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Descriptions that still call the notes the builds file''s English: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 021 with this file leave %', found, expected_keys;
    END IF;
END $$;
