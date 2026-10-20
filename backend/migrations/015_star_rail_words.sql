-- ============================================================================
-- 015_star_rail_words.sql - the words of Honkai: Star Rail go
-- ============================================================================
-- With 014 the site offers Genshin Impact alone (the owner's decision of
-- 2026-10-20). Nothing shows the other game's words any more, so its keys are
-- deleted with their strings, a rewording an editor gave them included (the
-- foreign keys cascade):
--
--   - the 44 keys of the Star Rail import guides (importGuide.hsr.*);
--   - the import's kinds only Star Rail had (import.error.showcase_empty_hsr,
--     hsr_not_export, hsr_empty; import.warning.showcase_relic_skipped,
--     hsr_relics_skipped), and the two that need two games to happen
--     (import.error.account_game_mismatch, file_game_mismatch - a second game
--     brings them back);
--   - the import wizard's first step, which asked for the game
--     (importWizard.step.game, importWizard.game.heading, importWizard.game.holds),
--     and the builds page's game switch (builds.game);
--   - legal.fanClause, the site's own fan clause naming both games: the
--     library's own (anotoki.legal.fanHoyoverse) names Genshin Impact alone,
--     which is exactly right now.
--
-- Six sentences that named the other game say Genshin Impact alone - written
-- only where they still read as 003 / 011 seeded them, so a rewording made in
-- the browser is kept - and every description that told a translator of the
-- other game's words (Light Cone, Relics, Traces, Eidolons, "S1"...) tells of
-- Genshin Impact's alone.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- On this computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

DELETE FROM translation_keys
 WHERE name LIKE 'importGuide.hsr.%'
    OR name IN (
        'import.error.showcase_empty_hsr', 'import.error.hsr_not_export', 'import.error.hsr_empty',
        'import.warning.showcase_relic_skipped', 'import.warning.hsr_relics_skipped',
        'import.error.account_game_mismatch', 'import.error.file_game_mismatch',
        'importWizard.step.game', 'importWizard.game.heading', 'importWizard.game.holds',
        'builds.game', 'legal.fanClause'
    );

-- ── The six sentences, where they read as seeded ────────────────────────────
UPDATE translations t
   SET value = v.value
  FROM (VALUES
    ('dashboard.subtitle', 'en',
     'Import a Genshin Impact or Honkai: Star Rail account and compare every character with community builds.',
     'Import a Genshin Impact account and compare every character with community builds.'),
    ('dashboard.subtitle', 'sk',
     'Importuj účet z hry Genshin Impact alebo Honkai: Star Rail a porovnaj každú postavu s buildmi od komunity.',
     'Importuj účet z hry Genshin Impact a porovnaj každú postavu s buildmi od komunity.'),
    ('dashboard.emptyText', 'en',
     'The import guide walks you through it step by step. The quickest way needs only your UID; a full inventory export also finds better artifacts and relics you already own.',
     'The import guide walks you through it step by step. The quickest way needs only your UID; a full inventory export also finds better artifacts you already own.'),
    ('dashboard.emptyText', 'sk',
     'Sprievodca importom ťa prevedie krok za krokom. Najrýchlejšie to ide, keď zadáš len UID; úplný export inventára navyše nájde lepšie kusy, ktoré už máš (artifacts, relics).',
     'Sprievodca importom ťa prevedie krok za krokom. Najrýchlejšie to ide, keď zadáš len UID; úplný export inventára navyše nájde lepšie kusy, ktoré už máš (artifacts).'),
    ('home.text', 'en',
     'Import a Genshin Impact or Honkai: Star Rail account by its UID or from an inventory file, and see per character what to improve. You can start without an account; sign in with your anotoki account to keep your data with it.',
     'Import a Genshin Impact account by its UID or from an inventory file, and see per character what to improve. You can start without an account; sign in with your anotoki account to keep your data with it.'),
    ('home.text', 'sk',
     'Importuj účet z hry Genshin Impact alebo Honkai: Star Rail podľa UID alebo zo súboru s inventárom a pri každej postave uvidíš, čo zlepšiť. Začať môžeš aj bez účtu; keď sa prihlásiš svojím anotoki účtom, tvoje údaje zostanú pri ňom.',
     'Importuj účet z hry Genshin Impact podľa UID alebo zo súboru s inventárom a pri každej postave uvidíš, čo zlepšiť. Začať môžeš aj bez účtu; keď sa prihlásiš svojím anotoki účtom, tvoje údaje zostanú pri ňom.'),
    ('legal.about', 'en',
     'Build Analyzer compares your Genshin Impact and Honkai: Star Rail characters with the builds community sites recommend, and shows what to improve. You import a game account by its UID or from an inventory file - with your anotoki account, or without one.',
     'Build Analyzer compares your Genshin Impact characters with the builds community sites recommend, and shows what to improve. You import a game account by its UID or from an inventory file - with your anotoki account, or without one.'),
    ('legal.about', 'sk',
     'Build Analyzer porovná tvoje postavy z Genshin Impact a Honkai: Star Rail s buildmi, ktoré odporúčajú komunitné stránky, a ukáže, čo zlepšiť. Herný účet importuješ podľa UID alebo zo súboru s inventárom - s účtom anotoki aj bez neho.',
     'Build Analyzer porovná tvoje postavy z Genshin Impact s buildmi, ktoré odporúčajú komunitné stránky, a ukáže, čo zlepšiť. Herný účet importuješ podľa UID alebo zo súboru s inventárom - s účtom anotoki aj bez neho.'),
    ('legal.accounts.what', 'en',
     'What you import: each game account''s UID, its in-game nickname (or the name you give it), level, world level, signature and region; its characters, weapons or light cones, and artifacts or relics, with their stats; and your imports - when, by UID or from a file, the file''s name, and what could not be read.',
     'What you import: each game account''s UID, its in-game nickname (or the name you give it), level, world level, signature and region; its characters, weapons and artifacts, with their stats; and your imports - when, by UID or from a file, the file''s name, and what could not be read.'),
    ('legal.accounts.what', 'sk',
     'Čo importuješ: UID každého herného účtu, jeho prezývku v hre (alebo názov, ktorý mu dáš), level a world level, podpis a región; jeho postavy, weapons alebo light cones a artifacts alebo relics s ich hodnotami; a tvoje importy - kedy, či podľa UID alebo zo súboru, názov súboru a čo sa nedalo prečítať.',
     'Čo importuješ: UID každého herného účtu, jeho prezývku v hre (alebo názov, ktorý mu dáš), level a world level, podpis a región; jeho postavy, weapons a artifacts s ich hodnotami; a tvoje importy - kedy, či podľa UID alebo zo súboru, názov súboru a čo sa nedalo prečítať.'),
    ('import.error.file_unknown_format', 'en',
     'Unknown file format. Upload a Genshin Impact inventory export in the GOOD format, or a Honkai: Star Rail inventory export (with "relics" and "light_cones").',
     'Unknown file format. Upload a Genshin Impact inventory export in the GOOD format.'),
    ('import.error.file_unknown_format', 'sk',
     'Neznámy formát súboru. Nahraj export inventára z hry Genshin Impact vo formáte GOOD alebo export inventára z hry Honkai: Star Rail (obsahuje "relics" a "light_cones").',
     'Neznámy formát súboru. Nahraj export inventára z hry Genshin Impact vo formáte GOOD.')
  ) AS v(key_name, language_code, seeded, value)
 WHERE t.key_name = v.key_name AND t.language_code = v.language_code AND t.value = v.seeded;

-- ── The descriptions: Genshin Impact's words alone ──────────────────────────
-- The game's words a placeholder stands for, and the examples, as they are
-- spelled across the descriptions.
UPDATE translation_keys SET description = replace(description, '"Artifacts" or "Relics"', '"Artifacts"') WHERE name NOT LIKE 'anotoki.%';
UPDATE translation_keys SET description = replace(description, '"Weapons" or "Light Cones"', '"Weapons"') WHERE name NOT LIKE 'anotoki.%';
UPDATE translation_keys SET description = replace(description, '"Weapon" or "Light Cone"', '"Weapon"') WHERE name NOT LIKE 'anotoki.%';
UPDATE translation_keys SET description = replace(description, '"Talents" or "Traces"', '"Talents"') WHERE name NOT LIKE 'anotoki.%';
UPDATE translation_keys SET description = replace(description, '"Constellations" or "Eidolons"', '"Constellations"') WHERE name NOT LIKE 'anotoki.%';
UPDATE translation_keys SET description = replace(description, '("R1", "S1")', '("R1")') WHERE name NOT LIKE 'anotoki.%';
UPDATE translation_keys SET description = replace(description, '"Genshin Impact" or "Honkai: Star Rail"', '"Genshin Impact"') WHERE name NOT LIKE 'anotoki.%';
UPDATE translation_keys SET description = replace(description, '("Genshin Impact", "Honkai: Star Rail")', '("Genshin Impact")') WHERE name NOT LIKE 'anotoki.%';
UPDATE translation_keys SET description = replace(description, '(Genshin Impact, Honkai: Star Rail)', '(Genshin Impact)') WHERE name NOT LIKE 'anotoki.%';
UPDATE translation_keys SET description = replace(description, '"Artifact set", "Cavern Relic set" or "Planar Ornament set"', '"Artifact set"') WHERE name NOT LIKE 'anotoki.%';
UPDATE translation_keys SET description = replace(description, '("Artifact set", "Cavern Relic set", "Planar Ornament set")', '("Artifact set")') WHERE name NOT LIKE 'anotoki.%';

-- The ones worded their own way.
UPDATE translation_keys t
   SET description = v.description
  FROM (VALUES
    ('dashboard.subtitle', 'The dashboard: the line under the heading, saying what the app is for. "Genshin Impact" is the game''s name and stays as it is. No placeholders.'),
    ('dashboard.emptyText', 'The dashboard while the person has no game account yet: the text under the invitation''s heading. "UID" (a player''s number) and "artifacts" (what characters wear) are the game''s words: they stay in English and are not declined. No placeholders.'),
    ('dashboard.importGame', 'The dashboard while the person has no game account yet: a button for each game the site offers, opening the import wizard for it. {game}: the game''s name, "Genshin Impact" - never translated or declined.'),
    ('buildDetail.noWeapons', 'A build''s detail (on a character''s builds page and on the character page), under the heading Weapons (the game''s term, not a string) when the build recommends none. Do not write a word that would have to agree with that heading. No placeholders.'),
    ('character.category.sets', 'The character page, a recommendation''s small heading: it is about the sets the character wears. (The headings Weapon, Talents, Main stat and Substats are the game''s terms, not strings.) No placeholders.'),
    ('character.consensus.sets', 'The character page, "What the sources agree on": the label of a row of sets whose kind this version of the site does not know. (The known kind is labelled with the game''s term Artifact sets, which is not a string.) No placeholders.'),
    ('character.score.targets', 'The character page, "Score breakdown": the label of the bar for the stat values the build aims for. The other four bars are labelled with the game''s terms (Weapon, Set bonuses, Main stats, Substats), which are not strings. No placeholders.'),
    ('import.error.file_unknown_format', 'Why a file import was refused or failed (import wizard, import history): the file is not in the format the site reads. The game''s name and "GOOD" stay as they are. No placeholders.'),
    ('importWizard.done.characters', 'The import wizard (/import), step Done: the label under the number of characters imported - a noun that needs no agreement with the number above it. The two labels beside it are the game''s own words ("Weapons", "Artifacts") and are not strings. No placeholders.'),
    ('item.percentOfIdealForBuild', 'A piece''s card (an artifact, on the character page): the tooltip of its grade. {percent} is the site''s own 0-100 score of the piece (not a game value), so Slovak writes the sign after a space.'),
    ('legal.accounts.what', 'The Privacy page, the game accounts: what an import keeps - the account''s UID, in-game nickname or the name given to it, level, world level, signature and region; the characters, weapons and artifacts with their stats; each import (when, how, the file''s name, what could not be read). Game terms stay as the game writes them. No placeholders.')
  ) AS v(name, description)
 WHERE t.name = v.name;

-- ============================================================================
-- PROVE IT
--
-- The retired keys are gone with every string of theirs, the six reworded
-- keys still have English, no description of the site's own tells of the
-- other game or its words, and the site's own keys are 013's less these 56
-- (483 - 56).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 427;
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT string_agg(name, ', ' ORDER BY name COLLATE "C") INTO wrong
      FROM translation_keys
     WHERE name LIKE 'importGuide.hsr.%'
        OR name IN (
            'import.error.showcase_empty_hsr', 'import.error.hsr_not_export', 'import.error.hsr_empty',
            'import.warning.showcase_relic_skipped', 'import.warning.hsr_relics_skipped',
            'import.error.account_game_mismatch', 'import.error.file_game_mismatch',
            'importWizard.step.game', 'importWizard.game.heading', 'importWizard.game.holds',
            'builds.game', 'legal.fanClause'
        );
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys this file retires are still there: %', wrong;
    END IF;

    SELECT string_agg(DISTINCT key_name, ', ' ORDER BY key_name) INTO wrong
      FROM translations
     WHERE key_name LIKE 'importGuide.hsr.%' OR key_name IN ('builds.game', 'legal.fanClause', 'importWizard.step.game');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Strings of the retired keys are still there: %', wrong;
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM translation_keys k
     WHERE k.name IN ('dashboard.subtitle', 'dashboard.emptyText', 'home.text', 'legal.about', 'legal.accounts.what', 'import.error.file_unknown_format')
       AND NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys without an English string: %', wrong;
    END IF;

    SELECT string_agg(name, ', ' ORDER BY name COLLATE "C") INTO wrong
      FROM translation_keys
     WHERE name NOT LIKE 'anotoki.%'
       AND description ~* '(star rail|\mhsr\M|light cone|relic|eidolon|"traces"|cavern|planar|"S1")';
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Descriptions that still tell of the other game: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 014 with this file leave %', found, expected_keys;
    END IF;
END $$;
