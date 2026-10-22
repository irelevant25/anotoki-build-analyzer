-- ============================================================================
-- 021_build_teams_words.sql - the words of a build's teams
-- ============================================================================
-- Version 0.6.0 (the owner's round of 2026-10-22, docs/decisions.md): a
-- build's detail shows the teams it is played in (020), and on a player's own
-- character page which teammates the account has. New, in English and Slovak -
-- 3 keys: the teams' heading (buildDetail.teams), the line under a member that
-- names who can take its place (buildDetail.teamAlternatives), and the title of
-- the panel that opens a team's note (buildDetail.teamNotes: with the team's
-- name, so a screen reader tells one team's notes from another's and from the
-- build's own).
--
-- Two keys take a second use, their words as they are: a teammate the account
-- has - a member or one who can stand in - wears the weapons' "Owned" chip
-- (buildDetail.owned); a member whose constellation is below what the team asks
-- of it says "needs C2" (character.needsRank). Their descriptions say so now.
--
-- A team's name and note are shown as the builds file has them, in English; a
-- member's name is the game's, its role builds.role.*, the constellation the
-- team asks of it the game's notation ("C6") - none of them a key.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- On this computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

INSERT INTO translation_keys (name, description) VALUES
    ('buildDetail.teams', 'A build''s detail: the heading over the teams the build is played in (each team''s name and note are shown as the builds file has them, in English; its members'' names are the game''s). No placeholders.'),
    ('buildDetail.teamAlternatives', 'A build''s detail, under a team member: who can take that member''s place. {names}: the game''s names of those characters, joined by " / ", each a link where it has builds - no word has to agree with them.'),
    ('buildDetail.teamNotes', 'A build''s detail, under a team: the title of the panel that opens the team''s note (the note itself is shown as written, in English). {team}: the team''s name as the builds file has it, in English ("Vaporize") - no word has to agree with it.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('buildDetail.teams', 'Teams'),
    ('buildDetail.teamAlternatives', 'or {names}'),
    ('buildDetail.teamNotes', 'Notes on {team}')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('buildDetail.teams', 'Tímy'),
    ('buildDetail.teamAlternatives', 'alebo {names}'),
    ('buildDetail.teamNotes', 'Poznámky k tímu {team}')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

UPDATE translation_keys SET description = 'A build''s detail on the character page: the chip on a weapon of the list that the player owns and does not use on this character, and on a team''s member - or one who can stand in for a member - that the account has. No placeholders.'
 WHERE name = 'buildDetail.owned';
UPDATE translation_keys SET description = 'The character page: what a screen reader hears after the name and score of a build''s tab whose constellation the character does not reach yet, after a comma (the tab shows a lock); and the chip on a team member the account has whose constellation is below what the team asks of it. {rank} is that constellation, in the game''s notation ("C2"). Begins in lower case.'
 WHERE name = 'character.needsRank';

-- ── Verification ────────────────────────────────────────────────────────────
-- Each new key has its English and its Slovak; the two reused keys are there
-- with their English - not their Slovak, which an editor may have deleted (a
-- blank saved), and which is no business of this file's: refusing for it would
-- keep the site closed with no way to put it back while the update waits. The
-- site's own keys are 019's with 3 more (436 + 3).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 439;
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM unnest(ARRAY['buildDetail.teams', 'buildDetail.teamAlternatives', 'buildDetail.teamNotes']) AS k(name)
     WHERE (SELECT count(*) FROM translations t WHERE t.key_name = k.name AND t.language_code IN ('en', 'sk')) <> 2;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'New keys of the teams without their English and Slovak: %', wrong;
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM unnest(ARRAY['buildDetail.owned', 'character.needsRank']) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys the teams reuse, missing or without their English: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 019 with this file leave %', found, expected_keys;
    END IF;
END $$;
