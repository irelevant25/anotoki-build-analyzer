# Build analyzer knowledge base

Reference for anyone (human or AI) changing this project. Read the page that matches your task; they are
written to be read on their own. Setting up, running, testing and deploying are in
[DEVELOPING.md](../DEVELOPING.md).

| Page | Read it when you… |
| --- | --- |
| [architecture.md](architecture.md) | need the big picture: modules, the setup gate, sign-in (anotoki IAM), per-user data, neutral names, stepped syncs, request flows, API and who may call it, frontend structure |
| [data-model.md](data-model.md) | touch tables (users, per-user accounts, sync runs), migrations, JSON columns (builds, items, talents), import semantics or the builds files a source is exported to |
| [stats-and-slots.md](stats-and-slots.md) | handle stat keys, slots, main stats, roll values, label parsing |
| [name-matching.md](name-matching.md) | see "unknown weapon / set / character", add aliases, sync the catalog |
| [build-sources.md](build-sources.md) | fix or add a scraper |
| [import-formats.md](import-formats.md) | fix or add an importer (showcase, GOOD, HSR scanner exports) |
| [analysis.md](analysis.md) | change scoring, grades, recommendations, inventory suggestions |
| [operations.md](operations.md) | configure, run, test, sync, move a source's builds to another installation, switch sign-in on or off, deploy to the FTP-only host, adopt an old database, troubleshoot, or hit a machine quirk |
| [decisions.md](decisions.md) | wonder why something is the way it is |

Project automation built on this knowledge:

- Skills (`.claude/skills/`): `add-build-source`, `debug-build-sync`, `add-import-format`, `tune-analysis`, `verify-ui`
- Agents (`.claude/agents/`): `build-source-researcher`, `sync-doctor`, `analysis-auditor`, `build-analyzer-reviewer`

Keep these pages current: when behavior described here changes, update the page in the same change.
