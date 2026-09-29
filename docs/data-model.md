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

## Cache and locks

| Table | Notes |
| --- | --- |
| `cache` | `key`, `value` (JSON), `expiration` (Unix time): the per-site request delays and retry counters, showcase answers, the showcase rate limit, catalog refresh markers. Expired rows are deleted when a build run ends |
| `cache_locks` | `key`, `owner`, `expiration`: the sync step lock (`sync-step`), the migration lock. Taken with an upsert that only succeeds over an expired row |
