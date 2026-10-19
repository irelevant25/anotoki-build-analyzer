---
name: deploy-builder
description: Build the build analyzer for the FTP-only hosting and ship it to builder.anotoki.eu with anotoki-deploy - a release of frontend/ and backend/, swapped in whole, a rollback, the setup page on a first install, a new migration's Apply. Use when the user asks to deploy, release, upload or publish the build analyzer, roll a release back, or asks what a new version needs on the hosting.
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
or delete anything in `config/` or `storage/` as part of a release.

Ask for (and never print, store in a repository, or put in a commit) the SFTP
login; it is the owner's to give per session (`ANOTOKI_DEPLOY_SECRETS` names
the `secrets.json` that holds it, outside every repository, with the pinned
`hostkey.txt` beside it). Confirm with the owner before `--go` - it changes the
live site.

## 1. Build

```bash
cd backend && tools/php.sh tests/run.php   # green first
cd ../frontend && npx anotoki-deploy build # the frontend's build, the neutral-names check, the release -> ../dist/
```

(Until `@anotoki/deploy` is a dev dependency of `frontend/` - from anotoki-lib
0.9.0's release - `node ../../anotoki-lib/deploy/cli.mjs` stands for
`npx anotoki-deploy`.) The build stops if the app names another site, service
or tool (`backend/tools/check-release.php`, with a PHP 8.4+:
`BUILD_ANALYZER_PHP`, else `php8`, else `php`).

## 2. Release

```bash
npx anotoki-deploy deploy --backups <folder>        # a dry run: what goes up
npx anotoki-deploy deploy --backups <folder> --go   # the live frontend/ and backend/ copied into <folder>,
                                                    # the release up as .new, checked, swapped in, the site checked
npx anotoki-deploy rollback --go                    # if it is wrong: the release before (kept as .old) back in
npx anotoki-deploy status                           # what is on the host
```

## 3. The database

- **A new migration**: the site is closed until it is applied; the ADMIN
  presses **Apply pending** on `/admin/migrations` at once (it backs the
  database up first). Once applied, `rollback` is no longer clean - check the
  release before pressing it.
- **First install**: the database in the hosting panel; `deploy --go`, then
  `npx anotoki-deploy switch --go` once; then `https://builder.anotoki.eu/setup.php`
  with the token it writes into `config/setup-token.local.php` (read it over
  SFTP), the database and the IAM (`https://iam.anotoki.eu`, client id
  `build-analyzer`). It checks that `storage/` is not served.

## 4. Check

The tool checks the live site after each swap: the app (the release just
sent), `/api/site-status`, an old bundle answering 404, and `backend/`,
`config/`, `storage/` never served. Then sign in as the ADMIN.
