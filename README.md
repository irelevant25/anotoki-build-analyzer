# Build Analyzer

Import your **Genshin Impact** account, compare each character with recommended builds - by role, and by the
constellation a build needs - and see what to change: set bonuses, main stats, substats, weapon, stat targets and
better pieces you already own. Hosted at builder.anotoki.eu: sign-in through the anotoki IAM, open to every anotoki
account, each person with their own accounts; the builds browser is public, and without signing in what you
import is kept for your browser for 30 days after your last visit.

- **Backend:** PHP 8.4+, Slim 4, PostgreSQL, in `backend/`
- **Frontend:** Angular 21 on the anotoki family's UI kit (`@anotoki/lib`), in `frontend/`

Setting it up, running, testing, building and deploying: **[DEV.md](DEV.md)**.

## Features

- **Guided import wizard** (method → step-by-step preparation → import → result). Data is read either:
  - by **UID**: the characters in your in-game Character Showcase, through a public showcase service, or
  - from a **full inventory export** written by a tool on your PC: a GOOD file.
- **Builds by role and constellation**: each character's builds are its variations - Main DPS, Sub DPS or
  Support, a short name where a character has two of one role ("Main DPS · Melt"), and the constellation a build
  needs ("C4+"). They are put together on the owner's computer from community build sites and imported by an
  editor as one file per game on the admin panel's Builds page (Export, Import a file: a dry run's preview, then
  Import). The site never fetches a build page, and names no build site anywhere.
- **Teams for every build**: the teams a build is played in, the best first - what each teammate does in it, a
  constellation the team asks of one, who can stand in -, each teammate with builds of its own a link to them; on
  your own characters' pages the teammates you already have wear a check, and lead to your analysis of them.
- **The builds browser**: every character with builds, with its roles, and filters - name, element, weapon type,
  rarity, region and the version a character came with ("6.1 (Luna II)") - kept in the page's address.
- **The game catalog from genshin.anotoki.eu**, the owner's own Genshin Impact site: characters, weapons and
  artifact sets with the game's own IDs, refreshed on the Builds page, from the command line, and by the site
  itself every few days; while that site is closed, this one keeps its catalog and the Builds page says why.
- **Sign-in through the anotoki IAM** (iam.anotoki.eu): anybody with an anotoki account imports accounts and
  sees their analysis; editors also import the builds, change them by hand and write the site's notes on them,
  refresh the catalog and reword the site's strings; admins do everything. Everyone's game accounts are their own.
- **Analysis per character and per build**: a 0–100 score and grade, prioritized recommendations, your pieces
  graded against the build, stat targets checked against your in-game totals, and (with a full inventory) the
  best replacement you already own for each slot. Your best build follows your constellation: a build that needs
  one your character does not have shows a lock ("needs C4") and is never counted as your best, and a stat target
  that depends on the constellation is the one for yours. Or choose the build a character is judged by yourself
  ("Use this build"): the account page then grades it by that one.
- **English and Slovak**: the member pages read in either, chosen in the top bar (or taken from the
  browser). The words live in the database, so editors reword them, and admins add or hide languages, in the
  browser (the admin panel's Translations and Languages pages, in English like the rest of `/admin/`). The game's own words -
  stat, set, weapon and character names, "Lv.", "4pc", values like "62.4%" - stay as the game writes them.
- **The anotoki top bar**: the same bar as on the other anotoki sites, on every page outside the admin panel - the
  brand, the sections, the language, the theme and the person's menu (their anotoki account, the admin panel for
  editors and admins, Sign out); on a phone (up to 880 px) the sections and the menu fold into one menu button.
- **The admin panel in the anotoki family's frame** (anotoki-lib 0.10.0, as on every anotoki site): no top bar - its
  pages in a column on the left, under the panel's name, who is signed in, Back to Build Analyzer, Sign out and the
  theme, and the page on the right; on a phone (up to 860 px) a row with the panel's name and Menu, the column in a
  drawer. Its menu has the family's one shape (anotoki-lib 0.11.0): Builds; Localization - Translations, Languages;
  System - Site settings, People, Feedback, Error log, Audit log, Backups, Migrations.
- **Site settings** (anotoki-lib 0.11.0, the ADMIN's, as on every anotoki site): close the site for maintenance (the
  ADMIN uses it as it is; an administrator signed out signs in at `/staff`), put an announcement above every page,
  and keep a page - the dashboard, the import, an account, a character, the builds - for people signed in, for editors,
  for administrators, or switch it off, with the API paths it reads.
- **robots.txt and sitemap.xml**, answered by the site's PHP: robots kept out of the admin panel, the API, the
  sign-in's way back and a reader's own accounts; the sitemap lists the legal pages, and while visitors are welcome
  the public pages and every character's builds - never what the site settings hide.
- **One anotoki account, one look**: signed in, the theme (the admin panel's too), the language and how dates
  are written are the anotoki account's - the same on every anotoki site - and a choice in the bar is saved to
  it. Without signing in, each browser keeps its own.
- **What's new**: the version at the foot of every page, and its button, open the changelog
  (`frontend/public/changelog.json`).
- **About, Support, Terms, Privacy and Contact**: the anotoki family's pages, linked at the foot of every page
  above the fan clause (a fan-made project, not affiliated with or endorsed by HoYoverse) - what the site keeps,
  what for and how long, its browser storage and who else handles data, true of the code as it is
  (`frontend/src/app/sites/main/legal/legal-facts.ts`); Contact opens the feedback form.

## Quick start

Requirements: PHP 8.4+ (`pdo_pgsql`, `openssl`, `mbstring`, `curl`, `zlib`), PostgreSQL, Node.js 22.12+, Bun,
and the anotoki IAM running locally (`../anotoki-iam`) to sign in.

```bash
cd backend && composer install && composer start   # the API on http://localhost:8400
cd frontend && npm start                           # bun ci, then the app at http://localhost:4600
```

The app then leads you to `setup.php`: the database (`anotoki_builder`, port 5432) and the IAM (address and
client id) - nothing else. Then, signed in as the IAM's administrator, open the admin panel's **Builds** page:
**Refresh catalog**, then **Import a file** with a builds file (one exported from another installation's Builds
page, or one made as [docs/builds.md](docs/builds.md) describes) - and import an account. Testing, the command
line and the upload for a host: [DEV.md](DEV.md).

## Importing your data

| Method | What it reads | You get |
| --- | --- | --- |
| UID (Character Showcase) | up to 12 showcase characters | equipped gear, exact roll counts, in-game totals |
| Exporter file | a GOOD file | every character, weapon and artifact |

Both can be combined on one account: import by UID for in-game totals, then upload a file for the full
inventory. The wizard shows the in-game steps. Accounts belong to the person who imported them (two people can
import the same UID, each into their own account). From the command line, `--user` names whose account it is
(an IAM user id; required):

```bash
cd backend
php import-account.php genshin 700123456 --user=1              # the Character Showcase of a UID, for IAM user 1
php import-account.php genshin path/to/good.json --user=5 --account=3
```

A name the game catalog does not know yet (a brand-new character or set) is skipped and listed in the import
result: an import by UID refreshes that list of the catalog first (at most once an hour), and once the catalog has
caught up - an editor's refresh, or the site's own every few days - import again. The formats and the services
behind them are described in [docs/import-formats.md](docs/import-formats.md).

## Builds

- **A build is a variation of a character**: its role (Main DPS, Sub DPS or Support), a short qualifier where a
  character has two builds of one role, and the constellation it needs (C0-C6); then its weapons in order, artifact
  sets, main stats per slot, substats by priority, talent priority, stat targets (one may start from a
  constellation: "C2+"), the teams it is played in (since 0.6.0: each teammate's role, the constellation the team
  asks of it, who can stand in) and the site's own notes on it and its teams (since 0.7.0: written in the admin
  panel's builds editor, in English and - where an editor wants - Slovak, which a reader of Slovak sees). A
  character's builds are in its order of preference: the first is its main one.
- **They come in as one file per game** (`builds-genshin-<date>.json`), imported by an editor on the admin panel's
  **Builds** page: the file is checked, a dry run shows what it would change (new, updated, unchanged, removed, the
  characters skipped, each change, and a question per build where the file and an editor's hand edits disagree), and
  **Import** makes the game's builds the file's - but for what editors did by hand (kept: an import asks only where the
  file changes the same part) and for characters this installation's catalog lacks, which are skipped and named. A build the file has again keeps its id; every
  import is recorded, with who made it. **Export** saves a game's builds as such a file: a copy of them (without the
  site's own notes, which never travel in a builds file), or the builds for another installation.
- **Editors change builds by hand** (since 0.7.0): the admin panel's builds editor, one character's builds at a
  time - its tabs, a build made, copied, moved, removed or brought back, its variation, the site's notes, and a form
  for its weapons, artifact sets, main stats, substats, talents, stat targets and teams (each team's note with it) -,
  saved in one go and kept by every import after it.
- **The site never fetches a build page.** The file is put together on the owner's computer by the owner's own
  tools, which are not in this repository (the git-ignored `local/` folder): they read the community build sites
  politely (their robots.txt, each site's own wait between pages), keep the pages on disk, sort each site's builds
  by role, pick per role and constellation, fill only gaps (talents, Energy Recharge targets, substats), give each
  build the teams the sites recommend for the role it is, make every text neutral, check the file with this site's
  own reader and refuse an export that shrinks by more than a fifth.
  What a build and the file are, and what the tools must deliver: [docs/builds.md](docs/builds.md).
- **Nothing names a build site** - not the pages, not the API, not this repository. Their names live in an
  untracked settings file of the owner's (`backend/config/neutral.local.php`, uploaded to the host by hand), which
  keeps them out of what the site shows, and `php backend/tools/check-names.php` checks the tracked files for them.

## How the analysis works

Every owned character is compared with each of its builds. Each part is scored from 0 to 100:

| Part | Scoring |
| --- | --- |
| Weapon | #1 on the list = 100, −8 per rank, unlisted = 40; better options you own are named |
| Set bonuses | first satisfied option (4pc, 2+2, "any two" 2pc groups, 2pc stat groups) |
| Main stats | per variable slot: first choice 100, alternatives 85 / 75, anything else 0 |
| Substats | rolls (value ÷ max roll) weighted by the build's priority order (1.0 / 0.8 / 0.6 / 0.45 / 0.3; flat stats about 0.35 of their % version), compared with an ideal piece (all upgrades in the best stat). 70 % of ideal already counts as full marks. A build that lists no substats is scored by substats derived from its own main stats. |
| Stat targets | in-game totals (showcase imports) against the build's targets: the requirements the build states (Energy Recharge mostly; of a stat's targets, the one from the highest constellation the character has) and every substat in three tiers - basic, intermediate, advanced - worked out from the game's numbers for the weapon in hand (`docs/analysis.md`, "Stat targets") |

The overall score weights the parts 20 / 25 / 20 / 25 / 10 (parts without data are skipped). Grades are
S ≥ 90, A ≥ 80, B ≥ 65 and C ≥ 50. A character's best build is the best scored of those its constellation reaches
(a tie goes to the earlier one); a build it does not reach shows a lock and "needs C4", and is never its best. The
account page grades a character by its best - the closest match - unless you chose another for it ("Use this build"
on the character page; "Choose automatically" undoes it). With
a full inventory, the app suggests the best replacement you own for each slot; a suggestion never makes your set
bonus worse. Details: [docs/analysis.md](docs/analysis.md).

## Sign-in and roles

People sign in at the **anotoki IAM** (iam.anotoki.eu, OpenID Connect) - on a host and on your own computer
alike; there is no other way in. This app is its client `build-analyzer`, and every anotoki account may use
it: signed in, a person imports their own accounts and sees their analysis. Without signing in, a visitor does
the same: their first import makes a visitor account on the server, which their browser opens with a key the
server gave it; it is deleted 30 days after the last visit, and moves into the person's account when they
sign in. Whether visitors are welcome at all is the IAM's switch for the application ("Sign-in required" in its
admin panel, off for this site): when it is on, nobody gets anything without signing in - the server reads the
switch from the IAM and refuses visitors and the signed-out builds browser, and every page sends people to sign
in first; the visitor key a browser holds is kept meanwhile, and what that visitor imported still moves into their
account when they sign in. A signed-in person can also save their game accounts as a file and import such a file here or on
another copy: an account the file has newer data for is replaced, one only the file has is added, and an
import never deletes an account. **EDITOR** also imports and exports the builds files, changes builds by hand and
writes the site's notes on them, refreshes the catalog and rewords the site's strings; **ADMIN** does all of that and also applies database updates, adds, renames,
switches and deletes languages, deletes a person's data (the People page) and has the admin tools - the error
log, the audit log, the visitors' feedback and the database's backups. Roles are granted in the IAM's admin panel.
Somebody already signed in to their anotoki account (on another anotoki site) is signed in here without a click.
The backend checks every access token itself (signature against the IAM's published keys, issuer, audience,
expiry), and checks the roles on every route. Signing in, the People page and the admin tools are the anotoki
family's, from its shared library. See [docs/operations.md](docs/operations.md).

**Current state** (version 0.7.0): backend and frontend are as described here - Genshin Impact alone (the code
keeps its shape for more than one game), the builds imported as one file per game - each build with the teams it is
played in (0.6.0) -, the builds editor (0.7.0: hand edits and the site's own notes, kept by every import), the catalog
from genshin.anotoki.eu, sign-in through the IAM only, visitors (somebody not signed in imports and uses their
accounts; the dashboard shows nobody the ways in, a visitor their accounts under a notice of how long they are
kept; at sign-in they move into the account - unless the IAM says this site requires a sign-in: then every page
asks for one first), the admin panel under `/admin/` in the anotoki family's admin frame (editors: Builds and Translations; admins also Languages,
People, Migrations - the database's updates seen and applied, as on the IAM - and the error log, the audit log,
feedback and backups), the "Your data" card for every signed-in person (export, import with a preview), and
Feedback at the foot of every page, under the anotoki top bar with the version in the footer. Version 0.7.0's
migrations 022-023 are applied on the host by an ADMIN at once after the upload, and nothing is imported again
(docs/operations.md, "Deploying 0.7.0"; each earlier version's order is there too). The browser check (`node .claude/skills/verify-ui/scripts/run.mjs`) goes
through all of it on a throwaway database.

**While the site is not ready** it behaves as every anotoki site does (the family's shared library,
[anotoki-lib](https://github.com/irelevant25/anotoki-lib), and the owner's decision of 2026-10-07): while a
database update waits, everybody reads "The site is being updated" in their language (a quiet Sign in while
nobody is signed in) and the page comes back by itself once it is applied; the site's ADMIN reads "A database
update is waiting" and reaches the Migrations page, the one page that works then - no other page runs behind
the waiting page. Not set up yet: the setup page is offered only while the site was never installed - never
again after `setup.php` finished on a host.

## Deploying

The host needs only Apache, PHP 8.4 and PostgreSQL, with files uploaded by FTP: no shell, cron or worker.
`bun run anotoki-deploy build` (in `frontend/`; anotoki-lib's deploy tool) assembles the release in `dist/` -
`frontend/` and `backend/`, with `vendor/` and the built frontend, and no configuration or tests - replaced whole
on the host; `setup.php` writes the host's configuration and builds the database, and the names file
(`config/neutral.local.php`) is put beside it by hand once; later database updates are applied on the admin
panel's Migrations page by an ADMIN, at once after the upload - until then the site tells everybody else it is
being updated. The builds come from a file imported on the Builds page, never from the host itself. The steps
and the upload map: [DEV.md](DEV.md) ("Deploying").

## Hosting checklist

What the host and Cloudflare need for this site - check it before the first upload and after any change of
hosting:

- **PHP 8.4 or newer** with `pdo_pgsql`, `openssl`, `mbstring`, `curl`, `json` and `zlib` (the backups). `tools/host-check.php` checks all of it on the host: write a long
  random value into its `HOST_CHECK_TOKEN`, upload it beside `index.html`, open
  `https://<the site>/host-check.php?token=<that value>`, and delete it afterwards (the page has a button for
  it). It is never part of a build.
- **Cloudflare** (the zone's settings, the same for every anotoki site):
  - SSL/TLS **Full (strict)**: the connection from Cloudflare to the host is https - the site's secure cookies
    need it end to end (the host check says which it is).
  - Network -> **Pseudo IPv4 not "Overwrite headers"**: that setting would put a made-up IPv4 address into
    `CF-Connecting-IP` for IPv6 visitors.
  - The site believes the visitor's address in `CF-Connecting-IP` only on a connection from Cloudflare's
    published ranges (the anotoki library's list), and counts every limit by it.
  - Rules -> Transform Rules -> **Modify Request Header**: "Set static" `X-Anotoki-Origin` to the origin
    secret, for every request of the zone - and the same value in `backend/config/cloudflare.local.php`
    (`<?php return ['origin_secret' => '...'];`, put there by hand, starting with `<?php` - no byte-order mark,
    no blank line before it; a long random value, 32+ characters). With it the site believes `CF-Connecting-IP`
    only on a request that carries the secret - anybody's Cloudflare Worker can connect from Cloudflare's ranges;
    without the file it believes `CF-Connecting-IP` from Cloudflare's ranges alone; with one it cannot read
    cleanly, or one that names no secret (blank, a misspelt key), from nobody (every visitor counts as their edge
    until it is mended) - deleting the file is the way to switch it off. Set the rule first, then the file.
- **The names file**: `backend/config/neutral.local.php` - the build sites' names, which the site keeps out of
  everything it shows - put there by hand once, beside the other settings (starting with `<?php`, like them; never
  in a release, and an upload leaves it where it is).
- **genshin.anotoki.eu** answers its catalog lists (`https://genshin.anotoki.eu/api/catalog/characters`, `/weapons`,
  `/artifacts`): the catalog is refreshed from them.
- **After an upload**: the private folders (`storage/`, `config/`) answer 403 in a browser; the host check's
  "Who connects" says Cloudflare, not a proxy of the host's (behind one, every visitor would share one
  address); pending database updates are applied on the admin panel's Migrations page.

## Project layout

```text
backend/
  api/                  the front controller (index.php), app.php, middleware/, routes/
  src/                  App (container), Config, Db/ (PDO; the site's migrations on anotoki-lib's engine), Auth/, Http/ (fetcher),
                        Cache/ (the cache table, the day's tidying up), Models/, Repositories/, Presenters/, Commands/,
                        Setup/, Support/ (stats, talents, keys, neutral text), People/ (the people module on this
                        site's users), Admin/ (the admin tools), Web/ (the pipeline, the address, after the answer,
                        SitePages - the site settings' and robots' part of this site)
  src/Services/         Catalog/ (the game catalog from genshin.anotoki.eu, name resolution, the upkeep), Import/
                        (showcase + GOOD parsers, AccountImporter, ImportKinds), Builds/ (the builds file and its
                        import), Transfer/ (a person's accounts file), Analysis/ (scores, recommendations, the best
                        build for a constellation), Translations/ (the site's words on anotoki-lib's translations
                        module: its released languages, its check that nothing names another site)
  config/               app.php (committed) and *.local.php (per installation: written by setup.php, some by hand)
  migrations/           the schema and the site's words, as SQL files applied in order
  tests/                php tests/run.php: throwaway PostgreSQL databases, fixtures, a fake network, the
                        library's test IAM (and its stand-in IAM, in vendor/)
  tools/                check-release.php/.mjs (the release names no other site), check-names.php (no tracked
                        file names a build site), php.sh (PHP 8.4+ for bash)
  setup.php, router.php, migration.php, sync-catalog.php, import-account.php, translations.php, backup.php
frontend/src/app/       the anotoki family's folders, the genshin site's: api/, shared/, sites/main/, sites/admin/
  api/                  API client, models
  shared/               what both halves use: metadata store, changelog and its dialog, sign-in (on anotoki-lib's auth
                        module), the visitor key, i18n/ (the site's words on anotoki-lib's service: typed keys, pipes,
                        the compiled English, a variation's words), site-status (anotoki-lib's site gate and Migrations
                        page, wired to this site), site-settings (anotoki-lib's site settings, wired to this site),
                        the one Sign out, the character avatar, ...
  sites/main/           everybody's pages (main.routes.ts): app-layout (their frame, with the announcement and the top
                        bar), dashboard, import wizard, account, character analysis (a tab per variation), builds (the
                        browser with its filters), not-open (a page the site settings closed, where the dashboard is
                        closed too), legal/ (the legal pages' frame with the site's facts; About and Support, /about
                        and /support), common/ (the top bar, build detail, item card, grade badge, score bar, the
                        visitor notice, the ways in for somebody new, the "Your data" card, game terms, the filters
                        kept in the address); /staff, where an administrator signs in while the site is closed
  sites/admin/          the admin panel (admin.routes.ts): app.ts (its frame - anotoki-lib's admin frame, the pages
                        in a column on the left, no top bar), builds, build-editor, translations, languages,
                        migrations - translations, languages and migrations are anotoki-lib's pages, and so are
                        Site settings, People and the admin tools, routed straight. Both frames with anotoki-lib's
                        footer (version, What's new, Feedback; About · Support · Terms · Privacy · Contact)
frontend/public/        changelog.json, favicon (robots.txt and sitemap.xml are the backend's: anotoki-lib's Robots)
docs/                   knowledge base (start at docs/README.md)
.claude/                project skills and agents for AI-assisted work
local/                  (git-ignored, never in the repository) the owner's own tools that make the builds file
```

## Working on the project with AI

- [CLAUDE.md](CLAUDE.md) is loaded automatically by Claude Code and routes each kind of task to the right
  documentation, skill and agent; other AI tools can read it, and `.claude/`, as plain Markdown.
- [docs/](docs/README.md) is the knowledge base: architecture, data model, stats and slots, name matching,
  builds (what a build is, the builds file and its import), import formats, analysis, operations and decisions.
- Project skills (`.claude/skills/`): `add-import-format`, `tune-analysis`, `verify-ui`, `deploy-builder`. Project
  agents (`.claude/agents/`): `analysis-auditor`, `build-analyzer-reviewer`.

## Notes

- The builds are only as fresh as the last file imported: the site never reads a build site itself. A newer file
  is made on the owner's computer and imported on the Builds page.
- This is a small community tool, and the services it reads - the showcase service, genshin.anotoki.eu's catalog -
  are shared by many: keep the default request delays.
- Not implemented yet: an import through the game's official web account. It would return every character
  without a tool, but needs your login cookies.
