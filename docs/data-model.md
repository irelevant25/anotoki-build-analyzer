# Data model

The schema is `backend/migrations/*.sql`, applied in order by one engine - the anotoki family's (anotoki-lib's
`Anotoki\Lib\Migrations\Migrator`, on this site's files through `src/Db/Migrations.php`) - whichever way it
runs: `php migration.php`, "Apply pending" on the admin panel's Migrations page, or `setup.php`. There is no
separate schema file; a database is what you get by replaying the migrations. `migrations(id, filename,
applied_at)` records what has run - the table this site's own engine made, which the library reads and writes
as it is (a new database gets the same: `tests/migrations.test.php` holds it to that) - each file in a
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
  sentences, and the family's top bar and footer (`brand.*`, `topbar.*`, `footer.version`, `language.button`)
  - in 003's form, retiring the keys of the sign-in modes, the old owner's menu and the toolbar the bar
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

Rules: never edit a migration that has run somewhere; add `NNN_what.sql`. A migration runs in one transaction
(PostgreSQL DDL is transactional), so a failing one leaves nothing behind. A migration that changes data ends
with a `DO $$ … $$` block that checks the result and raises an exception when it is wrong. A closed set of
values is a `CHECK (col IN (…))` on the column (`<table>_game_check`, `game_sets_kind_check`,
`sync_runs_kind_check`), not a lookup table or an enum type. `Migrations::migrator($pdo, $folder, $settings)` can
pass settings to the migrations (`build_analyzer.<name>`, set in each file's transaction); only the Laravel
adoption reads one, `build_analyzer.local_user_id`, and nothing but its own test passes it any more. Every
migration closes the site until it is applied (the site gate): on a host, apply it at once after the upload.

All times are `TIMESTAMPTZ`, written and read in UTC (the connection sets `TIME ZONE 'UTC'`); the API sends
ISO 8601 with `Z`, the UI converts them. Nested data is `JSON` (not `JSONB`: key order, such as the slots of a
build's main stats, is kept as written).

## Users

| Table | Notes |
| --- | --- |
| `users` | A person: `id` = the anotoki IAM user id (the token's `sub`, always a positive number; not a sequence), `name` = `preferred_username` (refreshed from each token). Rows are created on a person's first request (`Auth/UserProvisioner`, which refuses an id below 1); roles are never stored, and there are no passwords. Deleting a row (the People page, ADMIN: `Users::deletePerson()`) deletes the person's accounts with everything in them and their import runs by the foreign keys; `sync_runs.started_by` becomes NULL, and the strings they saved keep their words with `translations.updated_by` NULL. **A visitor** (migration 004): a negative `id` from the sequence `visitor_ids` (-1, -2 ...), `name` `visitor`, `visitor_key_hash` (CHAR(64), the SHA-256 of its key in lowercase hex, UNIQUE - the key itself is never stored), `last_seen_at` (written at most once an hour). `users_visitor_check`: a negative id has a hash and a last-seen time, a positive one neither; partial index `users_visitors_last_seen_at_index` on `last_seen_at` for `id < 0`. Only `App\Auth\Visitors` (through `Users`) makes, finds, sweeps and moves them; a visitor never starts a sync or saves a string, so `started_by` and `updated_by` are always people. The People list shows `id > 0` only and counts visitors apart |

A database adopted from the Laravel version gives every account and import run without an owner to IAM user
1 (the owner), creating that user's row.

Tests never assume which negative id a visitor gets: `TRUNCATE ... RESTART IDENTITY` does not reset
`visitor_ids` (no column owns it).

## Catalog (reference data, filled by catalog runs)

| Table | Key columns | Notes |
| --- | --- | --- |
| `game_characters` | `game`, `key` (unique per game), `name`, `element`, `rarity`, `weapon_type` (GI weapon type / HSR path), `external_id`, `icon_url`, `released_at`, `meta.ids` | Travelers / Trailblazers merge several in-game IDs into `meta.ids` |
| `game_weapons` | same shape | Genshin weapons and HSR light cones |
| `game_sets` | + `kind` (`artifact` \| `relic` \| `planar`), `meta.bonuses` (Genshin 2pc/4pc texts) | `SetBonusGroups` reads the 2pc texts |

The catalog is shared by every user. Only the catalog sync and, for brand-new characters, the build sync add
rows; imports only look names up (see [name-matching.md](name-matching.md)). `icon_url` is the provider's
address; the API sends `/api/icons/{c|w|s}/{id}` instead, which fetches the image once into `storage/icons/`.

## Accounts (per user)

| Table | Notes |
| --- | --- |
| `accounts` | `user_id` → `users` (cascade), `game`, `uid` (nullable: file imports have none), unique (`user_id`, `game`, `uid`) so two people can import the same UID, `nickname`, `level`, `world_level`, `has_full_inventory`, `last_import_at`, `meta` (region, tool…; the API sends only neutral keys) |
| `import_runs` | `user_id` → `users`, `account_id` (null for a failed attempt without an account), `source` (`enka` \| `good` \| `hsr_scanner`; the API says `showcase` or `file`), `status` (`success` \| `partial` \| `failed`), `summary` {characters, weapons, items, full_inventory, tool, warnings[], warning_kinds[{code, params}], unmatched{bucket: names[]}} (the API leaves out `tool`) - on a failed run `{error: {code, params}}` instead, `error` (the English sentence) |
| `account_characters` | unique (account, character); `level`, `ascension`, `rank` (constellation/eidolon), `talents`, `final_stats`, `source`, `synced_at` |
| `account_weapons` | `refinement` (= superimposition in HSR), `equipped_character_id` → `game_characters`, `external_uid` |
| `account_items` | artifacts/relics: `slot`, `rarity`, `level`, `main_stat`, `main_value` (nullable), `substats` JSON, `equipped_character_id`, `game_set_id` |

Every query goes through `Repositories\Accounts::owned()` / `findOwned()` (or takes the owner as a parameter);
an account is never looked up by id or UID alone.

JSON shapes:

- `account_items.substats`: `[{key: "critRate_", value: 10.5, rolls: 3|null}]` (canonical stat keys; `rolls`
  exact from showcase imports and exporters that capture the game data, null from screen scanners).
- `account_characters.talents`: Genshin `{normal, skill, burst, bonus?: {skill: 3}}` (base levels, `bonus` =
  constellation +3); HSR `{basic, skill, ultimate, talent, memo_skill?, memo_talent?}`.
- `account_characters.final_stats`: in-game totals, from Genshin showcase imports only, e.g.
  `{hp: 15000, critRate_: 65.2}`.
- `import_runs.summary.warning_kinds`: what each of `warnings` says as a kind of `ImportKinds::WARNINGS` and
  its params, index for index; the API sends it only when there are as many as there are warnings (a run
  stored before kinds has none, and its English is shown). A failed run's summary is `{error: {code,
  params}}` (a kind of `ImportKinds::ERRORS`, `unexpected` for anything else); the API sends it as
  `error_code` / `error_params` beside `error` and no summary. See [import-formats.md](import-formats.md),
  "Errors and warnings are kinds".

Import semantics (`AccountImporter`):

- Full inventory files (GOOD, HSR scanner) **replace all** weapons and items of the account.
- A showcase import replaces only the gear equipped on the imported characters.
- Characters are upserted, never deleted. `final_stats` are kept when a file import has none (the UI shows
  their source and date).
- Every import is for one user (`$owner`): the target is their explicit `account_id` (checked against their
  accounts), else their account with that (game, uid), else a new account of theirs. A target with a different
  UID, or of another user, is refused. A failed attempt is recorded for the user, on their account when there is one.
- Names are only looked up in the catalog; whatever it lacks is skipped with a warning and the run is `partial`.
  An in-game ID the catalog lacks refreshes that one list first (before the write transaction), at most once
  per game and list per `catalog.import_refresh_minutes` (60, `config/app.php`) for all users together.

## Builds

| Table | Notes |
| --- | --- |
| `build_sources` | one row per scraper (`key` like `prydwen.hsr`), `name` (its neutral label, "Source 2"), `base_url`, `enabled`, `last_synced_at`, `last_status`, `last_error` |
| `builds` | unique (source, character, `variant`); `role`, `recommended`, `position`, JSON columns below, `notes`, `source_url` (never sent by the API), `source_updated_at`, `game_version`, `content_hash`, `fetched_at`, `changed_at` |
| `sync_runs` | `kind` (`builds`: one source, `build_source_id` set; `catalog`: one game, no source), `game`, `status` (`running` until its last step, then `success` \| `partial` \| `failed` \| `cancelled`), `started_by` → `users` (null from the command line), `state` (while running: builds `{options{force, only, limit}, phase list\|characters, todo[CharacterRef], done, total}`, catalog `{lists, done, total}`), `next_step_at` (the site's crawl delay: no step before it), `last_step_at`, `stats` (builds: {characters_listed/synced/skipped/without_builds, builds_created/updated/unchanged/removed, errors, unmatched, partial_run}; catalog: {characters, weapons, sets, errors}), `log` (written as the run goes, in neutral words; includes `! unknown weapon/set: …` lines) |

`builds` JSON columns (normalized; stat keys canonical):

```jsonc
"weapons":   [{"key": "staffofhoma", "name": "Staff of Homa", "rank": 1, "refinement": 5|null, "note": "…", "known": true}],
"sets":      [{"rank": 1, "kind": "artifact|relic|planar", "choose": null|2, "note": "…",
               "pieces": [{"label": "Crimson Witch of Flames", "count": 4, "keys": ["crimsonwitchofflames"], "names": ["…"]}]}],
"main_stats": {"sands": [{"stat": "hp_", "priority": 1}, {"stat": "eleMas", "priority": 2}], "goblet": [{"stat": "elemental_dmg_", "priority": 1}]},
"substats":  [{"stat": "critRate_", "priority": 1}, {"stat": "critDMG_", "priority": 1}, {"stat": "atk_", "priority": 2}],
"talent_priority": [{"talent": "burst", "priority": 1}],
"stat_targets":    [{"stat": "enerRech_", "min": 180, "max": null, "text": "…"}]
```

- A set piece accepts any of its `keys` (stat groups like "any +80 EM set" list several); `choose` = how many
  pieces must be satisfied ("any 2 of these 2pc bonuses"). Semantics in `Analysis/SetMatcher`.
- Equal `priority` values are ties (`=`).
- `known: false` weapons / set keys without a catalog row still compare by key.
- Sync rules: unchanged content only bumps `fetched_at`; a changed hash sets `changed_at`; variants that
  disappear from the site are deleted; runs limited with `--character` / `--limit` never update the source's
  `last_synced_at`. The content hash is computed exactly as the Laravel version did (dates in Carbon's JSON
  form), so builds it stored do not all look changed after an upgrade.

## Builds files

A source's builds exported on the Sources page (`GET /api/sources/{key}/export`) and imported on another
installation (`POST /api/sources/{key}/import`, the file's JSON as the body): `Services/Builds/BuildFile` is the
format, `BuildTransfer` the export and import. How to use them: [operations.md](operations.md), "Moving builds
between installations". Format version 1:

```jsonc
{
  "format": "build-analyzer.builds", "format_version": 1,
  "source": "genshin-2", "game": "genshin",          // the API's key of the source, never its site
  "exported_at": "2026-09-29T14:03:12.123456Z",      // UTC, to the microsecond, like every time in the file
  "synced_at": "2026-09-29T13:40:05.000000Z",        // its last successful full sync where it was exported, or null
  "builds": [{
    "character": "hutao",                            // game_characters.key, matched by (game, key)
    "character_name": "Hu Tao",                      // for people reading the file
    "variant": "DPS", "role": "DPS", "recommended": true, "position": 0,
    "weapons": [], "sets": [], "main_stats": {}, "substats": [],   // the shapes of the builds table above
    "talent_priority": null, "stat_targets": null,   // a list or null
    "notes": null, "source_updated_at": null, "game_version": "6.0",
    "fetched_at": "2026-09-28T10:00:00.000000Z", "changed_at": "2026-09-20T10:00:00.000000Z"
  }]
}
```

- Builds come ordered by character key, position and variant, so two exports of the same builds differ only in
  `exported_at`. No page address (`source_url`), no site address or name: every text goes through
  `Neutral::text()`, as in every answer of the API, and is kept within the caps the import reads with (the
  words that replace an address can take more room than it did). Two variants of a character that neutral
  words make alike ("Prydwen DPS", "Game8 DPS") become "The source DPS" and "The source DPS #2". Whatever an
  export writes, an import reads.
- An import checks the whole file first and writes nothing when anything is off, naming the first problem:
  format and version, game and source; every field present (a build may leave out `fetched_at` /
  `changed_at`) and none the format lacks; the JSON shapes above, with whole numbers where the app counts
  (ranks, positions, priorities, refinements); the set kinds (`artifact` for Genshin, `relic` / `planar` for
  HSR), slots, stat keys (`Stats::keys()`, what `Stats::parse()` gives) and talents of the game; set pieces as
  syncs store them - each set listed once, a `count` of 2 or 4 (2 for a planar ornament); times from 1970 on;
  texts no longer than their columns; one build per (character, variant); and caps well above what the sources
  give (5000 builds, 100 weapons and 50 set options a build, 30 pieces an option, 100 sets a piece …; real syncs
  reach 23 weapons, 12 options, 5 pieces and 13 sets a piece), and 8 MB for the file, a common default of PHP's
  limit on a request, against about 2 MB for a whole source. Every text is made neutral on the way in too, so a
  file edited by hand cannot bring a site's name into the analysis.
- Those piece rules keep the set matcher small: with each set listed once and 2 or 4 pieces asked of it, no
  more than two equipped sets satisfy the pieces of an option. `SetMatcher` also works out each state of its
  search once (the piece, the sets used), so even an option it was not written for stays quick.
- It makes the source's builds exactly the file's, in one transaction under the sync step lock and never
  while a run of the source is going. Each character's builds are written by `BuildSync::write()`, like a
  sync's: created, updated (the content hash differs; `changed_at` moves) or unchanged, other variants of the
  character deleted. A file never carries a page address: a build this installation has already keeps its
  own and is hashed with it, so importing what a sync stored here changes nothing and the next sync does not
  count every build as changed; a new build gets none. `fetched_at` / `changed_at` come from the file where
  they are times (never later than now), else now. Every build of the source whose character the file lacks is
  deleted. Characters the catalog lacks are skipped: an import never adds to it.
- It is recorded as a `builds` run of the source, `started_by` the admin, with a sync's stats (skipped
  characters in `characters_skipped` and in `unmatched` as `{game}:characters`), a log that starts "imported
  from a file exported …" and ends with the "Done: …" line; and it leaves the source as a successful full sync
  does, with `last_synced_at` the file's `synced_at` (else `exported_at`).

## Accounts files

A signed-in person's own game accounts, exported (`GET /api/me/export`) and imported on this or another
installation (`POST /api/me/import`, the file as the body; `?dry_run=1` only says what would happen):
`Services/Transfer/AccountsFile` is the format, `AccountsTransfer` the export and import. Never a visitor's
(401 `sign_in_required`): a visitor's accounts become a person's by the move at sign-in. Format version 1,
written as compact JSON (one line), saved as `build-analyzer-accounts-YYYY-MM-DD.json`:

```jsonc
{
  "format": "build-analyzer.accounts", "format_version": 1,
  "exported_at": "2026-10-04T08:15:00Z",             // UTC, to the second, like every time in the file
  "app_version": "0.2",                              // Version::CURRENT where it was written
  "accounts": [{                                     // by game, then when they were made
    "game": "genshin", "uid": "700000001",           // uid null for an account from a file
    "nickname": "Alice", "level": 58, "world_level": 8, "signature": "…", "has_full_inventory": true,
    "last_import_at": "2026-09-20T08:00:00Z", "created_at": "2026-09-01T10:00:00Z",
    "meta": {"region": "EU"},                        // AccountPresenter::META_KEYS only (never the tool)
    "characters": [{"key": "hutao", "level": 90, "ascension": 6, "rank": 1,
      "talents": {"normal": 10, "skill": 9, "burst": 9, "bonus": {"skill": 3}}, "final_stats": {"hp": 30512},
      "source": "showcase", "synced_at": "2026-09-20T08:00:00Z"}],
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
  key)), never an internal id; no user id, no account id; a source is `showcase` or `file` (`enka` comes back
  as `enka`, a file as the game's file format: `good` / `hsr_scanner`); a run's summary without `tool`; every
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
  a field missing or unknown, a wrong type, a slot of the other game, a stat or catalog key of the wrong form, a
  time without its offset, a meta key the account page does not show, one UID twice, a character twice, a text
  longer than its column), `too_many_accounts` `{max}` (the import would leave more than 500 accounts). Caps
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
| `languages` | `code` (key; `^[a-z]{2}(-[a-z]{2})?$`, as a browser and the IAM's token write it), `name` (in English, for the admin pages), `native_name` (for the reader's chooser), `enabled` (offered to readers; a hidden one keeps its strings), `sort_order`. `en` and `sk` are seeded and cannot be deleted (`TranslationCheck::SEEDED`); English cannot be switched off. At most 20 |
| `translation_keys` | `name` (key: `namespace.thing[.detail]`), `description` (where it is shown and what its `{placeholders}` stand for - all a translator has to go by), `created_at`. Only migrations write keys; the browser changes values |
| `translations` | key (`key_name`, `language_code`), both cascading on delete and rename; `value` (never empty: a language with no string of its own has no row and reads the English), `updated_at`, `updated_by` (the IAM user id of whoever last reworded it; null as a migration wrote it; no foreign key) |

- A string is plain text, at most 2000 characters, drawn as text (there is no HTML on the site). Its
  `{placeholders}` are the code's: the English keeps exactly its own, another language may leave one out but
  never add one. No string names another website or holds a web address (`TranslationCheck`, on every save,
  import and seeded string).
- Saving an empty string in a language other than English deletes that row; an import passes empty ones over
  and deletes nothing. Deleting a language deletes its strings.
- Game terms are never keys (stat, character, weapon, set, slot and talent names, "Lv.", "4pc", UID).
- A later string migration: key rows with a description, one `INSERT … SELECT … FROM (VALUES …)` block per
  language, `ON CONFLICT DO NOTHING`, and a `DO $$` gate that counts what it seeds. Each installation's
  database holds its own strings: what the owner rewords in the browser is not in any file
  ([operations.md](operations.md), "The site's words").

## Cache and locks

| Table | Notes |
| --- | --- |
| `cache` | `key`, `value` (JSON), `expiration` (Unix time): the per-site request delays and retry counters, showcase answers, the showcase rate limit, catalog refresh markers. Expired rows are deleted when a build run ends |
| `cache_locks` | `key`, `owner`, `expiration`: the sync step lock (`sync-step`). Taken with an upsert that only succeeds over an expired row. (Applying migrations takes a PostgreSQL advisory lock instead - see the top of this page; the `migrate` row of older versions is no longer written) |
