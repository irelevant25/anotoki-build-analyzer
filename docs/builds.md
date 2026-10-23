# Builds

What a build is since 0.5.0 - and the teams it is played in, since 0.6.0 -, the file builds come in, its import and
export on the admin panel's Builds page, what an import keeps of the editors' hand edits (since 0.7.0), and what the
owner's local tools must deliver. The site never fetches a build itself (the owner's decision of 2026-10-20,
[decisions.md](decisions.md)): the builds are put together from the build sites on the owner's computer by untracked
tools, and an editor imports the result as one file per game.

Code: `backend/src/Services/Builds/BuildFile.php` (the format and its strict reader), `BuildImport.php` (the
import, the export, the imports made), `BuildParts.php` (a build's parts as an import compares them), `BuildNotes.php`
(the site's own notes), `api/routes/admin_builds.php` (the Builds page's routes), `api/routes/builds.php` (the public
builds browser), `src/Models/Build.php`, `src/Repositories/Builds.php`, `src/Presenters/BuildPresenter.php`;
migrations `016_builds_without_sources.sql`, `020_build_teams.sql`, `022_build_hand_edits.sql`. Frontend:
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
a picture or a link; the stand-ins follow as "or Yelan / Mona", each a link where it has builds; a note - the site's own, written in the
builds editor (since 0.7.0; "The editor") - opens in the kit's disclosure, titled with the team's name ("Notes on
Vaporize": a screen reader tells one team's notes from another's). On an account's character page a teammate the account has - a member or a stand-in - wears a check (on
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
same way. Whether a weapon or a teammate is in the catalog is the page's to say - looked up each time the build is
shown (the import only names the teammates it lacks; it stores a weapon's `known` as it was then, which nothing
reads any more: since 0.7.0 `BuildPresenter` asks the catalog).

## Importing a file

On the Builds page (`/admin/builds`, editors and admins), **Import a file** checks the chosen file in the browser
(JSON, the format, its version, the game, at most 8 MB - `build-file.ts`), sends it as a dry run and shows the
answer as a preview: what would be created, updated, unchanged and removed, what is kept by hand, whether the file is
older than the one the builds now come from, the characters skipped, the changes one by one - and the questions (see
"Hand edits" below), each answered with the kit's segmented control, the editor's work standing unless the file is
chosen, with "Keep every edit" and "Take the file's everywhere" for all of them; then the builds made of an edit and
the file's changes without asking, the characters whose order an editor set, and the team notes left waiting.
**Import** sends the same text for real with the answers and the preview's plan; **Cancel** forgets it.
`BuildImport::import()`:

- **The file is the truth for its game - but for what editors did by hand.** Afterwards the game's builds are the
  file's, but for characters this installation's catalog lacks, which are skipped (an import never adds to the
  catalog) and named in the answer, and but for the editors' work, which an import keeps ("Hand edits"). Every build
  of a character the file does not have is removed - unless an editor made it, edited it or wrote notes on it. A
  file whose builds name no character the catalog has is refused ("Refresh the catalog on the Builds page, then
  import again."): importing nothing would remove every build. A file with an empty `builds` list does remove the
  file's.
- **A build found again by its identity keeps its id.** Its parts are compared one by one (`BuildParts`) - with
  what the file said of it last time (`builds.file_build`) and with what is stored - and it is updated when the
  file changed a part nobody edited, its place among the character's builds or its qualifier's spelling; only marked
  checked otherwise. `content_hash` is the SHA-256 of what the file said of it (its content's JSON: weapons, sets,
  main stats, substats, talents, targets, notes, game version - and its teams, last and only when it has some; a
  build without teams hashes as it did before 0.6.0). A build the file lacks is removed, one new to it is created -
  but for the editors' work, as below.
- **Times.** `checked_at` of every build the file has becomes when the file was made (`exported_at`) - NULL for a
  build no file carries (one an editor made, or kept when the file dropped it) -, and `changed_at` of every build
  whose content or place it changed becomes the later of when the file was made and when the build last changed (an
  edit made after the file was made keeps its date); a time later than now (another computer's clock) is taken as
  now.
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
  meanwhile - or a save in the builds editor - is refused at once (409, "Another import of builds, or a save in the
  builds editor, is being written."), nothing written; the lock goes
  with the transaction, also when the request dies. `SET LOCAL statement_timeout` and
  `idle_in_transaction_session_timeout` of 60 s: nothing holds the builds' rows for minutes. What changes is worked
  out again inside the lock, so an import that committed meanwhile - or an editor's save - is what this one changes.
  Removed builds go first and new ones last: no identity is ever there twice. The route allows 240 s
  (`BuildImport::MAX_SECONDS`), and an import that has started finishes even when the tab is closed.
- **A dry run** (`?dry_run=1`) answers in the same shape (`dry_run: true`), every edit standing, and writes no build:
  no lock, no record. It can still refresh a catalog list first, as above.
- **Recorded**, in the import's transaction: a row of `build_imports` - the game, the file's `exported_at`, what it
  did (the counts, the hand edits' with them), who imported it - and the audit log's `builds.imported` (target the
  game; created, updated, removed, characters, skipped, questions, file_taken, edits_kept).

The answer:

```jsonc
{"dry_run": false, "game": "genshin", "exported_at": "2026-10-20T10:00:00Z",
 "older_than_current": false,       // made before the file the builds now come from (a dry run: the latest import's; an import: the one before)
 "characters": 127, "builds": 192,  // what the file holds of characters the catalog has
 "teams": 809,                      // the teams of those builds
 "created": 4, "updated": 11, "unchanged": 177, "removed": 2,
 "edits_kept": 3,                   // builds of the file's that keep an editor's edit afterwards
 "made_here": 1,                    // builds no file carries afterwards: made here, or kept when the file dropped them
 "kept_removed": 1,                 // builds an editor removed that the file brings: they stay removed
 "file_taken": 0,                   // questions answered with the file (none in a dry run)
 "skipped": [{"key": "...", "name": "...", "builds": 1}],
 "teammates_unknown": [{"key": "...", "name": "..."}],   // teammates of the builds imported the catalog lacks (the first 50, neutral): kept, shown by their names
 "teammates_unknown_total": 1,                           // how many, all told
 "questions": [{"id": "b42", "kind": "edited", "character": {"key": "hutao", "name": "Hu Tao"}, "role": "main_dps", "qualifier": "", "min_rank": 0,
                "parts": ["weapons"], "taken": ["substats"], "notes": false}],   // see "Hand edits"; all of them, by character name
 "plan": "<64 hex digits>",         // the questions' plan, sent back with the answers
 "merged": [{"character": {...}, "role": "...", "qualifier": "", "min_rank": 0, "kept": ["weapons"], "taken": ["substats"]}],   // made of an edit and the file's changes without asking
 "order_kept": [{"key": "hutao", "name": "Hu Tao"}],     // characters whose builds keep an editor's order where the file's differs
 "notes_dormant": [{"character": {...}, "role": "...", "qualifier": "", "min_rank": 0, "notes": 1}],   // team notes whose team the import takes away
 "changes": [{"change": "created", "character": {"key": "furina", "name": "Furina"}, "role": "support", "qualifier": "", "min_rank": 0}],
 "changes_listed": 17}              // at most 500 listed, by character name and then as the file has them, the removed after
```

Refused: 422 with the reason when the file does not fit or the choices name no question (nothing written), 409 while
another import writes, 409 `plan_changed` when the questions are not the preview's any more (the page checks the file
again and says so).

## Hand edits (since 0.7.0)

Editors change builds by hand - the editor's page comes with 0.7.0 -, make builds of their own, remove others and
write the site's own notes; every import keeps what they did, and asks per build only where the file and an edit
changed the same part (the owner's decision of 2026-10-20, "Hand edits later, so the identity is stable now", and of
2026-10-22, [decisions.md](decisions.md)).

- **What the file said stays beside every build** (`builds.file_build`, migration 022): its variation, place and
  content as the last file brought them - NULL for a build made here, which no file carries. A part of a build is
  **edited** where what is stored differs from it.
- **Parts** (`BuildParts::PARTS`): weapons, artifact sets, main stats, substats, talents, stat targets, teams, game
  version - each compared in a canonical form: without a weapon's or set option's `rank`, a weapon's `known`, and the
  texts the format still carries but the site no longer shows (a weapon's, set option's and team's `note`, a target's
  `text`; the build's own `notes` are no part); priorities, names and every list's order as they are; a target's
  bounds as floats; main stats' slots in one order. Notes and a character's order of builds are no parts.
- **R1, a build of the file's found again**, per part: nobody edited it - the file's version; the file did not change
  it - the edit; the file now says what the edit says - the edit, and what the file said before stays beside it, so it
  stays an edit (an export of this site imported again changes nothing); both changed it - a **question** for that
  build (`edited`: the parts both changed, and the parts only the file changed, which are the file's either way). The
  edit stands unless the file is chosen; either way what the file says now lands beside it, so the same file asks
  nothing again. The qualifier's spelling and the build's own `notes` are always the file's.
- **R2, a build made here that the file brings too**: the same - nothing changes (its qualifier's spelling neither), it
  stays made here; otherwise a question (`made_here`: the parts that differ) - kept unless the file is chosen, the
  file's version beside it from then on (a build of the file's with an edit, the qualifier spelled as the file spells
  it).
- **R3, a build an editor removed** (`build_removals`: its identity, what the file said of it, its notes): the file
  bringing it as it said it then - it stays removed, no question; changed - a question (`removed_here`) - kept removed,
  what the file says now beside it, unless bringing it back is chosen: the build as the file has it now, with its
  notes. A removal is never forgotten by an import.
- **R4, a stored build the file lacks**: made here - kept; edited, or with the site's notes - a question
  (`removed_by_file`: the parts edited, `notes`) - kept, the editor's from then on (no file version, `checked_at`
  NULL), unless removing it is chosen; any other - removed.
- **R5**: a build new to the file is created.
- **R7, order**: a character's builds come in the file's order, its builds made here after them (a build the file
  dropped and the editor kept among them: an editor can move it up again) - unless the order is the editor's: then in
  theirs, the file's new builds after them (`order_kept` names the characters where that differs from the file's).
  The order is the editor's where an editor said so (`build_orders`: the editor's save writes it when it changes the
  order; only an editor gives the order back to the file) - never guessed from the builds' places, which an export
  imported again or a file agreeing with the editor would make wrong -, and where every build of the character was
  the editor's: the file's first builds of it go after theirs, and the import writes the order down as the editor's.
  `file_build` always holds the places the latest file gave. Order never makes a question.
- **R8, notes**: an import never touches the site's notes (`builds.site_notes`); a team's note goes by the team's
  members (`BuildNotes::teamKey()`), and one whose team the import takes away waits for a team of those members
  (`notes_dormant` names the build).
- **Answers**: `POST …/import?file=<ids>|all&plan=<hash>` - `file` the questions answered with the file (`b<build id>`,
  `r<removal id>`, comma-separated, or `all`), `plan` the preview's. Inside the lock the questions are worked out again:
  another plan - an editor saved, another import came - is refused (409 `plan_changed`), nothing written. An import
  that answers nothing needs no plan: every edit stands, and what the file says lands beside it all the same. The plan
  is a hash of each question and what it is about (its parts' canonical forms), not of names, times or `known`, so a
  catalog refresh in between changes nothing.

## The editor (since 0.7.0)

The admin panel's builds editor (`BuildEditor`, the editors' routes below) loads a character's builds and saves them
in one go.

**The page** (`/admin/builds/:game/:key`, `frontend/src/app/pages/admin/build-editor/`; English, as the admin panel)
is reached from the Builds page's **Edit builds** card - every character of the catalog to pick by typing (the kit's
combobox, with portraits, each saying its builds and what was done to them here), and the characters changed here as
link cards - and from a character's builds page, where an editor or an admin finds **Edit builds** (`buildDetail.edit`,
migration 023). It holds the character's builds while an editor changes them (`editor-draft.ts`: plain functions
over the loaded answer; a part nobody touched goes back as the very object that came, so the server sees no edit
there):

- a tab a build (the kit's tabs), named by its variation, with what it is: "Edited" (it differs from what the file says
  of it), "Made here", "New", "Brought back" or "Unsaved";
- **Add build**: a new one (the first role with no build at C0, else "New" to be renamed), a copy of the open one (as a
  build of the editor's own, its parts and notes with it, "… copy"), a removed build of the file's brought back - put
  where the file puts it, with rows of its own (nothing typed in it before a delete comes back with it) - or one
  deleted on this page put back in the place it had;
- moving the open build earlier or later (the kit's buttons, the focus kept on the one pressed and the move said to a
  screen reader: `move-buttons`) makes the order the editor's; **Give the order back to the file** puts the file's
  order back (and says so in the save where the stored order was an editor's); deleting a build asks first - one of
  the file's is remembered once saved, and Bring back restores it;
- the open build's cards (`part-card`: "Edited" against the file, "Unsaved" against what was loaded, **Use the
  file's**, which asks first): its **Variation** - role, qualifier, constellation (fixed for a build of the file's or
  one brought back, with a line saying why: a copy of it can be renamed) and game version -, its **Notes** (the
  site's own: English, and Slovak in a block of `lang="sk"` beside it - `note-fields`) and its parts, each in its form:
  - **Weapons** (`weapons-form`): best first, each picked by typing its name (the kit's combobox with pictures: the
    catalog's weapons of the character's type - every type where "Offer every weapon type" is ticked -, none another
    row has), its refinement (any, or R1-R5), moved and removed; a weapon the catalog lacks keeps its stored name,
    marked; Add weapon adds an empty row (a problem at Save until a weapon is chosen); each weapon's option is made
    once, so what is typed in one picker outlives a change in another row (the kit's field shows the chosen option's
    name again whenever that option changes);
  - **Artifact sets** (`sets-form`): an option a box of its pieces - each piece its count (2pc or 4pc, the kit's
    segmented control), its sets as the kit's chips in the order they were added, a combobox to add one, and a label:
    the set's name for one set (written for the editor), what the sets have in common for several (the editor's to
    write: "any 18% ATK set") -; the picker drawn anew after each set added, empty and in hand for the next; "Wear"
    (all, or any 1 to n-1) for an option of several pieces; options and pieces moved and removed, an option keeping
    one piece - the focus then on the picker of the one now in its place, never on a Remove a second press would use;
    the kind of set shown only while the game has more than one;
  - **Main stats, substats, talents** (`ranked-list`, a slot each for the main stats): each row a stat or a talent no
    other row has, its priority (a number, 1-10 - any higher one a file wrote kept - under the column's name
    "Priority", each field named in full to a screen reader) and its remove button; a row whose priority changes moves
    to its place (ties as they stood, the focus with it, the move said to a screen reader) - priorities stay numbers,
    never renumbered; a slot with no row is left out of the build (any main stat does), talents with none are none
    (`null`);
  - **Stat targets** (`targets-form`): each row a group inside its list item, named by its place ("Target 2: Energy
    Recharge, from C1"), laid out by the room its card gives it -
    its stat, where it holds from ("From the start", "From C1"...), its minimum and maximum (typed; a percent stat's
    named "Minimum (%)" with "%" beside the field, a "%" typed after the number taken; what is typed stays as typed
    while it is no number - said wrong once the field is left, holding the save back at once, and a change of the
    page's: Save is offered, and says it) -, moved and removed; a target needs a minimum or a maximum, the minimum no
    higher than the maximum, one target a stat from each constellation; a target keeps its numbers alone;
  - **Teams** (`teams-form`, the card across the page's width): best first, each a box of its name (one line, 60
    characters at most, required - as members read it), its members and the site's note on it (English, Slovak
    optional - `note-fields`); a member is its character (picked by typing its name, the kit's combobox with portraits,
    none the team has twice; one the catalog lacks keeps its stored name, marked), the role it plays ("Not said" or a
    role), the constellation the team asks of it ("Any", C1-C6) and up to five stand-ins (the kit's chips and a picker,
    none of the team's members); the build's own character is in every team - no picker, no stand-in, no remove, only
    moved -, and a new team starts with it (in the build's role) and one member to choose; members and teams moved and
    removed, a team holding two to the game's team size of them. A team's note stays with its team as its members
    change (a save files it under the members it has then); a team removed with a note asks first, and its note then
    waits below, under "Notes waiting for their team", for a team of the same members (where a note can wait: two
    chosen members at least, its English written - else it goes with the team; `waitingOf()`). A waiting note stays
    there while the teams are edited: a save gives it to a team of exactly its members with no note of its own
    (`BuildNotes::fromEditor()`), which its entry names (`takerOf()`); Move gives it to a team that has none now - the
    choice kept with that team wherever it moves -, Delete drops it. "Use the file's" files the notes under the teams'
    chosen members (`withTeams()`). The checks are the builds file's (`teamProblems()`): a name, two members at least,
    each chosen and once, no stand-in of the team, no two teams of the same members - and a waiting note with no
    English, or whose team on the page has a note of its own (a save keeps one of the two); the problem focused at Save
    is the first on the page;
  - every list's rows keep their identity through an edit (`rowId()`): the row being changed keeps its focus, a
    moved row its name, and a problem the server names at "weapons[1].refinement" lands at that very field; a copy's
    rows are its own;
  - a part changed back to what was loaded - by hand, or by Use the file's where the file says what was loaded - is
    what was loaded again (`withPart()`: compared as an import compares it, `partText()`), a main stat's slots in
    their order too - the page holds no change nobody sees, and the rows on the page stay those rows (each loaded row
    takes the very id of the row it stands for); Use the file's gives the part rows of its own, never the file's
    very ones;
- **Save** and **Discard** in a bar at the foot of the window while anything is unsaved (the page kept scrolling a
  field above it, never under it); nothing on the page takes a change while a save is on its way (`inert`): its answer
  is the page's next state. A save is checked on the page first (`problems()`: two builds of one name - said at the one
  whose name can change -, a build of the editor's own taking a removed build's name, a build changed on the page that
  holds nothing - no weapon, artifact set, main stat or substat: members would see it empty; one a file brought empty
  is the file's to fill, and holds no save back -, a text over its cap, a note with Slovak but
  no English, what a part's form can leave wrong for a moment - a weapon row with no weapon, a piece with no set, more
  pieces to wear than there are, a target's minimum over its maximum or out of range, two targets of one stat from one
  constellation -, and the page's own rules, asked only of a part changed on the page - not one as it was loaded or
  as the file has it (a build brought back, Use the file's): a file's build may break them and still be read - a
  weapon listed twice, several sets with no label, a target with no bound; `changedParts()`), then by the
  server; a problem is shown at its field - the build's tab
  opened, the field scrolled to and focused (`data-path`, the server's `path`) - or above the build's cards where
  no field has it. 409 `changed`: the page says the builds changed meanwhile and keeps what was typed until
  **Reload** (asked first); 409 `busy`: a message to try again; any other failure is said in the bar;
- leaving with changes not saved asks first (the route's `anotokiUnsavedChangesGuard` asks the page), closing the tab
  too (the browser's own question), and the top bar's **Sign out** asks while the editor holds changes
  (`core/build-editor-changes.ts`; the admin panel's English). Nothing is kept in the browser's storage.

The members' pages show the notes (`BuildPresenter`: a build's `notes` and each team's `note` as `{en, sk?}` or null,
from `site_notes`): in Slovak to a reader of Slovak where an editor wrote it, else in English - marked `lang="en"`
while the page is in another language (`core/i18n/site-note.ts`). The texts the builds file carries but the site no
longer shows - the build's own `notes`, a weapon's, a set option's and a team's `note`, a stat target's `text` -
are sent nowhere (the character's analysis neither): stored, read and exported as the format has them, and written
empty by the local tools.

- **Loading** (`GET /api/admin/builds/{game}/characters/{key}`): each build in the editor's shape - the builds
  file's (`BuildFile::entry()`: every text neutral, the order the rank), without its character and without the texts
  the format carries but no page shows (the build's `notes`, a weapon's, a set option's and a team's `note`, a
  target's `text`) -, where it is from (`file` or `made_here`), what the file said of it (`file`: for "use the
  file's") and where the file puts it among the character's builds (`file_position`: where a page puts a build brought
  back), the parts that differ from that (`edited`, `BuildParts`), its notes (`notes`: the build's, each team's
  beside its team, those waiting for a team of their members with the members' names), its dates; the builds an
  editor removed, with what the file said of them, where the file put them and their notes; whose order it is (`order`: `file` or
  `editors`); and a `version` - a hash of what an editor could see change (each build's variation, place, parts,
  what the file said of it, its notes; the removals; the order's owner), never of a time: every import moves when a
  file last carried a build.
- **Saving** (`PUT`, the same address): `{version, builds: [{id, removal, build, notes}], order?: "file"}` - the
  character's builds as the editor leaves them, in their order:
  - `id` a stored build of this character's (or null for a new one), `removal` a removed one brought back (or
    null), `build` in the editor's shape - what a file's build has but the editor's has not (its character, its notes,
    the notes of its parts, a target's text) refused, never dropped -, `notes` `{build, teams: [a note or null for each
    team, in the teams' order - exactly as many: a note left out would be a note lost], waiting: [{key, note}]}`, a note
    `{en, sk?}`, a waiting note's key the keys of the members of the team it waits for (2 to the team size, each once,
    the build's own character among them; sorted as stored);
  - each build is read as a file's build is (`BuildFile::readEntry()`: every field, shape and cap; its first problem
    answered 422 `invalid_build` with `build` - its index - and `path`, "weapons[0].refinement"), its notes by
    `BuildNotes` (English required, Slovak optional, a build's at most 20 000 characters, a team's 5 000, no control
    characters; 422 `invalid_note`); a text the editor types - a qualifier, a game version, a team's name, a set
    piece's label, a note - that names another website or holds a web address is refused (422 `names_another_site`),
    never reworded behind the editor's back;
  - a build of the file's keeps its role, qualifier and constellation, its name in the file (422: copy it into a
    build of your own to change them); a build of the editor's own takes any name; no two builds share one; no build
    takes the name of a build of the file's that was removed (422: bring that one back instead);
  - a stored build the save lacks is removed: one of the file's remembered among the removals with what the file
    said of it and its notes, so no import brings it back unasked; one of the editor's own deleted. A removed build
    sent back is brought back with what the file said of it (its notes as sent);
  - the order is the save's: one other than an import would give (the file's builds by the place the file gave
    them, the editor's own after them) is said to be the editor's (`build_orders`); `order: "file"` puts the builds
    in the file's order and gives it back;
  - a part sent back as the page got it stays as stored - its texts as they were, also where the names list has
    learned a name since and the page got it neutral -, so only what the editor changed is an edit; a name compared
    the same way (the stored spelling kept). What members see changed moves `changed_at` and `edited_at`; a new or brought-back build has no `checked_at`
    until a file carries it; whether the catalog knows each weapon is worked out again; a save that changes nothing
    writes nothing;
  - one save at a time and never during an import: it takes the import's lock (409 `busy`, nothing written) and is
    checked inside it against the version loaded (409 `changed`: reload; nothing written). Recorded in its
    transaction: the audit log's `builds.edited` (target the character: what it created, updated - with the parts,
    renamed, moved, notes -, removed and brought back, and whose order it made it).
- **What it picks from** (`GET /api/admin/builds/{game}/catalog`): the game's facts - roles, the highest
  constellation and its prefix, the refinement's prefix, each slot's main stats ("Elemental DMG" where a slot has an
  element's), substats, stats, talents, the kinds of sets and their pieces, a team's size, the caps
  (`BuildFile::limits()`, the notes', the builds a character can have) and their English names (the admin panel's)
  -, and the catalog's characters, weapons and sets with their pictures, by name.
- **The Builds page's list** (`GET /api/admin/builds`'s `editing`, `BuildEditor::overview()`): every character with
  builds or removed builds, by name: how many builds, how many of the file's with an edit, made here, removed, with
  notes, and whether its order is the editor's.

## Exporting

**Export** on the Builds page (`GET /api/admin/builds/{game}/export`) saves the game's builds as a file named
`builds-{game}-{the day it was made}.json`: a backup. It holds the builds as they are here - hand edits and builds made
here with them, removed builds not -, without the site's notes, which never travel in a file. `BuildImport::export()`
writes

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
| `GET /api/admin/builds` | per game: `characters`, `builds`, `checked_at` (when the newest file carrying them was made), `changed_at`, `imports` (the last 10, newest first: `created_at`, `file_exported_at`, `summary`, `imported_by` as `{id, name}` - null once that person's data is deleted), `catalog` (each list's `entries`, `refreshed_at` and last `problem`) and `editing` ("The editor") |
| `GET /api/admin/builds/{game}/export` | the file, as a download |
| `POST /api/admin/builds/{game}/import[?dry_run=1][&file=<ids>\|all&plan=<hash>]` | the body is the file's JSON; the answer above; `file` and `plan` as in "Hand edits" |
| `GET /api/admin/builds/{game}/catalog` | what the editor picks from ("The editor") |
| `GET`, `PUT /api/admin/builds/{game}/characters/{key}` | a character's builds, loaded and saved ("The editor"); 404 for a character the catalog lacks |
| `POST /api/admin/catalog/{game}/{list}[?force=1]` | one catalog list (`characters`, `weapons`, `sets`) refreshed - a conditional request unless forced: `{outcome: changed \| unchanged, written, list, entries, refreshed_at, problem}`; 409 while another refresh of it runs, 502 with the reason when the provider is closed, lacks the list or cannot be read; audited `catalog.refreshed`. The page's **Refresh catalog** asks for the three one after another, unforced |

Public, behind `optionalSignIn($app)`: everybody reads the same, and while the IAM requires sign-in only a person
signed in does.

| Route | Answer |
| --- | --- |
| `GET /api/builds` | per game: how many characters have builds, how many builds, `checked_at`, `changed_at` (the dashboard) |
| `GET /api/builds/{game}` | the characters with builds, newest release first: `key`, `name`, `element`, `rarity`, `weapon_type`, `icon_url`, `released_at`, `region`, `version`, `version_name`, `builds` (how many), `roles` (in the game's order), `changed_at` - the builds browser and what it filters by |
| `GET /api/builds/{game}/{key}` | a character and its builds in their order (`BuildPresenter`: every text neutral once more, weapon and set icons from the catalog; each team's members and stand-ins with `known`, `icon_url`, `rarity`, `element` and `has_builds` looked up as the build is shown, a member with `own` - the build's character; the build's `notes` and each team's `note` the site's own, `{en, sk?}` or null; no weapon's or set option's note, no target's text) |

## The tables (migrations 016, 020, 022)

- `builds`: `game_character_id` (deleted with the character), `role` (CHECK), `qualifier` (`VARCHAR(40)`, `''` by
  default, trimmed - CHECK), `min_rank` (0-6), `position`, the JSON columns `weapons`, `sets`, `main_stats`,
  `substats`, `talent_priority`, `stat_targets` (the file's shapes; weapons and set options with their `rank`,
  weapons with `known`) and `teams` (migration 020: the file's shape, NULL for none), `notes`, `game_version`,
  `content_hash` (of what the file said of it), `checked_at` (when the file that last carried the build was made;
  NULL for one no file carries - migration 022), `changed_at` (when it last changed as members see it),
  `created_at`, `updated_at`; since migration 022 `file_build` (what the last file said of it: its variation, place
  and content as the columns store them; NULL for a build made here), `site_notes` (the site's notes, `BuildNotes`;
  NULL for none) and `edited_at` (the last save in the editor that changed it); unique on the identity.
- `build_removals` (migration 022): the builds of a file an editor removed - `game_character_id` (deleted with the
  character), `role`, `qualifier`, `min_rank` (the identity, unique as the builds'), `file_build` (what the file said
  of it), `site_notes` (its notes, back with it when it is brought back), `removed_at`. No removal shares its identity
  with a build.
- `build_orders` (migration 022): the characters whose builds an editor put in an order of their own -
  `game_character_id` (the key; deleted with the character), `ordered_at`.
- `build_imports`: `game`, `file_exported_at`, `summary` (characters, builds, created, updated, unchanged, removed,
  skipped and since 0.7.0 edits_kept, made_here, kept_removed, file_taken - counts, nobody's name), `imported_by` (a
  person's id, never a visitor's: CHECK > 0; NULL once that person's data is deleted), `created_at`; indexed on
  (`game`, `created_at`).

Migration 016 dropped the build sources, their builds, the sync runs and the syncs' locks, and carried nothing
over: the builds come back with the first import. Data model: [data-model.md](data-model.md).

## Where builds show

- The builds browser (`/builds/genshin`): each character with builds a link card with its roles, filtered by name,
  element, weapon type, rarity, region and version, the filters kept in the address (`core/query-filters.ts`); a
  character's builds in their order on `/builds/genshin/{key}`, each with its teams and the site's notes; an editor or
  an admin finds **Edit builds** there, the way to the character's builds editor.
- An account's character page: a tab per variation with its score, the closest build marked, one the character's
  constellation does not reach locked; the build's detail - the site's notes, "derived" substats, a target from a
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
