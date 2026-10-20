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
| `roles()` | `main_dps`, `sub_dps`, `support` (a build's role) |
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
