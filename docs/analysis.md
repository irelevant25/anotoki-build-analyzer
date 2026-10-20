# Analysis and scoring

Code: `backend/src/Services/Analysis/`. Tests: `backend/tests/analysis.test.php`, `scoring.test.php` (ItemScorer,
SetMatcher).

## Flow

`CharacterAnalyzer` loads an `AccountContext` (all items/weapons of the account, indexed by equipping
character and slot) and runs `BuildEvaluator::evaluate()` for every build of the character, in the character's
order. A build is a variation of the character's - its role, qualifier and the constellation it needs (`min_rank`) -
and a character's first build is its main one ([builds.md](builds.md)).

- Every build is evaluated in full, whether or not the character's constellation (`account_characters.rank`)
  reaches its `min_rank`; the evaluation's `build` says which (`applies`, `Build::appliesTo()`), with the build's
  `id`, `role`, `qualifier`, `min_rank`, `position`, `game_version` and `checked_at`.
- The best build (`CharacterAnalyzer::best()`): of the builds that apply, the highest overall score, the earlier
  build on a tie (a build without any score counts below every scored one); when none applies, the build needing
  the lowest constellation (the earlier on a tie), marked `applies: false` - the page shows it with a lock and
  "needs C4". A build that does not apply is never the best while one applies. There is no consensus of several
  builds and no "recommended" flag: the order of a character's builds is their preference.
- `overview()`: per owned character `builds_count` and its best build - its variation (`build_id`, `role`,
  `qualifier`, `min_rank`, `applies`), `overall`, `grade`, `parts`, `top_issue` / `top_issue_kind` /
  `top_issue_params` and `issues` (the recommendations above low) - for the account page.
- `detail()`: every evaluation, `best_build_id`, and for full-inventory accounts `suggestions` + `set_plans` for
  the focused build (`?build=ID`, default the best; `focus_build_id`).
- Substats are the build's own. A build that lists none is scored by what its own main stats ask for
  (`ItemScorer::fallbackSubstats()`, below) - never by another build's priorities; the build's detail says they
  are derived.
- Stat targets are those of the character's constellation (`BuildEvaluator::targetsFor()`): per stat, the target
  with the highest `from_rank` not above the character's constellation (of a build's targets from C0 and from C1, a
  C2 character is held to the C1 one); a stat whose targets all start later has none. They come in the order the
  build first names each stat, each with its `from_rank` (the page marks a later one "C1+").
- "Elemental DMG" (`elemental_dmg_`) resolves to the character's element before comparing.

## Parts (0–100 each)

| Part | Rule (`BuildEvaluator`) |
| --- | --- |
| Weapon | listed rank r → `max(60, 100 − 8·(r−1))`; not listed → 40; none → 0; level < max−10 (`Game::maxWeaponLevel()`, 90) → ×0.9. Better-ranked weapons the account owns are reported (with who holds them). |
| Sets | first satisfied option (in rank order) → `max(70, 100 − 8·(rank−1))`; none → `10 + 60·bestFraction` (partial credit). A group per kind of set the game has, weighed by `Game::setWeight()` - Genshin has one, `artifact`. `SetMatcher`: a piece needs `count` items of one of its keys; one equipped set satisfies only one piece; `choose` = pieces required. |
| Main stats | per variable slot the build lists: its top priority 100, the next 85, lower 75, not listed 0, empty slot 0. |
| Substats | `ItemScorer` per item → percent of an ideal item; part = `min(100, avg% / 70 · 100)` where the average runs over all slots (empty slots count 0). |
| Stat targets | the targets of the character's constellation (above), only with in-game totals (showcase imports): met → 100, else `max(0, current/min·100 − 10)`. |

Overall = weighted mean of available parts: weapon 20, sets 25, main stats 20, substats 25, targets 10
(`BuildEvaluator::PART_WEIGHTS`); a part the build has no data for is left out. Grades: S ≥ 90, A ≥ 80, B ≥ 65,
C ≥ 50, else D.

## Item scoring (`ItemScorer`)

- Substat weight by priority: 1 → 1.0, 2 → 0.8, 3 → 0.6, 4 → 0.45, lower → 0.3, unlisted → 0.
  Flat HP/ATK/DEF get 0.35 (`Game::flatStatFactor()`) of their percent stat's weight, unless the build lists them.
- Rolls = value / max 5★ roll ([stats-and-slots.md](stats-and-slots.md)); score = Σ weight × rolls.
- Ideal = best four weights among possible substats (never the main stat), all upgrades in the best one:
  `(w1·(1+u) + w2 + w3 + w4) · s` with u/s = 5/1.0 (5★), 4/0.8 (4★), 3/0.6.
- Item grade by percent of ideal: S ≥ 70, A ≥ 55, B ≥ 42, C ≥ 30, D. Roll counts shown in the UI are exact
  when the import gives them, else `round(value / (max · 0.85))`.
- Derived substats (`ItemScorer::fallbackSubstats()`, for a build that lists none): CRIT Rate and CRIT DMG at
  priority 1 when one of the build's main stats is a crit stat; each of ATK%, HP%, DEF%, Elemental Mastery and
  Energy Recharge its main stats list, at priority 2; Energy Recharge at priority 3 when they do not list it.

## Recommendations

Sorted high → medium → low. A recommendation is `{severity, category, kind, params, message, slot?}`: the
page words it from `kind` and `params` in the reader's language (`recommendation.<kind>`, through
`frontend/src/app/core/i18n/recommendation-words.ts`); `message` is the same sentence in English, kept for
the command line, for the tests and as the fallback the page shows (with `lang="en"`) for a kind it does not
know. The overview's best build carries `top_issue` (that English), `top_issue_kind` and `top_issue_params`
(the first recommendation's pair, null when there is none) for the account page. A build the character's
constellation does not reach gets its recommendations like any other.

- `BuildEvaluator::KINDS` lists every kind with the names of its params, in the order they are sent - 18
  kinds from 14 `recommend()` calls. A clause a sentence may or may not have is a kind of its own (`_owned`,
  `_on`, `_empty`: `weapon_unlisted_owned_on`, `sets_missing_empty`), never an English fragment inside a
  param.
- Params are flat: names and labels as the message uses them (`slot_label` is the label; the
  recommendation's own `slot` keeps the slot key), numbers as JSON numbers, lists joined by the server as
  in the message (", ", " / ", " + "). `weapon_label` is `Game::weaponLabel()` ("Weapon") and `set_label`
  `Game::setLabel()` ("Artifact set"), both with a capital, so they can open a sentence before a colon;
  `refinement` is prefix plus number ("R1"); for the set kinds `best` is the option without its "(any N)"
  (`optionPieces()`) and `best_choose` that N or null (`optionChoose()`) - the page adds the any-phrase
  (`buildDetail.optionAny`). For `target_below` the page formats `current` and `min` with
  `MetaStore.formatStat()`, the game's notation.
- Game terms and names reach the sentence only as params; Slovak puts the term first and a colon after it
  („Goblet of Eonothem: main stat je ATK%, tento build chce Pyro DMG Bonus.“), so no Slovak word agrees with
  an English one.
- A new kind: its row in `KINDS`, the `recommend()` call with the kind, its params and the English
  `message`, its tail in `frontend/src/app/core/i18n/dynamic-keys.ts` (`recommendation.`), its English in
  `core/i18n/en/core.json`, its row in the `KINDS` table of `recommendation-words.ts` (its key and the params
  the sentence needs; a kind missing there is shown as its English `message`), and a new
  migration with the key, its description, the English and informal Slovak. The tests hold `KINDS`, the
  declared tails and the database to each other (`tests/translation_catalogue.test.php`), and each seeded
  English's placeholders to the kind's params.

- high: no weapon, no recommended set option worn, wrong or missing main stat.
- medium: weapon not on the list; a better-ranked weapon is owned while the equipped one ranks below #3;
  set option #3 or lower; weak fully-leveled pieces (grade C/D, up to two); unmet stat targets; items at
  least 8 levels below max; character below level 80 (`Game::characterLevelTarget()`).
- low: better owned weapon while the equipped one is #2–#3; equipped weapon below #3 with nothing better
  owned; weapon under-leveled; set option #2; items a few levels below max; a priority-1 talent below 8.

## Inventory suggestions (full inventory only)

Per slot, candidates must have an acceptable main stat and may never lower the set-option fraction:

- `completes_set`: the swap alone completes the target option (≥ 25 % of ideal);
- `fixes_main_stat`: current main stat is wrong and the candidate keeps the set situation;
- `better_substats`: same set situation, ≥ 8 points better.

Target option = the one currently satisfied, else the build's top option. When no recommended option is
worn, single swaps must also keep the character's current 2pc/4pc bonuses (`keepsBonuses`), and
`set_plans` list the best owned piece per slot of the top option when it names a single set (one piece: as many
of the best as its `count` - a 4pc's four, the fifth slot free for an off-piece) with "you own N of M pieces".
`equipped_on` names the character currently holding a piece. Suggestions follow the focused build, whether or
not it applies.

## Changing the scoring

Use the `tune-analysis` skill. Keep the frontend in sync (`core/models.ts`, the character page's keys and
the recommendation wording) and the README's "How the analysis works" table.
