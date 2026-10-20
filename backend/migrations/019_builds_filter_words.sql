-- ============================================================================
-- 019_builds_filter_words.sql - the words of the builds browser's filters
-- ============================================================================
-- The builds browser (/builds/genshin) filters its characters as the lists of
-- genshin.anotoki.eu do (the owner's decision of 2026-10-20): by name, element,
-- weapon type and rarity, and by the region and the version a character came
-- with, which the catalog has from that site since 018. New, in English and
-- Slovak - 14 keys (builds.filter.*): the filters' labels, their "All" and
-- "Any" choices, a rarity's name, Reset, how many characters show, and what
-- the page says when none matches.
--
-- The weapon filter's label is the game's own word ("Weapon", /api/meta's
-- weapon_label) and no key; the names of elements, weapon types, regions and
-- versions are the game's, never translated.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations).
-- On this computer the same, or `php migration.php` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

INSERT INTO translation_keys (name, description) VALUES
    ('builds.filter.element', 'The builds browser: the label of the filter by element - a row of choices (builds.filter.all, then the elements, whose names are the game''s). No placeholders.'),
    ('builds.filter.rarity', 'The builds browser: the label of the filter by rarity - a row of choices (builds.filter.all, then builds.filter.stars.* for each rarity). No placeholders.'),
    ('builds.filter.region', 'The builds browser: the label of the filter by the region a character comes from - a list of the regions (their names are the game''s). No placeholders.'),
    ('builds.filter.version', 'The builds browser: the label of the filter by the game version a character came with - a list of the versions, written as the game numbers and names them ("6.1 (Luna II)"). No placeholders.'),
    ('builds.filter.all', 'The builds browser: the first choice of the filters by element, weapon type and rarity - no filter, every character. One word for the three: it stands after the label "Element", the game''s word "Weapon" and builds.filter.rarity. No placeholders.'),
    ('builds.filter.anyRegion', 'The builds browser: the first choice of the filter by region (builds.filter.region) - no filter, characters of every region and of none. No placeholders.'),
    ('builds.filter.anyVersion', 'The builds browser: the first choice of the filter by version (builds.filter.version) - no filter, characters of every version. No placeholders.'),
    ('builds.filter.stars.one', 'The builds browser, the filter by rarity: a rarity''s choice, by its number of stars - the form for 1. {count}: that number.'),
    ('builds.filter.stars.few', 'The builds browser, the filter by rarity: a rarity''s choice, by its number of stars - the form for 2 to 4 (4 stars; in English the same as for many). {count}: that number.'),
    ('builds.filter.stars.other', 'The builds browser, the filter by rarity: a rarity''s choice, by its number of stars - the form for 5 or more (5 stars). {count}: that number.'),
    ('builds.filter.reset', 'The builds browser: the button that clears every filter, and the same button under builds.filter.noneHint. No placeholders.'),
    ('builds.filter.showing', 'The builds browser, under the filters: how many characters the filters show, of how many there are with builds - read out as it changes. {shown}: the characters shown; {total}: all of them.'),
    ('builds.filter.none', 'The builds browser, in place of the characters when no character matches the filters: the heading. No placeholders.'),
    ('builds.filter.noneHint', 'The builds browser, under builds.filter.none: what to do - the button builds.filter.reset follows it. No placeholders.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('builds.filter.element', 'Element'),
    ('builds.filter.rarity', 'Rarity'),
    ('builds.filter.region', 'Region'),
    ('builds.filter.version', 'Version'),
    ('builds.filter.all', 'All'),
    ('builds.filter.anyRegion', 'Any region'),
    ('builds.filter.anyVersion', 'Any version'),
    ('builds.filter.stars.one', '{count} star'),
    ('builds.filter.stars.few', '{count} stars'),
    ('builds.filter.stars.other', '{count} stars'),
    ('builds.filter.reset', 'Reset filters'),
    ('builds.filter.showing', 'Showing {shown} of {total}'),
    ('builds.filter.none', 'No character matches these filters.'),
    ('builds.filter.noneHint', 'Change the filters, or reset them to see every character.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('builds.filter.element', 'Element'),
    ('builds.filter.rarity', 'Vzácnosť'),
    ('builds.filter.region', 'Región'),
    ('builds.filter.version', 'Verzia'),
    ('builds.filter.all', 'Všetky'),
    ('builds.filter.anyRegion', 'Akýkoľvek región'),
    ('builds.filter.anyVersion', 'Akákoľvek verzia'),
    ('builds.filter.stars.one', '{count} hviezdička'),
    ('builds.filter.stars.few', '{count} hviezdičky'),
    ('builds.filter.stars.other', '{count} hviezdičiek'),
    ('builds.filter.reset', 'Zrušiť filtre'),
    ('builds.filter.showing', 'Zobrazené: {shown} z {total}'),
    ('builds.filter.none', 'Týmto filtrom nezodpovedá žiadna postava.'),
    ('builds.filter.noneHint', 'Zmeň filtre alebo ich zruš a uvidíš všetky postavy.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ── Verification ────────────────────────────────────────────────────────────
-- Each new key has its English and its Slovak, and the site's own keys are
-- 017's with 14 more (422 + 14).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 436;
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT count(*) INTO found FROM translation_keys WHERE name LIKE 'builds.filter.%';
    IF found <> 14 THEN
        RAISE EXCEPTION 'There are % keys of the filters, and this file adds 14', found;
    END IF;

    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM translation_keys k
     WHERE k.name LIKE 'builds.filter.%'
       AND (SELECT count(*) FROM translations t WHERE t.key_name = k.name AND t.language_code IN ('en', 'sk')) <> 2;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'New keys without their English and Slovak: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 017 with this file leave %', found, expected_keys;
    END IF;
END $$;
