-- ============================================================================
-- 011_legal_pages.sql - the site's facts on the family's Terms, Privacy and Contact pages, and the toasts' descriptions
-- ============================================================================
-- Version 0.4 (anotoki-lib 0.6.2). Every anotoki site shows Terms, Privacy and
-- Contact - the family's pages (@anotoki/lib/legal/pages), their structure and
-- shared words the library's (anotoki.legal.*, which its set
-- anotoki_translations writes in its 005 to 011, after this site's files), each
-- site's facts its own (the frontend's core/legal-facts.ts names them). These
-- are this site's, true of the code as it is - what it keeps, what for and how
-- long, its browser storage by name, who else handles data - in English and
-- informal Slovak (ty, and nothing gendered about the reader):
--
--   title.terms|privacy|contact  the browser tab's titles of the three pages
--   footer.fanMade               the fan clause under every page (the owner's decision of 2026-10-13)
--   legal.about                  what Build Analyzer is (Terms' first words)
--   legal.fanClause              the fan clause on Terms and Contact, naming both games - read in place of the
--                                library's anotoki.legal.fanHoyoverse, which names Genshin Impact alone
--   legal.record.*               the record of a person (users)
--   legal.accounts.*             the game accounts and imports (accounts, account_*, import_runs)
--   legal.staff.*                who started a sync, who saved a word (sync_runs, translations.updated_by)
--   legal.limits.*               the counts that stop floods (keyed codes or the account; a minute, an hour, a day)
--   legal.showcase.*             the showcase service's answers, cached a few minutes (cache)
--   legal.log.*                  the site's own log (storage/logs/app.log)
--   legal.visitors.*             using the site without an account ({days}: 30)
--   legal.feedback.*             the form at the foot of every page
--   legal.auditLog.what          the audit log - the editors' and administrators' changes
--   legal.backups.what           the backups, one on each day the site is used
--   legal.showcaseService.*      who else handles data: the showcase service, never named (nothing the site shows
--                                names another website, service or tool)
--   legal.storage.*              the cookies and stored items the library has no words for
--
-- No string holds an address: where a link goes (the anotoki account's pages,
-- the operator, the hosting company, Cloudflare, the authority) is the code's,
-- never a string an administrator rewords (the library's 0.6.0 review).
--
-- And five keys whose descriptions still told of a "snack bar" - Angular
-- Material's, gone since the site's pages moved onto the family's kit, whose
-- messages are its toasts - are described as what they are now: common.close
-- (only the What's-new dialog's Close now), dashboard.deleted, data.exported,
-- visitor.moved.one and visitor.kept.one.
--
-- In 003's form: a key with its description, its English and its Slovak
-- string, every insert stepping aside for a row that is already there; then
-- the descriptions, and the gate. Nothing else of the schema, and nothing of
-- anybody's data, changes.
--
-- Applying it: with the other files of version 0.4, in one Apply on the admin
-- panel's Migrations page (see 007) - the library's translations 005 to 011
-- (the legal pages' words, the kit's newer pieces') run right after it. Export
-- every language from the admin panel's Translations page first: a copy of what
-- the editors wrote.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

INSERT INTO translation_keys (name, description) VALUES
    -- title: the browser tab's titles of the legal pages
    ('title.terms', 'The browser tab''s title of the Terms of use page (/terms, the anotoki family''s legal page), before " · Build Analyzer". The same words as the page''s own heading (the library''s anotoki.legal.termsTitle). No placeholders.'),
    ('title.privacy', 'The browser tab''s title of the Privacy policy page (/privacy, the anotoki family''s legal page), before " · Build Analyzer". The same words as the page''s own heading (the library''s anotoki.legal.privacyTitle). No placeholders.'),
    ('title.contact', 'The browser tab''s title of the Contact page (/contact, the anotoki family''s legal page), before " · Build Analyzer". The same words as the page''s own heading (the library''s anotoki.legal.contactTitle). No placeholders.'),
    -- footer: the fan clause under every page
    ('footer.fanMade', 'The foot of every page, under Terms · Privacy · Contact: the fan clause (the owner''s decision of 2026-10-13: the site is a fan project, not the games'' publisher''s). HoYoverse is the games'' publisher, a name never translated. Terms and Contact say it longer (legal.fanClause). No placeholders.'),
    -- legal: the site's facts on the family's Terms, Privacy and Contact pages (the library's words around them)
    ('legal.about', 'Terms of use, its first section ("What Build Analyzer is"): what the site is, a sentence or two - the library''s next sentence says it is one of the anotoki sites, run by its operator, free to use. The game names stay as they are; "anotoki" is the name of the accounts. No address in it. No placeholders.'),
    ('legal.fanClause', 'Terms of use and Contact: the fan clause, read in place of the library''s anotoki.legal.fanHoyoverse, whose second sentence names Genshin Impact alone - this site is about Honkai: Star Rail too. HoYoverse and the game names are never translated. The footer says its first sentence alone (footer.fanMade). No placeholders.'),
    ('legal.record.name', 'The Privacy page: the heading of one part of what the site keeps - its record of each person who signs in - and its line under "How long it is kept". Short. No placeholders.'),
    ('legal.record.what', 'The Privacy page, the record of a person: exactly what it holds - the anotoki account''s id and the name from the sign-in, first and last use; never an e-mail address. No placeholders.'),
    ('legal.record.why', 'The Privacy page, the record of a person: what it is for (under "What for"). No placeholders.'),
    ('legal.record.kept', 'The Privacy page, the record of a person: how long it stays (under "How long it is kept") - until an administrator deletes the person''s data on the People page; when the anotoki account is deleted, the name goes from it. No placeholders.'),
    ('legal.accounts.name', 'The Privacy page: the heading of the part about each person''s game accounts and imports, and its line under "How long it is kept". Short. No placeholders.'),
    ('legal.accounts.what', 'The Privacy page, the game accounts: what an import keeps - the account''s UID, in-game nickname or the name given to it, level, world level, signature and region; the characters, weapons or light cones and artifacts or relics with their stats; each import (when, how, the file''s name, what could not be read). Game terms stay as the games write them. No placeholders.'),
    ('legal.accounts.why', 'The Privacy page, the game accounts: what they are for (under "What for"). No placeholders.'),
    ('legal.accounts.kept', 'The Privacy page, the game accounts: how long they stay - until the person deletes one, or an administrator deletes their data; a new import replaces what it brings again; when the anotoki account is deleted they stay, with their UIDs and in-game nicknames, tied to nobody''s name. No placeholders.'),
    ('legal.staff.name', 'The Privacy page: the heading of the part about the editors and administrators - who started or stopped a sync of the builds, who saved one of the site''s words - and its line under "How long it is kept". Short. No placeholders.'),
    ('legal.staff.what', 'The Privacy page, the editors'' and administrators'' traces: what is kept - each sync''s starter and the one who stopped it, each word''s last writer and when. No placeholders.'),
    ('legal.staff.why', 'The Privacy page, the editors'' and administrators'' traces: what they are for (under "What for"). No placeholders.'),
    ('legal.staff.kept', 'The Privacy page, the editors'' and administrators'' traces: how long - no set limit; the syncs'' notes stop naming a person whose anotoki account is deleted, and an administrator''s delete of a person''s data leaves the syncs and words by nobody. No placeholders.'),
    ('legal.limits.name', 'The Privacy page: the heading of the part about the counts that stop one sender from flooding the site (imports, new visitors, feedback, error reports), and its line under "How long it is kept". Short. No placeholders.'),
    ('legal.limits.what', 'The Privacy page, the limits: what is counted, and how - by the account, or by a code made from the IP address with a secret key, never the address itself. No placeholders.'),
    ('legal.limits.why', 'The Privacy page, the limits: what they are for (under "What for"). No placeholders.'),
    ('legal.limits.kept', 'The Privacy page, the limits: how long a count stays - a minute, an hour or a day; then deleted when the site next tidies up (the next count, or the end of an editor''s next sync of the builds). No placeholders.'),
    ('legal.showcase.name', 'The Privacy page: the heading of the part about the showcase service''s answers the site keeps a few minutes (Character Showcase is the games'' own feature, never translated), and its line under "How long it is kept". Short. No placeholders.'),
    ('legal.showcase.what', 'The Privacy page, the showcase answers: what is kept for a UID imported by its number - the service''s whole answer: what the game shows everybody, and what the service says of the UID; nothing of who asked. No placeholders.'),
    ('legal.showcase.why', 'The Privacy page, the showcase answers: what they are for (under "What for"). No placeholders.'),
    ('legal.showcase.kept', 'The Privacy page, the showcase answers: how long - as long as the service asks (a few minutes), then deleted when the site next tidies up (the end of an editor''s next sync of the builds). No placeholders.'),
    ('legal.log.name', 'The Privacy page: the heading of the part about the site''s own log (what it does by itself), and its line under "How long it is kept". Short. No placeholders.'),
    ('legal.log.what', 'The Privacy page, the site''s own log: what its lines say - visitors'' data deleted, updates applied, a setting it could not read, rarely an anotoki account''s id; never an IP address or anybody''s imports. No placeholders.'),
    ('legal.log.why', 'The Privacy page, the site''s own log: what it is for (under "What for"). No placeholders.'),
    ('legal.log.kept', 'The Privacy page, the site''s own log: how long - until newer lines push the old ones out (at most about 10 MB is kept). No placeholders.'),
    ('legal.visitors.what', 'The Privacy page, the part "Using the site without an account" (its heading the library''s): what is kept while the site lets people use it without signing in - their game accounts and imports under a random key of the browser''s (only a hash of it kept), when made and last used. No placeholders.'),
    ('legal.visitors.why', 'The Privacy page, using the site without an account: what it is for (under "What for"). No placeholders.'),
    ('legal.visitors.kept', 'The Privacy page, using the site without an account: how long it stays - until a sign-in moves it into the account, or {days} days after the last visit. {days}: a whole number of days (30), the same as the notice to somebody not signed in says (visitor.notice). Placeholders: {days}.'),
    ('legal.feedback.what', 'The Privacy page, the part "Feedback" (its heading the library''s): exactly what a message sent with the form keeps - the words and their subject, its kind and part of the site, the page without anything after ? or #, its language, the browser, the account unless sent without the name, a reply address only if left. No placeholders.'),
    ('legal.feedback.kept', 'The Privacy page, feedback: how long a message stays - until an administrator deletes it; one marked as spam 30 days, a resolved one a year (deleted with the first message sent after that day); the reply address and browser go when the account is deleted or an administrator deletes the person''s data. No placeholders.'),
    ('legal.auditLog.what', 'The Privacy page, the part "The audit log" (its heading the library''s): what it keeps - the editors'' and administrators'' changes in the admin panel: who, what and when; never an IP address. No placeholders.'),
    ('legal.backups.what', 'The Privacy page, the part "Backups" (its heading the library''s): what a backup is and when one is made; the library''s sentences on where they are kept and how long follow it. No placeholders.'),
    ('legal.showcaseService.name', 'The Privacy page, "Who else handles data": the name given to the public service that answers a UID''s in-game Character Showcase - described, never named (nothing the site shows names another website, service or tool). Short. No placeholders.'),
    ('legal.showcaseService.role', 'The Privacy page, "Who else handles data": what the showcase service gets - a UID, from the site''s server, when somebody imports by UID; never their IP address or anything that names them. No placeholders.'),
    ('legal.storage.signIn.purpose', 'The Privacy page, "Cookies and storage in your browser": what the sign-in''s stored item is for (anotoki-auth:build-analyzer, the keys the anotoki account gave this site). No placeholders.'),
    ('legal.storage.hintTried.purpose', 'The Privacy page, browser storage: the note of the last time the site tried to sign somebody in without a click, and with which of the anotoki account''s hint values (anotoki-auth:build-analyzer:hint), so that each is tried once. No placeholders.'),
    ('legal.storage.hintTried.kept', 'The Privacy page, browser storage: how long that note stays - until the next try, or the site''s data is cleared. No placeholders.'),
    ('legal.storage.signInUnderWay.purpose', 'The Privacy page, browser storage: the items a sign-in keeps for its tab while it is on its way to the anotoki account and back (one-time check values, the account''s public settings). No placeholders.'),
    ('legal.storage.signInUnderWay.kept', 'The Privacy page, browser storage: how long a sign-in on its way keeps its items - until it comes back (the settings an hour), never longer than the tab is open. No placeholders.'),
    ('legal.storage.signInKind.purpose', 'The Privacy page, browser storage: how the present sign-in began - by itself (the anotoki account''s hint) or by a click (build-analyzer-sign-in) - so that an account with data here takes in a browser''s visitor data only after a click. No placeholders.'),
    ('legal.storage.signInKind.kept', 'The Privacy page, browser storage: how long that note stays - until the next sign-in in the browser, or the site''s data is cleared. No placeholders.'),
    ('legal.storage.tabLanguage.purpose', 'The Privacy page, browser storage: the language a link with ?lang= chose, kept for that tab alone. No placeholders.'),
    ('legal.storage.untilTabClosed', 'The Privacy page, browser storage: how long an item kept for one tab stays - until the tab is closed. No placeholders.'),
    ('legal.storage.visitorKey.kept', 'The Privacy page, browser storage: how long the key of somebody using the site without an account stays in the browser - until a sign-in moves their imports into the account, the site no longer knows it, or the site''s data is cleared. No placeholders.'),
    ('legal.storage.whatsNew.purpose', 'The Privacy page, browser storage: the last version whose news the browser showed (What''s new: whatsNew.title), so that it opens once a version. No placeholders.'),
    ('legal.storage.whatsNew.kept', 'The Privacy page, browser storage: how long that note stays - until a newer version is shown, or the site''s data is cleared. No placeholders.'),
    ('legal.storage.wordDrafts.purpose', 'The Privacy page, browser storage: for editors and administrators, the words typed on the admin panel''s Translations and Languages pages and not saved yet (those pages'' names are English, as the admin panel is). No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('title.terms', 'Terms of use'),
    ('title.privacy', 'Privacy policy'),
    ('title.contact', 'Contact'),
    ('footer.fanMade', 'This site is a fan-made project and is not affiliated with or endorsed by HoYoverse.'),
    ('legal.about', 'Build Analyzer compares your Genshin Impact and Honkai: Star Rail characters with the builds community sites recommend, and shows what to improve. You import a game account by its UID or from an inventory file - with your anotoki account, or without one.'),
    ('legal.fanClause', 'This site is a fan-made project and is not affiliated with or endorsed by HoYoverse. Genshin Impact, Honkai: Star Rail and their names, images and content belong to their respective owners.'),
    ('legal.record.name', 'Your record'),
    ('legal.record.what', 'Your anotoki account''s id and the name it gives (copied from each sign-in), and when you first and last used the site.'),
    ('legal.record.why', 'To keep your game accounts under your account, and show them to you alone.'),
    ('legal.record.kept', 'Until an administrator deletes your data here - signing in again makes a new, empty record. When your anotoki account is deleted, it stays without your name.'),
    ('legal.accounts.name', 'Your game accounts'),
    ('legal.accounts.what', 'What you import: each game account''s UID, its in-game nickname (or the name you give it), level, world level, signature and region; its characters, weapons or light cones, and artifacts or relics, with their stats; and your imports - when, by UID or from a file, the file''s name, and what could not be read.'),
    ('legal.accounts.why', 'To compare your characters with the recommended builds, and keep them for your next visit - on every device you sign in on.'),
    ('legal.accounts.kept', 'Until you delete the game account, or an administrator deletes your data here; a new import replaces what it brings again. When your anotoki account is deleted, your game accounts stay - with their UIDs and in-game nicknames, but no longer tied to your name.'),
    ('legal.staff.name', 'If you run syncs or keep the site''s words'),
    ('legal.staff.what', 'Editors and administrators sync the builds and reword the site''s words in its admin panel: each sync keeps who started it and who stopped it, and each word who saved it last, and when.'),
    ('legal.staff.why', 'So that the people who look after the site know who did what.'),
    ('legal.staff.kept', 'With no set time limit. When your anotoki account is deleted, the syncs'' notes no longer name you; when an administrator deletes your data here, the syncs and words stay, by nobody.'),
    ('legal.limits.name', 'Limits against floods'),
    ('legal.limits.what', 'How many imports each person or address makes, how many people start without an account from one address, and how many feedback messages and error reports each sender sends - counted by the account, or by a code made from the IP address with a secret key, never the address itself.'),
    ('legal.limits.why', 'To keep the site working for everybody when somebody sends too much.'),
    ('legal.limits.kept', 'A minute, an hour or a day each. Counts that have run out are deleted the next time the site tidies up - with the next count, or when an editor''s next sync of the builds ends.'),
    ('legal.showcase.name', 'Showcase answers'),
    ('legal.showcase.what', 'When you import by UID: a copy of the showcase service''s answer for that UID - what the game shows everybody (the in-game nickname, level, signature and the showcase characters with their gear) and what the service itself says of the UID. Nothing of who asked.'),
    ('legal.showcase.why', 'So that the site does not ask the service for the same UID twice within a few minutes.'),
    ('legal.showcase.kept', 'As long as the service asks - a few minutes -, then deleted the next time the site tidies up (when an editor''s next sync of the builds ends).'),
    ('legal.log.name', 'The site''s own log'),
    ('legal.log.what', 'Lines about what the site does by itself: visitors'' data deleted, updates applied, a setting it could not read - and, rarely, an anotoki account''s id where taking a deleted account''s name out went wrong. Never an IP address or what you imported.'),
    ('legal.log.why', 'To see what the site did, and put right what went wrong.'),
    ('legal.log.kept', 'Until newer lines push them out: at most about 10 MB of lines are kept.'),
    ('legal.visitors.what', 'While Build Analyzer lets you use it without signing in: the game accounts and imports you make, as listed above, under a random key this browser keeps (the site keeps only a hash of it), and when it was made and last used.'),
    ('legal.visitors.why', 'So that you can use the site without an account - and take your game accounts into your account when you sign in.'),
    ('legal.visitors.kept', 'Until a sign-in moves them into your account, or until {days} days have passed since your last visit, when they are deleted.'),
    ('legal.feedback.what', 'What you send with the form at the foot of every page: your message (and its subject, if you give one), its kind and the part of the site it is about, the page''s address without anything after ? or #, the page''s language and your browser''s name; your account''s id, unless you send it without your name; and an address to answer you at, only if you leave one (signed in, the form writes your account''s in - you may change it or clear it).'),
    ('legal.feedback.kept', 'Until an administrator deletes it - a message marked as spam goes 30 days later, a resolved one a year later (with the first message sent after that day). When your anotoki account is deleted, or an administrator deletes your data here, the reply address and the browser go from your messages; the words stay.'),
    ('legal.auditLog.what', 'What the site''s editors and administrators do in its admin panel - syncs started and stopped, sources switched on or off, builds brought in from a file, the words and languages saved, a person''s data deleted, an update applied, the backups, the error log and feedback handled: who, what and when. Never an IP address.'),
    ('legal.backups.what', 'A copy of the site''s whole database: one on each day the site is used, one before every update and every restore, and one whenever an administrator makes it. Each copy holds what the site kept when it was made.'),
    ('legal.showcaseService.name', 'The showcase service'),
    ('legal.showcaseService.role', 'When you import an account by its UID, the site''s server asks this public service for that UID''s in-game Character Showcase. It gets the UID from our server - never your IP address or anything that names you.'),
    ('legal.storage.signIn.purpose', 'Your sign-in to this site: the keys your anotoki account gave it, and whose they are.'),
    ('legal.storage.hintTried.purpose', 'When this site last tried to sign you in without a click, and with which value from the anotoki account, so that it tries each one once.'),
    ('legal.storage.hintTried.kept', 'Until the next try, or until you clear the site''s data in your browser.'),
    ('legal.storage.signInUnderWay.purpose', 'A sign-in on its way: the one-time values it is checked with, and the anotoki account''s public settings.'),
    ('legal.storage.signInUnderWay.kept', 'Until the sign-in comes back - the settings an hour -, and never longer than the tab is open.'),
    ('legal.storage.signInKind.purpose', 'How your present sign-in began - by itself, or with your click -, so that an account that has data here takes in what this browser imported without an account only after a click.'),
    ('legal.storage.signInKind.kept', 'Until your next sign-in in this browser, or until you clear the site''s data in your browser.'),
    ('legal.storage.tabLanguage.purpose', 'The language a link with ?lang= chose, for this tab alone.'),
    ('legal.storage.untilTabClosed', 'Until the tab is closed.'),
    ('legal.storage.visitorKey.kept', 'Until a sign-in moves your imports into your account, the site no longer knows the key, or you clear the site''s data in your browser.'),
    ('legal.storage.whatsNew.purpose', 'The last version whose news this browser showed (What''s new), so that it opens once a version.'),
    ('legal.storage.whatsNew.kept', 'Until a newer version is shown, or until you clear the site''s data in your browser.'),
    ('legal.storage.wordDrafts.purpose', 'For editors and administrators: the words typed on the Translations and Languages pages and not saved yet.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('title.terms', 'Podmienky používania'),
    ('title.privacy', 'Ochrana osobných údajov'),
    ('title.contact', 'Kontakt'),
    ('footer.fanMade', 'Táto stránka je fanúšikovský projekt a nie je prepojená so spoločnosťou HoYoverse ani ňou schválená.'),
    ('legal.about', 'Build Analyzer porovná tvoje postavy z Genshin Impact a Honkai: Star Rail s buildmi, ktoré odporúčajú komunitné stránky, a ukáže, čo zlepšiť. Herný účet importuješ podľa UID alebo zo súboru s inventárom - s účtom anotoki aj bez neho.'),
    ('legal.fanClause', 'Táto stránka je fanúšikovský projekt a nie je prepojená so spoločnosťou HoYoverse ani ňou schválená. Genshin Impact, Honkai: Star Rail a ich názvy, obrázky a obsah patria ich príslušným vlastníkom.'),
    ('legal.record.name', 'Tvoj záznam'),
    ('legal.record.what', 'Identifikátor tvojho účtu anotoki a meno, ktoré účet poskytne (prevezme sa pri každom prihlásení), a čas prvého a posledného použitia stránky.'),
    ('legal.record.why', 'Aby sa tvoje herné účty uchovávali pod tvojím účtom a boli viditeľné len pre teba.'),
    ('legal.record.kept', 'Kým tvoje údaje tu nezmaže správca - ďalšie prihlásenie vytvorí nový, prázdny záznam. Po zmazaní tvojho účtu anotoki zostáva bez tvojho mena.'),
    ('legal.accounts.name', 'Tvoje herné účty'),
    ('legal.accounts.what', 'Čo importuješ: UID každého herného účtu, jeho prezývku v hre (alebo názov, ktorý mu dáš), level a world level, podpis a región; jeho postavy, weapons alebo light cones a artifacts alebo relics s ich hodnotami; a tvoje importy - kedy, či podľa UID alebo zo súboru, názov súboru a čo sa nedalo prečítať.'),
    ('legal.accounts.why', 'Aby sa tvoje postavy dali porovnať s odporúčanými buildmi a zostali tu na ďalšiu návštevu - na každom zariadení, kde sa prihlásiš.'),
    ('legal.accounts.kept', 'Kým herný účet nezmažeš alebo kým tvoje údaje tu nezmaže správca; nový import nahradí to, čo prinesie znova. Po zmazaní tvojho účtu anotoki tvoje herné účty zostávajú - s ich UID a prezývkami z hry, ale už nie pri tvojom mene.'),
    ('legal.staff.name', 'Ak spúšťaš synchronizácie alebo spravuješ slová stránky'),
    ('legal.staff.what', 'Editori a správcovia v administrátorskom paneli synchronizujú buildy a upravujú slová stránky: pri každej synchronizácii sa uchováva, kto ju spustil a kto ju zastavil, a pri každom slove, kto ho naposledy uložil a kedy.'),
    ('legal.staff.why', 'Aby tí, čo sa o stránku starajú, vedeli, kto čo urobil.'),
    ('legal.staff.kept', 'Bez stanoveného časového obmedzenia. Po zmazaní tvojho účtu anotoki ťa záznamy synchronizácií už neuvádzajú menom; keď tvoje údaje tu zmaže správca, synchronizácie aj slová zostanú bez toho, kto ich urobil.'),
    ('legal.limits.name', 'Obmedzenia proti zahlteniu'),
    ('legal.limits.what', 'Koľko importov urobí každý človek alebo adresa, koľko ľudí začne bez účtu z jednej adresy a koľko správ spätnej väzby a hlásení o chybách pošle každý odosielateľ - počíta sa podľa účtu alebo podľa kódu, ktorý vznikne z IP adresy s tajným kľúčom, nikdy nie podľa samotnej adresy.'),
    ('legal.limits.why', 'Aby stránka fungovala pre všetkých, aj keď niekto posiela priveľa.'),
    ('legal.limits.kept', 'Každý počet minútu, hodinu alebo deň. Počty, ktorým čas uplynul, sa zmažú pri najbližšom upratovaní stránky - pri ďalšom počítaní alebo na konci najbližšej synchronizácie buildov, ktorú spustí editor.'),
    ('legal.showcase.name', 'Kópie údajov z Character Showcase'),
    ('legal.showcase.what', 'Keď importuješ podľa UID: kópia odpovede služby pre toto UID - čo hra ukazuje všetkým (prezývka v hre, level, podpis a postavy z Character Showcase s ich výbavou) a čo o tomto UID hovorí samotná služba. Nič o tom, kto sa pýtal.'),
    ('legal.showcase.why', 'Aby stránka nežiadala od služby to isté UID dvakrát za pár minút.'),
    ('legal.showcase.kept', 'Tak dlho, ako služba požiada - pár minút -, potom sa zmaže pri najbližšom upratovaní stránky (na konci najbližšej synchronizácie buildov, ktorú spustí editor).'),
    ('legal.log.name', 'Vlastný záznam stránky'),
    ('legal.log.what', 'Riadky o tom, čo stránka robí sama: zmazané údaje návštevníkov, použité aktualizácie, nastavenie, ktoré nevedela prečítať - a zriedka identifikátor účtu anotoki, keď sa nepodarilo odstrániť meno zmazaného účtu. Nikdy nie IP adresa ani to, čo importuješ.'),
    ('legal.log.why', 'Aby bolo vidieť, čo stránka urobila, a dalo sa napraviť, čo sa pokazilo.'),
    ('legal.log.kept', 'Kým ich nevytlačia novšie riadky: uchováva sa najviac okolo 10 MB riadkov.'),
    ('legal.visitors.what', 'Kým Build Analyzer dovolí používať stránku bez prihlásenia: herné účty a importy, ktoré urobíš, ako je uvedené vyššie, pod náhodným kľúčom, ktorý uchováva tento prehliadač (stránka si z neho necháva len odtlačok), a kedy vznikol a kedy sa naposledy použil.'),
    ('legal.visitors.why', 'Aby sa stránka dala používať bez účtu - a pri prihlásení sa tvoje herné účty presunuli do tvojho účtu.'),
    ('legal.visitors.kept', 'Kým ich prihlásenie nepresunie do tvojho účtu, alebo kým od tvojej poslednej návštevy neuplynie toľko dní: {days}; potom sa zmažú.'),
    ('legal.feedback.what', 'Čo pošleš formulárom na konci každej stránky: tvoju správu (a jej predmet, ak ho uvedieš), jej druh a časť stránky, ktorej sa týka, adresu stránky bez všetkého za ? alebo #, jazyk stránky a názov tvojho prehliadača; identifikátor tvojho účtu, ak ju nepošleš bez mena; a adresu na odpoveď, len ak nejakú necháš (po prihlásení do formulára vpíše adresu tvojho účtu - môžeš ju zmeniť alebo vymazať).'),
    ('legal.feedback.kept', 'Kým ju správca nezmaže - správa označená ako spam zanikne o 30 dní, vyriešená o rok (s prvou správou poslanou po tom dni). Po zmazaní tvojho účtu anotoki, alebo keď tvoje údaje tu zmaže správca, sa z tvojich správ odstráni adresa na odpoveď aj údaj o prehliadači; text zostáva.'),
    ('legal.auditLog.what', 'Čo robia editori a správcovia stránky v administrátorskom paneli - spustené a zastavené synchronizácie, zapnuté a vypnuté zdroje, buildy prinesené zo súboru, uložené slová a jazyky, zmazané údaje človeka, použitá aktualizácia, zálohy, záznam chýb a spätná väzba: kto, čo a kedy. Nikdy nie IP adresa.'),
    ('legal.backups.what', 'Kópia celej databázy stránky: jedna v každý deň, keď sa stránka používa, jedna pred každou aktualizáciou a obnovením a jedna vždy, keď ju vytvorí správca. Každá kópia obsahuje to, čo stránka uchovávala v čase jej vzniku.'),
    ('legal.showcaseService.name', 'Služba pre Character Showcase'),
    ('legal.showcaseService.role', 'Keď importuješ účet podľa UID, server stránky si od tejto verejnej služby vyžiada Character Showcase tohto UID. Dostane UID od nášho servera - nikdy nie tvoju IP adresu ani nič, čo ťa identifikuje.'),
    ('legal.storage.signIn.purpose', 'Tvoje prihlásenie na tejto stránke: kľúče, ktoré jej dal tvoj účet anotoki, a komu patria.'),
    ('legal.storage.hintTried.purpose', 'Kedy sa ťa stránka naposledy pokúsila prihlásiť bez kliknutia a s ktorou hodnotou od účtu anotoki, aby každú skúsila len raz.'),
    ('legal.storage.hintTried.kept', 'Do ďalšieho pokusu alebo kým nevymažeš údaje stránky v prehliadači.'),
    ('legal.storage.signInUnderWay.purpose', 'Prihlásenie, ktoré práve prebieha: jednorazové hodnoty, ktorými sa overuje, a verejné nastavenia účtu anotoki.'),
    ('legal.storage.signInUnderWay.kept', 'Kým sa prihlásenie nevráti - nastavenia hodinu -, a nikdy nie dlhšie, než je karta otvorená.'),
    ('legal.storage.signInKind.purpose', 'Ako sa začalo tvoje súčasné prihlásenie - samo, alebo tvojím kliknutím -, aby účet, ktorý tu už má údaje, prevzal to, čo tento prehliadač importoval bez účtu, len po kliknutí.'),
    ('legal.storage.signInKind.kept', 'Do tvojho ďalšieho prihlásenia v tomto prehliadači alebo kým nevymažeš údaje stránky v prehliadači.'),
    ('legal.storage.tabLanguage.purpose', 'Jazyk, ktorý zvolil odkaz s ?lang=, len pre túto kartu.'),
    ('legal.storage.untilTabClosed', 'Kým sa karta nezatvorí.'),
    ('legal.storage.visitorKey.kept', 'Kým prihlásenie nepresunie tvoje importy do tvojho účtu, kým kľúč stránka už nepozná alebo kým nevymažeš údaje stránky v prehliadači.'),
    ('legal.storage.whatsNew.purpose', 'Posledná verzia, ktorej novinky tento prehliadač ukázal (Čo je nové), aby sa otvorili raz za verziu.'),
    ('legal.storage.whatsNew.kept', 'Kým sa neukáže novšia verzia alebo kým nevymažeš údaje stránky v prehliadači.'),
    ('legal.storage.wordDrafts.purpose', 'Pre editorov a správcov: slová napísané na stránkach Translations a Languages, ktoré ešte nie sú uložené.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- The five descriptions that still told of a snack bar: the kit's messages are toasts, and common.close is the
-- What's-new dialog's alone now.
UPDATE translation_keys SET description = 'A button that closes what is open: the What''s-new dialog. No placeholders.'
 WHERE name = 'common.close';
UPDATE translation_keys SET description = 'The dashboard: a message at the foot of the window (the kit''s toast) after a game account was deleted. {name}: the account''s name as the person gave it, or "UID" and the player''s number - shown as it is.'
 WHERE name = 'dashboard.deleted';
UPDATE translation_keys SET description = 'A message at the foot of the window (the kit''s toast) after Export: the file is being downloaded. No placeholders.'
 WHERE name = 'data.exported';
UPDATE translation_keys SET description = 'A message at the foot of the window (the kit''s toast) after a sign-in: the game accounts imported without signing in are now in the person''s anotoki account. {count}: how many (1).'
 WHERE name = 'visitor.moved.one';
UPDATE translation_keys SET description = 'The same message, after visitor.moved when both are said: game accounts the person already had (the same game and UID) - their own copy stayed as it was, and the one imported without signing in was left out. {count}: how many (1).'
 WHERE name = 'visitor.kept.one';

-- ============================================================================
-- PROVE IT
--
-- Every key this file writes is here and reads in English and Slovak: a page
-- never shows a key, and Slovak never falls back to English by accident. No
-- description of the site's own keys tells of a snack bar any more. And the
-- site's own keys - the library's, under anotoki., are never counted here -
-- are exactly as many as 010 left, and the 52 this file adds (433 + 52).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 485;
    new_keys CONSTANT TEXT[] := ARRAY['title.terms', 'title.privacy', 'title.contact', 'footer.fanMade', 'legal.about', 'legal.fanClause',
        'legal.record.name', 'legal.record.what', 'legal.record.why', 'legal.record.kept',
        'legal.accounts.name', 'legal.accounts.what', 'legal.accounts.why', 'legal.accounts.kept',
        'legal.staff.name', 'legal.staff.what', 'legal.staff.why', 'legal.staff.kept',
        'legal.limits.name', 'legal.limits.what', 'legal.limits.why', 'legal.limits.kept',
        'legal.showcase.name', 'legal.showcase.what', 'legal.showcase.why', 'legal.showcase.kept',
        'legal.log.name', 'legal.log.what', 'legal.log.why', 'legal.log.kept',
        'legal.visitors.what', 'legal.visitors.why', 'legal.visitors.kept', 'legal.feedback.what', 'legal.feedback.kept',
        'legal.auditLog.what', 'legal.backups.what', 'legal.showcaseService.name', 'legal.showcaseService.role',
        'legal.storage.signIn.purpose', 'legal.storage.hintTried.purpose', 'legal.storage.hintTried.kept',
        'legal.storage.signInUnderWay.purpose', 'legal.storage.signInUnderWay.kept', 'legal.storage.signInKind.purpose',
        'legal.storage.signInKind.kept', 'legal.storage.tabLanguage.purpose', 'legal.storage.untilTabClosed',
        'legal.storage.visitorKey.kept', 'legal.storage.whatsNew.purpose', 'legal.storage.whatsNew.kept',
        'legal.storage.wordDrafts.purpose'];
    found INTEGER;
    wrong TEXT;
BEGIN
    IF cardinality(new_keys) <> 52 THEN
        RAISE EXCEPTION 'This file names % keys of its own; it adds 52', cardinality(new_keys);
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO wrong
      FROM unnest(new_keys) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys tk WHERE tk.name = k.name)
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en')
        OR NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'sk');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys without an English and a Slovak string: %', wrong;
    END IF;

    SELECT string_agg(name, ', ' ORDER BY name) INTO wrong
      FROM translation_keys
     WHERE name NOT LIKE 'anotoki.%' AND description ILIKE '%snack bar%';
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Descriptions that still tell of a snack bar: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 010 with this file leave %', found, expected_keys;
    END IF;
END $$;
