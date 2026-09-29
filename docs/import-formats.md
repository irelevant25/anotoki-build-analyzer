# Import formats

Entry point: `src/Services/Import/ImportService` (`fromEnka($game, $uid, $owner, $target)`, `fromFile($contents,
$owner, $game, $target, $fileName)` with format detection). Parsers produce a `ParsedAccount` whose characters /
weapons / items reference the catalog by `['external_id' => …]`, `['name' => …]` or `['key' => …]`;
`AccountImporter` resolves them (lookups only: whatever the shared catalog lacks is skipped and reported) and
writes them into an account of the importing user ([data-model.md](data-model.md)). The showcase import is
throttled to `showcase.per_minute` (10, `config/app.php`) a minute per user. User-facing steps for each method
live in the wizard: `frontend/src/app/pages/import/import-guides.ts`.

This page names the services, tools and formats: it is internal. The UI calls the two methods "Character
Showcase (UID)" and "Full inventory (exporter tool)", describes the tools without naming or linking them, and
the API reports an import's source as `showcase` or `file` (see [architecture.md](architecture.md), "Neutral
names").

## Enka.Network (showcase by UID) — `Import/Enka/*`

- `GET https://enka.network/api/uid/{uid}` (Genshin), `/api/hsr/uid/{uid}` (HSR); the base is
  `showcase.api` in `config/app.php`. **No trailing slash** (308). Errors mapped in `EnkaClient` (in neutral
  words): 400 bad UID, 404 no player, 424 maintenance, 429 rate limited, 5xx. Responses are cached (cache table)
  for the returned `ttl`. UIDs are 9–10 digits.
- Showcase limits: Genshin up to 12 characters ("Show Character Details" must be on); HSR showcase + support
  characters (~8).

Genshin (`EnkaGenshinParser`):

| Field | Meaning |
| --- | --- |
| `playerInfo.nickname/level/worldLevel/signature` | account header; no `avatarInfoList` = showcase empty or details hidden |
| `avatarId`, `skillDepotId` | Travelers 10000005/10000007: element from `skillDepotId % 100` (2 pyro, 3 hydro, 4 anemo, 5 cryo, 6 geo, 7 electro, 8 dendro) |
| `propMap["4001"].val`, `["1002"].val` | level, ascension |
| `talentIdList` length | constellation |
| `skillLevelMap` + store `gi/avatars.json` `SkillOrder` | base talent levels [normal, skill, burst]; `proudSkillExtraLevelMap` + `ProudMap` → `bonus` (+3) |
| `fightPropMap` | totals: 2000 HP, 2001 ATK, 2002 DEF, 20 CR, 22 CD, 23 ER, 26 heal, 28 EM, 30 phys, 40–46 pyro/electro/hydro/dendro/anemo/geo/cryo (ratios ×100) |
| `equipList[].weapon` | `itemId` (= catalog ID), `level`, `promoteLevel`, `affixMap` value + 1 = refinement |
| `equipList[].reliquary` | `level` − 1 = +level, `appendPropIdList`: each ID is one roll, stat = `floor(id/10) % 1000` (102 HP, 103 HP%, 105 ATK, 106 ATK%, 108 DEF, 109 DEF%, 120 CR, 122 CD, 123 ER, 124 EM) |
| `flat.setId`, `flat.equipType` | set (= catalog ID); EQUIP_BRACER flower, NECKLACE plume, SHOES sands, RING goblet, DRESS circlet |
| `flat.reliquaryMainstat` / `reliquarySubstats` | `FIGHT_PROP_*` + `statValue` (not `propValue` as the docs say) |

Store files: only `store/gi/avatars.json` of Enka's API docs repository (`showcase.genshin_avatars` in
`config/app.php`, cached 24 h by `EnkaStore`); the legacy `store/characters.json` + `loc.json` are frozen at game
version 6.2.

HSR (`EnkaHsrParser`): `detailInfo{uid, nickname, level, worldLevel, avatarDetailList[]}`; support characters
carry `_assist: true` and may duplicate showcase entries (deduplicated). Per avatar: `level`, `promotion`,
`rank` (eidolon, absent at 0), `skillTreeList[{pointId, level}]` (last 3 digits 001 basic, 002 skill,
003 ultimate, 004 talent), `equipment{tid, level, promotion, rank}`, `relicList[{tid, type 1..6
(head, hands, body, feet, sphere, rope), level, subAffixList[{affixId, cnt, step}], _flat{setID, props[]}}]`.
`_flat.props[0]` is the main stat (types keep a `Base` suffix, e.g. `CriticalChanceBase`), the rest are
substats; ratio values ×100. Sub affix IDs: 1 HP, 2 ATK, 3 DEF, 4 HP%, 5 ATK%, 6 DEF%, 7 SPD, 8 CR, 9 CD,
10 EHR, 11 RES, 12 BE; `cnt` = rolls. Relic rarity = first digit of `tid` − 1 (6xxxx = 5★). No totals.

## GOOD (Genshin, full inventory) — `Files/GoodParser`

Written by Irminsul (packet capture), Inventory Kamera, GOODScanner and Genshin Optimizer.
`{format: "GOOD", version: 1–3, source, characters[], weapons[], artifacts[], materials?}`.

- Characters: `key` PascalCase ("KaedeharaKazuha", "TravelerAnemo"; plain "Traveler" = old file, skipped),
  `level` (up to 100 in v3), `ascension`, `constellation`, `talent{auto, skill, burst}` (base levels).
- Weapons: `key`, `level`, `ascension`, `refinement`, `location` (character key or ""), `lock`.
- Artifacts: `setKey`, `slotKey`, `level`, `rarity`, `mainStatKey`, `substats[{key, value}]` (keys already
  canonical; empty `key` = no substat), `location` ("Traveler" = the file's Traveler), `lock`. No main values.

## Reliquary Archiver / HSR-Scanner (HSR, full inventory, "version 4") — `Files/HsrScannerParser`

The format the Fribbels HSR Optimizer reads. `{source: "reliquary_archiver"|"HSR-Scanner", build, version: 4,
metadata{uid, trailblazer}, light_cones[], relics[], characters[]}`.

- Relics: `set_id` (= catalog ID), `name` (set name), `slot` ("Head", "Hands", "Body", "Feet", "Planar Sphere",
  "Link Rope"), `rarity`, `level`, `mainstat` (no value; "HP"/"ATK" are flat only on Head/Hands, "DEF" main is
  always %), `substats[{key, value, count?, step?}]` (keys "HP", "ATK", "DEF", "SPD", "HP_", "ATK_", "DEF_",
  "CRIT Rate_", "CRIT DMG_", "Effect Hit Rate_", "Effect RES_", "Break Effect_"), `location` (character ID or ""),
  `lock`, `_uid` (stable in Reliquary Archiver, scan order in HSR-Scanner).
- Light cones: `id`, `level`, `ascension`, `superimposition`, `location`. Characters: `id` (Trailblazer 8001–8010,
  odd Caelus / even Stelle), `level`, `ascension`, `eidolon`, `skills{basic, skill, ult, talent, elation?}`,
  `memosprite{skill, talent}?`. HSR-Scanner writes `path: "The Hunt"`, integer SPD and no counts.

Detection (`ImportService::fromFile`): `format === "GOOD"` → GOOD; `relics` array + known `source` or
(`light_cones` + `version ≥ 3`) → HSR scanner; anything else → readable error. A UTF-8 BOM is tolerated.
Error messages name the format ("a GOOD file"), never a tool.

## The tools players use

The wizard describes these without their names ("an inventory exporter", "a relic exporter"); this table is for
whoever maintains the parsers.

| Tool | Game | How | Output |
| --- | --- | --- | --- |
| Irminsul | Genshin | run as admin (pktmon) before entering the world, export | `genshin_export_*.json` (GOOD) or clipboard |
| Inventory Kamera (taiwenlee fork) | Genshin | OCR, English client | `genshinData_GOOD_*.json` |
| Reliquary Archiver | HSR | pktmon build (admin) or pcap build (Npcap); start at "Click to Start", then enter | `archive_output-*.json`; also live websocket `ws://127.0.0.1:23313/ws` |
| HSR-Scanner (kel-z) | HSR | OCR, English, 16:9, ~5–10 min | `HSRScanData_*.json` |

## The game's official web account (not implemented — research notes)

The publisher's community site has a "battle chronicle" API that would return every owned character with
equipped gear (no unequipped inventory) without a tool, but it needs the player's login session cookies (store
encrypted, keep briefly), a public battle chronicle with "Character Details" on, and a request signature whose
salt changes over time. The open-source `genshin.py` library (seriaati) documents the routes, the signature
algorithm, the error codes and the server from the UID prefix. Captchas are likely from server IPs, and using
session cookies goes against the publisher's own security advice.
