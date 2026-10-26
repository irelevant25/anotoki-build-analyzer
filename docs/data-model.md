# Data model

The schema is `backend/migrations/*.sql` - the set `build_analyzer` - with the anotoki library's own sets
around it, in this order (the library's README): `anotoki_admin` first (`vendor/anotoki/lib/backend/migrations/admin`:
the audit log's and feedback's tables), then this site's, then `anotoki_translations` (`…/translations`: the
check of the words' tables and the library's words, since version 0.4.0) and `anotoki_people` (`…/people`: where
the scrub of the IAM's deleted accounts stands), applied in order by one engine - the anotoki family's (anotoki-lib's
`Anotoki\Lib\Migrations\Migrator`, on this site's files through `src/Db/Migrations.php`) - whichever way it
runs: `php migration.php`, "Apply pending" on the admin panel's Migrations page, or `setup.php`. There is no
separate schema file; a database is what you get by replaying the migrations. `migrations(id, filename,
applied_at)` records what has run - the table this site's own engine made, which the library reads and writes
as it is (a new database gets the same: `tests/migrations.test.php` holds it to that), this site's files by
their names and the library's as `anotoki_translations/001_languages_and_strings.sql` - each file in a
transaction of its own with its record, stopping at the first that fails. One apply at a time, whoever starts
it: each takes the same PostgreSQL advisory lock (`Migrations::LOCK`, the number this site's engine took),
and another is told it is busy and does nothing; a file recorded meanwhile is passed over. While a file waits
the site is closed to everybody but its ADMIN on the Migrations page (the site gate,
[architecture.md](architecture.md)).

- `001_adopt_laravel_database.sql` brings a database made by the first (Laravel) version forward, with all
  its rows (see [operations.md](operations.md)); on a new database it does nothing.
- `002_schema.sql` creates the tables (`CREATE … IF NOT EXISTS`, constraint names in the Laravel style
  `{table}_{columns}_unique` / `_foreign` / `_index`, so an adopted database and a new one are the same, down
  to the names - `tests/migrations.test.php` compares them), adds the `CHECK`s and ends with a verification
  gate.
- `003_languages_and_translations.sql` creates the three tables of the site's words (below), the two languages
  it is released in and every string, English and Slovak, in one file: a database never has the tables
  without the strings. It alters no existing table, so behind the site gate everything but the words works on a
  database that has not run it yet. Later string migrations follow its pattern.
- `004_visitors_and_family_bar.sql` (version 0.3) adds the visitor accounts to `users` (below) and every
  word 0.3 brings - the visitor notice, the ways in for somebody new, the "Your data" card, the new error
  sentences, and the family's top bar and footer (`brand.*`, `topbar.*`, `footer.version`, `language.button` -
  the library's `anotoki.language.button` since 006) - in 003's form, retiring the keys of the sign-in modes, the old owner's menu and the toolbar the bar
  replaced (`nav.dashboard`, `nav.import`, `nav.builds`, `toolbar.menu`, `toolbar.version`,
  `toolbar.yourAccount`, `toolbar.account`, `language.label`), and rewording the descriptions that told of
  the toolbar. What it changes: the schema part is additive; its new strings never write over one reworded in
  the browser; the 15 retired keys go with their strings, reworded ones included; 13 descriptions are
  rewritten. Gated: the schema, every key with an English string, the 67 keys it adds with a Slovak one too
  (an older key's Slovak may have been cleared in the Translations grid on purpose - it then reads the
  English, and 004 applies all the same), the retired ones gone, no description still telling of the
  toolbar, and the number of keys (406 + 67 - 15 = 458). It is complete;
  applied on the host at once after the upload (Migrations page, ADMIN), before the IAM's 007
  ([operations.md](operations.md), "Deploying").
- `005_retire_not_set_up_page.sql` deletes the six keys of the site's own "Not set up yet" page
  (`notSetUp.*`), with their strings: the anotoki family's site gate shows its own page now, in its own words
  (the owner's decision of 2026-10-07). Gated: the six gone with every string of theirs, and the number of keys
  (458 - 6 = 452).
- `006_strings_through_the_library.sql` (version 0.4.0: the site's words kept by the anotoki library's
  translations module) moves the language button's two keys to the library's namespace - `language.button` to
  `anotoki.language.button`, `language.notLoaded` to `anotoki.language.notLoaded` - with every language's
  string as it is, reworded ones included, and who reworded it when (`updated_at`, `updated_by`); the old keys
  go. It runs before the library's `anotoki_translations/002`, which writes a library key's strings only
  while it has none, so a rewording is never written over. Gated: every moved string there unchanged, nothing
  else under the two keys, the old keys gone, both with English, and the number of the site's own keys - every
  gate counts those only, `name NOT LIKE 'anotoki.%'`, since the library adds its own when it likes
  (452 - 2 = 450). Then the library's set: `001` finds this site's three tables, checks their shape and
  changes nothing; `002` adds its words (English and Slovak; descriptions its own).
- `007_people.sql` (version 0.4.0: the people module, anotoki-lib 0.4.1) adds `users.anonymized_at`
  (TIMESTAMPTZ, NULL) - when the IAM deleted the account; written by the library's scrub only - and touches no
  row. Gated: the column there and NULL everywhere, the rows as many and as named as before (people and
  visitors apart), `users_visitor_check` still there.
- `008_drop_laravel_leftovers.sql` (the owner's decisions of 2026-10-11: no email address, nor the address an
  old session came from, nor an old job's payload, on any anotoki site) drops what the first version left and
  001 kept where it was not empty, where it is: its login tables - `laravel_users` (old addresses and password
  hashes), `laravel_password_reset_tokens` (addresses and reset tokens), `laravel_sessions` (network addresses,
  browsers) - and its queue's, which nothing here reads - `laravel_jobs`, `laravel_job_batches`,
  `laravel_failed_jobs` - with their sequences (`laravel_users_id_seq`, `laravel_jobs_id_seq`,
  `laravel_failed_jobs_id_seq`). Only `laravel_migrations` stays (what that version ran; nobody's data). No
  CASCADE: anything pointing at one of the six makes it fail. Gated: none of them there, every other table still
  there with as many rows (the audit log's may only have grown: the admin tools that stay open during an update
  write it), nothing new - the two lists of tables sorted alike, `COLLATE "C"`, since the live database's first
  Apply (its collation put them in two orders, and the gate refused it; the one change made to the file before it
  ever applied there). `tests/migrations.test.php` applies it on a throwaway database whose ICU collation skips
  punctuation, as the live one's does (PostgreSQL 15 and newer; skipped, and said so, on a server that cannot).
- `009_sign_in_and_feedback_words.sql` moves the five words of the old sign-in page that the library's sign-in
  screen has under its own keys - `signIn.notCompleted`, `stateMismatch` (→ `anotoki.signIn.expired`),
  `storageUnavailable`, `failed`, `signingIn` → `anotoki.signIn.*` - with every language's string as it is, who
  saved it and when, before the library's `anotoki_translations/003` (where that ran first, its own words give
  way to the site's - never an owner's rewording of the library's key); retires `signIn.intro`,
  `signIn.notConfigured` and `signIn.unreachable` with their strings; adds `footer.feedback` and the feedback
  form's four places (`feedback.section.*`) in English and Slovak. Gated: every moved string as it was (or an
  owner's rewording of the library key), no `signIn.*` left, the new keys in both languages, and the site's own
  keys (450 - 8 + 5 = 447).
- `010_top_bar_words_through_the_library.sql` (the site's own pages on the family's kit: the top bar is the
  library's now) moves the twelve words the site had for its own bar under the library's keys -
  `topbar.phoneMenu`, `menuButton`, `menu`, `roleAdmin`, `roleEditor`, `account`, `admin` → `anotoki.topbar.*`,
  `common.signOut` → `anotoki.topbar.signOut`, `theme.label`, `light`, `dark` → `anotoki.theme.*` and
  `theme.system` → `anotoki.theme.auto` - with every language's string as it is, who saved it and when (where the
  library's 002 ran first, the site's words take the place of the library's own - the Slovak "Správca",
  "System" - never of an owner's rewording of the library's key); copies `common.signIn` under
  `anotoki.topbar.signIn` (the start page keeps its own); retires `stepper.optional` and `stepper.editable`,
  which only Angular Material's stepper said. Gated: every moved string as it was (or an owner's rewording), the
  library keys in English, the fourteen gone, `common.signIn` and `stepper.completed` there, and the site's own
  keys (447 - 14 = 433). Then the library's `anotoki_translations/003` (the sign-in screen's words) and `004`
  (the feedback form's), and `anotoki_people/001`.
- `011_legal_pages.sql` (anotoki-lib 0.6.2: the family's Terms, Privacy and Contact pages) adds this site's facts
  on them - `legal.*`: what it is, the record of a person, the game accounts, the editors' traces, the limits, the
  showcase answers, its own log, visitors, feedback, the audit log, the backups, the browser storage the library
  has no words for, the showcase service, and `legal.fanClause` (the fan clause naming both games, read in place of
  the library's) -, the pages' tab titles (`title.terms|privacy|contact`) and the footer's fan clause
  (`footer.fanMade`), in English and informal Slovak; and rewords the descriptions of five keys that still told of a
  snack bar (`common.close`, `dashboard.deleted`, `data.exported`, `visitor.moved.one`, `visitor.kept.one`: the
  kit's toasts). No string holds an address (`tests/translation_catalogue.test.php`). Gated: every new key in both
  languages, no description of the site's own telling of a snack bar, and the site's own keys (433 + 52 = 485).
  Then the library's `anotoki_translations/005`-`011` (the legal pages' words, the kit's newer pieces', its own
  rewordings of the Privacy page - each only where a key has no string, or nobody saved it).
- `012_stepper_and_tab_words.sql` (anotoki-lib 0.6.2: the kit's newer pieces where the site drew its own) adds
  `importWizard.steps` (the name a screen reader hears for the import guide's row of steps, the kit's stepper) and
  `character.tabScore` (what is heard after a build's tab's name: its score, which the tab shows as the kit's
  badge), in English and informal Slovak, and deletes `stepper.completed` with its strings - the kit's stepper
  says a step is done in the library's words (`anotoki.stepper.*`, its 007). Gated: both keys in both languages,
  the old one gone, and the site's own keys (485 + 2 - 1 = 486).
- `013_footer_words_through_the_library.sql` (anotoki-lib 0.6.3: the family's footer in the site's own's place)
  moves `footer.version`, `footer.feedback` and `footer.fanMade` to `anotoki.footer.version`, `feedback` and
  `fanHoyoverse`, and copies `whatsNew.title` (which stays) to `anotoki.footer.whatsNew` - 010's way: every string,
  writer and time, never over an owner's rewording of the library's key. Gated: the same, the three gone, and the
  site's own keys (486 - 3 = 483). Then the library's `anotoki_translations/012` (the four keys' descriptions).
- `014_star_rail_removed.sql` (version 0.5.0: Genshin Impact alone, the owner's decision of 2026-10-20 -
  [decisions.md](decisions.md); the code kept ready for another game) deletes every row of Honkai: Star Rail -
  its game accounts with everything in them (the foreign keys cascade: characters, weapons, relics, import runs),
  its failed import attempts that found no account, its build sources with their builds and sync runs, its
  catalog runs, its catalog, and its showcase answers in the cache table (`showcase:hsr:%`) - and narrows the
  closed sets to what the code knows: each `<table>_game_check` (`game_characters`, `game_weapons`, `game_sets`,
  `accounts`, `import_runs`, `build_sources`, `sync_runs`) to `'genshin'` (`App\Enums\Game`), and
  `game_sets_kind_check` to `'artifact'` (`Game::setKinds()`). A game added later widens them in a migration of
  its own. Gated: no row of another game nor set of another kind left, each of the eight constraints naming what
  the code knows and nothing of the other game, and every row of Genshin Impact and every person still there,
  table by table, as counted before the deletes. A backup made before it holds the other game's rows: it is
  restored offline only.
- `015_star_rail_words.sql` deletes the other game's 56 keys with their strings, a rewording an editor gave them
  included (the foreign keys cascade): the 44 of the Star Rail import guides (`importGuide.hsr.*`); the import
  kinds only Star Rail had (`import.error.showcase_empty_hsr`, `hsr_not_export`, `hsr_empty`;
  `import.warning.showcase_relic_skipped`, `hsr_relics_skipped`) and the two that need two games to happen
  (`import.error.account_game_mismatch`, `file_game_mismatch` - a second game brings them back); the import
  wizard's first step, which asked for the game (`importWizard.step.game`, `importWizard.game.heading`,
  `importWizard.game.holds`), and the builds page's game switch (`builds.game`); and `legal.fanClause`, the site's
  own fan clause naming both games - the library's (`anotoki.legal.fanHoyoverse`) names Genshin Impact alone,
  which is exactly right now. Six sentences that named the other game (`dashboard.subtitle`,
  `dashboard.emptyText`, `home.text`, `legal.about`, `legal.accounts.what`, `import.error.file_unknown_format`)
  say Genshin Impact alone, written only where they still read as 003 / 011 seeded them, so a rewording made in
  the browser is kept; every description that told a translator of the other game's words (Light Cone, Relics,
  Traces, Eidolons, "S1" ...) tells of Genshin Impact's alone. Gated: the retired keys gone with every string, the
  six with English, no description of the site's own telling of the other game, and the site's own keys
  (483 - 56 = 427).
- `016_builds_without_sources.sql` (the owner's decision of 2026-10-20: the site no longer syncs builds; they are
  put together elsewhere, [builds.md](builds.md), and an editor imports them as one file per game on the admin
  panel's Builds page) drops `builds`, `sync_runs`, `build_sources` and `cache_locks` - what referred to the
  sources first, then the sources - and carries none of it over (the site had one user when this was decided,
  the owner; the builds come back with the first import, and until then the site has none). It creates the new
  `builds`, each a variation of a character's, and `build_imports`, a row per import (both below, "Builds").
  Gated: the three old tables gone, every column of the two new ones with its type and nullability and none
  more, their constraints (the foreign keys' delete actions included) and both indexes. A backup made before it
  holds the old tables: like one made before 014, it is restored offline only.
- `017_builds_without_sources_words.sql` retires 23 keys with their strings, a rewording included - the sources'
  and the syncs' words (`builds.sourcesPage`, `nav.sources`, `toolbar.syncing`, `toolbar.syncingLabel`; the
  dashboard's `dashboard.sources`, `manageSources`, `noBuildsAdmin`, `sourceCharacters.*`, `syncing`,
  `notSyncedYet`), what the sources agreed on (`character.consensus.*`) and what a build said of its source
  (`buildDetail.substatsBorrowed`, `notesFrom`, `updatedAtSource`, `checkedNever`; `account.bestMatch`); adds 18
  in English and informal Slovak - a build's role (`builds.role.*`, with `builds.role.unknown` for a role a newer
  server names), the link to the admin panel's Builds page (`builds.adminPage`), a build the character's
  constellation does not reach (`character.needsRank`, `character.rankNotReached`), a build that lists no
  substats (`buildDetail.substatsDerived`), its notes (`buildDetail.notes`), the best build on the account page
  (`account.bestBuild`) and the dashboard's builds (`dashboard.builds`, `manageBuilds`, `buildsFor.*`,
  `buildsUpdated`, `buildsNever`, `noBuildsStaff`); and rewords the fifteen sentences that told of syncs and
  sources - the builds page's, the character page's, the dashboard's, the account page's, a build's, the import's
  warning that the catalog is behind, and the Privacy page's - only where they still read as seeded, and every
  description that told of them. Gated: the retired keys gone with every string, the 18 in both languages, the
  reworded ones with English, no description of the site's own telling of sources or syncs, and the site's own
  keys (427 - 23 + 18 = 422).
- `018_catalog_from_the_owners_site.sql` (the catalog from the owner's genshin.anotoki.eu - its public catalog
  lists, `App\Services\Catalog\Providers\GenshinCatalog` -, no longer from a third-party game data provider)
  adds what that site knows more of a character, which the builds browser filters by: `game_characters.region`
  (VARCHAR(50), "Liyue"), `version` (VARCHAR(10), the game version the character came with as the game numbers
  it, "6.1" - `game_characters_version_check`: `^[0-9]{1,2}\.[0-9]{1,2}$`) and `version_name` (VARCHAR(40), the
  name the game gave that version, "Luna II"), all NULL where there is none. It clears the old provider's icon
  addresses in the three catalog tables, so nothing is fetched from it any more (the next refresh writes the new
  site's), and forgets where each list stood with it - the cache table's `catalog-state:%` and
  `catalog-import-refresh:%` rows -, so the first refresh reads every list in full. Gated: the three columns with
  their types, the check, no old icon address and no old state left.
- `019_builds_filter_words.sql` adds the builds browser's filters' 14 words in English and informal Slovak
  (`builds.filter.*`: the filters' labels, their "All" and "Any" choices, a rarity's choice in its three plural
  forms, Reset, how many characters show, and what the page says when none matches); the weapon filter's label is
  the game's own word (`/api/meta`'s `weapon_label`) and no key, and the names of elements, weapon types, regions
  and versions are the game's, never translated. Gated: the 14 in both languages, and the site's own keys
  (422 + 14 = 436).
- `020_build_teams.sql` adds `builds.teams` (JSON, NULL for none: the teams a build is played in, the builds file's
  shape - [builds.md](builds.md), "Teams"), every row NULL. No build changes: a build without teams hashes as it did
  before (`BuildImport::content()` adds the teams last, and only when a build has some), so the first import of a
  version 3 file updates only the builds that gained teams. Gated: the column of type `json`, nullable and without
  a default, every row NULL, every build there before still there with its content hash (a temporary copy taken
  first), and the identity index `builds_identity_unique` still there.
- `021_build_teams_words.sql` adds the teams' three words in English and informal Slovak - the section's heading
  (`buildDetail.teams`, "Teams" / "Tímy"), the line of a member's stand-ins (`buildDetail.teamAlternatives`,
  "or {names}" / "alebo {names}": the names the game's) and the title of a team's note (`buildDetail.teamNotes`,
  "Notes on {team}" / "Poznámky k tímu {team}": with the team's name, so a screen reader tells one team's notes from
  another's) - and widens the descriptions of the two words the teams reuse: `buildDetail.owned` (a member or a
  stand-in the account has) and `character.needsRank` (a member the account has below the constellation the team
  asks). Gated: the three new keys in both languages, the two reused ones there with their English - not their
  Slovak, which an editor may have deleted (a gate that asked for it would keep the site closed, with no way to put
  it back while the update waits) -, and the site's own keys (436 + 3 = 439).
- `022_build_hand_edits.sql` keeps what editors do by hand beside what the files say ([builds.md](builds.md), "Hand
  edits"): `builds.file_build` (JSON: what the last builds file said of the build - its variation, place and content
  as the columns store them; NULL for a build made here, which no file carries), `builds.site_notes` (JSON: the site's
  own notes, `{build: {en, sk}, teams: {"<members' keys, sorted>": {en, sk}}}`, English required, Slovak optional;
  NULL for none), `builds.edited_at` (TIMESTAMPTZ: the last save in the editor that changed the build), `checked_at`
  nullable from then on (a build made here was in no file), and the table `build_removals` (the builds of a file an
  editor removed: their identity - unique as the builds', `build_removals_identity_unique` -, `file_build`,
  `site_notes`, `removed_at`; deleted with the character), and the table `build_orders` (the characters whose builds an
  editor put in an order of their own, said by the editor's save - never guessed: `game_character_id` the key,
  `ordered_at`; deleted with the character). Every build there was came from a file: its `file_build`
  is its columns as they are (`json_build_object`), so the first import after it compares as the last one did.
  Gated: the columns' types, nullability and defaults (`pg_attribute`), the index, every build's `file_build` equal
  to its columns (as text: the same expression writes the same text) with no notes and no edit, every build there
  before still there in its place with its content hash (a temporary copy taken first), no removal and no order yet,
  and `builds_identity_unique` still there; the columns read in this schema alone.
- `023_builds_editor_words.sql` adds the link from a character's builds page to its builds editor, for an editor or an
  admin (`buildDetail.edit`, "Edit builds" / "Uprav buildy"), rewords the Privacy page's sentence on what the audit
  log keeps - builds "imported from a file or edited by hand" (`legal.auditLog.what`) - where it still reads as 017
  left it, and describes the panels of a build's and a team's notes anew (`buildDetail.notes`,
  `buildDetail.teamNotes`: the site's own notes, in the reader's language where written, no longer the file's English).
  Gated: the new key in both languages, the reworded and described ones there with their English (not their Slovak:
  an editor may have deleted it), no description calling the notes the file's English any more, and the site's own
  keys (439 + 1 = 440).
- `024_admin_frame.sql` (anotoki-lib 0.10.0's admin frame) retires the name the old top bar gave the admin panel's
  home (`topbar.homeAdmin`, with every string of it, a reworded one too) and describes the frame's words anew
  (`brand.admin`, `topbar.backToApp`: the frame's column, not a top bar). The site's own keys 440 - 1 = 439.
- `025_site_settings_words.sql` (anotoki-lib 0.11.0's site settings) adds the Privacy page's part about the settings
  (`legal.settings.*`), the page that is not open (`title.notOpen`, `notOpen.*`), and names the settings in the audit
  log's sentence (`legal.auditLog.what`, where it reads as 023 left it). The site's own keys 439 + 7 = 446.
- `026_chosen_build.sql` (the owner's round of 2026-10-26): `account_characters.chosen_build_id` - the build a player
  chose to judge the character by, null for the closest match ([analysis.md](analysis.md), "Flow") -, a foreign key to
  `builds` that sets it null when the build goes (`account_characters_chosen_build_id_foreign`, `ON DELETE SET NULL`),
  with a partial index for it; that the build is the character's own is the route's to check (the host's PostgreSQL 14
  has no `ON DELETE SET NULL (column)` a composite key would need). Its six words (`character.useBuild`,
  `.chooseAutomatically`, `.chosen`, `.chosenSaved`, `.automaticSaved`, `account.chosenBuild`), and the Privacy
  page's sentence on the game accounts names the build chosen (`legal.accounts.what`, where it reads as 015 left it).
  Gated: the column and its foreign key, the new keys in both languages, the reworded one with its English, and the
  site's own keys (446 + 6 = 452).

Rules: never edit a migration that has run somewhere; add `NNN_what.sql`. A migration runs in one transaction
(PostgreSQL DDL is transactional), so a failing one leaves nothing behind. A migration that changes data ends
with a `DO $$ … $$` block that checks the result and raises an exception when it is wrong. A check that compares
two lists compares them as sets (each side's `EXCEPT` the other empty), or sorts both sides `COLLATE "C"`: the
type `name` (`pg_class.relname`, `pg_attribute.attname`) sorts byte by byte whatever the database's collation,
while text sorts by it - and the live database's, en_US.utf8, sorts words without their punctuation
("accounts" before "account_weapons"). A closed set of
values is a `CHECK (col IN (…))` on the column (`<table>_game_check`, `game_sets_kind_check`,
`builds_role_check`), not a lookup table or an enum type - narrowed or widened by a migration when the code's
list changes (014 narrowed every game's to `'genshin'`). `Migrations::migrator($pdo, $folder, $settings)` can
pass settings to the migrations (`build_analyzer.<name>`, set in each file's transaction); only the Laravel
adoption reads one, `build_analyzer.local_user_id`, and nothing but its own test passes it any more. Every
migration closes the site until it is applied (the site gate): on a host, apply it at once after the upload.

All times are `TIMESTAMPTZ`, written and read in UTC (the connection sets `TIME ZONE 'UTC'`); the API sends
ISO 8601 with `Z`, the UI converts them. Nested data is `JSON` (not `JSONB`: key order, such as the slots of a
build's main stats, is kept as written).

## Users

| Table | Notes |
| --- | --- |
| `users` | A person: `id` = the anotoki IAM user id (the token's `sub`, always a positive number; not a sequence), `name` = `preferred_username` (refreshed from each token; "user {id}" without one), `last_seen_at` (written at most every five minutes, since 0.4.0), `anonymized_at` (migration 007: when the IAM deleted the account - the scrub then names the row `deleted-{id}`, and nothing writes it again). Rows are made and kept on a person's requests by the anotoki library's people module (`RecordPerson`, `App\People\SitePeople`'s mapping; never an id below 1, never a row the scrub made nobody's); roles are never stored, and there are no passwords. Deleting a row (the People page, ADMIN: `Users::deletePerson()`) deletes the person's accounts with everything in them and their import runs by the foreign keys; `build_imports.imported_by` becomes NULL (the record of the builds files they imported stays, by nobody), and the strings they saved keep their words with `translations.updated_by` NULL. **A visitor** (migration 004): a negative `id` from the sequence `visitor_ids` (-1, -2 ...), `name` `visitor`, `visitor_key_hash` (CHAR(64), the SHA-256 of its key in lowercase hex, UNIQUE - the key itself is never stored), `last_seen_at` (written at most once an hour). `users_visitor_check`: a negative id has a hash and a last-seen time, a positive one no hash (a last-seen time it may have); partial index `users_visitors_last_seen_at_index` on `last_seen_at` for `id < 0`. Only `App\Auth\Visitors` (through `Users`) makes, finds, sweeps and moves them; a visitor never imports builds or saves a string, so `imported_by` (`build_imports_imported_by_check`: above 0) and `updated_by` are always people. The People list shows `id > 0` only and counts visitors apart |

A database adopted from the Laravel version gives every account and import run without an owner to IAM user
1 (the owner), creating that user's row.

Tests never assume which negative id a visitor gets: `TRUNCATE ... RESTART IDENTITY` does not reset
`visitor_ids` (no column owns it).

## The library's own tables (people, the admin tools, the site settings)

Made by the anotoki library's sets, read and written by its modules only (anotoki-lib's README, "People",
"Admin tools" and "Site settings", says what each column holds):

| Table | Notes |
| --- | --- |
| `anotoki_people_sync` | one row: where the scrub of the IAM's deleted accounts stands (`since` - the IAM's paging -, `checked_at`, `ok_at`, `error`, `scrubbed`) - shown on the People page |
| `anotoki_people_anonymized` | the IAM ids the IAM deleted (whether a row was here or not), with when: a token naming one never makes a row again |
| `anotoki_audit_log` | what was done in the admin panel: `actor_id` (the IAM id; NULL for the command line and the day's backup), `action` (`builds.imported`, `catalog.refreshed`, `translations.saved`, `person.data_deleted`, `backup.created` …), `target_type` / `target_id`, `details` (JSONB). No address is kept (`ip` stays NULL). Never restored by a backup's restore - a restore reads it: every `person.data_deleted` and `feedback.deleted` since the backup is done again, each written inside its delete's own transaction (its `details` name it, `_txid`; anotoki-lib 0.5.0); the scrub blanks a deleted person's name in the details |
| `anotoki_feedback` | the visitors' messages: `kind`, `section`, `subject`, `message`, `details`, `contact_email` (only what the form sent: for a signed-in person the page writes their account's address in, to keep, change or clear; none when sent "without my name"), `user_id` (the token's, never the body's; NULL without a name), `page` (a path, no query), `language`, `user_agent`, `status` (`new` \| `read` \| `resolved` \| `spam`), `handled_by`. Nothing of where it came from (no address, no hash); the scrub blanks a deleted person's address and browser, the message stays |
| `anotoki_site_settings` | the site settings' switches (anotoki-lib 0.11.0, `anotoki_admin/003`): `name` (`maintenance_mode`, `announcement_level`, ...), `value` (JSONB: true or false, a level, a text per language), `updated_at`, `updated_by` (the IAM id of who changed it last). Empty after the migration: the site open, no announcement. The People page's delete sets a person's `updated_by` to NULL here (`SitePeople::deleteData()`), the setting staying |
| `anotoki_page_access` | Page access, a row per page (`path` as the router names it - `/`, `/accounts/:id`, `/builds/:game/:key` ...): `visibility` (the least a reader must be: `PUBLIC`, `USER`, `EDITOR`, `ADMIN`), `blocked` (off for everybody but the administrators), `api_paths` (what the page reads, refused alike: 423 `page_locked`), `sort_order`, `updated_at`, `updated_by` (NULLed by the People page's delete, as above). No row: the page as it always was |

## Catalog (reference data, filled by catalog refreshes)

| Table | Key columns | Notes |
| --- | --- | --- |
| `game_characters` | `game`, `key` (unique per game), `name`, `element`, `rarity`, `weapon_type`, `region`, `version`, `version_name` (migration 018), `external_id`, `icon_url`, `released_at`, `meta.ids`, `meta.stats` | The Travelers are one entry per element (`traveler{element}`, "Traveler (Anemo)"), `external_id` `{lowest in-game ID}-{element}` and both twins' IDs in `meta.ids` - the way imports have always named them. `version` as the game numbers it (`game_characters_version_check`: `^[0-9]{1,2}\.[0-9]{1,2}$`, "6.1"), `version_name` the name the game gave it ("Luna II"); `region`, `version` and `version_name` NULL where the provider has none - the builds browser filters by them. `meta.stats` (since the round of 2026-10-26, the stat targets): what the provider's phases hold - `base` and `lv80` each `{hp, atk, def}` (level 1; level 80 ascended) and `ascension` `{stat, value}` (the catalog's key, the whole value) -, from which `MaxLevelStats` works out level 90; absent where the provider has none or the catalog could not read them |
| `game_weapons` | `game`, `key`, `name`, `rarity`, `weapon_type`, `external_id`, `icon_url`, `meta.stats` | Genshin Impact's weapons; of two the provider lists under one key (a quest copy), the lower in-game ID's. `meta.stats`: `{base_atk, lv80_atk, secondary: {stat, value} \| null}` at level 1 and level 80 ascended, likewise |
| `game_sets` | `game`, `key`, `name`, `kind` (`artifact`, the one kind since 014), `external_id`, `icon_url`, `meta.rarities`, `meta.bonuses` | `meta.bonuses` the 2-piece and 4-piece texts, as the provider gives them (`SetBonus` reads the 2-piece's sheet stat from the first, for the stat targets) |

The catalog is shared by every user. Only a catalog refresh writes it (`CatalogSync::refresh()`, one list at a
time, from the game's provider - Genshin Impact's the owner's genshin.anotoki.eu; [architecture.md](architecture.md),
"Main flows"): a changed list is written in one transaction, each row found again by its in-game ID
(`external_id`), then by its key - a renamed entry keeps its row and everything linked to it, unless another row
holds the new key already -, and no row is ever deleted. Imports only look entries up and never add a row - an
account import by in-game ID or name (see [name-matching.md](name-matching.md)), a builds import by key; either
may first refresh the list that lacks one (below, "Import semantics", and "Builds files"). `icon_url` is the
provider's address, kept only on the provider's own host; the API sends `/api/icons/{c|w|s}/{id}` instead, which
fetches the image once into `storage/icons/`. Where each list stands - its version tag, when the provider last
confirmed it, its last failure - is no table: it is a row of the cache table per list (below, "Cache and
locks").

## Accounts (per user)

| Table | Notes |
| --- | --- |
| `accounts` | `user_id` → `users` (cascade), `game`, `uid` (nullable: file imports have none), unique (`user_id`, `game`, `uid`) so two people can import the same UID, `nickname`, `level`, `world_level`, `has_full_inventory`, `last_import_at`, `meta` (region, tool…; the API sends only neutral keys) |
| `import_runs` | `user_id` → `users`, `account_id` (null for a failed attempt without an account), `source` (`enka` \| `good`, or `file` for a file that could not be read; the API says `showcase` or `file`), `status` (`success` \| `partial` \| `failed`), `summary` {characters, weapons, items, full_inventory, tool, warnings[], warning_kinds[{code, params}], unmatched{bucket: names[]}} (the API leaves out `tool`) - on a failed run `{error: {code, params}}` instead, `error` (the English sentence) |
| `account_characters` | unique (account, character); `level`, `ascension`, `rank` (the constellation: which builds the character reaches, and which stat targets hold it), `talents`, `final_stats`, `source`, `synced_at`, `chosen_build_id` → `builds` (set null when the build goes; migration 026: the build the player chose to judge the character by, null for the closest match - written by `Accounts::chooseBuild()` and by an accounts file's import (`AccountsTransfer`), each taking one of the character's own builds) |
| `account_weapons` | `refinement`, `equipped_character_id` → `game_characters`, `external_uid` |
| `account_items` | artifacts: `slot`, `rarity`, `level`, `main_stat`, `main_value` (nullable), `substats` JSON, `equipped_character_id`, `game_set_id` |

Every query goes through `Repositories\Accounts::owned()` / `findOwned()` (or takes the owner as a parameter);
an account is never looked up by id or UID alone.

JSON shapes:

- `account_items.substats`: `[{key: "critRate_", value: 10.5, rolls: 3|null}]` (canonical stat keys; `rolls`
  exact from showcase imports and exporters that capture the game data, null from screen scanners).
- `account_characters.talents`: `{normal, skill, burst, bonus?: {skill: 3}}` (base levels, `bonus` =
  constellation +3).
- `account_characters.final_stats`: in-game totals, from Genshin showcase imports only, e.g.
  `{hp: 15000, critRate_: 65.2}`.
- `import_runs.summary.warning_kinds`: what each of `warnings` says as a kind of `ImportKinds::WARNINGS` and
  its params, index for index; the API sends it only when there are as many as there are warnings (a run
  stored before kinds has none, and its English is shown). A failed run's summary is `{error: {code,
  params}}` (a kind of `ImportKinds::ERRORS`, `unexpected` for anything else); the API sends it as
  `error_code` / `error_params` beside `error` and no summary. See [import-formats.md](import-formats.md),
  "Errors and warnings are kinds".

Import semantics (`AccountImporter`):

- Full inventory files (GOOD) **replace all** weapons and items of the account.
- A showcase import replaces only the gear equipped on the imported characters.
- Characters are upserted, never deleted. `final_stats` are kept when a file import has none (the UI shows
  their source and date); the build chosen for a character (`chosen_build_id`) is never touched by an import.
- Every import is for one user (`$owner`): the target is their explicit `account_id` (checked against their
  accounts), else their account with that (game, uid), else a new account of theirs. A target with a different
  UID, or of another user, is refused. A failed attempt is recorded for the user, on their account when there is one.
- Names are only looked up in the catalog; whatever it lacks is skipped with a warning and the run is `partial`.
  An in-game ID the catalog lacks refreshes that one list first (before the write transaction), at most once
  per game and list per `catalog.import_refresh_minutes` (60, `config/app.php`) for all users together.

## Builds

| Table | Notes |
| --- | --- |
| `builds` | A variation of a character's (migration 016): `game_character_id` → `game_characters` (cascade), `role` (VARCHAR(16): `main_dps` \| `sub_dps` \| `support`, `builds_role_check` - `Game::roles()`), `qualifier` (VARCHAR(40) NOT NULL DEFAULT '': a word or two telling two builds of one role apart, "Melt", '' for most; trimmed - `builds_qualifier_check`), `min_rank` (SMALLINT NOT NULL DEFAULT 0, 0-6 - `builds_min_rank_check`: the constellation the build needs; a character below it sees the build, marked, and it is never their best), `position` (SMALLINT, ≥ 0 - `builds_position_check`: the build's place among the character's, the file's order; the first is the main one), the JSON columns below (`weapons`, `sets`, `main_stats`, `substats` NOT NULL; `talent_priority`, `stat_targets` and - migration 020 - `teams` NULL for none), `notes`, `game_version` (VARCHAR(20)), `content_hash` (VARCHAR(64): the SHA-256 of what the file said of the build's content as JSON - weapons, sets, main stats, substats, talent priority, stat targets, notes and game version, in that order, then its teams only when it has some, so a build without teams hashes as it did before 020; of its content for a build made here), `checked_at` (when the file that last carried the build was made; NULL for a build no file carries - migration 022), `changed_at` (when it last changed as members see it: a file's change or an edit), `created_at`, `updated_at` - every time but `checked_at` NOT NULL; since migration 022 `file_build` (JSON: what the last file said of the build - its variation, place and content as these columns store them; NULL for a build made here), `site_notes` (JSON: the site's notes, `BuildNotes`; NULL for none) and `edited_at` (the last save in the editor that changed it). Its identity is the unique index `builds_identity_unique` on (`game_character_id`, `role`, `lower(qualifier)`, `min_rank`): the qualifier compared without regard to case, and an empty one '' rather than NULL, as the host's PostgreSQL 14 has no `NULLS NOT DISTINCT`. No source, no page address: nothing in a row says where a build came from |
| `build_removals` | The builds of a file an editor removed (migration 022): `game_character_id` → `game_characters` (cascade), `role`, `qualifier`, `min_rank` (the identity, with the builds' CHECKs; unique as theirs - `build_removals_identity_unique`), `file_build` (JSON NOT NULL: what the file said of it - the same as the file's again: it stays removed unasked), `site_notes` (its notes, back with it when it is brought back), `removed_at`. No removal shares its identity with a build |
| `build_orders` | The characters whose builds an editor put in an order of their own (migration 022): `game_character_id` → `game_characters` (the key; cascade), `ordered_at`. An import keeps such a character's order, the file's new builds after them, until an editor gives it back to the file; the editor's save writes it, and an import only where every build of the character was the editor's |
| `build_imports` | Every import of a builds file (migration 016): `game` (`build_imports_game_check`), `file_exported_at` (when its file was made, never later than the import), `summary` (JSON `{characters, builds, created, updated, unchanged, removed, skipped}` and since 0.7.0 `edits_kept, made_here, kept_removed, file_taken`: what it did, in counts - nobody's name), `imported_by` → `users` (ON DELETE SET NULL: nobody's once their data is deleted; `build_imports_imported_by_check`: above 0 - a person, never a visitor), `created_at`; index `build_imports_game_created_at_index` on (`game`, `created_at`). The Builds page lists a game's last ten with who made each; an import's answer says whether its file was made before the one the builds there came from (`older_than_current`) |

Gone with migration 016, none of it carried over: the builds of the sources (one per source, character and
variant, with a page address), `build_sources` (one row per scraper), `sync_runs` (the builds' and the
catalog's runs, worked through in steps) and `cache_locks` (the sync step lock).

`builds` JSON columns (normalized; stat keys canonical):

```jsonc
"weapons":   [{"key": "staffofhoma", "name": "Staff of Homa", "rank": 1, "refinement": 5|null, "note": "…", "known": true}],
"sets":      [{"rank": 1, "kind": "artifact", "choose": null|2,
               "pieces": [{"label": "Crimson Witch of Flames", "count": 4, "keys": ["crimsonwitchofflames"], "names": ["…"]}], "note": "…"}],
"main_stats": {"sands": [{"stat": "hp_", "priority": 1}, {"stat": "eleMas", "priority": 2}], "goblet": [{"stat": "elemental_dmg_", "priority": 1}]},
"substats":  [{"stat": "critRate_", "priority": 1}, {"stat": "critDMG_", "priority": 1}, {"stat": "atk_", "priority": 2}],
"talent_priority": [{"talent": "burst", "priority": 1}],
"stat_targets":    [{"stat": "enerRech_", "min": 180, "max": null, "text": "…", "from_rank": 0}],
"teams":           [{"name": "Vaporize", "note": "…"|null,
                     "members": [{"key": "hutao", "name": "Hu Tao", "role": "main_dps", "min_rank": 0, "alternatives": []},
                                 {"key": "xingqiu", "name": "Xingqiu", "role": "sub_dps"|null, "min_rank": 6,
                                  "alternatives": [{"key": "yelan", "name": "Yelan"}]}]}]
```

- A weapon's and a set option's `rank` is its place in its list, from 1 (the file's order); `known` says whether
  the catalog had the weapon when the build was written - nothing reads it since 0.7.0 (`BuildPresenter` asks the
  catalog) -; weapons and set keys without a catalog row still compare by key.
- The texts the format carries beside a build's parts - `notes`, a weapon's, a set option's and a team's `note`, a
  target's `text` - are stored as the file brings them and shown nowhere since 0.7.0: the site's own notes are
  `site_notes`, written in the editor (the local tools write the file's empty).
- A set piece accepts any of its `keys` (stat groups like "any +80 EM set" list several); `choose` = how many
  pieces must be satisfied ("any 2 of these 2pc bonuses"; null: all). Semantics in `Analysis/SetMatcher`.
- Equal `priority` values are ties (`=`).
- A stat target holds from a constellation (`from_rank`, 0-6), each (stat, `from_rank`) once in a build: the
  analysis holds a character to, per stat, the target from the highest constellation it has reached
  (`BuildEvaluator::targetsFor()`); a stat whose targets all start later has none for it.
- A team (migration 020) lists 2-4 members by the catalog's keys, the build's own character among them once and
  without stand-ins, each with its role in the team (or null), the constellation the team asks of it and at most
  five stand-ins, none a member; no two teams of a build have the same members. What the catalog says of a
  teammate - whether it has it, its picture, rarity and element - and whether it has builds are looked up when a
  build is shown (`BuildPresenter`), never stored.
- Import rules (`BuildImport`; [architecture.md](architecture.md), "Main flows"; [builds.md](builds.md), "Hand
  edits"): the file is the truth for its game, but for what editors did by hand. A build the file has again by its
  identity keeps its id - a link `?build=ID` stays - and is compared part by part with what the file said of it
  (`file_build`) and with what is stored: a part nobody edited takes the file's version, an edited one stays, and a
  part both changed is a question for that build; it is updated when what members see changed (`changed_at` the
  later of the file's time and its own, `checked_at` the file's time), kept otherwise (`checked_at` alone); a build
  of the game the file lacks is removed - but one made here, edited or with notes -, a new one created - but one an
  editor removed; removed first and created last, so no identity is ever
  there twice, even for a moment. A character the catalog lacks is skipped: an import never adds to the catalog.
  One import at a time, in one transaction, recorded in it (`build_imports`, the audit log's `builds.imported`).

## Builds files

A game's builds, imported on the admin panel's Builds page (`POST /api/admin/builds/{game}/import`, the file's
JSON as the body; `?dry_run=1` only says what would happen) and exported there
(`GET /api/admin/builds/{game}/export`, `builds-{game}-{date}.json`): `Services/Builds/BuildFile` is the format,
`BuildImport` the import and export. The site never puts a builds file together itself: the owner's untracked
local tools read the build sites and write one per game ([builds.md](builds.md)), and the export serves as a
backup. The file is the truth for its game, but for what editors did by hand ([builds.md](builds.md), "Hand edits"). Format version 3 (2 until 0.6.0,
which gave builds their teams):

```jsonc
{
  "format": "build-analyzer.builds", "format_version": 3, "game": "genshin",
  "exported_at": "2026-10-20T10:00:00Z",             // when the file was made: the export writes UTC to the second (an offset and fractions are read too)
  "builds": [{                                       // a character's builds in their order of preference: the first is its main one
    "character": "furina",                           // game_characters.key, matched by (game, key)
    "character_name": "Furina",                      // for people reading the file
    "role": "support", "qualifier": "", "min_rank": 0,   // the variation: Game::roles(); '' or a word or two; 0-6
    "weapons": [{"key": "splendoroftranquilwaters", "name": "Splendor of Tranquil Waters", "refinement": 1, "note": null}],   // in order of rank
    "sets": [{"kind": "artifact", "choose": null,
              "pieces": [{"label": "Golden Troupe", "count": 4, "keys": ["goldentroupe"], "names": ["Golden Troupe"]}], "note": null}],
    "main_stats": {"sands": [{"stat": "hp_", "priority": 1}], "goblet": [{"stat": "hp_", "priority": 1}]},
    "substats": [{"stat": "critRate_", "priority": 1}, {"stat": "critDMG_", "priority": 1}],   // [] for none
    "talent_priority": [{"talent": "skill", "priority": 1}],                                    // or null
    "stat_targets": [{"stat": "enerRech_", "min": 200, "max": null, "text": "…", "from_rank": 1}],   // or null
    "teams": [{"name": "Hydro", "members": [{"key": "furina", "name": "Furina", "role": "support", "min_rank": 0, "alternatives": []},
                                            {"key": "neuvillette", "name": "Neuvillette", "role": null, "min_rank": 0, "alternatives": []}],
               "note": null}],                                                                 // [] for none
    "notes": null, "game_version": "6.1"
  }]
}
```

- A build is a variation of its character's: its role, a qualifier telling two builds of one role apart (at most
  40 characters, one line, trimmed; '' for most) and the constellation it needs (`min_rank`). (character, role,
  qualifier without regard to case, `min_rank`) is its identity, once in a file; a character's builds come in
  their order of preference, which becomes their `position`, and the order of a build's weapons and set options
  is their rank. A stat target holds from a constellation (`from_rank`), each (stat, `from_rank`) once in a build.
  A build's teams come the best first, `[]` for none.
- **Reading is strict**, the whole file before anything is written, naming the first problem (422, the
  sentence naming the build - `builds[3] (hutao, main_dps C2+)` - and the field): the format and its version (a
  version 2 file - builds without their teams - and a version 1 file - the builds of one source, before builds had
  variations - are refused each with its own message, a newer one asks for this installation to be updated) and
  the game; a team's rules (2-4 members each once, the build's own character among them without stand-ins, no
  stand-in a member, no two teams of the same members, a name of one line and at most 60 characters); exactly the
  fields of the format, none
  missing and none more; lists and objects where they belong, whole numbers where the app counts (`min_rank`
  and `from_rank` 0-6, priorities 1-99, refinements 1-5); the game's roles, set kinds (`artifact`), the slots
  whose main stat varies and the main stats each can have (an "Elemental DMG", `elemental_dmg_`, where the slot
  has such stats), substat and stat keys (`Stats::substatKeys()`, `Stats::keys()`) and talents; set pieces as the
  set matcher reads them - each set listed once in a piece, a `count` of 2 or 4; a target's `min` not above its
  `max`; times in ISO 8601 with their offset, from 1970 on; each identity once in the file; and caps well above
  what a build needs (5000 builds, 100 weapons and 50 set options a build, 30 pieces an option, 100 sets a
  piece, 30 stats, 10 talents, 30 targets, 20 teams with 5 stand-ins a member at most), and 8 MB for the file -
  a common default of PHP's `post_max_size`, against a game's builds in about 1 MB (1.2 MB with 809 teams,
  2026-10-22). Every text is made neutral on the way in too (`Neutral`), so a
  file edited by hand cannot bring a site's name into the analysis; a character's key is matched with the
  catalog as it is, and a skipped one named only neutrally.
- Those piece rules keep the set matcher small: with each set listed once and 2 or 4 pieces asked of it, no
  more than two equipped sets satisfy the pieces of an option. `SetMatcher` also works out each state of its
  search once (the piece, the sets used), so even an option it was not written for stays quick.
- **The export** writes the builds by character key (compared as bytes, whatever the database's collation) and
  then in each character's order, so two exports of the same builds differ in `exported_at` only. Every text
  goes through `Neutral::text()`, as in every answer of the API, kept within the caps the import reads with (the
  words that replace an address can take more room than it did); a piece's sets that neutral words make alike
  are listed once, and two qualifiers of a character's that neutral words make alike are told apart (the later
  "… #2"); a team's members or stand-ins that neutral words make alike are listed once, and a team left with fewer
  than two members or with another's members is left out (`BuildFile::teamsEntry()`). Whatever an export writes,
  an import reads.
- **An import** makes the game's builds the file's (see "Builds" above for how each build is matched and
  written) - but for what editors did by hand, and for characters the catalog lacks: the characters' list is refreshed first, at most once per game
  and list per `catalog.import_refresh_minutes` (60, `config/app.php`, shared with the account imports), and
  what is still missing is skipped and named in the answer (`skipped: [{key, name, builds}]`); a weapon the
  catalog lacks likewise refreshes its list once and is kept, `known: false`; a teammate the catalog lacks shares
  the characters' one refresh, skips nothing - it is kept, shown by its name - and is named in the answer
  (`teammates_unknown: [{key, name}]`, the first 50 of the builds kept, and `teammates_unknown_total`). A file whose
  builds name no
  character the catalog knows is refused - most likely a catalog nobody has refreshed yet, and "importing"
  nothing would remove every build. The file's `exported_at` becomes the builds' `checked_at` (and the
  `changed_at` of those it creates or changes) and the import's `file_exported_at`, never later than now. One
  transaction under an advisory lock (`BuildImport::LOCK`, `pg_try_advisory_xact_lock`: a second import
  meanwhile is refused, 409, nothing written), with `SET LOCAL` statement and idle timeouts of 60 s; the answer
  counts every change and lists the first 500. A dry run reads, matches and compares the same and writes
  nothing.

## Accounts files

A signed-in person's own game accounts, exported (`GET /api/me/export`) and imported on this or another
installation (`POST /api/me/import`, the file as the body; `?dry_run=1` only says what would happen):
`Services/Transfer/AccountsFile` is the format, `AccountsTransfer` the export and import. Never a visitor's
(401 `sign_in_required`): a visitor's accounts become a person's by the move at sign-in. Format version 2 (since
0.8.0: a character carries the build chosen for it; a version 1 file - the same without it - is read too, its
characters judged by their closest match), written as compact JSON (one line), saved as
`build-analyzer-accounts-YYYY-MM-DD.json`:

```jsonc
{
  "format": "build-analyzer.accounts", "format_version": 2,
  "exported_at": "2026-10-04T08:15:00Z",             // UTC, to the second, like every time in the file
  "app_version": "0.4.0",                            // Version::CURRENT where it was written
  "accounts": [{                                     // by game, then when they were made
    "game": "genshin", "uid": "700000001",           // uid null for an account from a file
    "nickname": "Alice", "level": 58, "world_level": 8, "signature": "…", "has_full_inventory": true,
    "last_import_at": "2026-09-20T08:00:00Z", "created_at": "2026-09-01T10:00:00Z",
    "meta": {"region": "EU"},                        // AccountPresenter::META_KEYS only (never the tool)
    "characters": [{"key": "hutao", "level": 90, "ascension": 6, "rank": 1,
      "talents": {"normal": 10, "skill": 9, "burst": 9, "bonus": {"skill": 3}}, "final_stats": {"hp": 30512},
      "source": "showcase", "synced_at": "2026-09-20T08:00:00Z",
      "build": {"role": "main_dps", "qualifier": "Vape", "min_rank": 1}}],   // the build chosen, by its variation; null for none
    "weapons": [{"key": "staffofhoma", "level": 90, "ascension": 6, "refinement": 1, "equipped": "hutao",
      "locked": true, "external_uid": "…", "source": "file"}],
    "items": [{"set": "crimsonwitchofflames", "slot": "flower", "rarity": 5, "level": 20, "main_stat": "hp",
      "main_value": 4780, "substats": [{"key": "critRate_", "value": 10.5, "rolls": 3}], "equipped": "hutao",
      "locked": false, "external_uid": null, "source": "file"}],
    "imports": [{"source": "showcase", "status": "success", "file_name": null,   // its newest 200, oldest first
      "summary": {"characters": 1, "warnings": [], "warning_kinds": []}, "error": null,
      "created_at": "2026-09-10T08:00:00Z"}]
  }]
}
```

- Nothing in it only makes sense here: catalog entries by key (`game_characters.key` …, matched by (game,
  key)), never an internal id; a chosen build by its variation (`role`, `qualifier`, `min_rank` - found again by
  the character and `BuildFile::identity()`, the qualifier in any case; one this copy lacks leaves the character
  judged by its closest match, uncounted), never its id; no user id, no account id; a source is `showcase` or `file` (`enka` comes back
  as `enka`, a file as the game's file format: `good`); a run's summary without `tool`; every
  text through `Neutral::text()` and within its column. Import runs without an account (a failed attempt that
  found none) are not in it.
- Times are UTC to the second, on the way out and when compared: an account without a UID is found again by
  game and `created_at`, so the same moment written by a copy in another time zone, or with fractions, is the
  same account.
- **Reading** is strict, the whole file before anything is written, naming the first problem as a kind
  (422 `{message, code, params}`): `no_file` (an empty body - what PHP makes of one past `post_max_size`),
  `file_too_big` `{max_mb}` (the raw body past `config/app.php` `me.file_max_mb`, 8, checked before it is
  read as an accounts file - PHP has the whole body by then, and Slim's body parser has decoded one sent as
  `application/json`: the host's `post_max_size` is what bounds a body), `not_a_data_file`, `newer_version` `{version}`, `bad_entry` `{path}` (`accounts[2].items[14].slot`:
  a field missing or unknown, a wrong type, a game this site does not offer - a Star Rail account's among
  them, since 0.5.0 -, a slot the game does not have, a stat or catalog key of the wrong form, a
  time without its offset, a meta key the account page does not show, one UID twice, a character twice, a text
  longer than its column, a chosen build of a role the game lacks, with a qualifier of more than one line or 40
  characters, or past the game's constellations - and a `build` in a version 1 file), `too_many_accounts` `{max}`
  (the import would leave more than 500 accounts). Caps
  well above a game's own: 500 accounts, 1000 characters, 10000 weapons and 10000 items an account, 200 import
  runs, 10 substats.
- **The export always imports**: it writes only what the reader accepts (a stored value of another shape stays
  behind), and refuses as a whole - 422 `export_too_big` `{max_mb, max_accounts}` - when the file would pass
  the size or the caps, rather than write a file no copy reads back. A few thousand artifacts take well under
  2 MB.
- **Importing** (one transaction, the person's `users` row taken `FOR UPDATE` first): each account of the file
  is matched with one of the person's - by game and UID, else by game and `created_at`; several made in the
  same second pair up in order. No match: **added**, with what it holds and its import runs (its
  `created_at` and `last_import_at` the file's, so the same file again finds it). A match: the **newer copy
  wins** by `last_import_at` (to the second; none is the oldest; a tie keeps this one) - newer in the file:
  **replaced** (its details and `last_import_at`, all its characters, weapons and items the file's; the file's
  meta over this one's, keys the file does not carry kept; the file's import runs it lacks - by time and kind -
  added); otherwise **kept**, nothing of it changed. An account the file lacks is never touched; an import never
  deletes an account. A character, weapon or set the catalog lacks is left out and counted (**skipped**); an
  equipped character it lacks leaves the weapon or item equipped on nobody. Answer: `{dry_run, added,
  replaced, kept, skipped, accounts: [{game, uid, nickname, outcome, skipped}]}`; no import run is recorded for
  it.

## The site's words (migration 003)

| Table | Notes |
| --- | --- |
| `languages` | `code` (key; `^[a-z]{2}(-[a-z]{2})?$`, as a browser and the IAM's token write it), `name` (in English, for the admin pages), `native_name` (for the reader's chooser), `enabled` (offered to readers; a hidden one keeps its strings), `sort_order`. `en` and `sk` are seeded and cannot be deleted (`SiteTranslations::RELEASED`); English cannot be switched off. At most 20 |
| `translation_keys` | `name` (key: `namespace.thing[.detail]`), `description` (where it is shown and what its `{placeholders}` stand for - all a translator has to go by), `created_at`. Only migrations write keys; the browser changes values |
| `translations` | key (`key_name`, `language_code`), both cascading on delete and rename; `value` (never empty: a language with no string of its own has no row and reads the English), `updated_at`, `updated_by` (the IAM user id of whoever last reworded it; null as a migration wrote it; no foreign key) |

- A string is plain text, at most 2000 characters, drawn as text (there is no HTML on the site). Its
  `{placeholders}` are the code's: the English keeps exactly its own, another language may leave one out but
  never add one. No string names another website or holds a web address (`SiteTranslations::namesAnotherSite()`,
  the library's last check, on every save and import; the catalogue test asks it of every seeded string).
- Saving an empty string in a language other than English deletes that row; an import passes empty ones over
  and deletes nothing. Deleting a language deletes its strings.
- Game terms are never keys (stat, character, weapon, set, slot and talent names, "Lv.", "4pc", UID).
- A later string migration: key rows with a description, one `INSERT … SELECT … FROM (VALUES …)` block per
  language, `ON CONFLICT DO NOTHING`, and a `DO $$` gate that counts what it seeds - and, keeping a running
  total, the site's own keys only (`WHERE name NOT LIKE 'anotoki.%'`). The namespace `anotoki.` is the
  library's: its keys come with its migrations, never with this site's. Each installation's
  database holds its own strings: what the owner rewords in the browser is not in any file
  ([operations.md](operations.md), "The site's words").

## Cache and locks

| Table | Notes |
| --- | --- |
| `cache` | `key`, `value` (JSON), `expiration` (Unix time): the per-host request delays (`fetcher:last-request:{host}`), the limits' counters (`rate:*` and the visitors', per person or per address - an address by a keyed hash of it, never the address itself), the showcase answers, the claims (`Cache::add()`: a row added only where none is, or over an expired one - a catalog list's refresh, `catalog-refresh:{game}:{list}`, two minutes; the upkeep's look, `catalog-upkeep:look`, and its try of a list, `catalog-upkeep:{game}:{list}`; an import's refresh of a list, `catalog-import-refresh:{game}:{list}`; the day's tidying up, `prune:{date}`; the visitors' sweep), and where each catalog list stands (`catalog-state:{game}:{list}`: `{etag, checked_at, changed_at, failed_at, problem, count}` - the list's version tag, when the provider last confirmed it, when it last changed, when and why a refresh last failed, how many entries the last change wrote; kept ten years, for the table's rule that everything expires). Expired rows are deleted by the day's tidying up (`App\Cache\Prune`: after the first answer of a UTC day, while the site is ready - with the downloaded files in `storage/http-cache` older than three days). A backup's restore never touches the table (`keep: ['cache']`): the counters, the claims and the catalog lists' state stay as they are |

No lock table since migration 016 (`cache_locks` went with the sync step lock it held). What must be one at a
time across a transaction takes a PostgreSQL advisory lock: applying migrations (`Migrations::LOCK` - see the top
of this page; the `migrate` row of older versions is no longer written) and importing builds
(`BuildImport::LOCK`, `pg_try_advisory_xact_lock`: given back when the import's transaction commits or rolls
back, so a request that dies holds it no longer). What is one at a time without a transaction - a refresh of one
catalog list, which asks the provider before it writes - is a claim in the cache table (above).
