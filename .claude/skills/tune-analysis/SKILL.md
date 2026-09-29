---
name: tune-analysis
description: Change how the build analyzer scores characters and what it recommends — part weights, grade thresholds, substat weighting, set/main-stat/weapon rules, recommendation messages, inventory suggestions and set plans, or a new score part. Use whenever the user says grades feel too harsh or too generous, a recommendation is wrong or missing, wants talents/levels/stat targets to count differently, or asks why a character got a score before changing it.
---

# Tune the analysis

Scores are only useful if they rank characters the way an experienced player would, so every change should
be checked against real accounts, not only against unit tests. Work in this order: explain the current
result, change the rule, update tests, compare before/after on real data.

`docs/analysis.md` documents every formula and constant; read it first. The code is in
`backend/src/Services/Analysis/`.

## Where the knobs are

| What | Where |
| --- | --- |
| Part weights (weapon 20, sets 25, main 20, substats 25, targets 10) | `BuildEvaluator::PART_WEIGHTS` |
| Overall grade thresholds | `BuildEvaluator::grade()` |
| Weapon, set, main stat, target formulas; recommendation texts and severities | `BuildEvaluator` (`weaponPart`, `setPart`/`evaluateOptions`, `mainStatPart`, `substatPart`, `targetPart`, `talentAdvice`, `levelAdvice`) |
| Substat weights by priority, flat-stat factor, ideal item, item grades | `ItemScorer` (`PRIORITY_WEIGHTS`, `FLAT_FACTOR`, `ideal()`, `grade()`) |
| Substats used when a build has none | `ItemScorer::fallbackSubstats()`, `CharacterAnalyzer::fallbackSubstats()` |
| Set option matching | `SetMatcher` |
| Best build choice, consensus, inventory suggestions, set plans | `CharacterAnalyzer` |

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
    if ($r['best'] && $r['items_count'] > 0) {
        echo str_pad($r['name'], 26), str_pad($r['best']['grade'], 3), str_pad((string) $r['best']['overall'], 5), json_encode($r['best']['parts']), "\n";
    }
}
```

The database may have no accounts; import one first (the user's UID via the app, or a sample file with
`cd backend && php import-account.php <game> <file>`). Remove sample accounts you imported once you're done.

## 2. Change the rule

Keep scores in 0–100, keep parts that lack data out of the overall (they return `null`), and keep
recommendation messages as short, actionable English sentences with the slot/stat named - and without the name
of any website ("Source 2's build", never the site). When you add a field to the evaluation or detail output,
add it to `frontend/src/app/core/models.ts` and render it where it helps (character page, build detail).

## 3. Tests

Update or extend `backend/tests/analysis.test.php` (full evaluation of a seeded character, suggestions, set
plans) and `backend/tests/scoring.test.php` (ItemScorer, SetMatcher). Assert behavior ("the Pyro goblet is
suggested because it fixes the main stat"), not incidental numbers, unless the number is the point.

```bash
cd backend && php tests/run.php
cd frontend && npx ng build && npx ng test --watch=false
```

## 4. Compare on real data

Re-run the step 1 script into `after.txt` and `diff` the two files. Look for surprises: characters that
jumped two grades, unbuilt characters scoring well, perfect builds not reaching S. Summarize the shift for
the user with a couple of concrete characters.

## 5. Document

Update `docs/analysis.md`, the README "How the analysis works" table, and `docs/decisions.md` when the
change alters what users see. A change users will notice gets a line in `frontend/public/changelog.json`.
