-- ============================================================================
-- 024_admin_frame.sql - the admin panel in the anotoki family's frame
-- ============================================================================
-- The owner's request of 2026-10-24 (docs/decisions.md): every anotoki admin
-- panel looks like the genshin site's - its pages in a column on the left, the
-- page on the right, and no top bar (anotoki-lib 0.10.0's <anotoki-admin-frame>,
-- frontend/src/app/sites/admin/app.ts). The column starts with the panel's name,
-- as its bar said it - "Analyzer · admin panel" (brand.short and brand.admin) -
-- a link to its first page, and has "Back to Build Analyzer" (topbar.backToApp)
-- above Sign out. The panel reads English, so these are its English strings:
--
--   - retired: the name the top bar gave its brand's link in the admin panel
--     (topbar.homeAdmin, "anotoki Build Analyzer: the admin panel") - the
--     frame's link is named by its own words, so nothing says it any more; its
--     strings go with it (ON DELETE CASCADE), a reworded one too, for nothing
--     shows it;
--   - described anew: brand.admin and topbar.backToApp, which the frame's
--     column says now (brand.admin's description named topbar.homeAdmin).
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- On this computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

DELETE FROM translation_keys WHERE name IN ('topbar.homeAdmin');

UPDATE translation_keys k
   SET description = v.description
  FROM (VALUES
    ('brand.admin', 'The admin panel (which reads English), at the top of its column: the part of the site this is, after the app''s short name (brand.short) and a dot - "Analyzer · admin panel", a link to the panel''s first page. Its words are kept together on one line. No placeholders.'),
    ('topbar.backToApp', 'The admin panel (which reads English), in its column above Sign out: the way back to the rest of the site. The app''s name stays as it is. No placeholders.')
  ) AS v(name, description)
 WHERE k.name = v.name;

-- ── Verification ────────────────────────────────────────────────────────────
-- The retired key is gone with its strings; the two described anew are there
-- with their English - not their Slovak, which an editor may have deleted (a
-- blank saved), and which is no business of this file's: refusing for it would
-- keep the site closed with no way to put it back while the update waits. The
-- site's own keys are 023's with 1 fewer (440 - 1).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 439;
    found INTEGER;
    wrong TEXT;
BEGIN
    IF EXISTS (SELECT 1 FROM translation_keys WHERE name = 'topbar.homeAdmin')
        OR EXISTS (SELECT 1 FROM translations WHERE key_name = 'topbar.homeAdmin') THEN
        RAISE EXCEPTION 'The name the top bar gave the admin panel''s home is still there';
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM unnest(ARRAY['brand.admin', 'topbar.backToApp']) AS k(name)
     WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = 'en');
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys the admin panel''s frame says, missing or without their English: %', wrong;
    END IF;

    SELECT string_agg(name, ', ' ORDER BY name COLLATE "C") INTO wrong
      FROM translation_keys
     WHERE name IN ('brand.admin', 'topbar.backToApp') AND description LIKE '%top bar%';
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Descriptions that still put the admin panel''s words in the top bar: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 023 with this file leave %', found, expected_keys;
    END IF;
END $$;
