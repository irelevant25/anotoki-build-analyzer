# Build analyzer knowledge base

Reference for anyone (human or AI) changing this project. Read the page that matches your task; they are
written to be read on their own. Setting up, running, testing and deploying are in
[DEV.md](../DEV.md).

| Page | Read it when you… |
| --- | --- |
| [architecture.md](architecture.md) | need the big picture: modules, the site gate (anotoki-lib's: not set up, a database update waiting - what the API answers and what people see), sign-in (the anotoki IAM only, through anotoki-lib's auth module; credentials that are sent must verify; somebody signed in at the IAM is signed in here by its hint), roles (EDITOR, ADMIN), the people (the People page, the scrub of the accounts the IAM deleted) and the admin tools (error log, audit log, feedback, backups), visitor accounts (who a request is without signing in, the key, the limits, the clean-up, the move at sign-in), per-user data, neutral names (and the build sites' names, kept out of the repository), the upkeep after an answer (the scrub, the day's backup and tidying up, a stale catalog list), the site's words (keys, kinds, English and Slovak, the Translations and Languages pages), the account's theme, dates and language (signed in, the IAM account's for every anotoki site), the site settings (closing the site, the announcement, Page access - the pages it governs and the API paths reserved) and robots.txt / sitemap.xml, request flows, API and who may call it, frontend structure |
| [data-model.md](data-model.md) | touch tables (users and visitors, per-user accounts, the catalog, builds and their imports, languages and strings, the library's audit log, feedback, people and site settings tables), migrations, JSON columns (builds and their teams, items, talents), import semantics, the builds file, or the accounts file a person exports and imports |
| [builds.md](builds.md) | change what a build is (a variation of a character: role, qualifier, the constellation it needs; targets from a constellation; the teams it is played in, and the teammates an account has), the builds file (format version 3: its fields, what the reader refuses), its import and export on the Builds page and their routes, hand edits and what an import keeps of them (0.7.0), the builds editor and the site's own notes, or what the owner's local tools must deliver |
| [stats-and-slots.md](stats-and-slots.md) | handle stat keys, slots, main stats, roll values, talents, the game's other facts (`Enums/Game`), label parsing |
| [name-matching.md](name-matching.md) | see an "unknown character / weapon / set" in an import, add an alias, refresh the catalog (from genshin.anotoki.eu: the refresh, its triggers, the mapping, the icons) |
| [import-formats.md](import-formats.md) | fix or add an importer (the Character Showcase, GOOD), or an import error or warning (a kind) |
| [analysis.md](analysis.md) | change scoring, grades, the best build (by the character's constellation), stat targets from a constellation, recommendations (kinds the page words), inventory suggestions |
| [operations.md](operations.md) | configure, run, test, refresh the catalog, import and export the builds, a person's own accounts as a file, release new strings or carry reworded ones between installations, sign-in and who may do what, the visitors (limits, which address counts, watching their number), the admin tools (the error log, the audit log, feedback, backups and restoring one), deploy to the FTP-only host (each version's order: 0.3's migration 004 and IAM 007, 0.5.0's genshin.anotoki.eu first, 0.6.0 before the next builds file, 0.7.0's Apply with nothing to import; every migration closes the site until an ADMIN applies it), adopt an old database, troubleshoot, or hit a machine quirk |
| [decisions.md](decisions.md) | wonder why something is the way it is |

Project automation built on this knowledge:

- Skills (`.claude/skills/`): `add-import-format`, `tune-analysis`, `verify-ui`, `deploy-builder`
- Agents (`.claude/agents/`): `analysis-auditor`, `build-analyzer-reviewer`

Keep these pages current: when behavior described here changes, update the page in the same change.
