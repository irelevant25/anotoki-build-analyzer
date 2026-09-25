# Stats, slots and talents

Everything uses canonical stat keys (`backend/app/Support/Stats.php`). Genshin keys follow the GOOD format;
HSR keys use the same style. A trailing underscore means "percent".

## Stat keys

| Key | Genshin | HSR | 5★ max substat roll (GI / HSR) |
| --- | --- | --- | --- |
| `hp`, `atk`, `def` | flat HP / ATK / DEF | flat | 298.75, 19.45, 23.15 / 42.34, 21.17, 21.17 |
| `hp_`, `atk_`, `def_` | HP% / ATK% / DEF% | same | 5.83, 5.83, 7.29 / 4.32, 4.32, 5.4 |
| `critRate_`, `critDMG_` | CRIT | CRIT | 3.89, 7.77 / 3.24, 6.48 |
| `eleMas` | Elemental Mastery | – | 23.31 |
| `enerRech_` | Energy Recharge | – | 6.48 |
| `heal_` | Healing Bonus | Outgoing Healing Boost | main stat only |
| `spd` (`spd_` from set bonuses) | – | SPD | 2.6 |
| `effectHitRate_`, `effectRes_` | – | EHR, Effect RES | 4.32, 4.32 |
| `breakEffect_` | – | Break Effect | 6.48 |
| `energyRegen_` | – | Energy Regeneration Rate | main stat only |
| `{element}_dmg_` | `pyro`, `hydro`, `anemo`, `electro`, `dendro`, `cryo`, `geo`, `physical` | `physical`, `fire`, `ice`, `lightning`, `wind`, `quantum`, `imaginary` | main stat only |
| `elemental_dmg_` | placeholder for "Elemental DMG" in builds; resolved per character with `Stats::resolveElemental()` | same | – |

Labels: `Stats::label($key, $game, $short)`; the frontend gets them from `/api/meta` (`MetaStore`).

## Slots (`Enums/Game`)

| Game | Slots (in order) | Variable main-stat slots | Main stat options |
| --- | --- | --- | --- |
| Genshin | flower, plume, sands, goblet, circlet | sands, goblet, circlet | `Game::mainStatOptions()` |
| HSR | head, hands, body, feet, sphere, rope | body, feet, sphere, rope | sphere/rope are Planar Ornaments (`isPlanarSlot`) |

Max item level: Genshin 5★ +20 / 4★ +16; HSR 5★ +15 / 4★ +12 (`Game::maxItemLevel`). File exports carry no
main stat value; `MainStatValues::compute()` fills it (Genshin interpolated, HSR `base + step × level`, 5★ only).

## Parsing human labels

- `Stats::parse($game, 'ATK%')` → `['atk_']`. Bare "ATK"/"HP"/"DEF" mean percentages ("Flat ATK" → `atk`);
  "Crit", "CR/CD", "CRIT Rate/DMG" → both crit stats; "Pyro"/"Pyro DMG Bonus" → `pyro_dmg_`; "Thunder" → lightning;
  known site typos ("Energy Rechage") are mapped. Unknown labels return `[]`.
- `Stats::parsePriorityList($game, 'CRIT Rate = CRIT DMG > ATK% > ER / EM')`: `>` (`≥`, `»`, `;`, newline) starts a
  lower priority, `=`, `/`, `,`, "or" keep it; notes in brackets ("EHR (Until 75%)") are stripped.
- `Stats::ordered($game, [...labels])` for plain ordered lists: adjacent CRIT Rate / CRIT DMG share a priority.
- `Talents::parse/parsePriority/ordered`: Genshin `normal`, `skill`, `burst`; HSR `basic`, `skill`, `ultimate`,
  `talent`, `memo_skill`, `memo_talent`.
- Numeric goals in prose: `Builds/Parsing/StatTargets::extract()` accepts only ranges ("125-145% Energy
  Recharge"), trailing "+" ("2000+ ATK", "SPD 160+") or colon forms ("EHR: 75%"), so effect text like
  "grants 20% ATK" is ignored. ATK/HP/DEF goals without `%` become flat stats.
