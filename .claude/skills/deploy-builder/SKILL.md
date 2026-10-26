---
name: deploy-builder
description: Build the build analyzer for the FTP-only hosting and ship it to builder.anotoki.eu with anotoki-deploy - a release of frontend/ and backend/, swapped in whole, a rollback, the setup page on a first install, a new migration's Apply, the catalog's refresh and the builds file's import after it, version 0.5.0's one-time steps (genshin.anotoki.eu first, the names file, Apply 014-019), 0.6.0's (Apply 020-021, then a version 3 builds file - the teams - before the next builds refresh), 0.6.1's (no migration; the builds file without the sites' notes) 0.7.0's (the builds editor: Apply 022-023 at once, nothing to import), 0.7.1's (no migration: the release only) and anotoki-lib 0.11.1's (the site settings, robots.txt and sitemap.xml: Apply 025 with the library's three files at once, the read-only checks after it). Use when the user asks to deploy, release, upload or publish the build analyzer, roll a release back, or asks what a new version needs on the hosting.
---

# Deploy the build analyzer

The hosting is SFTP only - PHP 8.4, PostgreSQL, Apache with `.htaccess`, no
shell. A release is built and put up by **anotoki-deploy** (the anotoki
library's deploy tool, the same for every site; this site's facts are in
`backend/composer.json`, `extra["anotoki-deploy"]`). DEV.md (*Deploying*) and
docs/operations.md have the whole of it.

The site's folder on the host, `anotoki.eu/sub/builder/`: `frontend/` and
`backend/` - the release, each replaced whole - beside `config/` (the
`*.local.php` settings) and `storage/` (the lock, logs, caches, backups, the
library's key), which no release writes, under the switch: the folder's
`.htaccess`, which sends every request into `frontend/`. Never upload, rename
or delete anything in `config/` or `storage/` as part of a release. The
settings written by hand - `cloudflare.local.php` (the origin secret) and
`neutral.local.php` (the build sites' names) - go into `config/` once, over
SFTP, apart from any release; they are the owner's: never open, print or
commit them.

Ask for (and never print, store in a repository, or put in a commit) the SFTP
login; it is the owner's to give per session (`ANOTOKI_DEPLOY_SECRETS` names
the `secrets.json` that holds it, outside every repository, with the pinned
`hostkey.txt` beside it). Confirm with the owner before `--go` - it changes the
live site.

## 1. Build

```bash
cd backend && tools/php.sh tests/run.php       # green first
cd ../frontend && bun run anotoki-deploy build # the frontend's build, the neutral-names check, the release -> ../dist/
```

(`@anotoki/deploy`: a dev dependency of `frontend/`, from anotoki-lib's release.
`bun run` finds it whichever tool installed `node_modules/`; on Windows, `npx`
misses bun's links.) The build stops if the app names another site, service
or tool - the build sites' names of `config/neutral.local.php` included
(`backend/tools/check-release.php`, with a PHP 8.4+: `BUILD_ANALYZER_PHP`,
else `php8`, else `php`).

## 2. Release

```bash
bun run anotoki-deploy deploy --backups <folder>        # a dry run: what goes up
bun run anotoki-deploy deploy --backups <folder> --go   # the live frontend/ and backend/ copied into <folder>,
                                                        # the release up as .new, checked, swapped in, the site checked
bun run anotoki-deploy rollback --go                    # if it is wrong: the release before (kept as .old) back in
bun run anotoki-deploy status                           # what is on the host
```

## 3. The database

- **A new migration**: the site is closed until it is applied; the ADMIN
  presses **Apply pending** on `/admin/migrations` at once (it backs the
  database up first). Once applied, `rollback` is no longer clean - check the
  release before pressing it.
- **First install**: the database in the hosting panel; `deploy --go`, then
  `bun run anotoki-deploy switch --go` once; then
  `https://builder.anotoki.eu/setup.php` with the token it writes into
  `config/setup-token.local.php` (read it over SFTP), the database and the IAM
  (`https://iam.anotoki.eu`, client id `build-analyzer`). It checks that
  `storage/` is not served. Then, signed in as the ADMIN, on `/admin/builds`:
  **Refresh catalog**, and **Import** the builds file (its dry run first) -
  the site never reads a build site; its builds are that file.

## 4. Check

The tool checks the live site after each swap: the app (the release just
sent), `/api/site-status`, an old bundle answering 404, and `backend/`,
`config/`, `storage/` never served. Then sign in as the ADMIN.

## 5. Deploying 0.5.0

Version 0.5.0 - Star Rail out, the builds as variations imported from a file,
the catalog from genshin.anotoki.eu (docs/decisions.md, 2026-10-20;
docs/operations.md, "Deploying 0.5.0") - goes up once, in this order:

1. **genshin.anotoki.eu first**: its release, with its migrations 030 and
   031 (the game's IDs) applied there. Its catalog must answer:
   `https://genshin.anotoki.eu/api/catalog/characters` (and `/weapons`,
   `/artifacts`) 200 with a JSON list and an `ETag`, and 304 when asked again
   with `If-None-Match` and that tag.
2. **The names file, once**: the owner uploads `backend/config/neutral.local.php`
   into the host's `config/` by hand (above) - before the release, so the
   site keeps the build sites' names out of everything it says from the start.
3. **The release** (section 2).
4. **Apply 014-019 at once**: signed in as the ADMIN, **Apply pending** on
   `/admin/migrations` runs all six in one go, after a backup (Star Rail's
   rows and words out; the sources, the syncs and the old builds out, the
   variations and `build_imports` in, with their words; the catalog's region,
   version and version name; the filters' words). The site is closed to
   everybody else until then.
5. **Refresh catalog** on `/admin/builds`: the three lists one after another,
   each read in full the first time (018 forgot the old provider's state);
   the page must show no list whose refresh failed.
6. **Import the builds file** on the same page (`builds-genshin-<date>.json`,
   from the owner's local tools): the dry run's preview first - the counts,
   the characters skipped, the changes -, then **Import**. Until then the site
   has no builds: keep the gap after step 4 to minutes.
7. **Check**: `/builds/genshin` lists the characters with their roles and
   filters; a character's page shows its variations; a UID import resolves
   characters, weapons and sets.

Backups made before 014 and 016 - the one this Apply makes too - hold Star
Rail and the old builds tables: read them offline only
(`php backup.php --inspect`, `--extract`), never restore one on the site (it
would bring back what 014-016 removed). After the Apply, `rollback` is not
clean (section 3).

## 6. Deploying 0.6.0

Version 0.6.0 - every build with the teams it is played in (docs/decisions.md,
2026-10-22; docs/operations.md, "Deploying 0.6.0"). The builds file becomes
format version 3: the owner's local tools write no other from now on, and this
version reads no other, so it goes up before the next builds file:

1. **The builds file ready**: a version 3 `builds-genshin-<date>.json` from
   the local tools; the version 2 files are refused from now on.
2. **The release** (section 2).
3. **Apply 020-021 at once**: `builds.teams` (NULL for every build: none
   changes) and the teams' three words, after the Apply's backup.
4. **Import the builds file** on `/admin/builds`: the dry run's preview says
   "with N teams" and updates only the builds that gained teams (the
   2026-10-22 file: 147 updated, 43 unchanged, none new or removed), with no
   teammate the catalog lacks; then **Import**.
5. **Check**: `/builds/genshin/hutao` lists each build's teams; on an
   account's character page the teammates it has are marked "Owned".

The backups made before the Apply are restored offline only, as after every
Apply (`409 schema_differs`).

## 7. Deploying 0.6.1

No migration, so nothing waits after the swap: the release (section 2), then
the builds file the local tools wrote without the sites' notes and with every
team (the second export of 2026-10-22) imported on `/admin/builds` - its dry
run 183 updated, 7 unchanged, none new or removed (docs/operations.md,
"Deploying 0.6.1").

## 8. Deploying 0.7.0

Version 0.7.0 - the builds editor (docs/decisions.md, 2026-10-22; docs/operations.md, "Deploying 0.7.0"): editors
change builds by hand and write the site's own notes; every import keeps what they did. Before uploading, Export
each language on the Translations page. Then the release (`deploy --backups <folder> --go`) and **Apply pending at
once** on `/admin/migrations`: 022 (the hand edits' storage; every build's `file_build` its columns as they are -
no build changes) and 023 (Edit builds on a character's builds page; the Privacy page's audit-log sentence, only
where it reads as before), one Apply after a backup. Nothing to import. Check: the Builds page's Edit builds card
opens a character's editor; a note saved there shows on the character's builds page (Slovak with `?lang=sk`) -
every member sees it at once: a real one, or deleted again once seen -; the audit log has `builds.edited`. Make a backup by hand once applied (the earlier ones restore offline only).

## 9. Deploying 0.7.1

No migration, so nothing waits after the swap: the release (section 2) and the site stays open (docs/operations.md,
"Deploying 0.7.1"). The owner's look at 0.7.0: Edit builds a table of every character, a build's name changeable
(a file build renamed becomes the editor's own), a team's members side by side.

## 10. Deploying anotoki-lib 0.11.1

The site settings (Getting in, the announcement, Page access), robots.txt and sitemap.xml from the site's PHP, the
family's dates and search, the admin menu in the family's one shape (docs/decisions.md, 2026-10-25;
docs/operations.md, "Deploying anotoki-lib 0.11.1"). Both halves are 0.11.1 - `vendor/` with the release; 0.11.1
adds nothing to Apply (the announcement bar and the administrators' notice out of the first load). Before
uploading, Export each language on the Translations page. Then:

1. **The release** (section 2). The release's front controller takes `/robots.txt` and `/sitemap.xml` beside
   `/api` (`composer.json`'s `routes`); a file of the app at either path would be refused by the build.
2. **Apply pending at once** on `/admin/migrations` (one Apply, after its backup): the library's
   `anotoki_admin/003` (the settings' two tables, empty: the site open, nothing announced, every page as before),
   this site's **025** (the Privacy page's part about the settings, the page that is not open, the audit log's
   sentence), the library's `anotoki_translations/014` and `015`.
3. **Read-only checks** (an agent never writes to the live site): `https://builder.anotoki.eu/robots.txt` ends with
   `Sitemap: https://builder.anotoki.eu/sitemap.xml`; `/sitemap.xml` lists the legal pages, About, Support - and the
   builds while visitors are welcome; `GET /api/site-settings` answers `{"maintenance":false,"announcement":null,"pages":[]}`;
   `/api/admin/site-settings` refuses a request without a token (401). The owner, signed in as the ADMIN, may try the
   switches on `/admin/site-settings` (an announcement; maintenance with `/staff` in a private window) and switch
   them off again.

`config/site.local.php` is only for an address other than `https://builder.anotoki.eu`. No release from before
025 goes on the host after it (its Privacy page would not say what the settings keep). Make a backup by hand once
applied (the earlier ones restore offline only).

## 11. Deploying 0.8.0

Version 0.8.0 - the build a player chooses for a character (migration 026) and stat targets for every build in
three tiers, worked out from the game's numbers (migration 027; docs/decisions.md, 2026-10-26; docs/operations.md,
"Deploying 0.8.0"). The tiers rest on each character's and weapon's base stats, which genshin.anotoki.eu's catalog
lists carry since the same day, so:

1. **genshin.anotoki.eu first**: its release with the catalog change (no migration there). Its
   `https://genshin.anotoki.eu/api/catalog/characters` must answer each character with a `stats` field (`base`,
   `lv80`, `ascension`), `/weapons` each weapon with `stats` (`base_atk`, `lv80_atk`, `secondary`).
2. **The release** (section 2). From then on the site is closed until the Apply.
3. **Apply pending at once** on `/admin/migrations` (one Apply, after its backup): **026**
   (`account_characters.chosen_build_id`, the choice's words, the Privacy page's sentence) and **027** (the tiers'
   six words). No build changes, nothing to import.
4. **Refresh catalog** on `/admin/builds`: the characters and the weapons lists (the sets' texts were there before)
   - a conditional request answers "changed" once the genshin site's release is up; a list refreshed before it
   answers "unchanged" and keeps no stats: refresh again after.
5. **Check**: `/builds/genshin/kamisatoayaka` shows "Stat targets" with "Worked out for Mistsplitter Reforged
   with 4pc Blizzard Strayer." and three tiers on CRIT DMG; on an account's character page "Use this build" and
   the tiers for the weapon worn; the account page says "Selected build: ..." once one is chosen.

Until step 4 the targets are the stated ones alone, as before. Make a backup by hand once applied (the earlier
ones restore offline only).

## 12. Deploying 0.8.1

No migration: the release goes up (section 2) and the site stays open. The owner deferred the genshin site's
release, so 0.8.1 reads the stats behind the tiers from that site's own phase lists while its catalog lists carry
none (`catalog.genshin.site_api`, docs/operations.md "The catalog"), and the Builds page's **Refresh catalog**
reads every list in full. After the swap the owner presses **Refresh catalog** on `/admin/builds` once: the
characters and the weapons lists come back "changed" with their stats, and every build shows its three tiers.
Check: `/builds/genshin/kamisatoayaka` shows "Worked out for Mistsplitter Reforged with 4pc Blizzard Strayer."
and three tiers on CRIT DMG.

## 13. Deploying 0.9.0

Version 0.9.0 - the totals worked out from the gear for a character without in-game totals, and the import guide
saying where an inventory file comes from (migration 028; docs/decisions.md, 2026-10-26; docs/operations.md,
"Deploying 0.9.0"). The character at its level rests on every ascension phase, which the catalog keeps since this
version, so:

1. **The release** (section 2). From then on the site is closed until the Apply.
2. **Apply pending at once** on `/admin/migrations` (one Apply, after its backup): **028** (one word; the file
   method's words reworded). No build changes, nothing to import.
3. **Refresh catalog** on `/admin/builds` once (read in full since 0.8.1): the characters and the weapons lists come
   back "changed" with their phases. The genshin site's release is not needed: the phases are read from its public
   phase lists while its catalog lists carry no stats.
4. **Check**: an account's character from a file shows "Stats" with "Worked out from your gear ..." and its Stat
   targets bar scored; `/import`'s file method reads "Full inventory (a file from a tool of your own)".

Make a backup by hand once applied (the earlier ones restore offline only).

## 14. Deploying 0.10.0

No migration, so nothing waits after the swap: the release (section 2) and the site stays open (docs/operations.md,
"Deploying 0.10.0"). Step 4 of the round of 2026-10-26: a bar under each stat target on an account's character
page, the dashboard's fourth score bar, every bar in three colours. Check: an account's character page shows a bar
under each stat target (a tiered one with two marks) and red, gold or green score bars; the account page's cards a
fourth bar, "Stat targets".

## 15. Deploying 0.11.0

No migration, so nothing waits after the swap: the release (section 2) and the site stays open (docs/operations.md,
"Deploying 0.11.0"). The editor's stat targets: the tiered targets in the builds editor with Auto, set by hand,
switched off, added (docs/builds.md, "The editor"). Check: `/admin/builds/genshin/hutao` shows "Tiered targets" with
Auto ticked on each stat worked out; a stat's tiers set by hand and saved show on `/builds/genshin/hutao`, a stat
switched off does not; Auto ticked again puts the worked-out ones back. The builds file's format stays version 3:
the owner's local tools write as before.
