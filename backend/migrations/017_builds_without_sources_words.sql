-- ============================================================================
-- 017_builds_without_sources_words.sql - the words of builds without sources
-- ============================================================================
-- With 016 a build is a variation of a character's - its role, a qualifier, the
-- constellation it needs - and an editor imports the builds as a file on the
-- admin panel's Builds page; nobody syncs a source any more (the owner's
-- decision of 2026-10-20). The words follow:
--
--   - retired, with their strings, a rewording an editor gave them included
--     (the foreign keys cascade) - 23 keys: the sources' and the syncs' words
--     (builds.sourcesPage, nav.sources, toolbar.syncing, toolbar.syncingLabel;
--     the dashboard's dashboard.sources, manageSources, noBuildsAdmin,
--     sourceCharacters.*, syncing, notSyncedYet), what the sources agreed on
--     (character.consensus.*) and what a build said of its source
--     (buildDetail.substatsBorrowed, notesFrom, updatedAtSource, checkedNever;
--     account.bestMatch);
--   - new, in English and Slovak - 18 keys: a build's role (builds.role.*), the
--     link to the admin panel's Builds page (builds.adminPage), a build the
--     character's constellation does not reach (character.needsRank,
--     character.rankNotReached), a build that lists no substats
--     (buildDetail.substatsDerived), the build's notes (buildDetail.notes), the
--     best build on the account page (account.bestBuild), and the dashboard's
--     builds (dashboard.builds, manageBuilds, buildsFor.*, buildsUpdated,
--     buildsNever, noBuildsStaff);
--   - reworded only where they still read as seeded, so a rewording made in the
--     browser is kept: the fifteen sentences that told of syncs and sources -
--     the builds page's, the character page's, the dashboard's, the account
--     page's, a build's, the import's warning that the catalog is behind, and the
--     Privacy page's - and every description that told a translator of them.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- On this computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

DELETE FROM translation_keys
 WHERE name IN (
    'builds.sourcesPage', 'nav.sources', 'toolbar.syncing', 'toolbar.syncingLabel',
    'dashboard.sources', 'dashboard.manageSources', 'dashboard.noBuildsAdmin',
    'dashboard.sourceCharacters.one', 'dashboard.sourceCharacters.few', 'dashboard.sourceCharacters.other',
    'dashboard.syncing', 'dashboard.notSyncedYet',
    'character.consensus.title', 'character.consensus.builds.one', 'character.consensus.builds.few',
    'character.consensus.builds.other', 'character.consensus.sets', 'character.consensus.bestWeapon',
    'buildDetail.substatsBorrowed', 'buildDetail.notesFrom', 'buildDetail.updatedAtSource', 'buildDetail.checkedNever',
    'account.bestMatch'
 );

-- ── The new keys ────────────────────────────────────────────────────────────
INSERT INTO translation_keys (name, description) VALUES
    ('builds.role.main_dps', 'What a build is for: the role of a character who deals most of the team''s damage on the field. A tab and a line of a character''s builds say it, with the build''s qualifier and constellation after it ("Main DPS · Melt · C2+"). "DPS" is the players'' own word: keep it. No placeholders.'),
    ('builds.role.sub_dps', 'What a build is for: the role of a character who deals damage mostly from off the field (see builds.role.main_dps). No placeholders.'),
    ('builds.role.support', 'What a build is for: the role of a character who helps the team - shields, heals, buffs (see builds.role.main_dps). No placeholders.'),
    ('builds.role.unknown', 'What a build is for, when the server names a role this page does not know yet (a newer server''s): in its place, a word for any build. No placeholders.'),
    ('builds.adminPage', 'The text of the link to the admin panel''s Builds page, where editors import the builds, inside builds.empty, character.noBuilds and dashboard.noBuildsStaff. It is that page''s name, and the admin panel reads English: keep the English word. No placeholders.'),
    ('character.needsRank', 'The character page, the tab of a build whose constellation the character does not reach yet: what a screen reader hears after the tab''s name and score, after a comma (the tab shows a lock). {rank} is the constellation the build needs, in the game''s notation ("C2"). Begins in lower case.'),
    ('character.rankNotReached', 'The character page, above a build whose constellation the character does not reach yet - it is judged all the same, and never called the best. {rank} is the constellation the build needs and {current} the character''s, in the game''s notation ("C2", "C0"); {name} is the character''s name (never declined).'),
    ('buildDetail.substatsDerived', 'A build''s detail, under the heading Substat priority (buildDetail.substatPriority) when the build lists none: the analysis then goes by the substats its own main stats ask for. "Main stats" is the game''s term. No placeholders.'),
    ('buildDetail.notes', 'A build''s detail: the title of the panel that opens the build''s notes (the notes themselves are shown as written, in English). No placeholders.'),
    ('account.bestBuild', 'The account page, on a character''s card: the build the character is closest to. {build} is the build''s variation as the page writes it, "Main DPS · Melt · C2+" - kept as it is, after the colon.'),
    ('dashboard.builds', 'The dashboard: the heading of the part about the recommended builds - for how many characters of each game, and when they were brought up to date. No placeholders.'),
    ('dashboard.manageBuilds', 'The dashboard, for an editor or an admin: the link beside the heading dashboard.builds, to the admin panel''s Builds page. No placeholders.'),
    ('dashboard.buildsFor.one', 'The dashboard, the builds part, a game''s line (it links to the game''s builds): for how many characters there are builds - the form for 1. {count}: that number.'),
    ('dashboard.buildsFor.few', 'The dashboard, the builds part, a game''s line: for how many characters there are builds - the form for 2 to 4 (in English the same as for many). {count}: that number.'),
    ('dashboard.buildsFor.other', 'The dashboard, the builds part, a game''s line: for how many characters there are builds - the form for 0 and for 5 or more. {count}: that number.'),
    ('dashboard.buildsUpdated', 'The dashboard, the builds part, at the end of a game''s line: when its builds were last brought up to date. {when} is how long ago, already in the page''s language as an adverbial ("3 days ago" / "pred 3 dňami"). Begins in lower case.'),
    ('dashboard.buildsNever', 'The dashboard, the builds part, in place of dashboard.buildsUpdated while the game has no builds yet. Lower case: it stands where a relative time would. No placeholders.'),
    ('dashboard.noBuildsStaff', 'The dashboard, for an editor or an admin, while no build has been imported yet. {link} is where the link to the admin panel''s Builds page stands; its text is builds.adminPage. A language may leave {link} out - then no link is drawn.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('builds.role.main_dps', 'Main DPS'),
    ('builds.role.sub_dps', 'Sub DPS'),
    ('builds.role.support', 'Support'),
    ('builds.role.unknown', 'Build'),
    ('builds.adminPage', 'Builds'),
    ('character.needsRank', 'needs {rank}'),
    ('character.rankNotReached', 'This build needs {rank}; {name} is {current}.'),
    ('buildDetail.substatsDerived', 'Not listed for this build; the analysis goes by what its main stats ask for.'),
    ('buildDetail.notes', 'Notes'),
    ('account.bestBuild', 'Best build: {build}'),
    ('dashboard.builds', 'Builds'),
    ('dashboard.manageBuilds', 'Manage'),
    ('dashboard.buildsFor.one', 'Builds for {count} character'),
    ('dashboard.buildsFor.few', 'Builds for {count} characters'),
    ('dashboard.buildsFor.other', 'Builds for {count} characters'),
    ('dashboard.buildsUpdated', 'updated {when}'),
    ('dashboard.buildsNever', 'not imported yet'),
    ('dashboard.noBuildsStaff', 'No builds yet. Import them on the {link} page.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('builds.role.main_dps', 'Hlavný DPS'),
    ('builds.role.sub_dps', 'Vedľajší DPS'),
    ('builds.role.support', 'Podpora'),
    ('builds.role.unknown', 'Build'),
    ('builds.adminPage', 'Builds'),
    ('character.needsRank', 'potrebuje {rank}'),
    ('character.rankNotReached', 'Tento build potrebuje {rank}; {name} má {current}.'),
    ('buildDetail.substatsDerived', 'Tento build ich neuvádza; analýza sa riadi tým, čo si žiadajú jeho main stats.'),
    ('buildDetail.notes', 'Poznámky'),
    ('account.bestBuild', 'Najlepší build: {build}'),
    ('dashboard.builds', 'Buildy'),
    ('dashboard.manageBuilds', 'Spravovať'),
    ('dashboard.buildsFor.one', 'Buildy pre {count} postavu'),
    ('dashboard.buildsFor.few', 'Buildy pre {count} postavy'),
    ('dashboard.buildsFor.other', 'Buildy pre {count} postáv'),
    ('dashboard.buildsUpdated', 'aktualizované {when}'),
    ('dashboard.buildsNever', 'zatiaľ neimportované'),
    ('dashboard.noBuildsStaff', 'Zatiaľ tu nie sú žiadne buildy. Importuješ ich na stránke {link}.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ── The fifteen sentences, where they read as seeded ────────────────────────
UPDATE translations t
   SET value = v.value
  FROM (VALUES
    ('builds.subtitle', 'en',
     'Everything the build syncs collected, per character and source.',
     'The recommended builds of each character, by what they are for.'),
    ('builds.subtitle', 'sk',
     'Všetko, čo nazbierali synchronizácie buildov, podľa postáv a zdrojov.',
     'Odporúčané buildy každej postavy podľa toho, na čo slúžia.'),
    ('builds.empty', 'en',
     'No builds yet. Start a sync on the {link} page.',
     'No builds yet. An editor imports them on the {link} page.'),
    ('builds.empty', 'sk',
     'Zatiaľ tu nie sú žiadne buildy. Synchronizáciu spustíš na stránke {link}.',
     'Zatiaľ tu nie sú žiadne buildy. Editor ich importuje na stránke {link}.'),
    ('character.noBuilds', 'en',
     'No builds for {name} yet. They appear after the next build sync (admins start one on the {link} page).',
     'No builds for {name} yet. They appear once an editor imports them (on the {link} page).'),
    ('character.noBuilds', 'sk',
     '{name}: zatiaľ žiadne buildy. Pribudnú po ďalšej synchronizácii buildov (správcovia ju spúšťajú na stránke {link}).',
     '{name}: zatiaľ žiadne buildy. Pribudnú, keď ich editor importuje (na stránke {link}).'),
    ('dashboard.noBuildsMember', 'en',
     'No builds downloaded yet. They appear once an admin syncs the build sites.',
     'No builds yet. They appear once an editor imports them.'),
    ('dashboard.noBuildsMember', 'sk',
     'Zatiaľ nie sú stiahnuté žiadne buildy. Pribudnú, keď správca synchronizuje zdroje buildov.',
     'Zatiaľ tu nie sú žiadne buildy. Pribudnú, keď ich editor importuje.'),
    ('account.withoutBuilds.one', 'en',
     '{count} character has no builds yet; they appear once a build sync has fetched them.',
     '{count} character has no builds yet.'),
    ('account.withoutBuilds.one', 'sk',
     '{count} postava zatiaľ nemá buildy; pribudnú po ďalšej synchronizácii buildov.',
     '{count} postava zatiaľ nemá buildy.'),
    ('account.withoutBuilds.few', 'en',
     '{count} characters have no builds yet; they appear once a build sync has fetched them.',
     '{count} characters have no builds yet.'),
    ('account.withoutBuilds.few', 'sk',
     '{count} postavy zatiaľ nemajú buildy; pribudnú po ďalšej synchronizácii buildov.',
     '{count} postavy zatiaľ nemajú buildy.'),
    ('account.withoutBuilds.other', 'en',
     '{count} characters have no builds yet; they appear once a build sync has fetched them.',
     '{count} characters have no builds yet.'),
    ('account.withoutBuilds.other', 'sk',
     '{count} postáv zatiaľ nemá buildy; pribudnú po ďalšej synchronizácii buildov.',
     '{count} postáv zatiaľ nemá buildy.'),
    ('buildDetail.noWeapons', 'en',
     'This source lists none.',
     'This build lists none.'),
    ('buildDetail.noWeapons', 'sk',
     'Tento zdroj tu nič neuvádza.',
     'Tento build tu nič neuvádza.'),
    ('import.warning.catalog_behind', 'en',
     'What the game catalog does not know yet was skipped. After an admin refreshes the catalog (Sources page), import again.',
     'What the game catalog does not know yet was skipped. Import again later: the catalog catches up on its own, or an editor refreshes it in the admin panel (Builds page).'),
    ('import.warning.catalog_behind', 'sk',
     'Čo katalóg hry ešte nepozná, to import preskočil. Keď správca obnoví katalóg (stránka Zdroje), importuj znova.',
     'Čo katalóg hry ešte nepozná, to import preskočil. Importuj znova neskôr: katalóg sa doplní sám, alebo ho editor obnoví v administrácii (stránka Builds).'),
    ('legal.staff.name', 'en',
     'If you run syncs or keep the site''s words',
     'If you import builds or keep the site''s words'),
    ('legal.staff.name', 'sk',
     'Ak spúšťaš synchronizácie alebo spravuješ slová stránky',
     'Ak importuješ buildy alebo spravuješ slová stránky'),
    ('legal.staff.what', 'en',
     'Editors and administrators sync the builds and reword the site''s words in its admin panel: each sync keeps who started it and who stopped it, and each word who saved it last, and when.',
     'Editors and administrators import the builds and reword the site''s words in its admin panel: each import of builds keeps who made it, and each word who saved it last, and when.'),
    ('legal.staff.what', 'sk',
     'Editori a správcovia v administrátorskom paneli synchronizujú buildy a upravujú slová stránky: pri každej synchronizácii sa uchováva, kto ju spustil a kto ju zastavil, a pri každom slove, kto ho naposledy uložil a kedy.',
     'Editori a správcovia v administrátorskom paneli importujú buildy a upravujú slová stránky: pri každom importe buildov sa uchováva, kto ho urobil, a pri každom slove, kto ho naposledy uložil a kedy.'),
    ('legal.staff.kept', 'en',
     'With no set time limit. When your anotoki account is deleted, the syncs'' notes no longer name you; when an administrator deletes your data here, the syncs and words stay, by nobody.',
     'With no set time limit. When your anotoki account is deleted, they no longer show your name; when an administrator deletes your data here, the imports and words stay, by nobody.'),
    ('legal.staff.kept', 'sk',
     'Bez stanoveného časového obmedzenia. Po zmazaní tvojho účtu anotoki ťa záznamy synchronizácií už neuvádzajú menom; keď tvoje údaje tu zmaže správca, synchronizácie aj slová zostanú bez toho, kto ich urobil.',
     'Bez stanoveného časového obmedzenia. Po zmazaní tvojho účtu anotoki už neukazujú tvoje meno; keď tvoje údaje tu zmaže správca, importy aj slová zostanú bez toho, kto ich urobil.'),
    ('legal.limits.kept', 'en',
     'A minute, an hour or a day each. Counts that have run out are deleted the next time the site tidies up - with the next count, or when an editor''s next sync of the builds ends.',
     'A minute, an hour or a day each. Counts that have run out are deleted the next time the site tidies up - with the next count, or once a day.'),
    ('legal.limits.kept', 'sk',
     'Každý počet minútu, hodinu alebo deň. Počty, ktorým čas uplynul, sa zmažú pri najbližšom upratovaní stránky - pri ďalšom počítaní alebo na konci najbližšej synchronizácie buildov, ktorú spustí editor.',
     'Každý počet minútu, hodinu alebo deň. Počty, ktorým čas uplynul, sa zmažú pri najbližšom upratovaní stránky - pri ďalšom počítaní alebo raz denne.'),
    ('legal.showcase.kept', 'en',
     'As long as the service asks - a few minutes -, then deleted the next time the site tidies up (when an editor''s next sync of the builds ends).',
     'As long as the service asks - a few minutes -, then deleted the next time the site tidies up (once a day).'),
    ('legal.showcase.kept', 'sk',
     'Tak dlho, ako služba požiada - pár minút -, potom sa zmaže pri najbližšom upratovaní stránky (na konci najbližšej synchronizácie buildov, ktorú spustí editor).',
     'Tak dlho, ako služba požiada - pár minút -, potom sa zmaže pri najbližšom upratovaní stránky (raz denne).'),
    ('legal.auditLog.what', 'en',
     'What the site''s editors and administrators do in its admin panel - syncs started and stopped, sources switched on or off, builds brought in from a file, the words and languages saved, a person''s data deleted, an update applied, the backups, the error log and feedback handled: who, what and when. Never an IP address.',
     'What the site''s editors and administrators do in its admin panel - builds imported from a file and the game catalog refreshed, the words and languages saved, a person''s data deleted, an update applied, the backups, the error log and feedback handled: who, what and when. Never an IP address.'),
    ('legal.auditLog.what', 'sk',
     'Čo robia editori a správcovia stránky v administrátorskom paneli - spustené a zastavené synchronizácie, zapnuté a vypnuté zdroje, buildy prinesené zo súboru, uložené slová a jazyky, zmazané údaje človeka, použitá aktualizácia, zálohy, záznam chýb a spätná väzba: kto, čo a kedy. Nikdy nie IP adresa.',
     'Čo robia editori a správcovia stránky v administrátorskom paneli - buildy importované zo súboru a obnovený herný katalóg, uložené slová a jazyky, zmazané údaje človeka, použitá aktualizácia, zálohy, záznam chýb a spätná väzba: kto, čo a kedy. Nikdy nie IP adresa.')
  ) AS v(key_name, language_code, seeded, value)
 WHERE t.key_name = v.key_name AND t.language_code = v.language_code AND t.value = v.seeded;

-- ── The descriptions: builds without sources ────────────────────────────────
UPDATE translation_keys k
   SET description = v.description
  FROM (VALUES
    ('builds.empty', 'The Builds page when no character has a build yet. {link} is where the link to the admin panel''s Builds page stands; its text is builds.adminPage. A language may leave {link} out - then no link is drawn.'),
    ('character.noBuilds', 'The character page when the character has no build yet. {name} is the character''s name (never declined: keep it first or after a colon); {link} is where the link to the admin panel''s Builds page stands, its text is builds.adminPage. A language may leave {link} out - then no link is drawn.'),
    ('dashboard.noBuildsMember', 'The dashboard, for everybody but an editor or an admin, while no build has been imported yet. No placeholders.'),
    ('account.noBuildsForCharacter', 'The account page, on the card of a character without a build yet. No placeholders.'),
    ('account.withoutBuilds.one', 'The account page, under the characters: how many of the account''s characters have no build yet - the form for 1. {count}: that number.'),
    ('account.withoutBuilds.few', 'The account page, under the characters: how many of the account''s characters have no build yet - the form for 2 to 4 (in English the same as for many). {count}: that number.'),
    ('account.withoutBuilds.other', 'The account page, under the characters: how many of the account''s characters have no build yet - the form for 5 or more. {count}: that number.'),
    ('buildDetail.checked', 'A build''s detail, the foot: when the file that last brought the build was made - how up to date it is. {when} is how long ago, already in the page''s language as an adverbial ("3 hours ago" / "pred 3 hodinami").'),
    ('character.tabScore', 'The character page, a build''s tab: what a screen reader hears after the tab''s name - the build''s score, which the tab shows as a small badge ("Main DPS, score 87"; for the build closest to the character''s setup, character.closestMatch follows it, and for a build whose constellation the character does not reach, character.needsRank). {score}: the score out of 100, written the page''s way. Placeholders: {score}.'),
    ('character.versus', 'The character page, the header: under the overall score, which build the score is measured against. {build} is the build''s variation as the page writes it, "Main DPS · Melt · C2+" - kept as it is.'),
    ('import.warning.catalog_behind', 'A warning of an import that went through (import wizard, import history): something was left out because the game catalog is older than the game. The catalog refreshes itself when an import meets what it does not know; "Builds page" is the admin panel''s page where editors refresh it. No placeholders.'),
    ('legal.staff.name', 'The Privacy page: the heading of the part about the editors and administrators - who imported the builds, who saved one of the site''s words - and its line under "How long it is kept". Short. No placeholders.'),
    ('legal.staff.what', 'The Privacy page, the editors'' and administrators'' traces: what is kept - who made each import of builds, each word''s last writer and when. No placeholders.'),
    ('legal.staff.kept', 'The Privacy page, the editors'' and administrators'' traces: how long - no set limit; a person whose anotoki account is deleted is no longer named, and an administrator''s delete of a person''s data leaves the imports and words by nobody. No placeholders.'),
    ('legal.limits.kept', 'The Privacy page, the limits: how long a count stays - a minute, an hour or a day; then deleted when the site next tidies up (the next count, or the day''s tidying up). No placeholders.'),
    ('legal.showcase.kept', 'The Privacy page, the showcase answers: how long - as long as the service asks (a few minutes), then deleted when the site next tidies up (once a day). No placeholders.')
  ) AS v(name, description)
 WHERE k.name = v.name;

UPDATE translation_keys
   SET description = replace(description, '(before " · Source 1, Source 2")', '(before " · Main DPS, Support": the roles of its builds)')
 WHERE name IN ('builds.count.one', 'builds.count.few', 'builds.count.other');

-- ============================================================================
-- PROVE IT
--
-- The retired keys are gone with every string of theirs, each new key has its
-- English and its Slovak, the reworded keys still have English, no description
-- of the site's own tells of sources or syncs, and the site's own keys are 015's
-- less 23 and with 18 more (427 - 23 + 18).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 422;
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT string_agg(name, ', ' ORDER BY name COLLATE "C") INTO wrong
      FROM translation_keys
     WHERE name IN (
        'builds.sourcesPage', 'nav.sources', 'toolbar.syncing', 'toolbar.syncingLabel',
        'dashboard.sources', 'dashboard.manageSources', 'dashboard.noBuildsAdmin', 'dashboard.syncing', 'dashboard.notSyncedYet',
        'buildDetail.substatsBorrowed', 'buildDetail.notesFrom', 'buildDetail.updatedAtSource', 'buildDetail.checkedNever',
        'account.bestMatch'
     ) OR name LIKE 'character.consensus.%' OR name LIKE 'dashboard.sourceCharacters.%';
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys this file retires are still there: %', wrong;
    END IF;

    SELECT string_agg(DISTINCT key_name, ', ' ORDER BY key_name) INTO wrong
      FROM translations
     WHERE key_name LIKE 'character.consensus.%' OR key_name LIKE 'dashboard.sourceCharacters.%' OR key_name IN ('builds.sourcesPage', 'nav.sources', 'account.bestMatch');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Strings of the retired keys are still there: %', wrong;
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM translation_keys k
     WHERE (k.name LIKE 'builds.role.%' OR k.name LIKE 'dashboard.buildsFor.%' OR k.name IN (
            'builds.adminPage', 'character.needsRank', 'character.rankNotReached', 'buildDetail.substatsDerived', 'buildDetail.notes',
            'account.bestBuild', 'dashboard.builds', 'dashboard.manageBuilds', 'dashboard.buildsUpdated', 'dashboard.buildsNever',
            'dashboard.noBuildsStaff'))
       AND (SELECT count(*) FROM translations t WHERE t.key_name = k.name AND t.language_code IN ('en', 'sk')) <> 2;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'New keys without their English and Slovak: %', wrong;
    END IF;

    SELECT count(*) INTO found
      FROM translation_keys
     WHERE name LIKE 'builds.role.%' OR name LIKE 'dashboard.buildsFor.%' OR name IN (
            'builds.adminPage', 'character.needsRank', 'character.rankNotReached', 'buildDetail.substatsDerived', 'buildDetail.notes',
            'account.bestBuild', 'dashboard.builds', 'dashboard.manageBuilds', 'dashboard.buildsUpdated', 'dashboard.buildsNever',
            'dashboard.noBuildsStaff');
    IF found <> 18 THEN
        RAISE EXCEPTION 'There are % new keys, and this file adds 18', found;
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM translation_keys k
     WHERE k.name IN ('builds.subtitle', 'builds.empty', 'character.noBuilds', 'dashboard.noBuildsMember', 'account.withoutBuilds.one',
                      'account.withoutBuilds.few', 'account.withoutBuilds.other', 'buildDetail.noWeapons', 'import.warning.catalog_behind',
                      'legal.staff.name', 'legal.staff.what', 'legal.staff.kept', 'legal.limits.kept', 'legal.showcase.kept', 'legal.auditLog.what')
       AND NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys without an English string: %', wrong;
    END IF;

    SELECT string_agg(name, ', ' ORDER BY name COLLATE "C") INTO wrong
      FROM translation_keys
     WHERE name NOT LIKE 'anotoki.%'
       AND description ~* '(source 1|sources page|a sync|syncs|synced|syncing|"source|source''s|no source|from which sources|sources agree)';
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Descriptions that still tell of sources or syncs: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 015 with this file leave %', found, expected_keys;
    END IF;
END $$;
