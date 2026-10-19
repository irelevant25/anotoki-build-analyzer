# Build Analyzer

Import your **Genshin Impact** and **Honkai: Star Rail** accounts, collect recommended builds from community
build sites, and see per character what to change: set bonuses, main stats, substats, weapon / light cone,
stat targets and better pieces you already own. Hosted at builder.anotoki.eu: sign-in through the anotoki IAM,
open to every anotoki account, each person with their own accounts; the builds browser is public, and without
signing in what you import is kept for your browser for 30 days after your last visit.

- **Backend:** PHP 8.4+, Slim 4, PostgreSQL, in `backend/`
- **Frontend:** Angular 21 on the anotoki family's UI kit (`@anotoki/lib`), in `frontend/`

Setting it up, running, testing, building and deploying: **[DEV.md](DEV.md)**.

## Features

- **Guided import wizard** (game → method → step-by-step preparation → import → result). Data is read either:
  - by **UID**: the characters in your in-game Character Showcase, through a public showcase service, or
  - from a **full inventory export** written by a tool on your PC: a GOOD file (Genshin) or a relic
    scanner's JSON export (Star Rail).
- **Build sync** from three community build sites, with every build variant a site lists (e.g. Raiden "DPS &
  Energy Support" and "Hyperbloom"), started by an editor on the Sources page and run in short steps from the
  browser: no queue worker, scheduler or long-running process. The app shows the sources as "Source 1",
  "Source 2" …; it names no other site anywhere in its UI.
- **Sign-in through the anotoki IAM** (iam.anotoki.eu): anybody with an anotoki account imports accounts and
  sees their analysis; editors also run the syncs and reword the site's strings; admins do everything.
  Everyone's game accounts are their own.
- **Analysis per character and per build**: a 0–100 score and grade, prioritized recommendations, your pieces
  graded against the build, stat targets checked against your in-game totals, where the sources agree, and
  (with a full inventory) the best replacement you already own for each slot.
- **English and Slovak**: the member pages read in either, chosen in the top bar (or taken from the
  browser). The words live in the database, so editors reword them, and admins add or hide languages, in the
  browser (the admin panel's Translations and Languages pages, in English like the rest of `/admin/`). The game's own words -
  stat, set, weapon and character names, "Lv.", "4pc", values like "62.4%" - stay as the game writes them.
- **The anotoki top bar**: the same bar as on the other anotoki sites - the brand, the sections, the language,
  the theme and the person's menu (their anotoki account, the admin panel for editors and admins, Sign out); on
  a phone (up to 880 px) the sections and the menu fold into one menu button.
- **One anotoki account, one look**: signed in, the theme (the admin panel's too), the language and how dates
  are written are the anotoki account's - the same on every anotoki site - and a choice in the bar is saved to
  it. Without signing in, each browser keeps its own.
- **What's new**: the version at the foot of every page, and its button, open the changelog
  (`frontend/public/changelog.json`).
- **Terms, Privacy and Contact**: the anotoki family's pages, linked at the foot of every page above the fan
  clause (a fan-made project, not affiliated with or endorsed by HoYoverse) - what the site keeps, what for and how
  long, its browser storage and who else handles data, true of the code as it is
  (`frontend/src/app/core/legal-facts.ts`); Contact opens the feedback form.

## Quick start

Requirements: PHP 8.4+ (`pdo_pgsql`, `openssl`, `mbstring`, `curl`, `dom`, `zlib`), PostgreSQL, Node.js 22.12+, Bun,
and the anotoki IAM running locally (`../anotoki-iam`) to sign in.

```bash
cd backend && composer install && composer start   # the API on http://localhost:8400
cd frontend && npm start                           # bun ci, then the app at http://localhost:4600
```

The app then leads you to `setup.php`: the database (`anotoki_builder`, port 5432) and the IAM (address and
client id) - nothing else. Then, signed in as the IAM's administrator, **Sources → Refresh catalog → Sync all**,
and import an account. Testing, the command line and the upload for a host: [DEV.md](DEV.md).

## Importing your data

| Method | Genshin Impact | Honkai: Star Rail | You get |
| --- | --- | --- | --- |
| UID (Character Showcase) | up to 12 showcase characters | showcase + support characters | equipped gear, exact roll counts, in-game totals (Genshin) |
| Exporter file | GOOD file | relic scanner export (JSON) | every character, weapon and artifact / relic |

Both can be combined on one account: import by UID for in-game totals, then upload a file for the full
inventory. The wizard shows the in-game steps. Accounts belong to the person who imported them (two people can
import the same UID, each into their own account). From the command line, `--user` names whose account it is
(an IAM user id; required):

```bash
cd backend
php import-account.php genshin 700123456 --user=1              # the Character Showcase of a UID, for IAM user 1
php import-account.php hsr path/to/export.json --user=5 --account=3
```

A name the game catalog does not know yet (a brand-new character or set) is skipped and listed in the import
result; after an editor refreshes the catalog, import again. The formats and the services behind them are
described in [docs/import-formats.md](docs/import-formats.md).

## Syncing builds

- An editor (or an admin) syncs on the **Sources** page: **Sync all** (every enabled source not synced successfully in the last
  20 hours, so extra clicks never hit a site twice a day), **Sync now** on one source, and **Refresh catalog**.
  A stale catalog is refreshed first automatically.
- A sync runs in short steps while the page is open: each step is one request that fetches a page or two,
  respects the site's crawl delay and saves its progress. Closing the tab pauses it; Resume continues where it
  stopped. Nothing is scheduled.
- `php sync-builds.php` (in `backend/`) does the same from a shell. Options: `--force`, `--character=hu_tao`
  (repeatable), `--limit=5`, `--game=hsr`, `--check-names` (lists each site's characters and reports names that
  do not match the game catalog), and `--dry-run` (parses and compares with the stored builds without writing;
  add `--cache-hours=96` to replay pages fetched in the last days).
- **Export** and **Import** on each source carry its builds to another installation as a file (or keep them as a
  backup): sync a source on a computer its site answers, export it, and import it on a server the site refuses.
  An import replaces the source's builds with the file's and counts as a sync of that source.
- Requests identify themselves, wait between requests to the same site, retry 429/5xx responses and cache pages
  for 12 hours. The sites and how each is read: [docs/build-sources.md](docs/build-sources.md).

## How the analysis works

Every owned character is compared with every build of every source. Each part is scored from 0 to 100:

| Part | Scoring |
| --- | --- |
| Weapon / light cone | #1 on the list = 100, −8 per rank, unlisted = 40; better options you own are named |
| Set bonuses | first satisfied option (4pc, 2+2, "any two" 2pc groups, 2pc stat groups); HSR: relics 60 %, ornaments 40 % |
| Main stats | per variable slot: first choice 100, alternatives 85 / 75, anything else 0 |
| Substats | rolls (value ÷ max roll) weighted by the build's priority order (1.0 / 0.8 / 0.6 / 0.45 / 0.3; flat stats about 0.35–0.4 of their % version), compared with an ideal piece (all upgrades in the best stat). 70 % of ideal already counts as full marks. |
| Stat targets | in-game totals (Genshin showcase imports) against ER requirements, CRIT goals, HSR endgame stats |

The overall score weights the parts 20 / 25 / 20 / 25 / 10 (parts without data are skipped). Grades are
S ≥ 90, A ≥ 80, B ≥ 65 and C ≥ 50. The best-matching build is starred. With a full inventory, the app suggests
the best replacement you own for each slot; a suggestion never makes your set bonus worse. Details:
[docs/analysis.md](docs/analysis.md).

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
import never deletes an account. **EDITOR** also syncs builds and
the catalog, reads the sync history, moves a source's builds through a file and rewords the site's strings;
**ADMIN** does all of that and also switches sources on and off, applies database updates, adds, renames,
switches and deletes languages, deletes a person's data (the People page) and has the admin tools - the error
log, the audit log, the visitors' feedback and the database's backups. Roles are granted in the IAM's admin panel.
Somebody already signed in to their anotoki account (on another anotoki site) is signed in here without a click.
The backend checks every access token itself (signature against the IAM's published keys, issuer, audience,
expiry), and checks the roles on every route. Signing in, the People page and the admin tools are the anotoki
family's, from its shared library. See [docs/operations.md](docs/operations.md).

**Current state**: backend and frontend are as described here - sign-in through the IAM only, visitors
(somebody not signed in imports and uses their accounts; the dashboard shows nobody the ways in, a visitor their
accounts under a notice of how long they are kept; at sign-in they move into the account - unless the IAM says
this site requires a sign-in: then every page asks for one first), the admin panel under
`/admin/` (editors: Sources and Translations; admins also Languages, People, Migrations - the database's
updates seen and applied, as on the IAM - and the error log, the audit log, feedback and backups), the "Your
data" card for every signed-in person (export, import with a preview), and Feedback at the foot of every page,
under the anotoki top bar with the version in the footer - version 0.4.0 (unreleased). Migration 004 (the
visitors' schema and every word of 0.3.0) is complete; it is applied
when 0.3.0 is handed over and deployed: on the host Apply pending on the Migrations page, by an ADMIN, at once
after the upload - and only then the IAM's migration 007 (DEV.md, docs/operations.md). The browser check
(`node .claude/skills/verify-ui/scripts/run.mjs`) goes through all of it on a throwaway database.

**While the site is not ready** it behaves as every anotoki site does (the family's shared library,
[anotoki-lib](https://github.com/irelevant25/anotoki-lib), and the owner's decision of 2026-10-07): while a
database update waits, everybody reads "The site is being updated" in their language (a quiet Sign in while
nobody is signed in) and the page comes back by itself once it is applied; the site's ADMIN reads "A database
update is waiting" and reaches the Migrations page, the one page that works then - no other page runs behind
the waiting page. Not set up yet: the setup page is offered only while the site was never installed - never
again after `setup.php` finished on a host.

## Deploying

The host needs only Apache, PHP 8.4 and PostgreSQL, with files uploaded by FTP: no shell, cron or worker.
`npx anotoki-deploy build` (in `frontend/`; anotoki-lib's deploy tool) assembles the release in `dist/` - `frontend/` and
`backend/`, with `vendor/` and the built frontend, and no configuration or tests - replaced whole on the host; `setup.php` writes the host's configuration and builds the database; later database
updates are applied on the admin panel's Migrations page by an ADMIN, at once after the upload - until then the
site tells everybody else it is being updated. The steps and the upload map: [DEV.md](DEV.md)
("Deploying").

## Hosting checklist

What the host and Cloudflare need for this site - check it before the first upload and after any change of
hosting:

- **PHP 8.4 or newer** with `pdo_pgsql`, `openssl`, `mbstring`, `curl`, `dom`, `json` and `zlib` (the backups). `tools/host-check.php` checks all of it on the host: write a long
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
- **After an upload**: the private folders (`storage/`, `config/`) answer 403 in a browser; the host check's
  "Who connects" says Cloudflare, not a proxy of the host's (behind one, every visitor would share one
  address); pending database updates are applied on the admin panel's Migrations page.

## Project layout

```text
backend/
  api/                  the front controller (index.php), app.php, middleware/, routes/
  src/                  App (container), Config, Db/ (PDO; the site's migrations on anotoki-lib's engine), Auth/, Http/ (fetcher),
                        Cache/, Models/, Repositories/, Presenters/, Commands/, Setup/, Support/ (stats,
                        talents, keys, neutral text), People/ (the people module on this site's users),
                        Admin/ (the admin tools), Web/ (the pipeline, the address, after the answer)
  src/Services/         Catalog/ (game catalog, name resolution), Import/ (showcase + file parsers,
                        AccountImporter, ImportKinds), Builds/ (BuildSync, the scrapers, page-data parsers), Sync/
                        (SyncRunner: runs worked through in steps), Analysis/ (scores, recommendations),
                        Translations/ (the site's words on anotoki-lib's translations module: its released
                        languages, its check that nothing names another site)
  config/               app.php (committed) and *.local.php (per installation, written by setup.php)
  migrations/           the schema and the site's words, as SQL files applied in order
  tests/                php tests/run.php: throwaway PostgreSQL databases, fixtures, a fake network, the
                        library's test IAM (and its stand-in IAM, in vendor/)
  tools/                check-release.php/.mjs (the release names no other site), php.sh (PHP 8.4+ for bash)
  setup.php, router.php, migration.php, sync-builds.php, sync-catalog.php, import-account.php, translations.php,
  backup.php
frontend/src/app/
  core/                 API client, models, metadata store, changelog, sign-in (on anotoki-lib's auth module), the
                        visitor key, sync driver, i18n/ (the site's words on anotoki-lib's service: typed keys,
                        pipes, the compiled English), game
                        terms, site-status (anotoki-lib's site gate and Migrations page, wired to this site)
  layouts/              top-bar, app-layout, legal-layout (the legal pages with the site's facts; About and Support,
                        /about and /support), admin-layout (the admin panel's frame) - each layout with anotoki-lib's
                        footer (version, What's new, Feedback; About · Support · Terms · Privacy · Contact)
  shared/               build detail, item card, grade badge, changelog dialog, confirm dialog, the visitor notice,
                        the ways in for somebody new, the "Your data" card, ...
  pages/                dashboard, import wizard, account, character analysis, builds;
                        admin/ (sources, translations, languages, migrations - translations, languages and
                        migrations are anotoki-lib's pages, and so are People and the admin tools, routed straight)
frontend/public/        changelog.json, favicon, robots.txt (keeps search engines off /auth/)
docs/                   knowledge base (start at docs/README.md)
.claude/                project skills and agents for AI-assisted work
```

## Working on the project with AI

- [CLAUDE.md](CLAUDE.md) is loaded automatically by Claude Code and routes each kind of task to the right
  documentation, skill and agent; other AI tools can read it, and `.claude/`, as plain Markdown.
- [docs/](docs/README.md) is the knowledge base: architecture, data model, stats and slots, name matching,
  build sources, import formats, analysis, operations and decisions.
- Project skills (`.claude/skills/`): `add-build-source`, `debug-build-sync`, `add-import-format`,
  `tune-analysis`, `verify-ui`. Project agents (`.claude/agents/`): `build-source-researcher`, `sync-doctor`,
  `analysis-auditor`, `build-analyzer-reviewer`.

## Notes

- Builds come from other websites, and the scrapers read their current page structure. When a site changes its
  layout, its sync reports errors on the Sources page, and that parser needs an update.
- This is a small community tool, and the sites and services it reads are community projects: keep the default
  request delays.
- Not implemented yet: an import through the game's official web account. It would return every character
  without a tool, but needs your login cookies.
