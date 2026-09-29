# Data model

The schema is `backend/migrations/*.sql`, applied in order by one engine (`src/Db/Migrator.php`) whichever
way it runs: `php migration.php`, "Apply" on the Sources page, or `setup.php`. There is no separate schema
file; a database is what you get by replaying the migrations. `migrations(id, filename, applied_at)` records
what has run.

- `001_adopt_laravel_database.sql` brings a database made by the first (Laravel) version forward, with all
  its rows (see [operations.md](operations.md)); on a new database it does nothing.
- `002_schema.sql` creates the tables (`CREATE … IF NOT EXISTS`, constraint names in the Laravel style
  `{table}_{columns}_unique` / `_foreign` / `_index`, so an adopted database and a new one are the same, down
  to the names - `tests/migrations.test.php` compares them), adds the `CHECK`s and ends with a verification
  gate.

Rules: never edit a migration that has run somewhere; add `NNN_what.sql`. A migration runs in one transaction
(PostgreSQL DDL is transactional), so a failing one leaves nothing behind. A migration that changes data ends
with a `DO $$ … $$` block that checks the result and raises an exception when it is wrong. A closed set of
values is a `CHECK (col IN (…))` on the column (`<table>_game_check`, `game_sets_kind_check`,
`sync_runs_kind_check`), not a lookup table or an enum type. The engine passes `local_user_id` to migrations
as the setting `build_analyzer.local_user_id`.

All times are `TIMESTAMPTZ`, written and read in UTC (the connection sets `TIME ZONE 'UTC'`); the API sends
ISO 8601 with `Z`, the UI converts them. Nested data is `JSON` (not `JSONB`: key order, such as the slots of a
build's main stats, is kept as written).

## Users

| Table | Notes |
| --- | --- |
| `users` | `id` = the anotoki IAM user id (the token's `sub`, not a sequence), `name` = `preferred_username` (refreshed from each token). Rows are created on a person's first request (`Auth/UserProvisioner`); roles are never stored, and there are no passwords |

A database adopted from the Laravel version gives every account and import run without an owner to
`local_user_id` (the owner), creating that user's row.

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
| `import_runs` | `user_id` → `users`, `account_id` (null for a failed attempt without an account), `source` (`enka` \| `good` \| `hsr_scanner`; the API says `showcase` or `file`), `status` (`success` \| `partial` \| `failed`), `summary` {characters, weapons, items, full_inventory, tool, warnings[], unmatched{bucket: names[]}} (the API leaves out `tool`), `error` |
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

## Cache and locks

| Table | Notes |
| --- | --- |
| `cache` | `key`, `value` (JSON), `expiration` (Unix time): the per-site request delays and retry counters, showcase answers, the showcase rate limit, catalog refresh markers. Expired rows are deleted when a build run ends |
| `cache_locks` | `key`, `owner`, `expiration`: the sync step lock (`sync-step`), the migration lock. Taken with an upsert that only succeeds over an expired row |
