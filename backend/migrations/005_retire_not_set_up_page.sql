-- ============================================================================
-- 005_retire_not_set_up_page.sql - the words of the old "Not set up yet" page go
-- ============================================================================
-- The site's own "Not set up yet" page is gone: while the site is not set up,
-- not available or being updated, every anotoki site now shows the family's
-- one page (anotoki-lib's site status page, the owner's decision of
-- 2026-10-07), whose words are the library's own, in English and Slovak - and
-- which never offers the setup page to a site that was installed. Nothing
-- reads the six keys of the old page any more, so they are deleted, and their
-- strings go with them (the foreign keys cascade), a wording an editor gave
-- them in the browser included. Nothing else changes.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- Until it is applied the site tells everybody else that it is being updated -
-- as every anotoki site does while one of its migrations waits. On this
-- computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

DELETE FROM translation_keys WHERE name IN (
    'notSetUp.title', 'notSetUp.lead', 'notSetUp.owner',
    'notSetUp.open', 'notSetUp.tryAgain', 'notSetUp.notOwner'
);

-- ============================================================================
-- PROVE IT
--
-- The six keys are gone, with every string of theirs, and there are exactly
-- as many keys as 004 left less these six (458 - 6).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 452;
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT string_agg(name, ', ' ORDER BY name) INTO wrong
      FROM translation_keys
     WHERE name LIKE 'notSetUp.%';
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Keys this file retires are still there: %', wrong;
    END IF;

    SELECT string_agg(DISTINCT key_name, ', ' ORDER BY key_name) INTO wrong
      FROM translations
     WHERE key_name LIKE 'notSetUp.%';
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'Strings of the retired keys are still there: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys;
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys, and 004 with this file make %', found, expected_keys;
    END IF;
END $$;
