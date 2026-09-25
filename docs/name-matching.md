# Names, keys and the catalog

Every source names things differently ("Kazuha", "KaedeharaKazuha", "kaedehara-kazuha", avatarId 10000047).
All of them meet on a **canonical key**: `Support/Key::from($name)` = lowercase ASCII letters and digits only
("Wolf's Gravestone" → `wolfsgravestone`, "Dan Heng • Imbibitor Lunae" → `danhengimbibitorlunae`).

## Catalog sync (`catalog:sync`, `Services/Catalog/CatalogSync`)

Source: yatta.moe (`/api/v2/en/{avatar,weapon,reliquary}` for Genshin, `{avatar,equipment,relic}` for HSR).
Refreshed daily at 04:00 and when older than `HOYO_CATALOG_REFRESH_DAYS` (3).

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
  `https://sr.yatta.moe/hsr/assets/UI/{avatar|equipment/medium|relic}/{icon}.png`.

## Resolution (`Services/Catalog/Catalog`)

`find($game, $type, $name)` in this order:

1. `normalize()`: `Key::from`, then character special cases, then aliases from `config/hoyo.php`
   (`aliases.{game}.{characters|weapons|sets}`, alias key → canonical key).
   - Genshin: `anemotraveler`, `traveleranemo`, `aetheranemo`, … → `traveleranemo`.
   - HSR: `trailblazerharmony`, `harmonytrailblazer`, `stelleharmony`, `trailblazertheharmony` → `trailblazerharmony`;
     `march7th(thehunt|hunt|imaginary|swordmaster…)` → `march7thhunt`; `march7th(preservation|ice)` → `march7th`.
2. Exact key.
3. Fuzzy, only when unique and the name is ≥ 4 characters: catalog key starts with, ends with, then
   contains the name (`kazuha` → `kaedeharakazuha`, `emblem` → `emblemofseveredfate`). A **shorter** catalog key
   never matches a longer name, so "Himeko • Nova" can't silently become "Himeko".

Creation: `character()`, `weapon()`, `set()` create a row when nothing matches and record the name in
`unmatched()`; import summaries show all three kinds. `BuildSync` creates unknown *characters* (new releases
appear on build sites first; they show up in the run's `stats.unmatched`) but only looks up weapons and sets:
misses appear **only** as `! unknown weapon|set: <name>` lines in the sync-run log (and in `--dry-run`
output) and are stored under their own normalized key.

External IDs: Enka avatarId / weapon itemId / artifact `setId`, HSR avatarId / light cone `tid` / relic
`setID` equal the yatta IDs (`Catalog::byExternalId`, including `meta.ids`).

## Adding an alias

1. Find the exact failing name (sync-run log or import summary) and the correct catalog key:
   `scripts/artisan.sh tinker --execute='echo App\Models\GameSet::where("game","hsr")->where("key","like","%poet%")->pluck("key");'`
2. Add `'<Key::from(bad name)>' => '<catalog key>'` to the right `aliases` list in `backend/config/hoyo.php`
   with a short comment when it's a site typo.
3. Check every alias target exists (catches typos in the config itself):

   ```bash
   scripts/artisan.sh tinker --execute='$m=["characters"=>App\Models\GameCharacter::class,"weapons"=>App\Models\GameWeapon::class,"sets"=>App\Models\GameSet::class]; foreach (config("hoyo.aliases") as $g=>$types) foreach ($types as $t=>$a) foreach ($a as $from=>$to) if (! $m[$t]::where("game",$g)->where("key",$to)->exists()) echo "MISSING $g/$t: $from => $to\n"; echo "ok\n";'
   ```

4. Re-sync the source (cached pages make it quick): `scripts/artisan.sh builds:sync <source> --force`.

Known site typos already aliased: "Prototype Archiac", "Gest of the Might Wolf", "Emblem of Severed Fates",
"Boothiill", "Topaz and Numby", "Messenger of Traversing Hackerspace", "Poet of Mourning Disciple",
"Pan-Galactic Commercial Enterprise", "A Grounded Ascend", and element-named Trailblazers on genshin.gg.
