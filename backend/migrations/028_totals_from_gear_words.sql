-- ============================================================================
-- 028_totals_from_gear_words.sql - the totals worked out from the gear: the words
-- ============================================================================
-- The owner's round of 2026-10-26, step 3 (docs/decisions.md): a character
-- imported from an inventory file has no in-game totals, so its stat targets
-- went unchecked. Now the site works the character sheet's totals out from
-- the gear - the character at its level, the weapon, every artifact's stats
-- and the 2-piece set bonuses (App\Services\Analysis\SheetTotals;
-- docs/analysis.md, "The totals from the gear") - wherever the game's own
-- are missing, and checks the targets against them. No table changes: the
-- stats it needs live in the catalog rows' meta, which the next catalog
-- refresh fills with every ascension phase.
--
-- New, one key: the stats panel's line over totals worked out this way
-- (character.stats.fromGear).
--
-- Reworded, where each reads as 003 seeded it (a rewording by an editor is
-- never written over): the inventory file's limit "No in-game total stats"
-- (importGuide.genshin.file.con2), which is no longer so; and the file
-- method's card and guide (importGuide.genshin.file.title, summary, con1,
-- intro, step1.title, step1.body, step2.title, step2.body, step3.title,
-- step3.body, step3.tip, trouble1), which read as if this site handed out
-- the tool: the file comes from a scanner or exporter tool of the player's
-- own, on their PC - there is no script here to copy and run - and the steps
-- fit a screen scanner as well as an exporter that captures the game's data.
-- Two of those keys' descriptions follow the words (step3.title, step3.body).
--
-- No string holds an address. In 003's form: a key with its description, its
-- English and its Slovak, every insert stepping aside for a row that is
-- already there; the rewordings in 026's form; then the gate.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations),
-- then Refresh catalog on the Builds page (the phases behind the totals). On
-- this computer the same, or `php migration.php` and `php sync-catalog.php
-- --force` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

INSERT INTO translation_keys (name, description) VALUES
    ('character.stats.fromGear', 'The character page, the stats panel when the account has no in-game totals of the character but the catalog knows its stats: what follows was worked out from the gear. "UID" is the game''s word and stays as it is; "weapon" and "artifacts" are the game''s words, never translated or declined - in Slovak in a list, where no Slovak word has to agree with them. No placeholders. In Slovak an import by UID is „import zadaním UID“ and the game''s totals „celkové hodnoty z hry“, as on every page.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('character.stats.fromGear', 'Worked out from your gear: the character at its level, the weapon, the artifacts and the 2-piece set bonuses. Passives and team buffs are not in it, so the game may show a little more. Import by UID to see the game''s own totals.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('character.stats.fromGear', 'Vypočítané z tvojej výbavy: postava na svojom leveli, weapon, artifacts a bonusy za 2 kusy setu. Pasívne schopnosti a bonusy tímu v tom nie sú, takže hra môže ukázať o niečo viac. Celkové hodnoty z hry uvidíš po importe zadaním UID.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ── The inventory file's words, where each reads as 003 seeded it ──────────
UPDATE translations t
   SET value = v.value
  FROM (VALUES
    ('importGuide.genshin.file.con2', 'en',
     'No in-game total stats (combine with a UID import)',
     'Totals are worked out from the gear, not read from the game (a UID import adds the exact ones)'),
    ('importGuide.genshin.file.con2', 'sk',
     'Bez celkových hodnôt z hry (doplní ich import zadaním UID)',
     'Celkové hodnoty sa vypočítajú z výbavy, nie z hry (presné doplní import zadaním UID)'),
    ('importGuide.genshin.file.title', 'en',
     'Full inventory (exporter tool)',
     'Full inventory (a file from a tool of your own)'),
    ('importGuide.genshin.file.title', 'sk',
     'Celý inventár (exportovací nástroj)',
     'Celý inventár (súbor z vlastného nástroja)'),
    ('importGuide.genshin.file.summary', 'en',
     'An inventory exporter on your PC saves every character, weapon and artifact as a GOOD file.',
     'A scanner or exporter tool on your PC saves every character, weapon and artifact as a GOOD file, which you upload here. This site gives no script or tool.'),
    ('importGuide.genshin.file.summary', 'sk',
     'Exportovací nástroj na tvojom PC uloží všetky postavy a celý inventár (weapons, artifacts) ako súbor GOOD.',
     'Nástroj na tvojom PC (skener alebo exportér) uloží všetky postavy a celý inventár (weapons, artifacts) ako súbor GOOD, ktorý sem nahráš. Táto stránka žiadny skript ani nástroj nedáva.'),
    ('importGuide.genshin.file.con1', 'en',
     'Needs an exporter tool on a Windows PC',
     'Needs a scanner or exporter tool of your own on a Windows PC'),
    ('importGuide.genshin.file.con1', 'sk',
     'Potrebuješ exportovací nástroj na PC so systémom Windows',
     'Potrebuješ vlastný nástroj (skener alebo exportér) na PC so systémom Windows'),
    ('importGuide.genshin.file.intro', 'en',
     'An inventory exporter reads the data the game sends to your PC when you log in (or scans your inventory from screenshots) and saves it as a GOOD file, the common JSON format for Genshin Impact inventories. It does not change the game.',
     'The file comes from a tool on your PC, not from this site - there is no script here to copy and run. An inventory scanner reads your characters, weapons and artifacts from the game''s screen, or an exporter reads the data the game sends to your PC when you log in; either saves them as a GOOD file, the common JSON format for Genshin Impact inventories, and neither changes the game.'),
    ('importGuide.genshin.file.intro', 'sk',
     'Exportovací nástroj číta údaje, ktoré hra pošle do tvojho PC pri prihlásení (alebo inventár prečíta zo snímok obrazovky), a uloží ich ako súbor GOOD – bežný formát JSON, v ktorom sa ukladá inventár z hry Genshin Impact. Hru nijako nemení.',
     'Súbor vznikne v nástroji na tvojom PC, nie na tejto stránke – žiadny skript na skopírovanie a spustenie tu nie je. Skener inventára prečíta tvoje postavy a inventár (weapons, artifacts) z obrazovky hry, alebo exportér prečíta údaje, ktoré hra pošle do tvojho PC pri prihlásení; oba ich uložia ako súbor GOOD – bežný formát JSON, v ktorom sa ukladá inventár z hry Genshin Impact – a hru nijako nemenia.'),
    ('importGuide.genshin.file.step1.title', 'en',
     'Get an inventory exporter',
     'Get an inventory scanner or exporter'),
    ('importGuide.genshin.file.step1.title', 'sk',
     'Zaobstaraj si exportovací nástroj',
     'Zaobstaraj si skener alebo exportér inventára'),
    ('importGuide.genshin.file.step1.body', 'en',
     'Use an exporter that saves the GOOD format. Download it from its official release page; most need no installation.',
     'Use a tool that saves the GOOD format. Download it from its official release page; most need no installation. This site has no tool of its own to give you.'),
    ('importGuide.genshin.file.step1.body', 'sk',
     'Použi nástroj, ktorý ukladá formát GOOD. Stiahni ho z jeho oficiálnej stránky s vydaniami; väčšinu netreba inštalovať.',
     'Použi nástroj, ktorý ukladá formát GOOD. Stiahni ho z jeho oficiálnej stránky s vydaniami; väčšinu netreba inštalovať. Táto stránka vlastný nástroj nemá.'),
    ('importGuide.genshin.file.step2.title', 'en',
     'Start the exporter before the game',
     'Start the tool'),
    ('importGuide.genshin.file.step2.title', 'sk',
     'Spusti nástroj skôr než hru',
     'Spusti nástroj'),
    ('importGuide.genshin.file.step2.body', 'en',
     'Run the exporter and start its capture before you enter the world.',
     'A scanner: start the game, open your inventory and let the tool scan it page by page. An exporter: run it and start its capture before you enter the world.'),
    ('importGuide.genshin.file.step2.body', 'sk',
     'Spusti nástroj a zapni v ňom zachytávanie ešte predtým, než vstúpiš do sveta hry.',
     'Skener: spusti hru, otvor inventár a nechaj nástroj prejsť ho stránku po stránke. Exportér: spusti ho a zapni v ňom zachytávanie ešte predtým, než vstúpiš do sveta hry.'),
    ('importGuide.genshin.file.step3.title', 'en',
     'Log into Genshin Impact',
     'Let it read everything'),
    ('importGuide.genshin.file.step3.title', 'sk',
     'Prihlás sa do hry Genshin Impact',
     'Nechaj ho prečítať všetko'),
    ('importGuide.genshin.file.step3.body', 'en',
     'Start the game and click through the door into the world. Wait until the exporter shows that it has your characters, weapons and artifacts.',
     'Wait until the tool shows that it has your characters, weapons and artifacts. With an exporter, log into Genshin Impact and click through the door into the world first.'),
    ('importGuide.genshin.file.step3.body', 'sk',
     'Spusti hru a prejdi dverami do sveta. Počkaj, kým nástroj ukáže, že už má tvoje postavy aj inventár (weapons, artifacts).',
     'Počkaj, kým nástroj ukáže, že už má tvoje postavy aj inventár (weapons, artifacts). Pri exportéri sa najprv prihlás do hry Genshin Impact a prejdi dverami do sveta.'),
    ('importGuide.genshin.file.step3.tip', 'en',
     'Already in the game? Log out to the title screen and enter again while the exporter is capturing.',
     'Using an exporter and already in the game? Log out to the title screen and enter again while it is capturing.'),
    ('importGuide.genshin.file.step3.tip', 'sk',
     'Hra už beží? Odhlás sa na úvodnú obrazovku a vstúp znova, kým nástroj zachytáva.',
     'Používaš exportér a hra už beží? Odhlás sa na úvodnú obrazovku a vstúp znova, kým zachytáva.'),
    ('importGuide.genshin.file.trouble1', 'en',
     'Nothing captured: make sure the exporter was capturing before you entered the world, then relog.',
     'Nothing read: a scanner needs the inventory open and the game in English; an exporter must be capturing before you enter the world, so start it first and relog.'),
    ('importGuide.genshin.file.trouble1', 'sk',
     'Nič sa nezachytilo: zachytávanie musí bežať ešte pred vstupom do sveta. Zapni ho a prihlás sa do hry znova.',
     'Nič sa neprečítalo: skener potrebuje otvorený inventár a hru v angličtine; pri exportéri musí zachytávanie bežať ešte pred vstupom do sveta – zapni ho a prihlás sa do hry znova.')
  ) AS v(key_name, language_code, seeded, value)
 WHERE t.key_name = v.key_name AND t.language_code = v.language_code AND t.value = v.seeded;

-- The two steps whose words moved: their descriptions say what the words now hold.
UPDATE translation_keys
   SET description = 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 3: its title - letting the tool read everything. No tool is ever named. No placeholders.'
 WHERE name = 'importGuide.genshin.file.step3.title';
UPDATE translation_keys
   SET description = 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 3: what to do - for a scanner and for an exporter. "weapons" and "artifacts" are the game''s words: never translated, never declined - in Slovak they stand in brackets or in a list, where no Slovak word has to agree with them. "Genshin Impact" is the game''s name - in Slovak after "do hry", not declined. No placeholders.'
 WHERE name = 'importGuide.genshin.file.step3.body';

-- ── Verification ────────────────────────────────────────────────────────────
-- The new key has its English and its Slovak; the site's own keys are 027's
-- with 1 more (458 + 1).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 459;
    found INTEGER;
BEGIN
    IF (SELECT count(*) FROM translations WHERE key_name = 'character.stats.fromGear' AND language_code IN ('en', 'sk')) <> 2 THEN
        RAISE EXCEPTION 'The new key of the totals from the gear is without its English and Slovak';
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 027 with this file leave %', found, expected_keys;
    END IF;
END $$;
