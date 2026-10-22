# Builds

What a build is since 0.5.0 - and the teams it is played in, since 0.6.0 -, the file builds come in, its import and
export on the admin panel's Builds page, and what the owner's local tools must deliver. The site never fetches a
build itself (the owner's decision of 2026-10-20, [decisions.md](decisions.md)): the builds are put together from
the build sites on the owner's computer by untracked tools, and an editor imports the result as one file per game.

Code: `backend/src/Services/Builds/BuildFile.php` (the format and its strict reader), `BuildImport.php` (the
import, the export, the imports made), `api/routes/admin_builds.php` (the Builds page's routes),
`api/routes/builds.php` (the public builds browser), `src/Models/Build.php`, `src/Repositories/Builds.php`,
`src/Presenters/BuildPresenter.php`; migrations `016_builds_without_sources.sql`, `020_build_teams.sql`. Frontend:
`pages/admin/builds/` (the Builds page; `build-file.ts` checks a chosen file and holds the page's words),
`core/i18n/variation-words.ts`, `shared/build-teams.*` (a build's teams). Tests: `backend/tests/builds_import.test.php`
(the catalog's own refresh: `catalog_refresh.test.php`; a teammate the account has: `analysis.test.php`).

## A build is a variation of a character

A character has one build or several, each a variation:

| Field | Meaning |
| --- | --- |
| `role` | what the build is for: `main_dps`, `sub_dps` or `support` (`Game::roles()`, the order the builds browser lists a character's roles in; migration 016's CHECK) |
| `qualifier` | a word or two telling two builds of one role apart ("Melt", "Hyperbloom"); `''` for most. At most 40 characters once trimmed, one line, stored trimmed and neutral |
| `min_rank` | the constellation the build needs, 0-6 (`Game::maxRank()`) |
| `position` | its place among the character's builds, from 0: the first is the main one |

A build's identity is (character, role, qualifier without regard to case, `min_rank`): `BuildFile::identity()`, and
the unique index `builds_identity_unique` on (`game_character_id`, `role`, `lower(qualifier)`, `min_rank`) - an
empty qualifier is `''`, never NULL, as the host's PostgreSQL 14 has no `NULLS NOT DISTINCT`. A file holds each
identity once, and an import keeps the id of a build it finds again by it, so a link to the build (`?build=ID`)
stays.

How a variation is written: the member pages word the role in the reader's language (`builds.role.*`) and add the
qualifier as the build has it and the constellation in the game's notation - "Main DPS · Melt · C4+"
(`variationLabel()`); the admin panel's English says "Main DPS", "Sub DPS", "Support"; a log line or a refused
file's message says `main_dps "Melt" C4+` (`BuildFile::variation()`).

## Constellations

- **`min_rank`**: a character whose constellation (`account_characters.rank`) is below it still has the build
  evaluated in full - every part scored, every recommendation made - but marked `applies: false`
  (`Build::appliesTo()`): its tab on the character page carries a lock and "needs C4", and the build a line "This
  build needs C4; Hu Tao is C0." It is never the character's best while one the character reaches exists; when it
  reaches none, the best is the build needing the lowest constellation, still marked ([analysis.md](analysis.md),
  "Flow").
- **`from_rank`** on a stat target: the target holds from that constellation on. Per stat, the analysis takes the
  target from the highest constellation the character has reached (`BuildEvaluator::targetsFor()`): of a build's
  Energy Recharge targets from C0 (200%) and from C1 (160%), a C2 character is held to the C1 one; a stat whose
  targets all start later than the character's constellation has none. A build has each (stat, `from_rank`) once;
  the page marks a target from a constellation "C1+".

## Teams (since 0.6.0)

A build lists the teams it is played in, the best first (at most 20 - thirteen at most so far): each a name
("Vaporize"), its members in the order the team is written, and a note. A member is a character by the catalog's
key, with its name, the role it plays in the team (one of `Game::roles()`, or null where nobody said), the
constellation the team asks of it (`min_rank`, 0 for none) and the characters who can take its place
(`alternatives`, at most five). The build's own character is a member of each of its teams exactly once, and has no
stand-in: the team is the build's. A team has two to `Game::teamSize()` (4) members, each once; no stand-in is a
member of the same team; no two teams of a build have the same members, whatever their order.

The teams are part of the build's content: a team gained, lost, renamed or reordered makes the build "updated" on
the next import - but whether a teammate is in the catalog, its picture, rarity and element, and whether it has
builds of its own are not stored: `BuildPresenter` looks them up whenever the build is shown, so a teammate the
catalog learns of later shows its picture without an import (weapons store `known`; teams deliberately do not -
[decisions.md](decisions.md)).

Where they show (`shared/build-teams`, after the stat targets): each team under its name - the stored English, read
with `lang="en"` -, its members side by side, on a phone too (four columns as wide as a 64 px portrait and a little
more, narrower on a phone), each with its portrait, its name - broken between its words, never inside one -, its
role in the reader's language and a constellation the team asks as "C2+" (on the build's own character only where it
is above the constellation the build needs). The build's own character is set apart and not a link; a teammate
with builds of its own links to them (`/builds/genshin/{key}`), one the catalog lacks shows the file's name, without
a picture or a link; the stand-ins follow as "or Yelan / Mona", each a link where it has builds; a note opens in the
kit's disclosure, titled with the team's name ("Notes on Vaporize": a screen reader tells one team's notes from
another's). On an account's character page a teammate the account has - a member or a stand-in - wears a check (on
the portrait, after a stand-in's name; a screen reader hears "Owned" after the name), a member also "needs C2" where
the account's constellation is below the team's, and links to the account's own analysis of it (`owned_teammates` in
the character's answer: [architecture.md](architecture.md)). The Traveler is one character in several elements: the
account's, in whichever element it was imported, makes every Traveler of the teams its own - checked, its
constellation there unknown (never "needs"), linked to its builds, as the account has no analysis of it.

## The builds file (format version 3)

One file per game, such as `builds-genshin-2026-10-22.json` (`App\Services\Builds\BuildFile`):

```jsonc
{
  "format": "build-analyzer.builds", "format_version": 3, "game": "genshin",
  "exported_at": "2026-10-20T10:00:00Z",              // ISO 8601 with its zone; the export writes UTC to the second
  "builds": [{                                        // a character's builds in order of preference: the first is its main one
    "character": "furina",                            // the catalog's key (game_characters.key), compared as it is
    "character_name": "Furina",                       // for people reading the file
    "role": "support", "qualifier": "", "min_rank": 0,
    "weapons": [{"key": "splendoroftranquilwaters", "name": "Splendor of Tranquil Waters", "refinement": 1, "note": null}],
    "sets": [{"kind": "artifact", "choose": null, "note": null,
              "pieces": [{"label": "Golden Troupe", "count": 4, "keys": ["goldentroupe"], "names": ["Golden Troupe"]}]}],
    "main_stats": {"sands": [{"stat": "hp_", "priority": 1}], "goblet": [{"stat": "hp_", "priority": 1}],
                   "circlet": [{"stat": "critRate_", "priority": 1}, {"stat": "critDMG_", "priority": 1}]},
    "substats": [{"stat": "critRate_", "priority": 1}, {"stat": "critDMG_", "priority": 1}, {"stat": "hp_", "priority": 2}],
    "talent_priority": [{"talent": "skill", "priority": 1}, {"talent": "burst", "priority": 2}],   // or null
    "stat_targets": [{"stat": "enerRech_", "min": 160, "max": null, "text": "160% Energy Recharge", "from_rank": 0}],   // or null
    "teams": [{                                       // [] for none
      "name": "Hydro",
      "members": [{"key": "furina", "name": "Furina", "role": "support", "min_rank": 0, "alternatives": []},
                  {"key": "neuvillette", "name": "Neuvillette", "role": "main_dps", "min_rank": 0, "alternatives": []},
                  {"key": "kaedeharakazuha", "name": "Kaedehara Kazuha", "role": "support", "min_rank": 2,
                   "alternatives": [{"key": "sucrose", "name": "Sucrose"}]}],
      "note": null
    }],
    "notes": null, "game_version": "6.1"
  }]
}
```

Every field is required - null where null is allowed - and a field the format lacks is refused:

| Field | What it holds |
| --- | --- |
| `format`, `format_version` | `"build-analyzer.builds"`, `3` |
| `game` | the game imported (`genshin`) |
| `exported_at` | when the file was made: ISO 8601 with its zone (fractions of a second optional), a real date from 1970 on |
| `builds` | at most 5000 builds; a character's in its order of preference |
| `character` | the catalog's key: lowercase letters and digits, at most 120 |
| `character_name` | text, not empty, at most 160 |
| `role`, `qualifier`, `min_rank` | the variation (above): one of `Game::roles()`; text, `''` for none, one line (no control character), at most 40 once trimmed; a whole number 0-6 |
| `weapons` | at most 100, in rank order: `{key, name, refinement, note}` - a key (lowercase letters and digits); a name, not empty; `refinement` 1-5 or null; a note or null |
| `sets` | at most 50 options, in rank order: `{kind, choose, pieces, note}` - `kind` one of `Game::setKinds()` (`artifact`); 1-30 `pieces`; `choose` how many of them must be satisfied (1 to their number), null for all; a note or null |
| a piece | `{label, count, keys, names}` - a label; `count` 2 or 4 (`Game::setPieceCounts()`); 1-100 set `keys`, each once - the piece accepts any of them ("any +80 EM set"); 1-100 `names`, not empty, each once |
| `main_stats` | an object of the variable slots (`sands`, `goblet`, `circlet`), each a list of at least one `{stat, priority}` among what the slot can roll (`Game::mainStatOptions()`), `elemental_dmg_` on the goblet; `{}` for none |
| `substats` | at most 30 `{stat, priority}` among the substat keys (`Stats::substatKeys()`); `[]` when the build lists none - the analysis then derives them from its main stats |
| `talent_priority` | at most 10 `{talent, priority}` (`normal`, `skill`, `burst`), or null |
| `stat_targets` | at most 30 `{stat, min, max, text, from_rank}`, or null: any stat key of the game (`Stats::keys()`); `min` and `max` numbers within ±1,000,000 or null, `min` ≤ `max`; the goal as it was written; `from_rank` 0-6 |
| `teams` | at most 20 `{name, members, note}`, the best first; `[]` for none (never null): a name - one line, not empty, at most 60 once trimmed; 2-4 `members`; a note or null |
| a member | `{key, name, role, min_rank, alternatives}` - the catalog's key of a character (lowercase letters and digits), each once in its team, the build's own character exactly once; a name, not empty; `role` one of `Game::roles()` or null; `min_rank` 0-6; at most 5 `alternatives` `{key, name}`, each once, none a member of the team - and none for the build's own character |
| `notes`, `game_version` | text or null |

In a ranked list (`{stat, priority}`, `{talent, priority}`) each stat or talent appears once; a priority is a whole
number 1-99, and equal priorities are ties.

### What the reader checks

`BuildFile::read($file, $game)` is strict, because the analysis reads every stored build for every user: a file is
refused as a whole, naming its first problem ("The file's builds[3] (hutao, main_dps C2+) is not valid:
weapons[0].refinement must be a whole number from 1 to 5, or null."), unless

- it is a JSON object with the format's name (else "This is not a builds file of the build analyzer.");
- its `format_version` is 3: a version 2 file - builds without their teams, before 0.6.0 - and a version 1 file -
  the builds of one source, before 0.5.0 - are refused each with its own message ("make the file again, or export
  it again from this installation"), a later version with "Update this installation, then import again.";
- its `game` is the game imported;
- every field is there and none the format lacks, at the top and in every build, weapon, set option, piece,
  ranked entry and target;
- every value has the shape above: lists and objects where they belong, whole numbers where the app counts, the
  game's roles, set kinds, slots, stats and talents;
- no identity is there twice ("its character, role, qualifier and min_rank are those of builds[2] too"), and no
  (stat, `from_rank`) twice in a build;
- each team holds the build's own character once ("must have the build's own character (hutao) among them"), with
  no stand-in, every member once, no stand-in that is a member, and no two teams of a build have the same members
  ("teams[1].members are those of teams[0] too");
- it keeps within the caps: 5000 builds; a build's 100 weapons, 50 set options, 30 pieces an option, 100 sets a
  piece, 30 stats a list, 10 talents, 30 targets and 20 teams - each of 2-4 members with 5 stand-ins at most; texts
  within their columns (a character's key 120, its name 160, a qualifier 40, a team's name 60, a game version 20) or
  within generous caps (notes 20,000 characters, a note or a target's text 5000, a name, label or key 1000). The
  caps only keep a file from being absurd.

The route refuses an empty body (what PHP makes of one larger than its `post_max_size`: "No file arrived.") and
one over 8 MB (`BuildFile::MAX_BYTES`, a common default of that limit); a game's builds take about 1 MB (1.2 MB
with 809 teams, 2026-10-22). The
set pieces' rules - each set once in a piece, 2 or 4 pieces of it - keep `SetMatcher`'s search small: no more than
two equipped sets can satisfy such pieces.

What it hands on is the build as it is stored: each weapon and set option with its `rank` (its place in its list,
from 1), each build with its `position` among its character's (the file's order, from 0; a character's builds need
not stand together), an empty `talent_priority` or `stat_targets` as null, the qualifier and a team's name trimmed,
and every text made neutral (`Neutral::text()`, within its cap) - a file edited by hand cannot bring a site's name
into what the API sends or the analysis writes. Weapon, set, member and stand-in keys are made neutral too
(`BuildFile::neutralKey()`); the character's key is only compared - and a team's own member by it, made neutral the
same way. Whether a weapon is in the catalog is the import's to say; whether a teammate is, the page's - looked up
each time the build is shown (the import only names the ones it lacks).

## Importing a file

On the Builds page (`/admin/builds`, editors and admins), **Import a file** checks the chosen file in the browser
(JSON, the format, its version, the game, at most 8 MB - `build-file.ts`), sends it as a dry run and shows the
answer as a preview: what would be created, updated, unchanged and removed, whether the file is older than the one
the builds now come from, the characters skipped, and the changes one by one. **Import** sends the same text for
real; **Cancel** forgets it. `BuildImport::import()`:

- **The file is the whole truth for its game.** Afterwards the game's builds are exactly the file's - but for
  characters this installation's catalog lacks, which are skipped (an import never adds to the catalog) and named
  in the answer. Every build of a character the file does not have is removed. A file whose builds name no
  character the catalog has is refused ("Refresh the catalog on the Builds page, then import again."): importing
  nothing would remove every build. A file with an empty `builds` list does remove them all.
- **A build found again by its identity keeps its id.** It is updated when its content (weapons, sets, main
  stats, substats, talents, targets, notes, game version - and its teams, last and only when it has some:
  `content_hash`, a SHA-256 of their JSON), its place among the character's builds or its qualifier's spelling
  changed, and only marked checked otherwise. A build without teams hashes as it did before 0.6.0, so the first
  version 3 file updates only the builds that gained teams. A build the file lacks is removed, one new to it is
  created.
- **Times.** `checked_at` of every build the file has becomes when the file was made (`exported_at`), and
  `changed_at` of every build it created or updated too; a time later than now (another computer's clock) is taken
  as now.
- **The catalog first.** A character or weapon key the catalog lacks has that list refreshed from the game data
  provider before anything else ([name-matching.md](name-matching.md)), at most once per game and list every
  `catalog.import_refresh_minutes` (60, `config/app.php`) - an account's import shares the interval, through the
  same claim -, and the keys are looked up again when the list changed. The builds' characters and their teammates
  (members and stand-ins) are looked up together, so one refresh serves both. A build's character still missing is
  skipped; a teammate still missing skips nothing - it is stored as the file has it and shown by its name until the
  catalog has it, and named in the answer (`teammates_unknown`). A weapon still missing is kept under its key and
  stored with `known: false` (it compares by key all the same). Set keys are not looked up: one the catalog lacks
  matches no equipped set. Keys are compared exactly as they are (`CatalogRows::byKeys()`): no alias and no fuzzy
  match - whoever made the file resolved the names.
- **One transaction under a lock of its own.** `pg_try_advisory_xact_lock` (`BuildImport::LOCK`): a second import
  meanwhile is refused at once (409, "Another import of builds is being written."), nothing written; the lock goes
  with the transaction, also when the request dies. `SET LOCAL statement_timeout` and
  `idle_in_transaction_session_timeout` of 60 s: nothing holds the builds' rows for minutes. What changes is worked
  out again inside the lock, so an import that committed meanwhile is what this one changes. Removed builds go
  first and new ones last: no identity is ever there twice. The route allows 240 s (`BuildImport::MAX_SECONDS`),
  and an import that has started finishes even when the tab is closed.
- **A dry run** (`?dry_run=1`) answers in the same shape (`dry_run: true`) and writes no build: no lock, no record.
  It can still refresh a catalog list first, as above.
- **Recorded**, in the import's transaction: a row of `build_imports` - the game, the file's `exported_at`, what it
  did (the counts), who imported it - and the audit log's `builds.imported` (target the game; created, updated,
  removed, characters, skipped).

The answer:

```jsonc
{"dry_run": false, "game": "genshin", "exported_at": "2026-10-20T10:00:00Z",
 "older_than_current": false,       // made before the file the builds now come from (a dry run: the latest import's; an import: the one before)
 "characters": 127, "builds": 192,  // what the file holds of characters the catalog has
 "teams": 809,                      // the teams of those builds
 "created": 4, "updated": 11, "unchanged": 177, "removed": 2,
 "skipped": [{"key": "...", "name": "...", "builds": 1}],
 "teammates_unknown": [{"key": "...", "name": "..."}],   // teammates of the builds imported the catalog lacks (the first 50, neutral): kept, shown by their names
 "teammates_unknown_total": 1,                           // how many, all told
 "changes": [{"change": "created", "character": {"key": "furina", "name": "Furina"}, "role": "support", "qualifier": "", "min_rank": 0}],
 "changes_listed": 17}              // at most 500 listed, by character name and then as the file has them, the removed after
```

Refused: 422 with the reason when the file does not fit (nothing written), 409 while another import writes.

## Exporting

**Export** on the Builds page (`GET /api/admin/builds/{game}/export`) saves the game's builds as a file named
`builds-{game}-{the day it was made}.json`: a backup, and the start of a hand edit. `BuildImport::export()` writes

- the builds by character key (compared as bytes, whatever the database's collation) and then in each character's
  order, so two exports of the same builds differ in `exported_at` only (now, in UTC to the second);
- every text made neutral within the reader's caps, a piece's sets that neutral words make alike listed once, and
  `main_stats` always as an object; two qualifiers of a character that neutral words make alike become "… #2";
- the teams in the file's fields (`BuildFile::teamsEntry()`): a member or stand-in that neutral words make alike
  another listed once, a stand-in that is a member left out, and so is a team then of fewer than two members or of
  another team's members;
- no `rank`, `position` or `known`: the order is the rank and the place, and the catalog says what it knows.

Whatever the export writes, the import reads.

## The routes

The admin panel's, behind `requireRole($app, ...Role::STAFF)` (EDITOR or ADMIN; 403 `editor_only`):

| Route | Answer |
| --- | --- |
| `GET /api/admin/builds` | per game: `characters`, `builds`, `checked_at` (when the newest file carrying them was made), `changed_at`, `imports` (the last 10, newest first: `created_at`, `file_exported_at`, `summary`, `imported_by` as `{id, name}` - null once that person's data is deleted) and `catalog` (each list's `entries`, `refreshed_at` and last `problem`) |
| `GET /api/admin/builds/{game}/export` | the file, as a download |
| `POST /api/admin/builds/{game}/import[?dry_run=1]` | the body is the file's JSON; the answer above |
| `POST /api/admin/catalog/{game}/{list}[?force=1]` | one catalog list (`characters`, `weapons`, `sets`) refreshed - a conditional request unless forced: `{outcome: changed \| unchanged, written, list, entries, refreshed_at, problem}`; 409 while another refresh of it runs, 502 with the reason when the provider is closed, lacks the list or cannot be read; audited `catalog.refreshed`. The page's **Refresh catalog** asks for the three one after another, unforced |

Public, behind `optionalSignIn($app)`: everybody reads the same, and while the IAM requires sign-in only a person
signed in does.

| Route | Answer |
| --- | --- |
| `GET /api/builds` | per game: how many characters have builds, how many builds, `checked_at`, `changed_at` (the dashboard) |
| `GET /api/builds/{game}` | the characters with builds, newest release first: `key`, `name`, `element`, `rarity`, `weapon_type`, `icon_url`, `released_at`, `region`, `version`, `version_name`, `builds` (how many), `roles` (in the game's order), `changed_at` - the builds browser and what it filters by |
| `GET /api/builds/{game}/{key}` | a character and its builds in their order (`BuildPresenter`: every text neutral once more, weapon and set icons from the catalog; each team's members and stand-ins with `known`, `icon_url`, `rarity`, `element` and `has_builds` looked up as the build is shown, a member with `own` - the build's character) |

## The tables (migrations 016, 020)

- `builds`: `game_character_id` (deleted with the character), `role` (CHECK), `qualifier` (`VARCHAR(40)`, `''` by
  default, trimmed - CHECK), `min_rank` (0-6), `position`, the JSON columns `weapons`, `sets`, `main_stats`,
  `substats`, `talent_priority`, `stat_targets` (the file's shapes; weapons and set options with their `rank`,
  weapons with `known`) and `teams` (migration 020: the file's shape, NULL for none), `notes`, `game_version`,
  `content_hash`, `checked_at` (when the file that last carried the build was made), `changed_at` (when the first
  one carrying it as it is now was), `created_at`, `updated_at`; unique on the identity.
- `build_imports`: `game`, `file_exported_at`, `summary` (characters, builds, created, updated, unchanged, removed,
  skipped - counts, nobody's name), `imported_by` (a person's id, never a visitor's: CHECK > 0; NULL once that
  person's data is deleted), `created_at`; indexed on (`game`, `created_at`).

Migration 016 dropped the build sources, their builds, the sync runs and the syncs' locks, and carried nothing
over: the builds come back with the first import. Data model: [data-model.md](data-model.md).

## Where builds show

- The builds browser (`/builds/genshin`): each character with builds a link card with its roles, filtered by name,
  element, weapon type, rarity, region and version, the filters kept in the address (`core/query-filters.ts`); a
  character's builds in their order on `/builds/genshin/{key}`, each with its teams.
- An account's character page: a tab per variation with its score, the closest build marked, one the character's
  constellation does not reach locked; the build's detail - its notes, "derived" substats, a target from a
  constellation marked "C2+" ([analysis.md](analysis.md)), its teams with the teammates the account has marked. The
  account page shows each character's best build.
- The dashboard: how many builds there are and when they last changed (`GET /api/builds`).

## The local tools (the owner's computer)

Where the builds come from is not in this repository. The build sites are read by untracked tools in the owner's
`local/` folder (in the root `.gitignore`), and nothing is copied from it into a tracked file. The build sites'
names are in the owner's untracked `backend/config/neutral.local.php` (`names`, which `Neutral` adds to its own
list, and `tree_words`; uploaded to a host by hand), and `php backend/tools/check-names.php` reads the tracked
tree - and what `git add -A` would add; `--staged`: the index - for them.

What they do: a script per build site reads it politely (robots.txt obeyed, each site's own wait between
requests) and keeps every page on disk; each site's builds are classed by role (the owner's list of roles); the
owner's curation per character is applied; per role and constellation one build is picked, by a ranking of the
sites, real page dates breaking ties; gaps are filled only for talents, stat targets (from another role only
Energy Recharge) and substats (only from the same role); a variation is named only where a role has two; notes
about another character are dropped; the teams the sites recommend for a character go with its variation of the
role it plays in each team (of the constellation the team asks, then the one a team's name speaks of, then the
first), the best site's first, one of the same members once with the stand-ins of both, named without the
character's own name, every one (within the 20 a file holds); the sites' notes - a build's, a weapon's, a set
option's, a team's - and a target's wording are not written (the owner's word of 2026-10-22: a reader could tell
from them where the builds come from; notes of the site's own are to be written on the site); every text is made
neutral; the file is checked with the analyzer's own `BuildFile::read()`; an export more than a fifth smaller than
the last one is refused; and `builds-genshin-<date>.json` is written with a report. The first export (2026-10-20):
192 builds of 127 characters; the first with teams (2026-10-22): 809 teams on 147 of 190 builds; then without notes
and with every team: 895 teams.

What a file must be, whatever makes it:

- A version 3 file of one game that `BuildFile::read()` accepts (above).
- Keys as this installation's catalog has them. The catalog's keys are `Key::from()` of genshin.anotoki.eu's
  names (the Travelers `traveler{element}`), and the import compares the file's keys exactly. A build site's own
  spellings, nicknames and typos are mapped by the tools' own list in `local/`, merged into the catalog's aliases
  (`config/app.php`) while they run - one the analyzer's own aliases send elsewhere stops them -, and never
  added to `config/app.php` ([name-matching.md](name-matching.md)).
- Canonical stat keys ([stats-and-slots.md](stats-and-slots.md)), with `elemental_dmg_` for a goblet's
  "Elemental DMG".
- A character's builds in order of preference, the main one first; a qualifier only where a role has two builds;
  a build needing a constellation with its `min_rank`, a target that holds from one with its `from_rank`.
- A build's teams the best first, each with the build's own character among its members; teammates by the
  catalog's keys (one it lacks is kept, shown by its name).
- Every text neutral, naming no site. The import makes every text neutral anyway; the file itself stays with the
  tools, out of the repository.

Then, on the site: an editor imports the file on the Builds page - its dry run first - and looks at the builds
browser. A character newer than the catalog has its list refreshed by the import itself (at most hourly); **Refresh
catalog** does it at once.
