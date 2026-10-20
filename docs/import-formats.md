# Import formats

Entry point: `src/Services/Import/ImportService` (`fromEnka($game, $uid, $owner, $target)`, `fromFile($contents,
$owner, $target, $fileName)` with format detection). Parsers produce a `ParsedAccount` whose characters /
weapons / items reference the catalog by `['external_id' => …]`, `['name' => …]` or `['key' => …]`;
`AccountImporter` resolves them (lookups only: whatever the shared catalog lacks is skipped and reported) and
writes them into an account of the importing user ([data-model.md](data-model.md)). The showcase import is
throttled to `showcase.per_minute` (10, `config/app.php`) a minute per user. User-facing steps for each method
live in the wizard: `frontend/src/app/pages/import/import-guides.ts` (keys, written out whole; their English in
`frontend/src/app/core/i18n/en/import.json`, every language in the database).

Since 0.5.0 an account is a Genshin Impact account, imported in two ways: the Character Showcase by UID and a GOOD
file. Honkai: Star Rail went with its showcase route, its scanner format and their kinds; the wizard has no Game
step. Builds are not imported here: an editor imports a builds file (`build-analyzer.builds`) on the admin panel's
Builds page ([builds.md](builds.md)).

This page names the services, tools and formats: it is internal. The UI calls the two methods "Character
Showcase (UID)" and "Full inventory (exporter tool)", describes the tools without naming or linking them, and
the API reports an import's source as `showcase` or `file` (see [architecture.md](architecture.md), "Neutral
names").

## Enka.Network (showcase by UID) — `Import/Enka/*`

- `GET https://enka.network/api/uid/{uid}`; the base is `showcase.api` in `config/app.php`. **No trailing
  slash** (308). Errors mapped in `EnkaClient` (in neutral words): 400 bad UID, 404 no player, 424 maintenance,
  429 rate limited, 500/503 down, any other status `showcase_http`. Responses are cached (cache table) for the
  returned `ttl` (at least a minute). UIDs are 9–10 digits.
- Showcase limit: up to 12 characters ("Show Character Details" must be on).

`EnkaGenshinParser`:

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

The in-game IDs it sends (`avatarId`, a weapon's `itemId`, an artifact's `setId`) are the catalog's
`external_id`s, from the game data provider ([name-matching.md](name-matching.md)); the Travelers go by their key
(`traveler{element}`).

## GOOD (full inventory) — `Files/GoodParser`

Written by Irminsul (packet capture), Inventory Kamera, GOODScanner and Genshin Optimizer.
`{format: "GOOD", version: 1–3, source, characters[], weapons[], artifacts[], materials?}`.

- Characters: `key` PascalCase ("KaedeharaKazuha", "TravelerAnemo"; plain "Traveler" = old file, skipped),
  `level` (up to 100 in v3), `ascension`, `constellation`, `talent{auto, skill, burst}` (base levels).
- Weapons: `key`, `level`, `ascension`, `refinement`, `location` (character key or ""), `lock`.
- Artifacts: `setKey`, `slotKey`, `level`, `rarity`, `mainStatKey`, `substats[{key, value}]` (keys already
  canonical; empty `key` = no substat), `location` ("Traveler" = the file's Traveler), `lock`. No main values:
  `MainStatValues::compute()` fills them ([stats-and-slots.md](stats-and-slots.md)). An artifact without a set,
  slot or main stat is skipped (`good_artifacts_skipped`, with the count); a substat with no key, no positive
  value or a key that cannot roll as a substat is left out.
- Characters, weapons and sets are looked up by name ([name-matching.md](name-matching.md), "Resolution"): a GOOD
  key is the English name in PascalCase without spaces or punctuation, so `Key::from()` of it is the catalog's key
  of the same name.

Detection (`ImportService::fromFile`): not JSON → `file_not_json`; `format === "GOOD"` → GOOD; anything else →
`file_unknown_format` ("Upload a Genshin Impact inventory export in the GOOD format."). A UTF-8 BOM is tolerated.
Error messages name the format ("a GOOD file"), never a tool.

## Errors and warnings are kinds

What an import says reaches the page as a **kind with params**, which the page words in the reader's
language; the English sentence beside it is kept for the command line, the tests and rows stored before kinds.
`src/Services/Import/ImportKinds.php` lists them: `ERRORS` (kind → the names of its params; `unexpected` for a
failure that is not an `ImportException`; 18 kinds) and `WARNINGS` (7).

- An error: `throw new ImportException($message, $kind, $params)`. The API answers 422 `{message, code,
  params}`; a failed run keeps `{"error": {code, params}}` as its summary and is sent with `error_code` /
  `error_params` (null for a run stored before kinds) and no summary ([data-model.md](data-model.md)).
- A warning: `ParsedAccount::warn($kind, $params, $message)` in a parser; the importer's own (a character,
  weapon or set the catalog lacks, a catalog behind the game) go through `ImportKinds::warning()`. A run that
  went through keeps `summary.warnings` (the English) and `summary.warning_kinds` (`[{code, params}]`, index
  for index); the API sends the kinds only when they are as many as the sentences.
- Params are flat strings and numbers: `weapon_label` is `Game::weaponLabel()`, `ref` is a catalog
  reference's name, else its key, else its in-game ID (`ImportKinds::ref()`), `count` and `status` numbers, a
  UID as the data or the account has it. What comes from an uploaded file goes through `Neutral` on the way out,
  like the messages.
- The page: `frontend/src/app/core/i18n/import-words.ts` maps each code to its key (`import.error.<code>`,
  `import.warning.<code>`) and the params its sentence needs. A code it does not know, a stored row without
  one, or one missing a param is shown as the stored English with `lang="en"`; a request that fails now never
  is (it falls back to the general error words).
- A new kind is, together: its row in `ImportKinds`, its throw or `warn()` site, its tail in
  `frontend/src/app/core/i18n/dynamic-keys.ts`, its row in `import-words.ts`, its English in
  `core/i18n/en/core.json`, and a new string migration with the key, its description, the English and
  informal Slovak (game terms first, then a colon). `tests/import.test.php` reads every throw and `warn()`
  site in `src/` against `ImportKinds`; `tests/translation_catalogue.test.php` holds `ImportKinds`, the
  declared tails, the compiled English and the database to each other.

## The tools players use

The wizard describes these without their names ("an inventory exporter"); this table is for whoever maintains
the parser.

| Tool | How | Output |
| --- | --- | --- |
| Irminsul | run as admin (pktmon) before entering the world, export | `genshin_export_*.json` (GOOD) or clipboard |
| Inventory Kamera (taiwenlee fork) | OCR, English client | `genshinData_GOOD_*.json` |

## The game's official web account (not implemented — research notes)

The publisher's community site has a "battle chronicle" API that would return every owned character with
equipped gear (no unequipped inventory) without a tool, but it needs the player's login session cookies (store
encrypted, keep briefly), a public battle chronicle with "Character Details" on, and a request signature whose
salt changes over time. The open-source `genshin.py` library (seriaati) documents the routes, the signature
algorithm, the error codes and the server from the UID prefix. Captchas are likely from server IPs, and using
session cookies goes against the publisher's own security advice.
