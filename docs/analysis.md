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
  builds and no "recommended" flag: the order of a character's builds is their preference. The pages call the
  best the "Closest match to your current setup".
- The build the player chose (`account_characters.chosen_build_id`, migration 026; the owner's round of
  2026-10-26): on the character page **Use this build** makes the build in hand the one the account page grades the
  character by, **Choose automatically** goes back to the best (`PUT /api/accounts/{id}/characters/{key}/build`
  `{build_id}` - one of the character's own builds, reached or not, or null; another 422 `invalid`). The best stays
  what it is, the closest match; the choice survives every import of the account (its characters are updated in
  place), moves with the account at sign-in, travels in the accounts file by the build's variation
  ([data-model.md](data-model.md)), and goes with the build when an import or an editor removes it (`ON DELETE SET
  NULL`: the closest match again). `CharacterAnalyzer::chosen()` finds it among the evaluations.
- `overview()`: per owned character `builds_count` and the build it is judged by - the one chosen, else its best -
  as `build`: `chosen` (true where the player chose it), its variation (`build_id`, `role`, `qualifier`,
  `min_rank`, `applies`), `overall`, `grade`, `parts`, `top_issue` / `top_issue_kind` / `top_issue_params` and
  `issues` (the recommendations above low) - for the account page, which grades, sorts and averages by it and names
  it "Selected build: ..." or "Best build: ...".
- `detail()`: every evaluation, `best_build_id`, `chosen_build_id` (null: none chosen), and for full-inventory
  accounts `suggestions` + `set_plans` for the focused build (`?build=ID`, default the one chosen, else the best;
  `focus_build_id` - the tab the page opens on).
- Substats are the build's own. A build that lists none is scored by what its own main stats ask for
  (`ItemScorer::fallbackSubstats()`, below) - never by another build's priorities; the build's detail says they
  are derived.
- Stat targets are worked out from the game's numbers for every build (`TargetTiers`, "Stat targets" below): the
  targets a build states are requirements, held at the character's constellation (`BuildEvaluator::targetsFor()`:
  per stat, the target with the highest `from_rank` not above it - of a build's targets from C0 and from C1, a C2
  character is held to the C1 one; a stat whose targets all start later has none; the page marks a later one
  "C1+"), and every substat of the build gets three tiers. While the catalog lacks the stats behind them, the
  stated targets stand alone, as before.
- "Elemental DMG" (`elemental_dmg_`) resolves to the character's element before comparing.

## Parts (0–100 each)

| Part | Rule (`BuildEvaluator`) |
| --- | --- |
| Weapon | listed rank r → `max(60, 100 − 8·(r−1))`; not listed → 40; none → 0; level < max−10 (`Game::maxWeaponLevel()`, 90) → ×0.9. Better-ranked weapons the account owns are reported (with who holds them). |
| Sets | first satisfied option (in rank order) → `max(70, 100 − 8·(rank−1))`; none → `10 + 60·bestFraction` (partial credit). A group per kind of set the game has, weighed by `Game::setWeight()` - Genshin has one, `artifact`. `SetMatcher`: a piece needs `count` items of one of its keys; one equipped set satisfies only one piece; `choose` = pieces required. |
| Main stats | per variable slot the build lists: its top priority 100, the next 85, lower 75, not listed 0, empty slot 0. |
| Substats | `ItemScorer` per item → percent of an ideal item; part = `min(100, avg% / 70 · 100)` where the average runs over all slots (empty slots count 0). |
| Stat targets | the rows of "Stat targets" below, against the sheet's totals - the game's own (a showcase import), else worked out from the gear ("The totals from the gear" below): a requirement met → 100, else `max(0, current/min·100 − 10)`; a tiered stat by the tier reached (`BuildEvaluator::tierScore()`, `TIER_SCORES`): advanced 100, intermediate 85, basic 65, in proportion between two tiers, and below basic 65 in proportion to it. The part is the mean of the rows with a total. |

Overall = weighted mean of available parts: weapon 20, sets 25, main stats 20, substats 25, targets 10
(`BuildEvaluator::PART_WEIGHTS`); a part the build has no data for is left out. Grades: S ≥ 90, A ≥ 80, B ≥ 65,
C ≥ 50, else D.

## Stat targets (`TargetTiers`)

Since the round of 2026-10-26 ([decisions.md](decisions.md)) every build has stat targets, worked out from the
game's numbers rather than read from anywhere - the build sites give a number for one build in four, and nearly
only Energy Recharge. The catalog keeps, since the same round, what genshin.anotoki.eu knows of each character and
weapon (`meta.stats`: [data-model.md](data-model.md)); `App\Support\LevelStats` takes it to level 90
([stats-and-slots.md](stats-and-slots.md), "A level"), and `App\Support\SetBonus` reads a set's 2-piece bonus from
its words.

- **The fixed sources** of each stat on the character sheet: its base (5 % CRIT Rate, 50 % CRIT DMG, 100 % Energy
  Recharge, nought otherwise), the character's ascension stat, the weapon's secondary stat, the main stat of each
  artifact slot (a 5-star piece at +20: the flower's HP and the plume's ATK always; a variable slot's the build's top
  choice - "Elemental DMG" as the character's element -, or the one the player wears where the build lists it) and
  the 2-piece bonuses of the set option (each piece it takes; a 4-piece counts its 2-piece). Nothing conditional is
  on the sheet - a 4-piece bonus, a weapon's passive, a buff -, so nothing conditional is in the sources; a 4-piece
  that adds CRIT Rate in combat enters the crit pair's balance instead (below).
- **The weapon and the set option**: on the builds page the first of the build's weapons the catalog has the stats
  of and the build's first set option; on an account's character page the weapon the character wears, where the
  catalog knows it, and the set option it wears where it is one of the build's, else the firsts. The rows say which
  (`targets_basis`: `{weapon, sets, main_stats}`); the page writes "Worked out for {weapon} with {sets}".
- **Requirements**: the targets the build states - a file's or an editor's - that hold at the constellation (on the
  builds page the one the build is for; a 4-star character's at C6, the owner's rule: nearly every player has them
  there; on a character page the character's own). The substat rolls a requirement needs are paid first (a stated
  total of HP, ATK or DEF rolls its percentage), and it goes out as stated, never tiered: `{stat, tiers: null, min,
  max, from_rank}`.
- **The tiers**: each a budget of useful substat rolls at the average roll (85 % of the max): basic 16,
  intermediate 24, advanced 32 of the 45 a set of five pieces holds (`TIERS`, `AVERAGE_ROLL`). What a tier has left
  after the requirements splits among the build's substats (a build that lists none: what its main stats ask for,
  as the scorer does) by their priority - 1.0, 0.35, 0.2, 0.12, lower 0.1 (`PRIORITY_SHARES`, `LOWEST_SHARE`:
  far steeper than the scorer's weights - the top players put nearly every roll into the top stats) -, no stat
  taking more than five rolls per piece that can roll it (`ROLLS_PER_PIECE`: one whose main stat it is not; triple
  Elemental Mastery leaves two pieces), and Energy Recharge's rolls stopping at the role's ceiling - main DPS 140 %,
  sub DPS 180 %, support 200 % (`ENERGY_RECHARGE_MOST`: a need no number of the game's gives; a stated requirement,
  higher or lower, decides instead) -, the rest flowing on. CRIT Rate and CRIT DMG, both listed, are one share whatever their
  priorities (the sites rank CRIT Rate last where a set adds it in combat, which the sheet cannot show), split so
  CRIT DMG is twice the CRIT Rate in combat - the sheet's plus what the set option's 4-piece adds, read from its
  words (`SetBonus::fourPieceCritRate()`: Blizzard Strayer's 40 %, Marechaussee Hunter's 36 %, Obsidian Codex's
  40 %) - and the combat CRIT Rate never past 85 % (`CRIT_RATE_CAP`): a Blizzard Strayer build stops at 45 % on the
  sheet and puts the rest into CRIT DMG. Each tier's value is the fixed sources plus the rolls:
  `{stat, tiers: {basic, intermediate, advanced}, min: null, max: null, floor}`. HP%, ATK% and DEF% go out as the
  sheet's totals under `hp`, `atk`, `def` (base × (1 + %) + flat); the rest as the percentage or points. Every row
  says where the sheet starts for its stat (`floor`: the fixed sources alone, not one useful roll yet).
- **Rows**: the requirements first in the build's order, then the tiered stats by priority; on an account's
  character each row carries `current` - the sheet's total: the game's own from a showcase import, else worked out
  from the gear (below) -, `ok` (a requirement met; the basic tier reached) and the `tier` reached.
  A stat below a requirement is a `target_below` recommendation, one below the basic tier a `target_basic_below`
  (medium), with the basic value as its `min`.
- **The bars** (step 4 of the round): on an account's character page each target with a total is drawn as the
  kit's progress bar (`sites/main/common/target-bar`) from the row's `floor` to the advanced tier - the basic and
  the intermediate tiers marked along it by the site, the kit having no marks - or, for a requirement, to its
  value; the fill is the total, in the card's green where the requirement or a tier is reached, its red below. The
  tier values and "you: ..." stay beside it; the builds browser, with no total, keeps the values alone. The account
  page's character cards carry the stat targets' part as a fourth score bar beside the sets', main stats' and
  substats'.
- **Without the stats** - the catalog not refreshed since the genshin site hands them on, a character or weapon it
  has none for - the rows are the stated targets alone (`tiers` null, `floor` null: a bar from nought), and
  `targets_basis` is null.

The budgets, the shares and the Energy Recharge ceilings were set against what the top players of 36 builds reach
(the owner's samples of 2026-10-26, never in the repository): a whale's set holds 37-40 useful rolls, so advanced is
a set farmed for a long time, short of that - its CRIT DMG within a tenth of the top twenty's lowest for most crit
builds -; intermediate a well-rolled set; basic a set with the right main stats and some luck. Where a build's
priorities put Elemental Mastery or ATK% high, the targets follow them and the top players, who stack CRIT DMG,
sit above the computed CRIT DMG: the priorities are editors' to set. Constellations reach the targets through the
requirements alone (`from_rank`): they change damage and energy, not the sheet's stats, and the samples' C1, C2 and
C6 variants read like their C0.

## The totals from the gear (`SheetTotals`)

A showcase import brings the game's own totals (`account_characters.final_stats`); an inventory file brings none. Since
the round's step 3 a character without them is shown with, and has its stat targets checked against, the totals its
character sheet would show, worked out from the gear it wears (`App\Services\Analysis\SheetTotals`,
`AccountContext::sheetTotals()` once per character; the detail's `computed_stats`, null beside the game's own, which
stand first) - never stored, worked out when the character is shown:

- **the sheet's base** (5 % CRIT Rate, 50 % CRIT DMG, 100 % Energy Recharge), **the character at its level** in its
  ascension's phase with the ascension stat's value there (`LevelStats::characterAt()`; an ascension below the fewest
  the level needs - an import naming none stores 0 - is read as that fewest, `LevelStats::leastAscension()`), **the
  weapon at its level** (`LevelStats::weaponAt()`: base ATK and the
  secondary stat), **every equipped piece's main stat** (its value as imported, or `MainStatValues`' for an export
  without one) **and substats**, and **the 2-piece bonus** of every set worn on two pieces or more (`SetBonus`);
- HP, ATK and DEF are the base times its percentage plus the flat points, the rest the percentage or points; the keys
  are a showcase's (`hp`, `atk`, `def`, `eleMas`, `critRate_`, `critDMG_`, `enerRech_`, `heal_`, `physical_dmg_`,
  `{element}_dmg_` - Elemental Mastery, the crit pair, Energy Recharge, Healing Bonus, Physical DMG and the
  character's own element's DMG always, nought included, as a showcase sends them; another element's DMG only where
  the gear gives some), so the two read alike and a target on any of them compares alike;
- **nothing conditional**: no weapon passive, no 4-piece bonus, no resonance, no buff - the same sources the targets
  are worked out from. The game shows a little more, and the page says so ("Worked out from your gear ... Passives
  and team buffs are not in it").

Null while the catalog keeps no stats of the character (the rows go unscored, as before); without the weapon's
stats no ATK is said, and without a weapon the ATK is the character's own.

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
`frontend/src/app/shared/i18n/recommendation-words.ts`); `message` is the same sentence in English, kept for
the command line, for the tests and as the fallback the page shows (with `lang="en"`) for a kind it does not
know. The overview's `build` (the one chosen, else the best) carries `top_issue` (that English), `top_issue_kind` and `top_issue_params`
(the first recommendation's pair, null when there is none) for the account page. A build the character's
constellation does not reach gets its recommendations like any other.

- `BuildEvaluator::KINDS` lists every kind with the names of its params, in the order they are sent - 19
  kinds from 15 `recommend()` calls. A clause a sentence may or may not have is a kind of its own (`_owned`,
  `_on`, `_empty`: `weapon_unlisted_owned_on`, `sets_missing_empty`), never an English fragment inside a
  param.
- Params are flat: names and labels as the message uses them (`slot_label` is the label; the
  recommendation's own `slot` keeps the slot key), numbers as JSON numbers, lists joined by the server as
  in the message (", ", " / ", " + "). `weapon_label` is `Game::weaponLabel()` ("Weapon") and `set_label`
  `Game::setLabel()` ("Artifact set"), both with a capital, so they can open a sentence before a colon;
  `refinement` is prefix plus number ("R1"); for the set kinds `best` is the option without its "(any N)"
  (`optionPieces()`) and `best_choose` that N or null (`optionChoose()`) - the page adds the any-phrase
  (`buildDetail.optionAny`). For `target_below` and `target_basic_below` the page formats `current` and `min`
  with `MetaStore.formatStat()`, the game's notation.
- Game terms and names reach the sentence only as params; Slovak puts the term first and a colon after it
  („Goblet of Eonothem: main stat je ATK%, tento build chce Pyro DMG Bonus.“), so no Slovak word agrees with
  an English one.
- A new kind: its row in `KINDS`, the `recommend()` call with the kind, its params and the English
  `message`, its tail in `frontend/src/app/shared/i18n/dynamic-keys.ts` (`recommendation.`), its English in
  `shared/i18n/en/core.json`, its row in the `KINDS` table of `recommendation-words.ts` (its key and the params
  the sentence needs; a kind missing there is shown as its English `message`), and a new
  migration with the key, its description, the English and informal Slovak. The tests hold `KINDS`, the
  declared tails and the database to each other (`tests/translation_catalogue.test.php`), and each seeded
  English's placeholders to the kind's params.

- high: no weapon, no recommended set option worn, wrong or missing main stat.
- medium: weapon not on the list; a better-ranked weapon is owned while the equipped one ranks below #3;
  set option #3 or lower; weak fully-leveled pieces (grade C/D, up to two); a stat target's requirement unmet or
  a stat below its basic tier; items at least 8 levels below max; character below level 80
  (`Game::characterLevelTarget()`).
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

Use the `tune-analysis` skill. Keep the frontend in sync (`api/models.ts`, the character page's keys and
the recommendation wording) and the README's "How the analysis works" table.
