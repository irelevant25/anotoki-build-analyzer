# Hoyo Analyzer knowledge base

Reference for anyone (human or AI) changing this project. Read the page that matches your task; they are
written to be read on their own.

| Page | Read it when you… |
| --- | --- |
| [architecture.md](architecture.md) | need the big picture: modules, request flows, API, frontend structure |
| [data-model.md](data-model.md) | touch tables, JSON columns (builds, items, talents) or import semantics |
| [stats-and-slots.md](stats-and-slots.md) | handle stat keys, slots, main stats, roll values, label parsing |
| [name-matching.md](name-matching.md) | see "unknown weapon / set / character", add aliases, sync the catalog |
| [build-sources.md](build-sources.md) | fix or add a scraper (genshin-builds.com, Prydwen, genshin.gg) |
| [import-formats.md](import-formats.md) | fix or add an importer (Enka, GOOD, Reliquary Archiver / HSR-Scanner, HoYoLAB notes) |
| [analysis.md](analysis.md) | change scoring, grades, recommendations, inventory suggestions |
| [operations.md](operations.md) | run, test, sync, schedule, troubleshoot, or hit a machine quirk |
| [decisions.md](decisions.md) | wonder why something is the way it is |

Project automation built on this knowledge:

- Skills (`.claude/skills/`): `add-build-source`, `debug-build-sync`, `add-import-format`, `tune-analysis`, `verify-ui`
- Agents (`.claude/agents/`): `build-source-researcher`, `sync-doctor`, `analysis-auditor`, `hoyo-reviewer`

Keep these pages current: when behavior described here changes, update the page in the same change.
