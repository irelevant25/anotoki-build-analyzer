# Names, keys and the catalog

Every source names things differently ("Kazuha", "KaedeharaKazuha", "kaedehara-kazuha", avatarId 10000047).
All of them meet on a **canonical key**: `Support/Key::from($name)` = lowercase ASCII letters and digits only
("Wolf's Gravestone" → `wolfsgravestone`, "Dan Heng • Imbibitor Lunae" → `danhengimbibitorlunae`). It
transliterates exactly as the Laravel version's `Str::ascii()` did (`voku/portable-ascii`), so keys stored by
that version still match.

## Catalog sync (`php sync-catalog.php`, `src/Services/Catalog/CatalogSync`)

Source: yatta.moe (`catalog` in `config/app.php`: `/api/v2/en/{avatar,weapon,reliquary}` for Genshin,
`{avatar,equipment,relic}` for HSR). The UI calls it "the game data source". Refreshed when an admin presses
"Refresh catalog" on the Sources page (or runs `php sync-catalog.php`), and before a build sync when the game's
catalog is older than `catalog.refresh_days` (3): a catalog run per game takes one list (download + one
transaction) per unit of work. `CatalogSync::syncList()` refreshes a single list.

- Rows are matched by in-game ID first, so renames keep their row (and everything linked to it).
- Markup in names is stripped (`Silver Wolf LV.<unbreak>999</unbreak>` → `Silver Wolf LV.999`).
- Genshin Travelers: one row per element, key `traveler{element}` (`traveleranemo`), `meta.ids` holds both
  Aether and Lumine IDs (`10000005-anemo`, `10000007-anemo`).
- HSR Trailblazers: one row per path, key `trailblazer{path}` (`trailblazerharmony`), `meta.ids` = both IDs
  (8005/8006). Paths: Warrior=destruction, Rogue=hunt, Mage=erudition, Shaman=harmony, Warlock=nihility,
  Knight=preservation, Priest=abundance, Memory=remembrance, Elation=elation.
- Duplicate names (the two March 7th) keep the lowest ID on the plain key; others get the path appended
  (`march7thhunt`, name "March 7th (The Hunt)").
- Duplicate Genshin weapons (quest copies) keep the lowest ID. Genshin set rows store the 2pc/4pc texts in
  `meta.bonuses`.
- Icons: `https://gi.yatta.moe/assets/UI/{icon}.png` (sets: `/UI/reliquary/`), HSR
  `https://sr.yatta.moe/hsr/assets/UI/{avatar|equipment/medium|relic}/{icon}.png`, stored in `icon_url`. The
  browser never asks the provider: the API sends `/api/icons/{c|w|s}/{id}`, which fetches the image once into
  `storage/icons/`.

## Resolution (`src/Services/Catalog/Catalog`)

`find($game, $type, $name)` in this order:

1. `normalize()`: `Key::from`, then character special cases, then aliases from `config/app.php`
   (`aliases.{game}.{characters|weapons|sets}`, alias key → canonical key).
   - Genshin: `anemotraveler`, `traveleranemo`, `aetheranemo`, … → `traveleranemo`.
   - HSR: `trailblazerharmony`, `harmonytrailblazer`, `stelleharmony`, `trailblazertheharmony` → `trailblazerharmony`;
     `march7th(thehunt|hunt|imaginary|swordmaster…)` → `march7thhunt`; `march7th(preservation|ice)` → `march7th`.
2. Exact key.
3. Fuzzy, only when unique and the name is ≥ 4 characters: catalog key starts with, ends with, then
   contains the name (`kazuha` → `kaedeharakazuha`, `emblem` → `emblemofseveredfate`). A **shorter** catalog key
   never matches a longer name, so "Himeko • Nova" can't silently become "Himeko".

Creation: `character()`, `weapon()`, `set()` create a row when nothing matches (unless called with
`create: false`) and record the name in `unmatched()`. The catalog is shared by every user, so only trusted
sources add to it:

- `BuildSync` creates unknown *characters* (new releases appear on build sites first; they show up in the run's
  `stats.unmatched`) but only looks up weapons and sets: misses appear **only** as `! unknown weapon|set: <name>`
  lines in the sync-run log (and in `--dry-run` output) and are stored under their own normalized key.
- Imports never create rows (`create: false`): an uploaded file is untrusted, and a junk row would change fuzzy
  matching for everyone. Unknown names are skipped with a warning and listed under `unmatched` in the import
  summary; the fix is a catalog refresh (a new release) or an alias (a nickname or typo).

External IDs: the showcase's avatarId / weapon itemId / artifact `setId`, HSR avatarId / light cone `tid` /
relic `setID` equal the catalog provider's IDs (`Catalog::byExternalId`, including `meta.ids`).

## Adding an alias

1. Find the exact failing name (sync-run log or import summary) and the correct catalog key, e.g. with `psql`
   on the database of `config/database.local.php`, or:

   ```bash
   cd backend && php -r 'require "vendor/autoload.php"; print_r(App\Cli::app()->db()->column("SELECT key FROM game_sets WHERE game = ? AND key LIKE ?", ["hsr", "%poet%"]));'
   ```

2. Add `'<Key::from(bad name)>' => '<catalog key>'` to the right `aliases` list in `backend/config/app.php`
   with a short comment when it's a site typo.
3. Check every alias target exists (catches typos in the config itself):

   ```bash
   cd backend && php -r 'require "vendor/autoload.php"; $app = App\Cli::app(); $tables = ["characters" => "game_characters", "weapons" => "game_weapons", "sets" => "game_sets"]; foreach ($app->config->get("aliases") as $g => $types) foreach ($types as $t => $a) foreach ($a as $from => $to) if (! $app->db()->value("SELECT 1 FROM {$tables[$t]} WHERE game = ? AND key = ?", [$g, $to])) echo "MISSING $g/$t: $from => $to\n"; echo "ok\n";'
   ```

4. Re-sync the source (cached pages make it quick): `cd backend && php sync-builds.php <source> --force`.

Known site typos already aliased: "Prototype Archiac", "Gest of the Might Wolf", "Emblem of Severed Fates",
"Boothiill", "Topaz and Numby", "Messenger of Traversing Hackerspace", "Poet of Mourning Disciple",
"Pan-Galactic Commercial Enterprise", "A Grounded Ascend", and element-named Trailblazers on genshin.gg.
