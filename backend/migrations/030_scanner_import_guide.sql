-- ============================================================================
-- 030_scanner_import_guide.sql - the import guide points to the site's scanner
-- ============================================================================
-- The owner's request of 2026-10-27 (docs/decisions.md): the project has its
-- own game scanner now (game-scanner/, released on the repository's page as
-- the release "scanner"): a PowerShell line, pasted as administrator, fetches
-- it, and it reads the player's weapons, artifacts and characters from the
-- game's screen into a GOOD file. The import guide's file method says so and
-- shows the line, with a Copy button and a link to the scanner's own
-- instructions - the line and that address are the code's
-- (frontend sites/main/import/scanner.ts), never a string: no string holds an
-- address, as before. A GOOD file from any other tool still works.
--
-- New, two keys: the name of the box with the line, and the link's words
-- (importGuide.genshin.file.step1.command, step1.link).
--
-- Reworded, where each reads as 028 left it - or as 003 seeded it, for the
-- keys 028 did not touch - so a rewording by an editor is never written over:
-- the file method's card (importGuide.genshin.file.title, summary, con1), its
-- guide (intro, the four steps' titles, bodies and tips, the three lines of
-- "Something not working?"), and the paste panel's title
-- (importWizard.file.paste), which named an exporter. Their descriptions
-- follow the words.
--
-- In 003's form: a key with its description, its English and its Slovak,
-- every insert stepping aside for a row that is already there; the
-- rewordings in 026's form; then the gate.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- Publish the scanner's release first (Actions -> Game scanner -> Run
-- workflow): the line the page shows downloads it. On this computer the
-- same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

INSERT INTO translation_keys (name, description) VALUES
    ('importGuide.genshin.file.step1.command', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 1: the name of the box with the line to paste into PowerShell (the page shows the line, which starts the site''s scanner, with a Copy button) - heard before the line. "PowerShell" is the program''s name. No placeholders.'),
    ('importGuide.genshin.file.step1.link', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 1: a link under the line to paste, to the scanner''s own instructions and source code (the site''s repository; the address is the code''s, never this text). No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('importGuide.genshin.file.step1.command', 'The line to paste into PowerShell'),
    ('importGuide.genshin.file.step1.link', 'How the scanner works, and its source code')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('importGuide.genshin.file.step1.command', 'Riadok, ktorý vložíš do PowerShellu'),
    ('importGuide.genshin.file.step1.link', 'Ako skener funguje a jeho zdrojový kód')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ── The file method's words, where each reads as 028 (or 003) left it ──────
UPDATE translations t
   SET value = v.value
  FROM (VALUES
    ('importGuide.genshin.file.title', 'en',
     'Full inventory (a file from a tool of your own)',
     'Full inventory (a file from our scanner or another tool)'),
    ('importGuide.genshin.file.title', 'sk',
     'Celý inventár (súbor z vlastného nástroja)',
     'Celý inventár (súbor z nášho skenera alebo iného nástroja)'),
    ('importGuide.genshin.file.summary', 'en',
     'A scanner or exporter tool on your PC saves every character, weapon and artifact as a GOOD file, which you upload here. This site gives no script or tool.',
     'Our scanner for Windows reads every character, weapon and artifact from the game and saves them as a GOOD file, which you upload here. A GOOD file from any other tool works too.'),
    ('importGuide.genshin.file.summary', 'sk',
     'Nástroj na tvojom PC (skener alebo exportér) uloží všetky postavy a celý inventár (weapons, artifacts) ako súbor GOOD, ktorý sem nahráš. Táto stránka žiadny skript ani nástroj nedáva.',
     'Náš skener pre Windows prečíta z hry všetky postavy a celý inventár (weapons, artifacts) a uloží ich ako súbor GOOD, ktorý sem nahráš. Funguje aj súbor GOOD z iného nástroja.'),
    ('importGuide.genshin.file.con1', 'en',
     'Needs a scanner or exporter tool of your own on a Windows PC',
     'Needs a Windows PC: the scanner reads the game''s screen'),
    ('importGuide.genshin.file.con1', 'sk',
     'Potrebuješ vlastný nástroj (skener alebo exportér) na PC so systémom Windows',
     'Potrebuješ PC so systémom Windows: skener číta obrazovku hry'),
    ('importGuide.genshin.file.intro', 'en',
     'The file comes from a tool on your PC, not from this site - there is no script here to copy and run. An inventory scanner reads your characters, weapons and artifacts from the game''s screen, or an exporter reads the data the game sends to your PC when you log in; either saves them as a GOOD file, the common JSON format for Genshin Impact inventories, and neither changes the game.',
     'The easiest way to the file is our scanner for Windows. It reads your characters, weapons and artifacts from the game''s screen, as you see them - it never touches the game''s files or memory, and sends nothing about you anywhere - and saves them as a GOOD file, the common format of Genshin Impact inventories. A GOOD file from any other tool works as well.'),
    ('importGuide.genshin.file.intro', 'sk',
     'Súbor vznikne v nástroji na tvojom PC, nie na tejto stránke – žiadny skript na skopírovanie a spustenie tu nie je. Skener inventára prečíta tvoje postavy a inventár (weapons, artifacts) z obrazovky hry, alebo exportér prečíta údaje, ktoré hra pošle do tvojho PC pri prihlásení; oba ich uložia ako súbor GOOD – bežný formát JSON, v ktorom sa ukladá inventár z hry Genshin Impact – a hru nijako nemenia.',
     'Najľahšie získaš súbor naším skenerom pre Windows. Prečíta tvoje postavy a inventár (weapons, artifacts) z obrazovky hry tak, ako ich vidíš ty – na súbory ani pamäť hry nesiahne a nikam o tebe nič neposiela – a uloží ich ako súbor GOOD, bežný formát inventára z hry Genshin Impact. Funguje aj súbor GOOD z akéhokoľvek iného nástroja.'),
    ('importGuide.genshin.file.step1.title', 'en',
     'Get an inventory scanner or exporter',
     'Start the scanner'),
    ('importGuide.genshin.file.step1.title', 'sk',
     'Zaobstaraj si skener alebo exportér inventára',
     'Spusti skener'),
    ('importGuide.genshin.file.step1.body', 'en',
     'Use a tool that saves the GOOD format. Download it from its official release page; most need no installation. This site has no tool of its own to give you.',
     'Open PowerShell as administrator: right-click the Start button and choose Terminal (Admin) or Windows PowerShell (Admin). Paste this line there and press Enter. It downloads the scanner (about 60 MB), checks its fingerprint and starts it; when you are done, it is deleted again.'),
    ('importGuide.genshin.file.step1.body', 'sk',
     'Použi nástroj, ktorý ukladá formát GOOD. Stiahni ho z jeho oficiálnej stránky s vydaniami; väčšinu netreba inštalovať. Táto stránka vlastný nástroj nemá.',
     'Otvor PowerShell ako správca: klikni pravým tlačidlom na tlačidlo Štart a vyber Terminál (správca) alebo Windows PowerShell (správca). Vlož doň tento riadok a stlač Enter. Riadok stiahne skener (asi 60 MB), overí jeho odtlačok a spustí ho; keď skončíš, skener sa zase zmaže.'),
    ('importGuide.genshin.file.step1.tip', 'en',
     'Exporters that read the game data need administrator rights to capture the network traffic; screenshot scanners need English game text.',
     'The game runs as administrator, so the scanner must as well: without it, Windows blocks the scanner''s clicks and key presses.'),
    ('importGuide.genshin.file.step1.tip', 'sk',
     'Nástroje, ktoré čítajú údaje hry, potrebujú práva správcu, aby mohli zachytávať sieťovú komunikáciu; nástroje, ktoré čítajú snímky obrazovky, potrebujú hru v angličtine.',
     'Hra beží ako správca, takže skener musí tiež: inak mu Windows zablokuje kliknutia a stlačenia kláves.'),
    ('importGuide.genshin.file.step2.title', 'en',
     'Start the tool',
     'Get the game ready'),
    ('importGuide.genshin.file.step2.title', 'sk',
     'Spusti nástroj',
     'Priprav hru'),
    ('importGuide.genshin.file.step2.body', 'en',
     'A scanner: start the game, open your inventory and let the tool scan it page by page. An exporter: run it and start its capture before you enter the world.',
     'Run the game in English, full screen, at a 16:9 resolution (1920×1080, 2560×1440 or 3840×2160). The scanner asks for the names your Traveler, Wanderer, Manekin and Manekina have in the game: type them exactly as the game shows them.'),
    ('importGuide.genshin.file.step2.body', 'sk',
     'Skener: spusti hru, otvor inventár a nechaj nástroj prejsť ho stránku po stránke. Exportér: spusti ho a zapni v ňom zachytávanie ešte predtým, než vstúpiš do sveta hry.',
     'Spusti hru v angličtine, na celej obrazovke, v rozlíšení 16:9 (1920×1080, 2560×1440 alebo 3840×2160). Skener sa spýta na mená, ktoré majú v hre tvoje postavy Traveler, Wanderer, Manekin a Manekina: napíš ich presne tak, ako ich ukazuje hra.'),
    ('importGuide.genshin.file.step3.title', 'en',
     'Let it read everything',
     'Scan the inventory and the characters'),
    ('importGuide.genshin.file.step3.title', 'sk',
     'Nechaj ho prečítať všetko',
     'Naskenuj inventár a postavy'),
    ('importGuide.genshin.file.step3.body', 'en',
     'Wait until the tool shows that it has your characters, weapons and artifacts. With an exporter, log into Genshin Impact and click through the door into the world first.',
     'Open the inventory (B) on the Weapons or the Artifacts tab, scrolled to the top, or the character screen (C), and press Ctrl+S. Keep off the mouse and keyboard until the scanner beeps; Ctrl+S again stops it. Scan the weapons, the artifacts and the characters, in any order.'),
    ('importGuide.genshin.file.step3.body', 'sk',
     'Počkaj, kým nástroj ukáže, že už má tvoje postavy aj inventár (weapons, artifacts). Pri exportéri sa najprv prihlás do hry Genshin Impact a prejdi dverami do sveta.',
     'Otvor inventár (B) na karte Weapons alebo Artifacts, posunutej úplne hore, alebo obrazovku postáv (C) a stlač Ctrl+S. Kým skener nepípne, nechaj myš aj klávesnicu na pokoji; ďalšie Ctrl+S ho zastaví. Naskenuj weapons, artifacts aj postavy, v akomkoľvek poradí.'),
    ('importGuide.genshin.file.step3.tip', 'en',
     'Using an exporter and already in the game? Log out to the title screen and enter again while it is capturing.',
     '1800 artifacts take about 15 minutes, the characters a couple of minutes. What the game''s filters hide in the inventory is not scanned.'),
    ('importGuide.genshin.file.step3.tip', 'sk',
     'Používaš exportér a hra už beží? Odhlás sa na úvodnú obrazovku a vstúp znova, kým zachytáva.',
     'Skenovanie 1800 kusov (artifacts) trvá asi 15 minút, postáv pár minút. Čo v inventári skryjú filtre hry, sa nenaskenuje.'),
    ('importGuide.genshin.file.step4.title', 'en',
     'Export the GOOD file',
     'Import the GOOD file'),
    ('importGuide.genshin.file.step4.title', 'sk',
     'Exportuj súbor GOOD',
     'Importuj súbor GOOD'),
    ('importGuide.genshin.file.step4.body', 'en',
     'Save the export as a file, or copy it to the clipboard and paste it in the next step.',
     'When everything is scanned, the scanner saves the file in your Downloads folder and copies it to the clipboard. Upload it or paste it in the next step; then press Enter in the scanner, and it deletes the file and everything it saved.'),
    ('importGuide.genshin.file.step4.body', 'sk',
     'Ulož export ako súbor, alebo ho skopíruj do schránky a vlož v ďalšom kroku.',
     'Keď je všetko naskenované, skener uloží súbor do priečinka Stiahnuté súbory a skopíruje ho do schránky. Nahraj ho alebo ho vlož v ďalšom kroku; potom v skeneri stlač Enter a skener zmaže súbor aj všetko, čo uložil.'),
    ('importGuide.genshin.file.trouble1', 'en',
     'Nothing read: a scanner needs the inventory open and the game in English; an exporter must be capturing before you enter the world, so start it first and relog.',
     'The scanner says it is not running as administrator: close PowerShell, open it again as administrator and paste the line once more.'),
    ('importGuide.genshin.file.trouble1', 'sk',
     'Nič sa neprečítalo: skener potrebuje otvorený inventár a hru v angličtine; pri exportéri musí zachytávanie bežať ešte pred vstupom do sveta – zapni ho a prihlás sa do hry znova.',
     'Skener hlási, že nebeží ako správca: zatvor PowerShell, otvor ho znova ako správca a vlož riadok ešte raz.'),
    ('importGuide.genshin.file.trouble2', 'en',
     'After a game update the exporter may need an update too; check its release page.',
     'A scan stopped: the scanner says why and what to do - another tab open, the list not at the top, the game not in English. Put it right and press Ctrl+S again.'),
    ('importGuide.genshin.file.trouble2', 'sk',
     'Po aktualizácii hry môže aktualizáciu potrebovať aj nástroj; pozri jeho stránku s vydaniami.',
     'Skenovanie sa zastavilo: skener povie prečo a čo urobiť – otvorená iná karta, zoznam nie je úplne hore, hra nie je v angličtine. Naprav to a stlač znova Ctrl+S.'),
    ('importGuide.genshin.file.trouble3', 'en',
     'Tools from other people are used at your own risk. An exporter only reads data; this app never asks for your game password.',
     'Another tool than ours is used at your own risk. Our scanner only looks at the screen and presses keys as you would, and this site never asks for your game password.'),
    ('importGuide.genshin.file.trouble3', 'sk',
     'Nástroje od iných ľudí používaš na vlastné riziko. Exportovací nástroj údaje iba číta; táto aplikácia ťa nikdy nepožiada o heslo do hry.',
     'Iný nástroj ako náš používaš na vlastné riziko. Náš skener sa iba pozerá na obrazovku a stláča klávesy tak ako ty a táto stránka ťa nikdy nepožiada o heslo do hry.'),
    ('importWizard.file.paste', 'en',
     '…or paste the JSON (if your exporter copies it to the clipboard)',
     '…or paste the JSON (the scanner copies it to the clipboard)'),
    ('importWizard.file.paste', 'sk',
     '…alebo vlož JSON (ak ho tvoj nástroj kopíruje do schránky)',
     '…alebo vlož JSON (skener ho kopíruje do schránky)')
  ) AS v(key_name, language_code, seeded, value)
 WHERE t.key_name = v.key_name AND t.language_code = v.language_code AND t.value = v.seeded;

-- Their descriptions say what the words now hold.
UPDATE translation_keys k
   SET description = v.description
  FROM (VALUES
    ('importGuide.genshin.file.title', 'The import wizard (/import), step Method: the card of the Genshin Impact import of a full inventory file, its title: the file comes from the site''s own scanner or from another tool of the player''s. No tool is ever named by its name. No placeholders.'),
    ('importGuide.genshin.file.summary', 'The import wizard (/import), step Method: the card of the Genshin Impact import of a full inventory file, the line under its title: the site''s own scanner makes the file, another tool''s GOOD file works too. "GOOD" is the file format''s name and "Windows" the system''s: they stay as they are. "weapon" and "artifact" are the game''s words: never translated, never declined - in Slovak they stand in brackets or in a list, where no Slovak word has to agree with them. No tool is ever named by its name. No placeholders.'),
    ('importGuide.genshin.file.con1', 'The import wizard (/import), step Method: the card of the Genshin Impact import of a full inventory file, a limit (a line with a dash, no full stop): the scanner runs on Windows and reads the game''s screen. No tool is ever named; the operating system is. No placeholders.'),
    ('importGuide.genshin.file.intro', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file: the paragraph above the steps - the site''s own scanner reads the game''s screen (never its files or memory, and sends nothing about the player) and saves a GOOD file; another tool''s GOOD file works too. "GOOD" is a file format, "Windows" the system and "Genshin Impact" the game''s name: they stay as they are. "weapons" and "artifacts" are the game''s words: never translated, never declined - in Slovak in brackets. No tool is ever named by its name. No placeholders.'),
    ('importGuide.genshin.file.step1.title', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 1: its title - starting the site''s own scanner. No placeholders.'),
    ('importGuide.genshin.file.step1.body', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 1: what to do - open PowerShell as administrator and paste the line shown under these words (the page shows it with a Copy button; no address is ever in this text). "PowerShell" is the program''s name; the Start button and the menu entries "Terminal (Admin)" and "Windows PowerShell (Admin)" are named as Windows names them in the reader''s language (in Slovak Windows: Štart, Terminál (správca), Windows PowerShell (správca)). No placeholders.'),
    ('importGuide.genshin.file.step1.tip', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 1: the tip under it (beside a light bulb) - why PowerShell has to run as administrator. No tool is ever named by its name. No placeholders.'),
    ('importGuide.genshin.file.step2.title', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 2: its title - setting the game up for the scan. No placeholders.'),
    ('importGuide.genshin.file.step2.body', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 2: what to do - the game in English, full screen, at 16:9; the scanner asks the names the player gave four characters. Traveler, Wanderer, Manekin and Manekina are the game''s names of characters: never translated, never declined - in Slovak after "postavy". No gendered past tense about the reader in Slovak. No placeholders.'),
    ('importGuide.genshin.file.step3.title', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 3: its title - the scans themselves. No placeholders.'),
    ('importGuide.genshin.file.step3.body', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 3: what to do - open a screen of the game, press Ctrl+S, hands off until it beeps. B, C and Ctrl+S are keys; "Weapons" and "Artifacts" are the game''s tabs and words: never translated, never declined - in Slovak in a list or after "karte". No placeholders.'),
    ('importGuide.genshin.file.step3.tip', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 3: the tip under it (beside a light bulb) - how long the scans take, and that the game''s own filters decide what is scanned. "artifacts" is the game''s word: never translated, never declined - in Slovak in brackets. No placeholders.'),
    ('importGuide.genshin.file.step4.title', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 4: its title. "GOOD" is the format''s name. No placeholders.'),
    ('importGuide.genshin.file.step4.body', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, step 4: what to do - the file is in the Downloads folder and on the clipboard; after the import Enter in the scanner deletes it. The Downloads folder is named as Windows names it in the reader''s language (in Slovak Windows: Stiahnuté súbory). No placeholders.'),
    ('importGuide.genshin.file.trouble1', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, a line of "Something not working?": the scanner was started without administrator rights. "PowerShell" is the program''s name. No gendered past tense about the reader in Slovak. No placeholders.'),
    ('importGuide.genshin.file.trouble2', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, a line of "Something not working?": a scan that stopped says why. Ctrl+S is a key. No tool and no website is ever named. No placeholders.'),
    ('importGuide.genshin.file.trouble3', 'The import wizard (/import), step Prepare: the guide for the Genshin Impact import of a full inventory file, a line of "Something not working?": other people''s tools at one''s own risk; the site''s scanner only reads the screen and presses keys; no password is ever asked for. No tool is ever named by its name. No placeholders.'),
    ('importWizard.file.paste', 'The import wizard (/import), step Import of a file: the title of the panel that opens a text field for pasting the file - the site''s scanner copies it to the clipboard. It continues the drop area above, so it starts with an ellipsis and a lower-case letter. "JSON" is a format''s name. No tool is ever named by its name. No placeholders.')
  ) AS v(name, description)
 WHERE k.name = v.name;

-- ── Verification ────────────────────────────────────────────────────────────
-- The new keys have their English and their Slovak; the site's own keys are
-- 029's with 2 more (466 + 2).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 468;
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM unnest(ARRAY['importGuide.genshin.file.step1.command', 'importGuide.genshin.file.step1.link']) AS k(name)
     WHERE (SELECT count(*) FROM translations t WHERE t.key_name = k.name AND t.language_code IN ('en', 'sk')) <> 2;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'New keys of the scanner''s guide without their English and Slovak: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 029 with this file leave %', found, expected_keys;
    END IF;
END $$;
