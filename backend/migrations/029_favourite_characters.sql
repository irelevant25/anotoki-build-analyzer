-- ============================================================================
-- 029_favourite_characters.sql - the characters a player is building
-- ============================================================================
-- The owner's request of 2026-10-27 (docs/decisions.md): an account imported
-- from a file holds every character - ninety of them - while the player builds
-- a few. A star marks a character as a favourite, on the account page's card
-- and on the character page; the account page lists the favourites first,
-- under a heading of their own, and can show them alone.
--
--   - account_characters.favourite: whether the player starred the
--     character (PUT /api/accounts/{id}/characters/{key}/favourite). An
--     import leaves it as it is (it writes the game's numbers only), the
--     accounts file carries it (format version 3), and a visitor's mark
--     moves with the account at sign-in, as everything of it does;
--   - new, the words of the star and of the account page's parts: the
--     star's name and its two tooltips (favourites.*), the two headings, the
--     switch and the hint (account.*);
--   - reworded only where it still reads as 026 left it, so a rewording made
--     in the browser is kept: the Privacy page's sentence on what the site
--     keeps of a game account, which now names the favourites
--     (legal.accounts.what).
--
-- No string holds an address. In 003's form: a key with its description, its
-- English and its Slovak, every insert stepping aside for a row that is
-- already there; the rewording in 026's form; then the gate.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- On this computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

ALTER TABLE account_characters ADD COLUMN IF NOT EXISTS favourite BOOLEAN NOT NULL DEFAULT false;

INSERT INTO translation_keys (name, description) VALUES
    ('favourites.mark', 'A character''s star button - on the account page''s card of a character and beside its name on the character page: its name, heard with whether it is pressed (the character is one of the person''s favourites, the ones they are building). {name} is the character''s name - the game''s, never translated or declined (after the colon). In Slovak it agrees with "postava".'),
    ('favourites.add', 'A character''s star button, not pressed: the tooltip saying what pressing it does - the character becomes one of the person''s favourites, listed first on the account page. Short. No placeholders.'),
    ('favourites.remove', 'A character''s star button, pressed: the tooltip saying what pressing it does - the character is no longer one of the person''s favourites. Short. No placeholders.'),
    ('account.favourites', 'The account page: the heading over the characters the person starred - their favourites, the ones they are building -, listed before all the others. One or two words. No placeholders.'),
    ('account.allCharacters', 'The account page: the heading over every character of the account (the favourites among them), under the favourites'' own list. Shown only while there are favourites. No placeholders.'),
    ('account.onlyFavourites', 'The account page, among the filters: the switch that shows the starred characters (the favourites) alone. Short, as a label. No placeholders.'),
    ('account.favouritesHint', 'The account page, under the filters while no character is starred: how to keep the characters one is building at the top - press a character''s star. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('favourites.mark', 'Favourite: {name}'),
    ('favourites.add', 'Add to favourites'),
    ('favourites.remove', 'Remove from favourites'),
    ('account.favourites', 'Favourites'),
    ('account.allCharacters', 'All characters'),
    ('account.onlyFavourites', 'Only favourites'),
    ('account.favouritesHint', 'Star the characters you are building, and they stay at the top of this page.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('favourites.mark', 'Obľúbená postava: {name}'),
    ('favourites.add', 'Pridať medzi obľúbené'),
    ('favourites.remove', 'Odobrať z obľúbených'),
    ('account.favourites', 'Obľúbené'),
    ('account.allCharacters', 'Všetky postavy'),
    ('account.onlyFavourites', 'Len obľúbené'),
    ('account.favouritesHint', 'Hviezdičkou označ postavy, na ktorých práve pracuješ, a zostanú navrchu tejto stránky.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ── The Privacy page's game accounts, where it reads as 026 left it ─────────
UPDATE translations t
   SET value = v.value
  FROM (VALUES
    ('legal.accounts.what', 'en',
     'What you import: each game account''s UID, its in-game nickname (or the name you give it), level, world level, signature and region; its characters, weapons and artifacts, with their stats; and your imports - when, by UID or from a file, the file''s name, and what could not be read. And the build you choose for a character, if you choose one.',
     'What you import: each game account''s UID, its in-game nickname (or the name you give it), level, world level, signature and region; its characters, weapons and artifacts, with their stats; and your imports - when, by UID or from a file, the file''s name, and what could not be read. And the build you choose for a character, if you choose one, and the characters you mark as favourites.'),
    ('legal.accounts.what', 'sk',
     'Čo importuješ: UID každého herného účtu, jeho prezývku v hre (alebo názov, ktorý mu dáš), level a world level, podpis a región; jeho postavy, weapons a artifacts s ich hodnotami; a tvoje importy - kedy, či podľa UID alebo zo súboru, názov súboru a čo sa nedalo prečítať. A build, ktorý vyberieš pre postavu, ak nejaký vyberieš.',
     'Čo importuješ: UID každého herného účtu, jeho prezývku v hre (alebo názov, ktorý mu dáš), level a world level, podpis a región; jeho postavy, weapons a artifacts s ich hodnotami; a tvoje importy - kedy, či podľa UID alebo zo súboru, názov súboru a čo sa nedalo prečítať. A build, ktorý vyberieš pre postavu, ak nejaký vyberieš, a postavy, ktoré si označíš ako obľúbené.')
  ) AS v(key_name, language_code, seeded, value)
 WHERE t.key_name = v.key_name AND t.language_code = v.language_code AND t.value = v.seeded;

UPDATE translation_keys
   SET description = 'The Privacy page, the game accounts: what an import keeps - the account''s UID, in-game nickname or the name given to it, level, world level, signature and region; the characters, weapons and artifacts with their stats; each import (when, how, the file''s name, what could not be read); the build the person chose for a character, if they chose one, and the characters they starred as favourites. Game terms stay as the game writes them. No placeholders.'
 WHERE name = 'legal.accounts.what';

-- ── Verification ────────────────────────────────────────────────────────────
-- The column is there, a boolean that is never null; the new keys have their
-- English and their Slovak; the reworded key is there with its English - not
-- its Slovak, which an editor may have deleted (026 says why that is no
-- business of this file's). The site's own keys are 028's with 7 more
-- (459 + 7).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 466;
    found INTEGER;
    wrong TEXT;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = 'account_characters' AND column_name = 'favourite' AND data_type = 'boolean' AND is_nullable = 'NO'
    ) THEN
        RAISE EXCEPTION 'account_characters.favourite is missing, or not a boolean that is never null';
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM unnest(ARRAY['favourites.mark', 'favourites.add', 'favourites.remove', 'account.favourites', 'account.allCharacters', 'account.onlyFavourites', 'account.favouritesHint']) AS k(name)
     WHERE (SELECT count(*) FROM translations t WHERE t.key_name = k.name AND t.language_code IN ('en', 'sk')) <> 2;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'New keys of the favourites without their English and Slovak: %', wrong;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM translations WHERE key_name = 'legal.accounts.what' AND language_code = 'en') THEN
        RAISE EXCEPTION 'The Privacy page''s game accounts sentence (legal.accounts.what) is missing or without its English';
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 028 with this file leave %', found, expected_keys;
    END IF;
END $$;
