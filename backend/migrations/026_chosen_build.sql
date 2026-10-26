-- ============================================================================
-- 026_chosen_build.sql - the build a player chooses for a character
-- ============================================================================
-- The owner's round of 2026-10-26 (docs/decisions.md): on an account's
-- character page a player picks the build the character is judged by - "Use
-- this build" -, and the account page grades the character by it from then
-- on; until they pick one, by the closest match, as before
-- (App\Services\Analysis\CharacterAnalyzer).
--
--   - account_characters.chosen_build_id: the build chosen, null for "choose
--     automatically". A build an import or an editor removes takes the choice
--     with it (ON DELETE SET NULL): the character is judged by the closest
--     match again. That the build is one of the character's own is the
--     route's to check (PUT /api/accounts/{id}/characters/{key}/build): the
--     host's PostgreSQL 14 has no ON DELETE SET NULL (column) a composite key
--     would need. The index serves the foreign key when a build goes;
--   - new, the words of the choice: the button, its undo, the mark of the
--     build chosen, what the page says after either, and the account page's
--     line naming the build chosen (character.*, account.chosenBuild);
--   - reworded only where it still reads as 015 left it, so a rewording made
--     in the browser is kept: the Privacy page's sentence on what the site
--     keeps of a game account, which now names the build chosen
--     (legal.accounts.what).
--
-- No string holds an address. In 003's form: a key with its description, its
-- English and its Slovak, every insert stepping aside for a row that is
-- already there; then the gate.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- On this computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

ALTER TABLE account_characters ADD COLUMN IF NOT EXISTS chosen_build_id BIGINT;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'account_characters_chosen_build_id_foreign') THEN
        ALTER TABLE account_characters
            ADD CONSTRAINT account_characters_chosen_build_id_foreign FOREIGN KEY (chosen_build_id) REFERENCES builds (id) ON DELETE SET NULL;
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS account_characters_chosen_build_id_index ON account_characters (chosen_build_id) WHERE chosen_build_id IS NOT NULL;

INSERT INTO translation_keys (name, description) VALUES
    ('character.useBuild', 'The character page (an account''s character), above the build in hand: the button that makes it the build the account page grades this character by, from now on. "build" is the site''s word for a recommended setup. Short. No placeholders.'),
    ('character.chooseAutomatically', 'The character page, above the build the player chose: the button that undoes the choice - the account page then grades the character by the build closest to what it wears, as it did before any choice. Short. No placeholders.'),
    ('character.chosen', 'The character page: the mark (a badge) on the build the player chose for this character, the one the account page grades it by; also heard after a build''s tab. One word if it can be, as a label. In Slovak it agrees with "build" (ten build). No placeholders.'),
    ('character.chosenSaved', 'The character page: a short message after the player chose the build in hand for this character. {name} is the character''s name - the game''s, never translated or declined (in Slovak after "Postava").'),
    ('character.automaticSaved', 'The character page: a short message after the player undid their choice - the character is graded by the build closest to what it wears again. {name} is the character''s name - the game''s, never translated or declined (in Slovak after "Postava").'),
    ('account.chosenBuild', 'The account page, on a character''s card: the build the player chose for the character, which the card''s grade and bars are for (account.bestBuild names the build closest to what it wears, where none was chosen). {build} is the build''s variation as the page writes it, "Main DPS · Melt · C2+" - kept as it is, after the colon.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('character.useBuild', 'Use this build'),
    ('character.chooseAutomatically', 'Choose automatically'),
    ('character.chosen', 'Selected'),
    ('character.chosenSaved', '{name} is now graded by this build.'),
    ('character.automaticSaved', '{name} is graded by the closest match again.'),
    ('account.chosenBuild', 'Selected build: {build}')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('character.useBuild', 'Použiť tento build'),
    ('character.chooseAutomatically', 'Vyberať automaticky'),
    ('character.chosen', 'Vybraný'),
    ('character.chosenSaved', 'Postava {name} sa teraz hodnotí podľa tohto buildu.'),
    ('character.automaticSaved', 'Postava {name} sa opäť hodnotí podľa buildu najbližšieho k tomu, čo máš nasadené.'),
    ('account.chosenBuild', 'Vybraný build: {build}')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ── The Privacy page's game accounts, where it reads as 015 left it ─────────
UPDATE translations t
   SET value = v.value
  FROM (VALUES
    ('legal.accounts.what', 'en',
     'What you import: each game account''s UID, its in-game nickname (or the name you give it), level, world level, signature and region; its characters, weapons and artifacts, with their stats; and your imports - when, by UID or from a file, the file''s name, and what could not be read.',
     'What you import: each game account''s UID, its in-game nickname (or the name you give it), level, world level, signature and region; its characters, weapons and artifacts, with their stats; and your imports - when, by UID or from a file, the file''s name, and what could not be read. And the build you choose for a character, if you choose one.'),
    ('legal.accounts.what', 'sk',
     'Čo importuješ: UID každého herného účtu, jeho prezývku v hre (alebo názov, ktorý mu dáš), level a world level, podpis a región; jeho postavy, weapons a artifacts s ich hodnotami; a tvoje importy - kedy, či podľa UID alebo zo súboru, názov súboru a čo sa nedalo prečítať.',
     'Čo importuješ: UID každého herného účtu, jeho prezývku v hre (alebo názov, ktorý mu dáš), level a world level, podpis a región; jeho postavy, weapons a artifacts s ich hodnotami; a tvoje importy - kedy, či podľa UID alebo zo súboru, názov súboru a čo sa nedalo prečítať. A build, ktorý vyberieš pre postavu, ak nejaký vyberieš.')
  ) AS v(key_name, language_code, seeded, value)
 WHERE t.key_name = v.key_name AND t.language_code = v.language_code AND t.value = v.seeded;

UPDATE translation_keys
   SET description = 'The Privacy page, the game accounts: what an import keeps - the account''s UID, in-game nickname or the name given to it, level, world level, signature and region; the characters, weapons and artifacts with their stats; each import (when, how, the file''s name, what could not be read); and the build the person chose for a character, if they chose one. Game terms stay as the game writes them. No placeholders.'
 WHERE name = 'legal.accounts.what';

-- ── Verification ────────────────────────────────────────────────────────────
-- The column is there with its foreign key; the new keys have their English
-- and their Slovak; the reworded key is there with its English - not its
-- Slovak, which an editor may have deleted (a blank saved), and which is no
-- business of this file's: refusing for it would keep the site closed with no
-- way to put it back while the update waits. The site's own keys are 025's
-- with 6 more (446 + 6).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 452;
    found INTEGER;
    wrong TEXT;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = 'account_characters' AND column_name = 'chosen_build_id' AND data_type = 'bigint' AND is_nullable = 'YES'
    ) THEN
        RAISE EXCEPTION 'account_characters.chosen_build_id is missing, or not a nullable bigint';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'account_characters_chosen_build_id_foreign' AND contype = 'f' AND confrelid = 'builds'::regclass AND confdeltype = 'n'
    ) THEN
        RAISE EXCEPTION 'account_characters.chosen_build_id has no foreign key to builds that sets it null when the build goes';
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM unnest(ARRAY['character.useBuild', 'character.chooseAutomatically', 'character.chosen', 'character.chosenSaved', 'character.automaticSaved', 'account.chosenBuild']) AS k(name)
     WHERE (SELECT count(*) FROM translations t WHERE t.key_name = k.name AND t.language_code IN ('en', 'sk')) <> 2;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'New keys of the chosen build without their English and Slovak: %', wrong;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM translations WHERE key_name = 'legal.accounts.what' AND language_code = 'en') THEN
        RAISE EXCEPTION 'The Privacy page''s game accounts sentence (legal.accounts.what) is missing or without its English';
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 025 with this file leave %', found, expected_keys;
    END IF;
END $$;
