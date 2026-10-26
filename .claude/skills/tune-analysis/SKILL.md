---
name: tune-analysis
description: Change how the build analyzer scores characters and what it recommends — part weights, grade thresholds, substat weighting, set/main-stat/weapon rules, recommendation messages, inventory suggestions and set plans, which of a character's variations is its best build and which stat targets its constellation is held to, or a new score part. Use whenever the user says grades feel too harsh or too generous, a recommendation is wrong or missing, wants talents/levels/stat targets/constellations to count differently, or asks why a character got a score before changing it.
---

# Tune the analysis

Scores are only useful if they rank characters the way an experienced player would, so every change should
be checked against real accounts, not only against unit tests. Work in this order: explain the current
result, change the rule, update tests, compare before/after on real data.

`docs/analysis.md` documents every formula and constant; read it first, and `docs/builds.md` for what a build's
fields mean. The code is in `backend/src/Services/Analysis/`.

## The analysis by variation and constellation

A character's builds are its **variations** - a role (`main_dps`, `sub_dps`, `support`), a qualifier where it has two
of one role, the constellation it needs (`min_rank`) - in the order of preference the builds file gave them
(`position`: the first is the main one). Every one of them is evaluated, and the analysis follows the character's
constellation (`AccountCharacter::$rank`):

- **Whether a build applies**: `Build::appliesTo($rank)` - `min_rank` reached. A build the character does not reach
  is judged all the same and says so (`applies: false`); the page shows it locked ("needs C4").
- **The best build** (`CharacterAnalyzer::best()`): of the builds that apply, the highest overall score, a tie to the
  lower `position`; when none applies, the one with the lowest `min_rank` (`applies: false`) - never one the
  character does not reach while another applies. The account page grades by the build the player chose instead,
  where they chose one (`CharacterAnalyzer::chosen()`, `account_characters.chosen_build_id`); the best is unchanged by it.
- **Stat targets**: the requirements a build states - per stat, the target with the highest `from_rank` the
  character has reached (`BuildEvaluator::targetsFor()`); a stat whose targets all start later has none - and every
  substat in three tiers worked out from the game's numbers (`TargetTiers`: the fixed sources of the sheet, budgets
  of 16 / 24 / 32 useful rolls split by priority, the crit pair's 1:2 rule, caps per piece; `docs/analysis.md`,
  "Stat targets"); scored by the tier reached (`BuildEvaluator::tierScore()`, `TIER_SCORES`).
- **Substats**: the build's own, or - when it lists none - `ItemScorer::fallbackSubstats()` from its own main stats.
  Never another build's (there is no consensus of builds any more).

Keep these four when you change a rule; changing one of them is a decision of its own (`docs/decisions.md`).

## Where the knobs are

| What | Where |
| --- | --- |
| Part weights (weapon 20, sets 25, main 20, substats 25, targets 10) | `BuildEvaluator::PART_WEIGHTS` |
| Overall grade thresholds | `BuildEvaluator::grade()` |
| Weapon, set, main stat, target formulas; recommendation texts and severities | `BuildEvaluator` (`weaponPart`, `setPart`/`evaluateOptions`, `mainStatPart`, `substatPart`, `targetPart`, `talentAdvice`, `levelAdvice`) |
| Substat weights by priority, flat-stat factor, ideal item, item grades | `ItemScorer` (`PRIORITY_WEIGHTS`, `FLAT_FACTOR`, `ideal()`, `grade()`) |
| Substats used when a build has none (from its own main stats) | `ItemScorer::fallbackSubstats()` |
| Whether a build applies; the targets of the character's constellation | `Build::appliesTo()` (`min_rank`), `BuildEvaluator::targetsFor()` (`from_rank`) |
| Set option matching | `SetMatcher` |
| Best build choice, inventory suggestions, set plans | `CharacterAnalyzer` (`best()`, `suggestions()`, `setPlans()`) |

## 1. Explain the current result

Ask the `analysis-auditor` agent to explain the character in question, or list an account's grades with a
scratch PHP file outside the repo, run from the repo root (`php <file> > "${TMPDIR:-/tmp}/before.txt"`):

```php
<?php
require 'backend/vendor/autoload.php';
$app = App\Cli::app();
$db = $app->db();
$row = $db->one('SELECT id, user_id FROM accounts ORDER BY id LIMIT 1');   // or the account in question
$account = $app->get(App\Repositories\Accounts::class)->findOwned((int) $row['user_id'], (int) $row['id']);
foreach ($app->get(App\Services\Analysis\CharacterAnalyzer::class)->overview($account) as $r) {
    if ($r['build'] && $r['items_count'] > 0) {
        $b = $r['build'];   // the build it is judged by - the player's choice (chosen) or the best of its variations
        $variation = $b['role'] . ($b['qualifier'] !== '' ? " {$b['qualifier']}" : '') . ($b['min_rank'] > 0 ? " C{$b['min_rank']}+" : '') . ($b['applies'] ? '' : ' (not reached)') . ($b['chosen'] ? ' (chosen)' : '');
        echo str_pad($r['name'], 26), str_pad("C{$r['rank']}", 4), str_pad($variation, 28), str_pad($b['grade'], 3), str_pad((string) $b['overall'], 5), json_encode($b['parts']), "\n";
    }
}
```

The database may have no accounts; import one first (the user's UID via the app, or a sample file with
`cd backend && php import-account.php genshin <file> --user=<IAM id>`). Remove sample accounts you imported once
you're done. Without builds there is nothing to score against (`build` is null): they come from a builds file an
editor imports on the admin panel's Builds page - ask the user rather than writing builds yourself. A character a
player chose a build for is graded by that one (`chosen`): compare it before and after as it is, or look at the
best in `detail()`'s `best_build_id`.

## 2. Change the rule

Keep scores in 0–100, keep parts that lack data out of the overall (they return `null`), and keep the English
`message` of a recommendation a short, actionable sentence with the slot/stat named - and without the name of
any website (a build is named by its variation, "the Main DPS build", never by where it came from). When you add a
field to the evaluation or detail output,
add it to `frontend/src/app/api/models.ts` and render it where it helps (character page, build detail), with
its words as keys (`shared/i18n/en/*.json` and a string migration, as below).

A recommendation is a **kind with params** that the page words in the reader's language; the English
`message` beside it is for the command line, the tests and the fallback (`docs/analysis.md`,
"Recommendations"). A new or changed recommendation therefore touches, together:

1. `BuildEvaluator::KINDS` - the kind and the names of its params, in the order they are sent. A clause the
   sentence may or may not have is a kind of its own (`_owned`, `_on`, `_empty`), never an English fragment
   inside a param; params are flat (game names and labels as the message uses them, numbers as numbers,
   lists joined as in the message).
2. The `recommend($severity, $category, $kind, $params, $message, $slot)` call - an existing `message` stays
   byte for byte (tests assert it).
3. `frontend/src/app/shared/i18n/dynamic-keys.ts` - the tail under `recommendation.`, written out whole.
4. `frontend/src/app/shared/i18n/recommendation-words.ts` - the kind's row (its key, the params its sentence
   needs); `target_below`-like numbers go through `MetaStore.formatStat()`, the game's notation.
5. The English in `frontend/src/app/shared/i18n/en/core.json` (`recommendation.<kind>`) - the message with the
   params as `{placeholders}`.
6. A **new migration** (`backend/migrations/NNN_*.sql`, 003's pattern: the key with a description saying
   where it shows and its placeholders, one `VALUES` block per language, `ON CONFLICT DO NOTHING`, a `DO $$`
   gate counting the keys) with the English and **informal Slovak** ("ty"). Game terms stay English and come
   first, then a colon - no Slovak word may have to agree with one („CRIT Rate: máš 62.0%, build mieri na
   70.0% a viac.“). Never edit 003 once it has run somewhere.

`tests/translation_catalogue.test.php` fails until all of them agree (KINDS = the declared tails = the
database's keys; each seeded English's placeholders among the kind's params; `en/*.json` = the seeded
English); `analysis.test.php` asserts each kind's params against `KINDS`.

## 3. Tests

Update or extend `backend/tests/analysis.test.php` (full evaluation of a seeded character, suggestions, set
plans; which variation is the best - a build not reached never is while another applies, a tie goes to the earlier -,
the targets by `from_rank`, substats from the build's own main stats) and `backend/tests/scoring.test.php`
(ItemScorer, SetMatcher). Assert behavior ("the Pyro goblet is suggested because it fixes the main stat"), not
incidental numbers, unless the number is the point.

```bash
cd backend && php tests/run.php
cd frontend && npx ng build && npx ng test --watch=false
```

## 4. Compare on real data

Re-run the step 1 script into `after.txt` and `diff` the two files. Look for surprises: characters that
jumped two grades, unbuilt characters scoring well, perfect builds not reaching S, a best build that changed
variation. Where a change touches constellations, look at one character below a build's `min_rank` (or a target's
`from_rank`) and at one at or above it - a GOOD file's `constellation` sets it -: the best build and the targets
must follow the constellation. Summarize the shift for the user with a couple of concrete characters.

## 5. Document

Update `docs/analysis.md`, the README "How the analysis works" table, and `docs/decisions.md` when the
change alters what users see (`docs/builds.md` too when it changes what a build's fields mean to the analysis). A
change users will notice gets a line in `frontend/public/changelog.json`.
A new or reworded recommendation is looked at on the character
page in English and in Slovak at 390 px (`verify-ui`): no key on the page, game terms in English before a
colon.
