# Build sources (scrapers)

Scrapers implement `App\Services\Builds\BuildSource` and are listed in `config/hoyo.php` → `sources`.
`BuildSync` calls `listCharacters()` (→ `CharacterRef[]`, unreleased ones skipped) and `fetchBuilds($ref)`
(→ `ScrapedBuild[]` with canonical stat keys but display names for weapons/sets), then normalizes and stores
them ([data-model.md](data-model.md)).

## Sync mechanics

- `builds:sync [source] [--game=] [--character=slug|name]... [--limit=N] [--force] [--check-names] [--dry-run]`.
- `--dry-run` parses like a sync but only reports, per build, `unchanged` / `changed` / `new` against the
  database (plus builds that would be removed and unknown names). It never writes and never refreshes the
  catalog; with `HOYO_HTTP_CACHE_HOURS=96` it replays cached pages offline (all sources in ~5 s). It shares
  `BuildSync::buildData()` / `contentHash()` with real syncs, so "unchanged" means byte-identical content.
- A source synced successfully within `HOYO_SYNC_STALE_HOURS` (20) is skipped unless `--force`.
- A cache lock per source (`builds-sync:{key}`) prevents parallel runs (scheduler + UI button).
- Per-character exceptions are logged and counted; the run ends `success` / `partial` / `failed`.
- `Fetcher`: waits `delayMs()` between requests to the same host, retries 429/5xx (honors `Retry-After`),
  caches bodies for `HOYO_HTTP_CACHE_HOURS` (12) in `backend/storage/app/private/http-cache/<sha1(url)>.cache`
  (pruned after 3 days). Re-running a sync after a parser fix therefore costs no requests.
- `--check-names` lists each site's characters and prints names the catalog can't match; run it after
  adding a source or when a new patch drops.
- Reading a cached page by hand: the cache key is `sha1` of the **exact** URL (with host, e.g.
  `https://www.prydwen.gg/…`): `Storage::disk("local")->get("http-cache/".sha1($url).".cache")`, then
  `NextFlight::findProps()` / `AstroIslands::find()` on it (full recipe in the `debug-build-sync` skill).

Page URLs used by the scrapers:

| Source | List | Detail |
| --- | --- | --- |
| genshin-builds.genshin | `https://genshin-builds.com/en/characters` | `https://genshin-builds.com/en/character/{slug}` |
| prydwen.genshin | `https://www.prydwen.gg/genshin-impact/characters` | `https://www.prydwen.gg/genshin-impact/characters/{slug}` |
| prydwen.hsr | `https://www.prydwen.gg/star-rail/characters` | `https://www.prydwen.gg/star-rail/characters/{slug}` |
| genshin-gg.* | `https://genshin.gg/asset-manifest.json` → `https://genshin.gg/static/js/main.<hash>.chunk.js` | (bundle holds all characters) |

## genshin-builds.com — Genshin (`GenshinBuildsGenshin`, key `genshin-builds.genshin`)

- Astro site; robots.txt allows bots. Delay 1.5 s, browser UA.
- List: `/en/characters`, island `CharactersList` → `props.characters[]` (`id` slug, `name`, `element.id`,
  `upcoming`, `status: "speculated"` for unreleased). `traveler_pyro` is named "Traveler (undefined)" → name
  rebuilt from the slug.
- Detail: `/en/character/{slug}`, island `CharacterBuildTabs` → `props.builds[]`, `props.weapons` (id → name),
  `props.artifacts` (id → name, `children` for pseudo sets).
- Island props are HTML-escaped JSON in Astro's `[type, value]` format (0 = value, 1 = array);
  `Parsing/AstroIslands::find($html, 'Component')` revives them.
- Build fields: `name`, `role`, `recommended`, `weapons[{id, r}]` (r = refinement, 0 = unspecified),
  `sets[][]` (1 id = 4pc, 2 ids = 2+2, 3+ ids = choose 2), `stats{sands,goblet,circlet}` alternatives,
  `stats_priority[]` (ordered, crit pairs tied), `talent_priority[]`, `build_notes` / `description` (HTML;
  goals via `StatTargets`), `weapon_notes`, `artifact_notes`. Page title carries "(v7.0)".
- Pseudo sets: ids with `children` (e.g. `80elementalmastery_set`) list member sets; ids without an entry
  (`15_cryo_dmg_set`, `30_def_set`, `and_15_anemo_dmg_set`) are resolved through `SetBonusGroups`
  (catalog 2pc texts) by `pseudoSet()`.
- genshin-builds' Star Rail pages mirror game8 and are **not** used. The site also has a JSON API
  (`/api/genshin/character?id=…&lang=english`, first build only) and an MCP server; the HTML islands are richer.

## Prydwen — Genshin (`PrydwenGenshin`, `prydwen.genshin`) and HSR (`PrydwenHsr`, `prydwen.hsr`)

- Next.js App Router. robots.txt: `Crawl-delay: 10`, `Disallow: /api/` → delay 10 s; a full run is ~20 min
  per game. Cloudflare challenges non-browser user agents → `browser: true`.
- Data is in the RSC payload (`self.__next_f.push([1,"…"])` chunks). `Parsing/NextFlight::findProps($html, 'key')`
  concatenates chunks, splits rows (text rows `id:T<hexlen>,<text>` have **no** trailing newline and are read
  by byte length), JSON-decodes rows containing the key and returns the first object holding it.
- List: `/star-rail/characters` or `/genshin-impact/characters`, props `characters[]` (`slug`, `name`,
  `isReleased`/`upcoming` for HSR, `isUpcoming` for Genshin, `element`).
- HSR detail `/star-rail/characters/{slug}`, props with `statBuilds`:
  `statBuilds[]{name, body, feet, sphere, rope, substats, comments}` (priority strings with `=`, `>`, `>=`;
  one variant per stat build), `relicBuilds[]{relic_set_1, relic_set_2 ("" = 4pc, "Flex" = 2pc + any of
  `flex_options`, otherwise 2+2), comment, ornament_options[]}`, `coneBuilds[]{cone_name, dupes, comment}`,
  `skillOrder.Order` ("Ultimate = Skill > Talent > Basic"), `skillOrder.TracePriority`,
  `character.endgameStats` (HTML list → targets), `character.updatedAt`, `character.showBuild` (false for
  upcoming), `lastUpdate.build` (version). "(Until 75%)" in substats also becomes a target.
- Genshin detail `/genshin-impact/characters/{slug}`, props with `profile_type`:
  - `full-build`: `build.weaponsConfig.groups` (variants/team contexts), `weaponsData[{ref|custom, dupes, cells[group]{team|solo, notes}}]`
    (cell = rank "1" or relative damage "124.72%"; empty = not rated), `artifactsData[{ref|custom}]`
    (`custom` like "2PC EM + 2PC EM" / "2PC ATK% Combinations" → `SetBonusGroups`), `artifactStats[{slot, stat}]`,
    `substatsTalents{stats, talents}`, `energyConfig/energyData` (ER table → lowest recommended value as target).
    Separate variants only when weapon and artifact group counts match; otherwise one build ranked by group 0.
  - `analytics-only`: `usage{weapons, artifactSets, {sands,goblet,circlet}MainStats, totalCount, syncedAt}`
    → a "Most used" build (main stats used by ≥ 15 %, no substats: the analyzer borrows them from another source).
- `$undefined` / `$…` strings are RSC placeholders; treat as null.

## genshin.gg — Genshin (`GenshinGgGenshin`) and HSR (`GenshinGgHsr`), base `GenshinGgSource`

- Static React app; no bot protection. `/asset-manifest.json` → `files["main.js"]` (~2.3 MB) holds every
  character as `JSON.parse('…')` literals; `decodeBundle()` un-escapes them. The bundle is fetched once per
  process and shared by both games; lists are recognized by keys (`build`+`type`+`weapon` vs
  `light_cone`+`relic`+`path`).
- One build per character. Genshin `build{name, stats[sands,goblet,circlet], substats[], weapons[] ("The Catch R5"),
  artifacts[] ("A + B" = 2+2)}`. HSR `relic[]`, `ornament[]`, `light_cone[]`, `main_stats[body,feet,sphere,rope]`
  ("Any" = no preference), `sub_stats[]` ("Speed (186+)" → target).
- Entries can hold alternatives on separate lines → `lines()` splits them.
- Names: element-named Trailblazers ("Trailblazer (Imaginary)") and several typos are handled by aliases.
- Character URLs are only for linking (`/characters/{slug}/`, `/star-rail/characters/{slug}/`).

## Sources evaluated and rejected (2026-09)

- **game8**: builds are hand-written HTML tables whose row labels differ between page templates; AWS WAF
  blocks non-browser clients; the list page lagged a patch behind.
- **KeqingMains** (Genshin): WordPress with the REST API disabled; free-text guides, many versions old.
- **hsr.keqingmains.com**: REST API open but guides are prose and stale (newest 3.7).
- **genshin-builds.com HSR**: a copy of game8's data.

## Politeness rules

Keep `delayMs()` ≥ what robots.txt asks, identify with the configured UA unless a site blocks it, never
fetch `/api/` paths a robots.txt disallows, and prefer one list request + one request per character. Test
parsers against cached or saved pages, not live sites.
