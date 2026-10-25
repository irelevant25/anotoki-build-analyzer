-- ============================================================================
-- 025_site_settings_words.sql - the site settings' words of the site's own
-- ============================================================================
-- anotoki-lib 0.11.0 (the owner's requests of 2026-10-25, docs/decisions.md):
-- the family's site settings on every site - Getting in (closing the site for
-- a while), an announcement at the top of its pages, and Page access (who may
-- see which page) -, switched on the admin panel's Site settings page and kept
-- in the library's tables (its anotoki_admin/003, which runs before this file).
-- Their own words are the library's (anotoki.siteSettings.*,
-- anotoki.maintenance.*: its anotoki_translations/015, which runs after this
-- file). What follows is this site's own, in English and Slovak (informal):
--
--   - new, the Privacy page's part about the site settings (legal.settings.*):
--     each setting, and each page's row of Page access, keeps who changed it
--     last and when (the library's updated_by - the administrator's anotoki
--     id) until it changes again; the scrub leaves it naming the IAM's
--     placeholder, and the People page's delete leaves it changed by nobody
--     (App\People\SitePeople::deleteData());
--   - new, the page drawn where a page Page access closes to the reader falls
--     through to the dashboard and the dashboard is closed to them too - the
--     router has nowhere else to go (title.notOpen, notOpen.title,
--     notOpen.text; frontend/src/app/sites/main/not-open);
--   - reworded only where it still reads as 023 left it, so a rewording made in
--     the browser is kept: the Privacy page's sentence on what the audit log
--     keeps, which now names the site settings changed (legal.auditLog.what -
--     site_settings.changed, page_access.added, .changed, .removed).
--
-- No string holds an address. In 003's form: a key with its description, its
-- English and its Slovak, every insert stepping aside for a row that is
-- already there; then the gate.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations):
-- the library's anotoki_admin/003 and anotoki_translations/014 and 015 go in
-- the same Apply. On this computer the same, or `php migration.php` from
-- backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

INSERT INTO translation_keys (name, description) VALUES
    ('title.notOpen', 'The browser tab''s title of the page shown in place of one the site''s administrators closed to this reader (the site settings'' Page access) - when the dashboard, where such a page falls through to, is closed to them too - before " · Build Analyzer". Short. No placeholders.'),
    ('notOpen.title', 'The heading of the page shown in place of one the site''s administrators closed to this reader (the site settings'' Page access), when the dashboard is closed to them too: the page they asked for is not open to them now. No placeholders.'),
    ('notOpen.text', 'The sentence under the heading of the page that is not open: the administrators closed it for now, or opened it for some people only (people who are signed in, for example). Never says who. No placeholders.'),
    ('legal.settings.name', 'The Privacy page: the heading of the part about the administrators who change the site''s settings (who changed a setting last, and when), and its line under "How long it is kept". Short. No placeholders.'),
    ('legal.settings.what', 'The Privacy page, the settings'' changers: what is kept - the site''s settings (an announcement at the top of its pages, closing the site for a while, who may see which page) each keep who changed them last, and when. No placeholders.'),
    ('legal.settings.why', 'The Privacy page, the settings'' changers: what it is for (under "What for"). No placeholders.'),
    ('legal.settings.kept', 'The Privacy page, the settings'' changers: how long - until the setting is changed again; a person whose anotoki account is deleted is no longer named, and an administrator''s delete of a person''s data leaves the setting changed by nobody. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('title.notOpen', 'Not open right now'),
    ('notOpen.title', 'This page is not open right now'),
    ('notOpen.text', 'The site''s administrators have closed it for now, or opened it for some people only.'),
    ('legal.settings.name', 'If you change the site''s settings'),
    ('legal.settings.what', 'Administrators change the site''s settings in its admin panel - an announcement at the top of its pages, closing the site for a while, who may see which page: each setting keeps who changed it last, and when.'),
    ('legal.settings.why', 'So that the administrators know who changed what.'),
    ('legal.settings.kept', 'Until the setting is changed again. When your anotoki account is deleted, it no longer shows your name; when an administrator deletes your data here, the setting stays, changed by nobody.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('title.notOpen', 'Teraz nie je otvorené'),
    ('notOpen.title', 'Táto stránka teraz nie je otvorená'),
    ('notOpen.text', 'Správcovia stránky ju zatiaľ zatvorili alebo ju otvorili len pre niektorých ľudí.'),
    ('legal.settings.name', 'Ak meníš nastavenia stránky'),
    ('legal.settings.what', 'Správcovia menia nastavenia stránky v administrátorskom paneli - oznámenie navrchu stránok, dočasné zatvorenie stránky, kto môže vidieť ktorú stránku: pri každom nastavení sa uchováva, kto ho naposledy zmenil a kedy.'),
    ('legal.settings.why', 'Aby správcovia vedeli, kto čo zmenil.'),
    ('legal.settings.kept', 'Kým sa nastavenie znova nezmení. Po zmazaní tvojho účtu anotoki pri ňom už nie je tvoje meno; keď tvoje údaje tu zmaže správca, nastavenie zostane, ale bez toho, kto ho zmenil.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ── The Privacy page's audit log, where it reads as 023 left it ─────────────
UPDATE translations t
   SET value = v.value
  FROM (VALUES
    ('legal.auditLog.what', 'en',
     'What the site''s editors and administrators do in its admin panel - builds imported from a file or edited by hand, the game catalog refreshed, the words and languages saved, a person''s data deleted, an update applied, the backups, the error log and feedback handled: who, what and when. Never an IP address.',
     'What the site''s editors and administrators do in its admin panel - builds imported from a file or edited by hand, the game catalog refreshed, the words and languages saved, the site''s settings changed, a person''s data deleted, an update applied, the backups, the error log and feedback handled: who, what and when. Never an IP address.'),
    ('legal.auditLog.what', 'sk',
     'Čo robia editori a správcovia stránky v administrátorskom paneli - buildy importované zo súboru alebo upravené ručne, obnovený herný katalóg, uložené slová a jazyky, zmazané údaje človeka, použitá aktualizácia, zálohy, záznam chýb a spätná väzba: kto, čo a kedy. Nikdy nie IP adresa.',
     'Čo robia editori a správcovia stránky v administrátorskom paneli - buildy importované zo súboru alebo upravené ručne, obnovený herný katalóg, uložené slová a jazyky, zmenené nastavenia stránky, zmazané údaje človeka, použitá aktualizácia, zálohy, záznam chýb a spätná väzba: kto, čo a kedy. Nikdy nie IP adresa.')
  ) AS v(key_name, language_code, seeded, value)
 WHERE t.key_name = v.key_name AND t.language_code = v.language_code AND t.value = v.seeded;

-- ── Verification ────────────────────────────────────────────────────────────
-- The new keys have their English and their Slovak; the reworded key is there
-- with its English - not its Slovak, which an editor may have deleted (a blank
-- saved), and which is no business of this file's: refusing for it would keep
-- the site closed with no way to put it back while the update waits. The
-- site's own keys are 024's with 7 more (439 + 7).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 446;
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM unnest(ARRAY['title.notOpen', 'notOpen.title', 'notOpen.text', 'legal.settings.name', 'legal.settings.what', 'legal.settings.why', 'legal.settings.kept']) AS k(name)
     WHERE (SELECT count(*) FROM translations t WHERE t.key_name = k.name AND t.language_code IN ('en', 'sk')) <> 2;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'New keys of the site settings without their English and Slovak: %', wrong;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM translations WHERE key_name = 'legal.auditLog.what' AND language_code = 'en') THEN
        RAISE EXCEPTION 'The Privacy page''s audit log sentence (legal.auditLog.what) is missing or without its English';
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 024 with this file leave %', found, expected_keys;
    END IF;
END $$;
