-- ============================================================================
-- 004_visitors_and_family_bar.sql - visitor accounts, the family's top bar, and their words
-- ============================================================================
-- Version 0.3. Two parts, one file: the schema of the visitor accounts, and the
-- site's words that came with it - the visitors, the "Your data" card, and the
-- family's top bar and footer (with the toolbar's keys they replace retired).
-- What it changes, and what it does not: the schema part is additive (nothing
-- that is there changes); the new strings never write over one reworded in
-- the browser; the 15 keys it retires are deleted, and their strings go with
-- them - a Slovak or English wording an editor gave them included; and 13
-- keys' descriptions (what a translator reads, not what a page shows) are
-- rewritten. The gate at the end fails loudly when any of it did not happen.
--
-- Applying it: on the host, upload the new version and press Apply pending
-- on the admin panel's Migrations page AT ONCE, signed in as an ADMIN
-- (/admin/migrations - an EDITOR has no such page); until it is applied,
-- imports without signing in answer "not ready" and the bar's new words show
-- as their keys.
-- On this computer the same, or `php migration.php` from backend/. Only THEN
-- apply the IAM's migration 007 (every site open to every account, ADMIN and
-- EDITOR here, no MEMBER): the new code works with the old IAM, the old code
-- with the new one refuses everybody. If nobody holds ADMIN here yet, 007 is
-- what gives the IAM's administrators ADMIN - apply it right after the upload
-- instead (the old code is gone by then), and Apply this file next.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
--
-- A visitor is somebody who uses the site without signing in. What they import
-- is kept on the server, under a visitor account: a row of users with a
-- NEGATIVE id from the sequence below. An IAM id is always a positive integer
-- (anotoki_iam_user_id()), so the two kinds can never meet, and accounts and
-- import_runs already point at users with cascades - a visitor's data goes
-- with its row. The browser holds a long random key the server made; the row
-- keeps only its SHA-256 (visitor_key_hash, 64 hex digits). last_seen_at is
-- when the key was last used (written at most once an hour); visitors unseen
-- for 30 days are deleted by the site itself (App\Auth\Visitors::sweep()).
--
-- The schema part changes no row that is there: two nullable columns, a
-- sequence, a UNIQUE, two CHECKs every row already satisfies (positive ids,
-- NULLs) and a partial index.
-- The names follow 002 ({table}_{column}_unique, _check, _index).
-- ============================================================================

-- ── Visitor ids: -1, -2, -3 ... never an IAM id ─────────────────────────────
CREATE SEQUENCE IF NOT EXISTS visitor_ids AS BIGINT INCREMENT BY -1 MINVALUE -9223372036854775807 MAXVALUE -1 START WITH -1;

ALTER TABLE users ADD COLUMN IF NOT EXISTS visitor_key_hash CHAR(64);
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMPTZ;

-- A key opens one visitor; the hash is lowercase hex, as PHP's hash() writes it.
ALTER TABLE users ADD CONSTRAINT users_visitor_key_hash_unique UNIQUE (visitor_key_hash);
ALTER TABLE users ADD CONSTRAINT users_visitor_key_hash_check CHECK (visitor_key_hash ~ '^[0-9a-f]{64}$');

-- The two kinds held apart: a negative id has a key's hash and a time it was
-- last seen; a positive one (the IAM's) has neither key nor sweep.
ALTER TABLE users ADD CONSTRAINT users_visitor_check
    CHECK ((id < 0) = (visitor_key_hash IS NOT NULL) AND (id > 0 OR last_seen_at IS NOT NULL));

-- The sweep reads the oldest visitors first; members are not in the index.
CREATE INDEX IF NOT EXISTS users_visitors_last_seen_at_index ON users (last_seen_at) WHERE id < 0;

-- ── The site's words: the visitors, the "Your data" card, the top bar, the footer ──
-- 003's three statements (keys, English, Slovak), in the same form - the
-- tests read them the same way (tests/translation_catalogue.test.php) - and
-- inserts that step aside for a row already there, so nothing reworded in the
-- browser is written over. The English is, key for key, the English compiled
-- into the app (frontend/src/app/core/i18n/en/). Slovak speaks to the reader
-- informally ("ty"), as in 003. The app's name (in brand.*) is a name: the
-- same in every language.

INSERT INTO translation_keys (name, description) VALUES
    -- signIn, error (the core part)
    ('signIn.storageUnavailable', 'A snack bar after a Sign in button (the bar''s, the visitor notice''s, the dashboard''s, the sign-in page''s), and the sign-in page: the browser refuses the site''s storage, where the sign-in in progress is kept, so the sign-in never started. Says how to get past it. No placeholders.'),
    ('error.editor_only', 'Shared by the member pages, through errorKey(): something only an editor or an admin may do was asked for by somebody with neither role. A whole sentence ending in its full stop. No placeholders.'),
    ('error.visitor_unknown', 'The dashboard (once) and wherever a request fails with it: the key this browser kept to reach what it imported without signing in opens nothing any more - another tab signed in and moved it into an account, or it was deleted after a long time without a visit. The page then starts over as for somebody new. No placeholders.'),
    ('error.too_many_visitors', 'An import by somebody not signed in, refused because too many people import without signing in just now (from this address, or on the whole site). Ends by offering the sign-in. No placeholders.'),
    ('error.visitors_unavailable', 'An import by somebody not signed in, refused because the server''s database update for it has not been applied yet. Ends by offering the sign-in. No placeholders.'),
    ('error.visitor_limit_accounts', 'An import by somebody not signed in, refused because it would add a game account past the limit for one browser. {max}: that limit, a number; the sentence puts it where no word has to agree with it. Ends by offering the sign-in.'),
    ('error.visitor_limit_file_size', 'An import by somebody not signed in, refused because the file is larger than such an import may be. {max}: the limit in kilobytes, a number, before "KB". Ends by offering the sign-in.'),
    -- language (the core part)
    ('language.button', 'The top bar: the accessible name of the round button with the current language''s code ("SK"), which opens the list of languages. {name}: that language in its own name ("Slovenčina"), shown as it is. {code}: the code the button shows ("SK") - keep it in, for somebody who speaks to the computer names the button by what it shows (WCAG 2.5.3, Label in Name).'),
    -- brand, topbar, footer (the family's top bar and the foot of every page)
    ('brand.area', 'The top bar, after the anotoki mark and the word "anotoki": the app''s name. A name: the same in every language. No placeholders.'),
    ('brand.short', 'The top bar on a phone: the app''s name alone beside the anotoki mark, short enough for a 360 px screen. A name: the same in every language. No placeholders.'),
    ('brand.admin', 'The top bar in the admin panel (which reads English), after the anotoki mark and the word "anotoki": the part of the site this is, as the IAM''s "anotoki admin panel" (the app''s name is in the brand link''s accessible name, topbar.homeAdmin). No placeholders.'),
    ('topbar.home', 'The top bar: the accessible name of the brand, a link to the start page (the dashboard). "anotoki" and the app''s name stay as they are. No placeholders.'),
    ('topbar.homeAdmin', 'The top bar in the admin panel (which reads English): the accessible name of the brand, a link to the admin panel''s first page. No placeholders.'),
    ('topbar.sections', 'The top bar: the accessible name of the row of links to the site''s sections (Dashboard, Import, Builds). No placeholders.'),
    ('topbar.dashboard', 'The top bar (and, on a phone, its menu): the link to the start page with the person''s game accounts. Also the button back to it at the end of the import guide. No placeholders.'),
    ('topbar.import', 'The top bar (and, on a phone, its menu): the link to the import guide. No placeholders.'),
    ('topbar.builds', 'The top bar (and, on a phone, its menu): the link to the recommended builds of every character. No placeholders.'),
    ('topbar.phoneMenu', 'The top bar on a phone: the accessible name of the round menu button, and of the menu it opens (the site''s sections, then the person''s menu or Sign in). No placeholders.'),
    ('topbar.menuButton', 'The top bar, signed in: the accessible name of the button with the person''s initial and name, which opens their menu. {name}: the person''s name (or common.userFallback), shown as it is.'),
    ('topbar.menu', 'The top bar, signed in: the accessible name of the person''s menu (their name and role, their anotoki account, the admin panel, Sign out). No placeholders.'),
    ('topbar.roleAdmin', 'The person''s menu in the top bar: the small label under the name of somebody with the ADMIN role here. One word. No placeholders.'),
    ('topbar.roleEditor', 'The person''s menu in the top bar: the small label under the name of somebody with the EDITOR role here. One word. No placeholders.'),
    ('topbar.account', 'The person''s menu in the top bar: the link to their account page at anotoki (profile, password, sessions), which opens in a new tab. "anotoki" is the name of the accounts, never translated. No placeholders.'),
    ('topbar.admin', 'The person''s menu in the top bar, for an editor or an admin: the way into the admin panel (which is English). No placeholders.'),
    ('topbar.backToApp', 'The person''s menu in the top bar, in the admin panel (which reads English): the way back to the rest of the site. The app''s name stays as it is. No placeholders.'),
    ('topbar.signOutUnsaved.title', 'The question before signing out (Sign out in the person''s menu) while changes typed on the Translations and Languages pages are not saved: its title. No placeholders.'),
    ('topbar.signOutUnsaved.message', 'The same question: what is lost by signing out now. Translations and Languages are the admin pages'' English names, kept as they are. No placeholders.'),
    ('topbar.signOutUnsaved.confirm', 'The same question: the button that signs out all the same. No placeholders.'),
    ('topbar.signOutUnsaved.stay', 'The same question: the button that stays signed in, with the changes kept. No placeholders.'),
    ('footer.version', 'The foot of every page: the version of the site, beside the What''s-new button. {version}: the version number, shown as it is ("0.3").'),
    -- home (the dashboard, for somebody neither signed in nor a visitor; also an account or character page opened without a sign-in)
    ('home.title', 'The dashboard for somebody new (neither signed in nor having imported anything): the heading that says what the site does. No placeholders.'),
    ('home.text', 'The dashboard for somebody new: under the heading - what the site does, and that it can be used without an account but signing in keeps the data. The game names stay as they are. No placeholders.'),
    ('home.import', 'The dashboard for somebody new: the main button, to the import guide. No placeholders.'),
    ('home.browse', 'The dashboard for somebody new: a button to the builds of one game. {game}: the game''s name (Genshin Impact, Honkai: Star Rail), never translated - no word may have to agree with it.'),
    ('home.signInText', 'The dashboard for somebody new: the line above the Sign in button - what signing in gives. No placeholders.'),
    -- visitor (somebody who imported without signing in)
    ('visitor.notice', 'The notice on the dashboard, the import page and the account and character pages for somebody not signed in, beside a Sign in button: what they import is kept on the server for this browser, for {days} days after their last visit, and signing in keeps it in their anotoki account. {days}: a number of days (30). "anotoki" is the name of the accounts, never translated.'),
    ('visitor.moved.one', 'A snack bar after a sign-in: the game accounts imported without signing in are now in the person''s anotoki account. {count}: how many (1).'),
    ('visitor.moved.few', 'The same, for a {count} that takes the "few" form (2-4 in Slovak).'),
    ('visitor.moved.other', 'The same, for any other {count}.'),
    ('visitor.kept.one', 'The same snack bar, after visitor.moved when both are said: game accounts the person already had (the same game and UID) - their own copy stayed as it was, and the one imported without signing in was left out. {count}: how many (1).'),
    ('visitor.kept.few', 'The same, for a {count} that takes the "few" form (2-4 in Slovak).'),
    ('visitor.kept.other', 'The same, for any other {count}.'),
    -- data (the "Your data" card on the dashboard, for somebody signed in)
    ('data.title', 'The "Your data" card on the dashboard: its heading. No placeholders.'),
    ('data.text', 'The "Your data" card: what the file holds and what an import of it does - adds what is missing, keeps the newer copy of an account that is there twice, never deletes one. No placeholders.'),
    ('data.export', 'The "Your data" card: the button that downloads the file. No placeholders.'),
    ('data.exported', 'A snack bar after Export: the file is being downloaded. No placeholders.'),
    ('data.import', 'The "Your data" card: the button that chooses a file to import. Nothing is imported before the preview is confirmed. No placeholders.'),
    ('data.busy', 'The "Your data" card: the accessible name of the progress bar while the file is exported, read or imported. No placeholders.'),
    ('data.previewTitle', 'The "Your data" card, the preview of an import: its heading. {name}: the chosen file''s name, shown as it is.'),
    ('data.preview', 'The "Your data" card, the preview: what the import would do. {added}: new game accounts; {replaced}: accounts the file has a newer copy of; {kept}: accounts the person''s copy is as new or newer of - each a number, put where no word has to agree with it.'),
    ('data.skipped.one', 'The "Your data" card, the preview: entries of the file (characters, weapons, pieces) this site''s catalog does not know, which are left out. {count}: how many (1).'),
    ('data.skipped.few', 'The same, for a {count} that takes the "few" form (2-4 in Slovak).'),
    ('data.skipped.other', 'The same, for any other {count}.'),
    ('data.empty', 'The "Your data" card, the preview of a file with no game account in it. No placeholders.'),
    ('data.outcome.added', 'The "Your data" card, the preview''s list: beside a game account the import adds. One word or two, lower case. No placeholders.'),
    ('data.outcome.replaced', 'The preview''s list: beside a game account the file''s newer copy replaces. Short, lower case. No placeholders.'),
    ('data.outcome.kept', 'The preview''s list: beside a game account that stays as it is (the person''s copy is as new or newer). One word, lower case. No placeholders.'),
    ('data.confirm', 'The "Your data" card, the preview: the button that does the import. No placeholders.'),
    ('data.done', 'The "Your data" card, after the import: what it did. {added}, {replaced}, {kept}: numbers, as in data.preview.'),
    ('data.error.unreadable', 'The "Your data" card: the browser could not read the chosen file. No placeholders.'),
    ('data.error.no_file', 'The "Your data" card: the chosen file is empty. No placeholders.'),
    ('data.error.file_too_big', 'The "Your data" card: the file is larger than an import may be. {max_mb}: the limit in megabytes, a number, before "MB".'),
    ('data.error.not_a_data_file', 'The "Your data" card: the file is not one this site exported (another kind of file, or one of another site). No placeholders.'),
    ('data.error.newer_version', 'The "Your data" card: the file was written by a newer version of the site, which this copy cannot read. {version}: the file''s format number, shown as it is.'),
    ('data.error.bad_entry', 'The "Your data" card: the file is damaged or was edited by hand. {path}: where in the file (such as accounts[2].characters[5].level), shown as it is - never translated.'),
    ('data.error.too_many_accounts', 'The "Your data" card: importing the file would give the person more game accounts than the site keeps for one person. {max}: that limit, a number, put where no word has to agree with it.'),
    ('data.error.export_too_big', 'The "Your data" card: the person''s data is too large for one file. {max_mb}: the file''s limit in megabytes; {max_accounts}: the most game accounts a file holds - numbers, put where no word has to agree with them.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('signIn.storageUnavailable', 'This browser will not let the site remember a sign-in while it is under way, so it could not start. Allow this site to store data (or leave private browsing) and try again.'),
    ('error.editor_only', 'Only an editor or an admin can do this.'),
    ('error.visitor_unknown', 'What this browser imported without signing in is not kept here any more: it was moved into an account at a sign-in, or deleted after a long time without a visit.'),
    ('error.too_many_visitors', 'Too many people are importing without signing in right now. Sign in, or try again later.'),
    ('error.visitors_unavailable', 'Importing without signing in is not ready on this server yet. Sign in to import.'),
    ('error.visitor_limit_accounts', 'Without signing in, this browser keeps at most {max} game accounts. Import into one of them, or sign in to add more.'),
    ('error.visitor_limit_file_size', 'Without signing in, a file can be up to {max} KB. Sign in to import a larger one.'),
    ('language.button', 'Language: {name} ({code})'),
    ('brand.area', 'Build Analyzer'),
    ('brand.short', 'Analyzer'),
    ('brand.admin', 'admin panel'),
    ('topbar.home', 'anotoki Build Analyzer: the start page'),
    ('topbar.homeAdmin', 'anotoki Build Analyzer: the admin panel'),
    ('topbar.sections', 'The site''s sections'),
    ('topbar.dashboard', 'Dashboard'),
    ('topbar.import', 'Import'),
    ('topbar.builds', 'Builds'),
    ('topbar.phoneMenu', 'Menu'),
    ('topbar.menuButton', 'Your menu: {name}'),
    ('topbar.menu', 'Your menu'),
    ('topbar.roleAdmin', 'Administrator'),
    ('topbar.roleEditor', 'Editor'),
    ('topbar.account', 'Your anotoki account'),
    ('topbar.admin', 'Admin panel'),
    ('topbar.backToApp', 'Back to Build Analyzer'),
    ('topbar.signOutUnsaved.title', 'Sign out without your latest changes?'),
    ('topbar.signOutUnsaved.message', 'Some changes to the site''s strings or languages are not saved - the Translations and Languages pages show them. Signed out, they are lost.'),
    ('topbar.signOutUnsaved.confirm', 'Sign out anyway'),
    ('topbar.signOutUnsaved.stay', 'Stay'),
    ('footer.version', 'Version {version}'),
    ('home.title', 'Every character, measured against community builds'),
    ('home.text', 'Import a Genshin Impact or Honkai: Star Rail account by its UID or from an inventory file, and see per character what to improve. You can start without an account; sign in with your anotoki account to keep your data with it.'),
    ('home.import', 'Import your account'),
    ('home.browse', 'Browse the {game} builds'),
    ('home.signInText', 'Signed in, your accounts stay with your anotoki account, on every device.'),
    ('visitor.notice', 'You are not signed in. What you import is kept for this browser on our server for {days} days after your last visit. Signing in keeps it in your anotoki account.'),
    ('visitor.moved.one', '{count} account you imported without signing in is now in your anotoki account.'),
    ('visitor.moved.few', '{count} accounts you imported without signing in are now in your anotoki account.'),
    ('visitor.moved.other', '{count} accounts you imported without signing in are now in your anotoki account.'),
    ('visitor.kept.one', '{count} account you already had stayed as it was.'),
    ('visitor.kept.few', '{count} accounts you already had stayed as they were.'),
    ('visitor.kept.other', '{count} accounts you already had stayed as they were.'),
    ('data.title', 'Your data'),
    ('data.text', 'Your game accounts, everything imported into them and their import history, as one file - to keep, or to bring into another copy of the site. An import adds what is missing and keeps the newer copy of an account you have twice; it never deletes an account.'),
    ('data.export', 'Export'),
    ('data.exported', 'The file is on its way to your downloads.'),
    ('data.import', 'Import a file'),
    ('data.busy', 'Working on the file…'),
    ('data.previewTitle', 'What importing {name} does'),
    ('data.preview', '{added} new, {replaced} replaced by a newer copy, {kept} kept - yours is as new or newer.'),
    ('data.skipped.one', '{count} entry this site does not know is left out.'),
    ('data.skipped.few', '{count} entries this site does not know are left out.'),
    ('data.skipped.other', '{count} entries this site does not know are left out.'),
    ('data.empty', 'There are no game accounts in this file.'),
    ('data.outcome.added', 'new'),
    ('data.outcome.replaced', 'replaced by the newer copy'),
    ('data.outcome.kept', 'kept'),
    ('data.confirm', 'Import'),
    ('data.done', 'Imported: {added} new, {replaced} replaced by a newer copy, {kept} kept.'),
    ('data.error.unreadable', 'The file could not be read.'),
    ('data.error.no_file', 'The file is empty.'),
    ('data.error.file_too_big', 'The file is larger than {max_mb} MB.'),
    ('data.error.not_a_data_file', 'This is not a file of game accounts exported by this site.'),
    ('data.error.newer_version', 'This file comes from a newer version of the site (format {version}). This copy cannot read it yet.'),
    ('data.error.bad_entry', 'The file is damaged at {path}. Export it again.'),
    ('data.error.too_many_accounts', 'With this file you would have more than {max} game accounts.'),
    ('data.error.export_too_big', 'Your data is too big for one file (more than {max_mb} MB or {max_accounts} game accounts).')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('signIn.storageUnavailable', 'Tento prehliadač nedovolí stránke zapamätať si prihlásenie, kým prebieha, preto sa nemohlo začať. Povoľ tejto stránke ukladať údaje (alebo opusti súkromné prehliadanie) a skús to znova.'),
    ('error.editor_only', 'Toto môže urobiť len editor alebo správca.'),
    ('error.visitor_unknown', 'To, čo tento prehliadač importoval bez prihlásenia, tu už nie je: pri prihlásení sa to presunulo do účtu, alebo sa to po dlhom čase bez návštevy vymazalo.'),
    ('error.too_many_visitors', 'Bez prihlásenia teraz importuje priveľa ľudí. Prihlás sa alebo to skús neskôr.'),
    ('error.visitors_unavailable', 'Import bez prihlásenia ešte nie je na tomto serveri pripravený. Ak chceš importovať, prihlás sa.'),
    ('error.visitor_limit_accounts', 'Bez prihlásenia si tento prehliadač nechá najviac tento počet herných účtov: {max}. Importuj do niektorého z nich, alebo sa prihlás a pridaj ďalšie.'),
    ('error.visitor_limit_file_size', 'Bez prihlásenia môže mať súbor najviac {max} KB. Ak chceš importovať väčší, prihlás sa.'),
    ('language.button', 'Jazyk: {name} ({code})'),
    ('brand.area', 'Build Analyzer'),
    ('brand.short', 'Analyzer'),
    ('brand.admin', 'administrátorský panel'),
    ('topbar.home', 'anotoki Build Analyzer: úvodná stránka'),
    ('topbar.homeAdmin', 'anotoki Build Analyzer: administrátorský panel'),
    ('topbar.sections', 'Časti stránky'),
    ('topbar.dashboard', 'Prehľad'),
    ('topbar.import', 'Import'),
    ('topbar.builds', 'Buildy'),
    ('topbar.phoneMenu', 'Ponuka'),
    ('topbar.menuButton', 'Tvoja ponuka: {name}'),
    ('topbar.menu', 'Tvoja ponuka'),
    ('topbar.roleAdmin', 'Správca'),
    ('topbar.roleEditor', 'Editor'),
    ('topbar.account', 'Tvoj anotoki účet'),
    ('topbar.admin', 'Administrátorský panel'),
    ('topbar.backToApp', 'Späť na Build Analyzer'),
    ('topbar.signOutUnsaved.title', 'Odhlásiť sa bez posledných zmien?'),
    ('topbar.signOutUnsaved.message', 'Niektoré zmeny v textoch alebo jazykoch stránky nie sú uložené - nájdeš ich na stránkach Translations a Languages. Po odhlásení sa stratia.'),
    ('topbar.signOutUnsaved.confirm', 'Aj tak sa odhlásiť'),
    ('topbar.signOutUnsaved.stay', 'Zostať'),
    ('footer.version', 'Verzia {version}'),
    ('home.title', 'Každá postava v porovnaní s buildmi od komunity'),
    ('home.text', 'Importuj účet z hry Genshin Impact alebo Honkai: Star Rail podľa UID alebo zo súboru s inventárom a pri každej postave uvidíš, čo zlepšiť. Začať môžeš aj bez účtu; keď sa prihlásiš svojím anotoki účtom, tvoje údaje zostanú pri ňom.'),
    ('home.import', 'Importovať účet'),
    ('home.browse', 'Buildy pre hru {game}'),
    ('home.signInText', 'Keď sa prihlásiš, tvoje účty zostanú pri tvojom anotoki účte na každom zariadení.'),
    ('visitor.notice', 'Používaš stránku bez prihlásenia. Čo importuješ, uchováme pre tento prehliadač na našom serveri, kým od tvojej poslednej návštevy neuplynie toľko dní: {days}. Keď sa prihlásiš, zostane to v tvojom anotoki účte.'),
    ('visitor.moved.one', 'Účet importovaný bez prihlásenia je teraz v tvojom anotoki účte ({count}).'),
    ('visitor.moved.few', 'Účty importované bez prihlásenia sú teraz v tvojom anotoki účte ({count}).'),
    ('visitor.moved.other', 'Účty importované bez prihlásenia sú teraz v tvojom anotoki účte ({count}).'),
    ('visitor.kept.one', 'Účet, ktorý už bol v tvojom anotoki účte, zostal, ako bol ({count}).'),
    ('visitor.kept.few', 'Účty, ktoré už boli v tvojom anotoki účte, zostali, ako boli ({count}).'),
    ('visitor.kept.other', 'Účty, ktoré už boli v tvojom anotoki účte, zostali, ako boli ({count}).'),
    ('data.title', 'Tvoje údaje'),
    ('data.text', 'Tvoje herné účty, všetko, čo sa do nich importovalo, a história importov v jednom súbore - na uchovanie alebo na prenesenie do inej kópie stránky. Import doplní, čo chýba, a pri účte, ktorý máš dvakrát, ponechá novšiu kópiu; žiadny účet nevymaže.'),
    ('data.export', 'Exportovať'),
    ('data.exported', 'Súbor sa sťahuje.'),
    ('data.import', 'Importovať súbor'),
    ('data.busy', 'Spracúva sa súbor…'),
    ('data.previewTitle', 'Čo urobí import súboru {name}'),
    ('data.preview', 'Nové: {added}, nahradené novšou kópiou: {replaced}, ponechané: {kept} - tvoja kópia je rovnako nová alebo novšia.'),
    ('data.skipped.one', 'Položka, ktorú táto stránka nepozná, sa vynechá ({count}).'),
    ('data.skipped.few', 'Položky, ktoré táto stránka nepozná, sa vynechajú ({count}).'),
    ('data.skipped.other', 'Položky, ktoré táto stránka nepozná, sa vynechajú ({count}).'),
    ('data.empty', 'V tomto súbore nie sú žiadne herné účty.'),
    ('data.outcome.added', 'nový'),
    ('data.outcome.replaced', 'nahradený novšou kópiou'),
    ('data.outcome.kept', 'ponechaný'),
    ('data.confirm', 'Importovať'),
    ('data.done', 'Importované. Nové: {added}, nahradené novšou kópiou: {replaced}, ponechané: {kept}.'),
    ('data.error.unreadable', 'Súbor sa nepodarilo prečítať.'),
    ('data.error.no_file', 'Súbor je prázdny.'),
    ('data.error.file_too_big', 'Súbor je väčší ako {max_mb} MB.'),
    ('data.error.not_a_data_file', 'Toto nie je súbor s hernými účtami, ktorý exportovala táto stránka.'),
    ('data.error.newer_version', 'Tento súbor pochádza z novšej verzie stránky (formát {version}). Táto kópia ho zatiaľ nevie prečítať.'),
    ('data.error.bad_entry', 'Súbor je poškodený na mieste {path}. Exportuj ho znova.'),
    ('data.error.too_many_accounts', 'S týmto súborom by tvojich herných účtov bolo viac, ako je povolené: {max}.'),
    ('data.error.export_too_big', 'Tvoje údaje sú na jeden súbor priveľké (viac ako {max_mb} MB alebo viac herných účtov, ako je {max_accounts}).')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- Keys nothing reads any more (the sign-in modes and the MEMBER role are gone,
-- the Translations and Languages pages moved into the admin area, and the top
-- bar replaced the toolbar - its links are topbar.*, the version moved into
-- the footer, the theme and the language have controls of their own): their
-- strings go with them (the foreign keys cascade), reworded ones too.
DELETE FROM translation_keys WHERE name IN (
    'error.local_only', 'error.no_access', 'signIn.noAccess', 'signIn.anotherAccount',
    'dashboard.backendHint', 'nav.translations', 'nav.languages',
    'nav.dashboard', 'nav.import', 'nav.builds', 'toolbar.menu', 'toolbar.version',
    'toolbar.yourAccount', 'toolbar.account', 'language.label'
);

-- Descriptions that named what is gone (the toolbar, its menu, the sign-in page's no-access branch).
UPDATE translation_keys SET description = 'What stands in place of a person''s name when their account has neither a user name nor an email address: in the top bar, on the person''s button and at the head of their menu. {sub}: the account''s number at the sign-in service, shown as it is. The Slovak is only „č. {sub}“.'
 WHERE name = 'common.userFallback';
UPDATE translation_keys SET description = 'The link inside the dashboard''s sentence "Start the first sync on the … page", for an editor or an admin (the page itself is English), and the name other strings use for that page. No placeholders.'
 WHERE name = 'nav.sources';
UPDATE translation_keys SET description = 'The button that goes to the sign-in: in the top bar while nobody is signed in (on a phone, in its menu), on the visitor notice, the dashboard and the sign-in page. No placeholders.'
 WHERE name = 'common.signIn';
UPDATE translation_keys SET description = 'The person''s menu in the top bar: the item that signs the person out. No placeholders.'
 WHERE name = 'common.signOut';
UPDATE translation_keys SET description = 'The top bar''s theme switch: the accessible name of its three choices together. No placeholders.'
 WHERE name = 'theme.label';
UPDATE translation_keys SET description = 'The top bar''s theme switch, one of its three round choices (an icon, named by this for a screen reader and in a tooltip): follow the device''s light or dark setting. No placeholders.'
 WHERE name = 'theme.system';
UPDATE translation_keys SET description = 'The top bar''s theme switch, one of its three round choices (an icon, named by this for a screen reader and in a tooltip): the light theme. No placeholders.'
 WHERE name = 'theme.light';
UPDATE translation_keys SET description = 'The top bar''s theme switch, one of its three round choices (an icon, named by this for a screen reader and in a tooltip): the dark theme. No placeholders.'
 WHERE name = 'theme.dark';
UPDATE translation_keys SET description = 'A snack bar when a language chosen in the top bar could not be fetched; the page stays in the language it was in. No placeholders.'
 WHERE name = 'language.notLoaded';
UPDATE translation_keys SET description = 'The top bar, for an editor or an admin while this browser tab is driving a build sync: the tooltip of the spinning sync icon, which links to the Sources page. The sync stops when the tab is closed. No placeholders.'
 WHERE name = 'toolbar.syncing';
UPDATE translation_keys SET description = 'The top bar, while this browser tab is driving a build sync: the accessible name of the spinning sync icon (what a screen reader says); on a phone, the item of the menu button that leads to the Sources page. No placeholders.'
 WHERE name = 'toolbar.syncingLabel';
UPDATE translation_keys SET description = 'The What''s-new dialog: its title. Also the button beside the version at the foot of every page, which opens it. The release notes inside the dialog are not strings: they stay in English. No placeholders.'
 WHERE name = 'whatsNew.title';
UPDATE translation_keys SET description = 'The text of the link to the Sources page inside builds.empty and character.noBuilds. It is that page''s name: keep it the same word as nav.sources, the dashboard''s link to it. No placeholders.'
 WHERE name = 'builds.sourcesPage';

-- ============================================================================
-- PROVE IT
--
-- The sequence counts down from -1; both columns, both constraints and the
-- index are there under their names; every row that was here is an IAM
-- person's, untouched (no visitor can exist before this file). Every key reads
-- in English (the fallback, which cannot be cleared in the browser); the keys
-- this file adds read in Slovak too - an older key's Slovak may have been
-- cleared in the Translations grid on purpose (it then reads the English), and
-- that is not this file's to put back. The retired keys are gone, no
-- description still tells of the toolbar this file retires, and there are
-- exactly as many keys as 003 seeded and this file adds and retires
-- (406 + 67 - 15).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 458;
    -- The 67 keys the first statement above inserts, in its order.
    new_keys CONSTANT TEXT[] := ARRAY[
        'signIn.storageUnavailable', 'error.editor_only', 'error.visitor_unknown', 'error.too_many_visitors',
        'error.visitors_unavailable', 'error.visitor_limit_accounts', 'error.visitor_limit_file_size',
        'language.button', 'brand.area', 'brand.short', 'brand.admin', 'topbar.home', 'topbar.homeAdmin', 'topbar.sections',
        'topbar.dashboard', 'topbar.import', 'topbar.builds', 'topbar.phoneMenu', 'topbar.menuButton', 'topbar.menu',
        'topbar.roleAdmin', 'topbar.roleEditor', 'topbar.account', 'topbar.admin', 'topbar.backToApp',
        'topbar.signOutUnsaved.title', 'topbar.signOutUnsaved.message', 'topbar.signOutUnsaved.confirm',
        'topbar.signOutUnsaved.stay', 'footer.version', 'home.title', 'home.text', 'home.import', 'home.browse',
        'home.signInText', 'visitor.notice', 'visitor.moved.one', 'visitor.moved.few', 'visitor.moved.other',
        'visitor.kept.one', 'visitor.kept.few', 'visitor.kept.other', 'data.title', 'data.text', 'data.export',
        'data.exported', 'data.import', 'data.busy', 'data.previewTitle', 'data.preview', 'data.skipped.one',
        'data.skipped.few', 'data.skipped.other', 'data.empty', 'data.outcome.added', 'data.outcome.replaced',
        'data.outcome.kept', 'data.confirm', 'data.done', 'data.error.unreadable', 'data.error.no_file',
        'data.error.file_too_big', 'data.error.not_a_data_file', 'data.error.newer_version', 'data.error.bad_entry',
        'data.error.too_many_accounts', 'data.error.export_too_big'
    ];
    found INTEGER;
    wrong TEXT;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_sequences WHERE schemaname = current_schema() AND sequencename = 'visitor_ids'
                    AND increment_by = -1 AND max_value = -1) THEN
        RAISE EXCEPTION 'The sequence visitor_ids is missing, or does not count down from -1';
    END IF;

    WITH expected (name, type) AS (VALUES ('visitor_key_hash', 'character'), ('last_seen_at', 'timestamp with time zone'))
    SELECT string_agg(e.name, ', ') INTO wrong
      FROM expected e
     WHERE NOT EXISTS (SELECT 1 FROM information_schema.columns c
                        WHERE c.table_schema = current_schema() AND c.table_name = 'users'
                          AND c.column_name = e.name AND c.data_type = e.type AND c.is_nullable = 'YES');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Columns of users missing or of another type: %', wrong;
    END IF;

    WITH expected (name, kind) AS (VALUES
        ('users_visitor_key_hash_unique', 'u'), ('users_visitor_key_hash_check', 'c'), ('users_visitor_check', 'c')
    )
    SELECT string_agg(e.name, ', ') INTO wrong
      FROM expected e
     WHERE NOT EXISTS (SELECT 1 FROM pg_constraint c
                        JOIN pg_namespace n ON n.oid = c.connamespace AND n.nspname = current_schema()
                       WHERE c.conname = e.name AND c.contype::TEXT = e.kind AND c.conrelid = 'users'::regclass);
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Constraints missing: %', wrong;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'users'
                    AND indexname = 'users_visitors_last_seen_at_index') THEN
        RAISE EXCEPTION 'The index users_visitors_last_seen_at_index is missing';
    END IF;

    IF EXISTS (SELECT 1 FROM users WHERE id < 1 OR visitor_key_hash IS NOT NULL OR last_seen_at IS NOT NULL) THEN
        RAISE EXCEPTION 'A row of users is not an IAM person''s as it was before this file';
    END IF;

    IF cardinality(new_keys) <> 67 THEN
        RAISE EXCEPTION 'The gate lists % keys this file adds, not 67', cardinality(new_keys);
    END IF;

    SELECT string_agg(n.name, ', ' ORDER BY n.name) INTO wrong
      FROM unnest(new_keys) AS n(name)
     WHERE NOT EXISTS (SELECT 1 FROM translation_keys k WHERE k.name = n.name);
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys this file adds that are not there: %', wrong;
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name) INTO wrong
      FROM translation_keys k
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys without an English string: %', wrong;
    END IF;

    SELECT string_agg(n.name, ', ' ORDER BY n.name) INTO wrong
      FROM unnest(new_keys) AS n(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = n.name AND t.language_code = 'sk');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys this file adds without a Slovak string: %', wrong;
    END IF;

    SELECT string_agg(name, ', ' ORDER BY name) INTO wrong
      FROM translation_keys
     WHERE name IN ('error.local_only', 'error.no_access', 'signIn.noAccess', 'signIn.anotherAccount',
                    'dashboard.backendHint', 'nav.translations', 'nav.languages',
                    'nav.dashboard', 'nav.import', 'nav.builds', 'toolbar.menu', 'toolbar.version',
                    'toolbar.yourAccount', 'toolbar.account', 'language.label');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys this file retires are still there: %', wrong;
    END IF;

    SELECT string_agg(name, ', ' ORDER BY name) INTO wrong
      FROM translation_keys
     WHERE description ILIKE '%toolbar%' OR description ILIKE '%bar''s menu%' OR description ILIKE '%version button%';
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Descriptions that still tell of the toolbar: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys;
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys, and 003 with this file make %', found, expected_keys;
    END IF;
END $$;
