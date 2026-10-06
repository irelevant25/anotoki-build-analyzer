# Build analyzer knowledge base

Reference for anyone (human or AI) changing this project. Read the page that matches your task; they are
written to be read on their own. Setting up, running, testing and deploying are in
[DEV.md](../DEV.md).

| Page | Read it when you… |
| --- | --- |
| [architecture.md](architecture.md) | need the big picture: modules, the setup gate, sign-in (the anotoki IAM only; credentials that are sent must verify), roles (EDITOR, ADMIN), visitor accounts (who a request is without signing in, the key, the limits, the clean-up, the move at sign-in), per-user data, neutral names, stepped syncs, the site's words (keys, kinds, English and Slovak, the Translations and Languages pages), the account's theme, dates and language (signed in, the IAM account's for every anotoki site), request flows, API and who may call it, frontend structure |
| [data-model.md](data-model.md) | touch tables (users and visitors, per-user accounts, sync runs, languages and strings), migrations, JSON columns (builds, items, talents), import semantics, the builds files a source is exported to, or the accounts file a person exports and imports |
| [stats-and-slots.md](stats-and-slots.md) | handle stat keys, slots, main stats, roll values, label parsing |
| [name-matching.md](name-matching.md) | see "unknown weapon / set / character", add aliases, sync the catalog |
| [build-sources.md](build-sources.md) | fix or add a scraper |
| [import-formats.md](import-formats.md) | fix or add an importer (showcase, GOOD, HSR scanner exports), or an import error or warning (a kind) |
| [analysis.md](analysis.md) | change scoring, grades, recommendations (kinds the page words), inventory suggestions |
| [operations.md](operations.md) | configure, run, test, sync, move a source's builds to another installation, a person's own accounts as a file, release new strings or carry reworded ones between installations, sign-in and who may do what, the visitors (limits, which address counts, watching their number), deploy to the FTP-only host (the order for migration 004 and IAM 007), adopt an old database, troubleshoot, or hit a machine quirk |
| [decisions.md](decisions.md) | wonder why something is the way it is |

Project automation built on this knowledge:

- Skills (`.claude/skills/`): `add-build-source`, `debug-build-sync`, `add-import-format`, `tune-analysis`, `verify-ui`
- Agents (`.claude/agents/`): `build-source-researcher`, `sync-doctor`, `analysis-auditor`, `build-analyzer-reviewer`

Keep these pages current: when behavior described here changes, update the page in the same change.
