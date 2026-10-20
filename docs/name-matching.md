# Names, keys and the catalog

Every file and service names things its own way ("Kazuha", "KaedeharaKazuha", "kaedehara-kazuha", avatarId
10000047). All of them meet on a **canonical key**: `Support/Key::from($name)` = lowercase ASCII letters and digits
only ("Wolf's Gravestone" → `wolfsgravestone`, `"The Catch"` → `thecatch`). It transliterates exactly as the
Laravel version's `Str::ascii()` did (`voku/portable-ascii`), so keys stored by that version still match.

## The catalog (`src/Services/Catalog/`)

Genshin Impact's catalog comes from the owner's genshin.anotoki.eu since 0.5.0 (the owner's decision of
2026-10-20; the old third-party game data provider is no longer asked): its public lists `GET {api}/characters`,
`/weapons` and `/artifacts` - bare JSON lists of its live rows, each with an ETag -, where `{api}` is
`catalog.genshin.api` in `config/app.php` (`https://genshin.anotoki.eu/api/catalog`). `Providers\GenshinCatalog`
reads them (`catalog.genshin.provider`; `CatalogProviders` makes each game's `CatalogProvider`, which answers one
list as a `CatalogAnswer`). The admin panel and the API call it "the game data provider", never by its name.

A list is refreshed by `CatalogSync::refresh($game, $list, $force)`:

- A conditional request with the ETag the list was last read with (`If-None-Match`), unless forced. 200: changed;
  304: unchanged, nothing written; 401, 423 and 503: closed (that site's own switches - sign-in required, a route
  locked, maintenance or an update); 404: unavailable; anything else, no connection or an answer it cannot read:
  failed. An answer over 5 MB or of more than 5000 entries is refused as a whole.
- A changed list is written in one transaction: each row found again by its in-game ID (`external_id`), then by
  its key, so a renamed entry keeps its row and everything linked to it (unless another row holds the new key
  already); a key twice in one answer is written once; nothing is ever deleted.
- Where each list stands - its ETag, when the provider last confirmed it, when it last changed, the last failure
  and why - is kept in the cache table (`catalog-state:{game}:{list}`). One refresh of a list at a time: a claim of
  two minutes, and a second one meanwhile is told it is busy.
- When the provider is closed or failing, the catalog stays as it was and the Builds page shows each list's last
  problem.

What refreshes a list:

- The admin panel's Builds page: **Refresh catalog** asks for the three lists one after another, one request each
  (`POST /api/admin/catalog/{game}/{list}`, `?force=1` asks in full; audited `catalog.refreshed`; 409 busy, 502
  with the reason - [builds.md](builds.md), "The routes").
- The upkeep after an answer (`CatalogUpkeep`, `upkeep.catalog` in `config/app.php`, off in the tests): at most
  every five minutes an answer looks for a list not confirmed within `catalog.refresh_days` (3), and refreshes the
  first one after the answer; a list whose refresh failed waits `catalog.retry_minutes` (60) before the next try.
- An account import naming an in-game ID the catalog lacks: that list, before the import writes (all three when
  the game has no catalog yet); a builds import naming a character or weapon key it lacks: that list, before it
  is skipped. Both at most once per game and list every `catalog.import_refresh_minutes` (60), for every user
  together - one shared claim.
- `php sync-catalog.php [genshin] [--list=characters|weapons|sets] [--force]`: without `--force` a game whose
  catalog was confirmed within `refresh_days` is skipped, and the others are asked conditionally; `--force` asks
  every list in full.

How the provider's rows become the catalog's (`GenshinCatalog`):

- Characters: the key from the name (`Key::from()`), the element lowercased (one the game does not have -
  "Adaptive" - none), the weapon type lowercased, rarity 1-5, `region`, `version` ("6.1"), `version_name` ("Luna
  II"), `released_at` from the release date, `external_id` the in-game ID. The builds browser filters by them
  (migration 018 added `region`, `version`, `version_name`).
- The Travelers (rows marked `is_traveler`, one per twin and element) become one entry per element: key
  `traveler{element}` (`traveleranemo`), name "Traveler (Anemo)", `external_id` `{lowest in-game ID}-{element}`, and
  both twins' IDs in `meta.ids` (`10000005-anemo`, `10000007-anemo`) - as the catalog named them before 0.5.0. An
  import finds a Traveler by its key (the showcase's element from `skillDepotId`, GOOD's `TravelerAnemo`).
- Weapons: the key from the name, the type lowercased, rarity; of two under one key (a quest copy) the lower
  in-game ID's.
- Artifact sets: the key from the name, kind `artifact`, `meta.rarities` and `meta.bonuses` (the 2-piece and
  4-piece texts).
- Markup in names is stripped and entities decoded; a row without a usable name (none, or over 100 characters) is
  left out.
- An icon is kept only on the API's own host (https; http only for this computer), at most 500 characters of
  printable ASCII with no space, `@` or backslash - nothing two URL readers could read two ways; anything else is
  none. The browser never asks the provider: the API sends `/api/icons/{c|w|s}/{id}`, which fetches the stored
  address once into `storage/icons/` and serves it with the type its first bytes say - PNG, JPEG, WebP, GIF or
  AVIF (`Presenters\Icons::type()`: an `ftyp` box naming `avif` or `avis`, genshin.anotoki.eu's format).

Migration 018 cleared the old provider's icon addresses and forgot where each list stood with it, so the first
refresh reads every list in full.

## Resolution (`src/Services/Catalog/Catalog`)

`find($game, $type, $name)` in this order:

1. `normalize()`: `Key::from`, then the Travelers' special case (`anemotraveler`, `traveleranemo`, `aetheranemo`,
   `lumineanemo`, `travelleranemo` … → `traveleranemo`), then aliases from `config/app.php`
   (`aliases.genshin.{characters|weapons|sets}`, alias key → canonical key).
2. Exact key.
3. Fuzzy, only when unique and the name is ≥ 4 characters: catalog key starts with, ends with, then
   contains the name (`kazuha` → `kaedeharakazuha`, `emblem` → `emblemofseveredfate`). A **shorter** catalog key
   never matches a longer name, so a brand-new character whose name extends an older one's can't silently become
   that older one.

Lookups only: `character()`, `weapon()` and `set()` find a row or record the name in `unmatched()`, and nothing in
the site adds a row from a name (the catalog has had no create mode since 0.5.0) - only a refresh from the
provider writes the catalog. It is shared by every user: a junk row from an uploaded file would change fuzzy
matching for everyone.

- An account import resolves a reference by its key, then its in-game ID (`Catalog::byExternalId`, which reads
  `meta.ids` too), then its name. Whatever the catalog lacks is skipped with a warning and listed under
  `unmatched` in the import summary; the fix is a catalog refresh (a new release) or an alias (a nickname).
- A builds import compares the file's character and weapon keys exactly (`CatalogRows::byKeys()`: no alias, no
  fuzzy match) and skips a character the catalog lacks ([builds.md](builds.md)).

External IDs: the showcase's avatarId, a weapon's itemId and an artifact's `setId` are the in-game IDs the
provider sends as `game_id` - the catalog's `external_id`.

## Adding an alias

Aliases are for the nicknames and abbreviations players use ("Childe", "vv", "eosf"). A build site's own
spellings, nicknames and typos are not aliased here: the local tools keep their own list in the owner's untracked
`local/` folder, merged into these aliases while they run ([builds.md](builds.md), "The local tools").

1. Find the exact failing name (the import summary's `unmatched`, or its warning) and the correct catalog key,
   e.g. with `psql` on the database of `config/database.local.php`, or:

   ```bash
   cd backend && php -r 'require "vendor/autoload.php"; print_r(App\Cli::app()->db()->column("SELECT key FROM game_sets WHERE game = ? AND key LIKE ?", ["genshin", "%witch%"]));'
   ```

2. Add `'<Key::from(bad name)>' => '<catalog key>'` to the right `aliases` list in `backend/config/app.php`.
3. Check every alias target exists (catches typos in the config itself):

   ```bash
   cd backend && php -r 'require "vendor/autoload.php"; $app = App\Cli::app(); $tables = ["characters" => "game_characters", "weapons" => "game_weapons", "sets" => "game_sets"]; foreach ($app->config->get("aliases") as $g => $types) foreach ($types as $t => $a) foreach ($a as $from => $to) if (! $app->db()->value("SELECT 1 FROM {$tables[$t]} WHERE game = ? AND key = ?", [$g, $to])) echo "MISSING $g/$t: $from => $to\n"; echo "ok\n";'
   ```

4. Import again: the name now meets its row.
