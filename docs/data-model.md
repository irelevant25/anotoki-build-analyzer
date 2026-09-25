# Data model

Migrations: `backend/database/migrations/2026_09_24_0001..0003_*`. SQLite now (WAL, busy timeout 5 s),
PostgreSQL later; only portable column types are used (JSON columns for nested build data). All timestamps
are UTC (`app.timezone`); the UI converts them.

## Catalog (reference data, filled by `catalog:sync`)

| Table | Key columns | Notes |
| --- | --- | --- |
| `game_characters` | `game`, `key` (unique per game), `name`, `element`, `rarity`, `weapon_type` (GI weapon type / HSR path), `external_id`, `icon_url`, `released_at`, `meta.ids` | Travelers / Trailblazers merge several in-game IDs into `meta.ids` |
| `game_weapons` | same shape | Genshin weapons and HSR light cones |
| `game_sets` | + `kind` (`artifact` \| `relic` \| `planar`), `meta.bonuses` (Genshin 2pc/4pc texts) | `SetBonusGroups` reads the 2pc texts |

Rows can also be created on the fly when an import or a build names something the catalog lacks (reported as
"unmatched"). See [name-matching.md](name-matching.md).

## Accounts

| Table | Notes |
| --- | --- |
| `accounts` | `game`, `uid` (nullable: file imports have none), `nickname`, `level`, `world_level`, `has_full_inventory`, `last_import_at`, `meta` (tool, region…) |
| `import_runs` | `source` (`enka` \| `good` \| `hsr_scanner`), `status` (`success` \| `partial` \| `failed`), `summary` {characters, weapons, items, full_inventory, tool, warnings[], unmatched{bucket: names[]}}, `error` |
| `account_characters` | unique (account, character); `level`, `ascension`, `rank` (constellation/eidolon), `talents`, `final_stats`, `source`, `synced_at` |
| `account_weapons` | `refinement` (= superimposition in HSR), `equipped_character_id` → `game_characters`, `external_uid` |
| `account_items` | artifacts/relics: `slot`, `rarity`, `level`, `main_stat`, `main_value` (nullable), `substats` JSON, `equipped_character_id`, `game_set_id` |

JSON shapes:

- `account_items.substats`: `[{key: "critRate_", value: 10.5, rolls: 3|null}]` (canonical stat keys; `rolls`
  exact from Enka / Reliquary Archiver, null from GOOD / HSR-Scanner).
- `account_characters.talents`: Genshin `{normal, skill, burst, bonus?: {skill: 3}}` (base levels, `bonus` =
  constellation +3); HSR `{basic, skill, ultimate, talent, memo_skill?, memo_talent?}`.
- `account_characters.final_stats`: in-game totals, Genshin via Enka only, e.g. `{hp: 15000, critRate_: 65.2}`.

Import semantics (`AccountImporter`):

- Full inventory files (`GOOD`, HSR scanner) **replace all** weapons and items of the account.
- Enka (showcase) replaces only the gear equipped on the imported characters.
- Characters are upserted, never deleted. `final_stats` are kept when a file import has none (the UI shows
  their source and date).
- Target account: explicit `account_id`, else by (game, uid), else a new account. A target with a different
  UID is refused.
- Unknown external IDs trigger one catalog refresh before falling back to names.

## Builds

| Table | Notes |
| --- | --- |
| `build_sources` | one row per scraper (`key` like `prydwen.hsr`), `enabled`, `last_synced_at`, `last_status`, `last_error` |
| `builds` | unique (source, character, `variant`); `role`, `recommended`, `position`, JSON columns below, `notes`, `source_url`, `source_updated_at`, `game_version`, `content_hash`, `fetched_at`, `changed_at` |
| `sync_runs` | `status` (`running` \| `success` \| `partial` \| `failed`), `stats` {characters_listed/synced/skipped/without_builds, builds_created/updated/unchanged/removed, errors, unmatched, partial_run}, `log` (includes `! unknown weapon/set: …` lines) |

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
  `last_synced_at`.
