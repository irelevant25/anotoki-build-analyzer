# Stats, slots and talents

Everything uses canonical stat keys (`backend/src/Support/Stats.php`); they follow the GOOD format. A trailing
underscore means "percent". Since 0.5.0 the site has one game, Genshin Impact (Honkai: Star Rail went, with its
stats, slots and talents); every fact that differs between games is still a method of `Enums/Game` - a match with
an arm per game -, so a game added later is a case and its arms.

## Stat keys

| Key | Stat | 5★ max substat roll |
| --- | --- | --- |
| `hp`, `atk`, `def` | flat HP / ATK / DEF | 298.75, 19.45, 23.15 |
| `hp_`, `atk_`, `def_` | HP% / ATK% / DEF% | 5.83, 5.83, 7.29 |
| `critRate_`, `critDMG_` | CRIT Rate / CRIT DMG | 3.89, 7.77 |
| `eleMas` | Elemental Mastery | 23.31 |
| `enerRech_` | Energy Recharge | 6.48 |
| `heal_` | Healing Bonus | main stat only |
| `{element}_dmg_` | `pyro`, `hydro`, `anemo`, `electro`, `dendro`, `cryo`, `geo`, `physical` DMG Bonus | main stat only |
| `elemental_dmg_` | placeholder for "Elemental DMG" in builds; resolved per character with `Stats::resolveElemental()` | – |

- `Stats::substatKeys($game)`: the ten keys with a max roll, the ones that can roll as substats.
- `Stats::keys($game)`: every key above (all `Stats::parse()` can give) - every stat key a build may hold. A
  builds file's stat targets may name no other, its substats only the substat keys, and a slot's main stats only
  what the slot can roll ([builds.md](builds.md)).

Labels: `Stats::label($key, $game, $short)` ("Energy Recharge" / "ER"); the frontend gets them from `/api/meta`
(`MetaStore`).

## Slots (`Enums/Game`)

| Slot | Main stat options (`Game::mainStatOptions()`) |
| --- | --- |
| flower | `hp` |
| plume | `atk` |
| sands | `hp_`, `atk_`, `def_`, `eleMas`, `enerRech_` |
| goblet | `hp_`, `atk_`, `def_`, `eleMas`, `physical_dmg_` and the seven elements' `{element}_dmg_` |
| circlet | `hp_`, `atk_`, `def_`, `eleMas`, `critRate_`, `critDMG_`, `heal_` |

Sands, goblet and circlet are the variable main-stat slots (`Game::variableSlots()`): the ones builds give main
stats for. Every slot holds an artifact (`Game::setKindOf()`: the one kind of set, `artifact`).

Max artifact level (`Game::maxItemLevel`): 5★ +20, 4★ +16, 3★ +12. A file export (GOOD) carries no main stat
value; `MainStatValues::compute()` fills it, interpolated between +0 and the max level (4★ and 5★ pieces).

## Talents

`Game::talentKeys()`: `normal`, `skill`, `burst` (`Talents::label()`: Normal Attack, Elemental Skill, Elemental
Burst). `Talents::keys($game)` is the same list: all a builds file's talent priority may name. An account
character's talent levels: [data-model.md](data-model.md).

## The game's other facts (`Enums/Game`)

| Method | Genshin Impact |
| --- | --- |
| `label()` | "Genshin Impact" |
| `rankLabel()`, `rankPrefix()`, `maxRank()` | Constellation, "C", 6 (a build's `min_rank` and a target's `from_rank` go up to it) |
| `roles()` | `main_dps`, `sub_dps`, `support` (a build's role, and what a member plays in a team - or none) |
| `teamSize()` | 4: the most members a team of a build has (two at least) |
| `refinementPrefix()`, `maxWeaponLevel()` | "R", 90 |
| `characterLevelTarget()` | 80 (below it the analysis says to level the character up) |
| `elements()`, `weaponTypes()` | the seven elements; sword, claymore, polearm, bow, catalyst |
| `weaponLabel()`, `itemLabel()`, `slotLabel()` | "Weapon", "Artifact", "Flower of Life" … "Circlet of Logos" |
| `setKinds()`, `setLabel()`, `setPieceCounts()`, `setWeight()` | `artifact`, "Artifact set", 2 and 4 pieces, 1.0 |
| `flatStatFactor()` | 0.35 (a flat roll's weight against its percent stat's) |

## Parsing human labels

Nothing in the site reads a stat's or a talent's label any more: a builds file carries keys, a GOOD file its own
canonical keys, the showcase its property IDs. The parsers stay for the owner's local tools, which read the build
sites' labels with them ([builds.md](builds.md)); a build site's own typos are the tools' to map, not these lists'.

- `Stats::parse($game, 'ATK%')` → `['atk_']`. Bare "ATK"/"HP"/"DEF" mean percentages ("Flat ATK" → `atk`);
  "Crit", "CR/CD", "CRIT Rate/DMG" → both crit stats; "Pyro"/"Pyro DMG Bonus" → `pyro_dmg_`; "Elemental DMG
  Bonus" → `elemental_dmg_`. Unknown labels return `[]`.
- `Stats::parsePriorityList($game, 'CRIT Rate = CRIT DMG > ATK% > ER / EM')`: `>` (`≥`, `»`, `;`, newline) starts a
  lower priority, `=`, `/`, `,`, `&`, "or", "and" keep it; a note in brackets ("ER (until 160%)") is stripped.
- `Stats::ordered($game, [...labels])` for plain ordered lists: adjacent CRIT Rate / CRIT DMG share a priority.
- `Talents::parse/parsePriority/ordered`: `normal` ("NA", "Auto"), `skill` ("E"), `burst` ("Q").
- A numeric goal written in prose ("125-145% Energy Recharge") is the local tools' to read; it reaches the site as
  a stat target `{stat, min, max, text, from_rank}`.
- A set's 2-piece bonus, as the catalog keeps its words (`meta.bonuses[0]`), is read by `SetBonus::parse()` for the
  stat targets: "ATK +18%.", "ATK increased by 18%.", "Increases Elemental Mastery by 80.", "Cryo DMG Bonus +15%",
  "Energy Recharge + 20%.", "Healing Bonus +15%." ("Character Healing Effectiveness" too), "Max HP increased by
  1000." (flat). A bonus that is no sheet stat - a skill's DMG, a resistance, shield strength - is none.
  `SetBonus::parseCritRate()` reads a 4-piece's CRIT Rate in combat from its words for the crit pair's balance
  ([analysis.md](analysis.md)): every "CRIT Rate ... N%" added up (Blizzard Strayer's 20 and 20 more), the first
  number where two are offered ("15%/30%"), times the stacks where the text says "Max 3 stacks" (Marechaussee
  Hunter's 36); a 4-piece worded another way counts as nought.

## A level (`LevelStats`)

The stat targets are compared on the character sheet at level 90, and the totals worked out from the gear
([analysis.md](analysis.md), "The totals from the gear") take the character and the weapon at the level they stand
at. The catalog keeps what genshin.anotoki.eu's ascension phases hold ([data-model.md](data-model.md), `meta.stats`:
level 1, level 80 ascended, and every phase there is with its two ends), and the game's growth curves enter as the few
constants the top level needs:

| Constant | Value | What it says |
| --- | --- | --- |
| `CHARACTER_STEP` | 5★ 0.9028, 4★ 0.826 | a character's base HP, ATK and DEF at 90 = the level 80 value + the level 1 value × this (one curve per rarity for all three; the ascension bonus is in the level 80 value and unchanged after it) |
| `WEAPON_ATK_90` | by rarity and level 1 base ATK: 5★ 44/46/48/49 → 542/608/674/741, 4★ 39/41/42/44/45 → 440/454/510/565/620, 3★ 38/39/40 → 354/401/448 | a weapon's base ATK at 90 (a dozen curves, told apart by the pair); a pair the table lacks keeps its level 80 value |
| `WEAPON_SECONDARY_90` | 4.59375 | a weapon's secondary stat at 90 in level 1 values, one curve whatever the weapon (9.6% CRIT DMG at level 1 is 44.1% at 90) |
| `PHASE_LEVELS` | 0: 1-20, 1: 20-40, 2: 40-50, 3: 50-60, 4: 60-70, 5: 70-80, 6: 80-90 | the levels of each ascension phase, its first (ascended) and its last |

A level inside a phase (`characterAt()`, `weaponAt()`) is read off the phase's own two ends as a straight line - the
curves are near enough to one within a phase: a character's from the phase's start to its end as the catalog keeps
them (`meta.stats.phases`), phase 6's end being level 90 as above, the ascension stat at the phase's value; a weapon's
from the phase's start to the next phase's start (the ascension's step a little early: the catalog keeps no end of a
weapon's phase), the last to level 90, the secondary stat along the same line. A phase the catalog has not, a catalog
refreshed before the phases came, or an ascension past the game's leaves the level 90 values standing in; an import
that names no ascension stores 0, and an ascension below the fewest the level needs is read as that fewest
(`LevelStats::leastAscension()`: a level 90 is never at phase 0).

Checked against the game's own level 90 values for twenty characters and the common weapons (within a point:
`tests/stat_targets.test.php`). A character of another rarity, or one the catalog has no stats of, has no level 90,
no tiered targets and no totals from the gear.
