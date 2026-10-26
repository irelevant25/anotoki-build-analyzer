-- ============================================================================
-- 027_stat_target_tiers_words.sql - the stat targets in three tiers: the words
-- ============================================================================
-- The owner's round of 2026-10-26, step 2 (docs/decisions.md): every build's
-- stat targets are worked out from the game's numbers - the character's and
-- the weapon's stats the catalog keeps since this version, the main stats, the
-- set's 2-piece bonus - in three tiers, basic, intermediate and advanced
-- (App\Services\Analysis\TargetTiers; docs/analysis.md, "Stat targets"), the
-- targets a build states kept as requirements. No table changes: the stats
-- live in the catalog rows' meta, which the next catalog refresh fills.
--
-- New, the words of the tiers: the three tiers' names on a target
-- (buildDetail.tier*), the line saying which weapon - and which set option -
-- the tiers were worked out for (buildDetail.targetsBasis, targetsBasisSets),
-- and the recommendation for a stat below the basic tier
-- (recommendation.target_basic_below - the kind of BuildEvaluator::KINDS,
-- worded by the page).
--
-- No string holds an address. In 003's form: a key with its description, its
-- English and its Slovak, every insert stepping aside for a row that is
-- already there; then the gate.
--
-- Applying it: upload the new version, then press Apply pending on the admin
-- panel's Migrations page at once, signed in as an ADMIN (/admin/migrations),
-- then Refresh catalog on the Builds page (the stats behind the tiers). On
-- this computer the same, or `php migration.php` and `php sync-catalog.php
-- --force` from backend/.
--
-- Once applied anywhere, this file is never edited again: a later change is
-- a migration of its own.
-- ============================================================================

INSERT INTO translation_keys (name, description) VALUES
    ('buildDetail.tierBasic', 'A build''s stat targets, on a stat worked out in three tiers: the first tier''s name, over its value - what a set of artifacts with the right main stats and a few good substat rolls reaches. One word. No placeholders.'),
    ('buildDetail.tierIntermediate', 'A build''s stat targets, on a stat worked out in three tiers: the middle tier''s name, over its value - a well-rolled set. One word. No placeholders.'),
    ('buildDetail.tierAdvanced', 'A build''s stat targets, on a stat worked out in three tiers: the top tier''s name, over its value - a set farmed for a long time. One word. No placeholders.'),
    ('buildDetail.targetsBasis', 'A build''s stat targets, under the heading: which weapon the tiers were worked out for - on the builds page the build''s first weapon, on an account''s character page the one the character wears -, for a build that lists no artifact sets. {weapon} is the weapon''s name, the game''s, never translated.'),
    ('buildDetail.targetsBasisSets', 'A build''s stat targets, under the heading: which weapon and which set option the tiers were worked out for - on the builds page the build''s first of each, on an account''s character page the weapon the character wears and the set option it wears where the build lists it. {weapon} is the weapon''s name and {sets} the option in the game''s notation ("4pc Blizzard Strayer", "2pc A + 2pc B"), both the game''s, never translated.'),
    ('recommendation.target_basic_below', 'A recommendation on the character page (what to improve): an in-game stat total below the basic tier of the build''s target for it. {stat} is the stat''s name as the game writes it, {current} the character''s total and {min} the basic tier''s value, both in the game''s notation ("62.0%", "4,780"). In Slovak the stat comes first, before a colon.')
ON CONFLICT (name) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'en', v.value FROM (VALUES
    ('buildDetail.tierBasic', 'Basic'),
    ('buildDetail.tierIntermediate', 'Intermediate'),
    ('buildDetail.tierAdvanced', 'Advanced'),
    ('buildDetail.targetsBasis', 'Worked out for {weapon}.'),
    ('buildDetail.targetsBasisSets', 'Worked out for {weapon} with {sets}.'),
    ('recommendation.target_basic_below', '{stat} is {current}; the basic target for this setup is {min}.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

INSERT INTO translations (key_name, language_code, value)
SELECT v.key_name, 'sk', v.value FROM (VALUES
    ('buildDetail.tierBasic', 'Základná'),
    ('buildDetail.tierIntermediate', 'Stredná'),
    ('buildDetail.tierAdvanced', 'Pokročilá'),
    ('buildDetail.targetsBasis', 'Vypočítané pre {weapon}.'),
    ('buildDetail.targetsBasisSets', 'Vypočítané pre {weapon} so setom {sets}.'),
    ('recommendation.target_basic_below', '{stat}: máš {current}, základný cieľ pre túto zostavu je {min}.')
) AS v(key_name, value)
ON CONFLICT (key_name, language_code) DO NOTHING;

-- ── Verification ────────────────────────────────────────────────────────────
-- The six new keys have their English and their Slovak; the site's own keys
-- are 026's with 6 more (452 + 6).
-- ============================================================================
DO $$
DECLARE
    expected_keys CONSTANT INTEGER := 458;
    found INTEGER;
    wrong TEXT;
BEGIN
    SELECT string_agg(k.name, ', ' ORDER BY k.name COLLATE "C") INTO wrong
      FROM unnest(ARRAY['buildDetail.tierBasic', 'buildDetail.tierIntermediate', 'buildDetail.tierAdvanced', 'buildDetail.targetsBasis', 'buildDetail.targetsBasisSets', 'recommendation.target_basic_below']) AS k(name)
     WHERE (SELECT count(*) FROM translations t WHERE t.key_name = k.name AND t.language_code IN ('en', 'sk')) <> 2;
    IF wrong IS NOT NULL THEN
        RAISE EXCEPTION 'New keys of the stat target tiers without their English and Slovak: %', wrong;
    END IF;

    SELECT count(*) INTO found FROM translation_keys WHERE name NOT LIKE 'anotoki.%';
    IF found <> expected_keys THEN
        RAISE EXCEPTION 'There are % keys of the site''s own, and 026 with this file leave %', found, expected_keys;
    END IF;
END $$;
