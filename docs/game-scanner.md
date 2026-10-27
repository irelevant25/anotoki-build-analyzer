# The game scanner (`game-scanner/`)

The project's own Windows program that reads a player's weapons, artifacts and characters from the game's screen
into a GOOD file for the import page (since 1.0.0; the owner's decision of 2026-10-27, [decisions.md](decisions.md)).
It is the project's recommendation, not a requirement: the site reads a GOOD file from any tool
([import-formats.md](import-formats.md)). Its own [README](../game-scanner/README.md) says how it works inside, how
to develop it and how it is released; this page is what the rest of the project needs to know about it.

## What a player does

1. Opens PowerShell **as administrator** (the game runs as administrator, so Windows blocks the clicks and key
   presses of a program that does not) and pastes the line the import page shows:
   `irm https://github.com/irelevant25/anotoki-build-analyzer/releases/download/scanner/scanner.ps1 | iex`.
2. The script (`game-scanner/tools/scanner.ps1`, with the zip's address and SHA-256 written in by the build) refuses
   without administrator rights, downloads the zip (about 60 MB: the official, signed `node.exe` and the program),
   checks its SHA-256, unpacks it into TEMP and runs it; when the program ends, it deletes that folder.
3. The program checks the screen (16:9) and asks for the names the player gave the Traveler, the Wanderer, the
   Manekin and the Manekina (the game shows those instead of the characters' names). Then it guides through the
   three scans - weapons, artifacts, characters, each started with Ctrl+S in the game -, makes the GOOD file (the
   Downloads folder and the clipboard), offers to open builder.anotoki.eu/import, and deletes the file and
   everything it saved once the player says the import is done.

It reads the screen only - never the game's files or memory - and sends nothing about the player anywhere: its only
request fetches the game's names from genshin.anotoki.eu's catalog lists (`data/names.json` is the copy it falls back
on; `npm run names` refreshes it).

## Where the site shows it

The import guide's file method (`/import`, step Prepare) shows the line in its first step, with the kit's Copy
button (`@anotoki/lib/ui/copy`), and links to the scanner's README:

- The line and the README's address are the frontend's code - `sites/main/import/scanner.ts` (`SCANNER_COMMAND`,
  `SCANNER_GUIDE`), read by `import-guides.ts` (a step's `command` and `link`) -, never a string: no string holds a
  web address (`SiteTranslations` refuses one), and these two are the only addresses the import guide holds - the
  project's own repository, the one exception to "the UI names no other website" (CLAUDE.md's rules). The release
  check (`backend/tools/check-release.php`) reads them from `scanner.ts` and lets exactly them through, each on the
  repository or the build stops; any other address or name of GitHub in the built app is refused.
- The guide's words are migration 030's (the method's card, intro, four steps, three troubles, the paste panel's
  title); `import-guides.spec.ts` and `import-wizard.spec.ts` hold the line and the link to the constants.

## Releasing it

- Every push to `main` that changes `game-scanner/` (or the workflow) runs `.github/workflows/game-scanner.yml` on a
  Windows runner: `npm ci`, `npm test`, and `npm run package` - the zip and the script in `dist/`, the zip's
  self-test run with its own `node.exe` before it is accepted.
- Publishing is by hand: Actions → Game scanner → Run workflow. It does the same, then puts both files in the
  release `scanner` (the tag moved to the commit; the files replaced; never the repository's latest release). Bump
  `version` in `game-scanner/package.json` for a new release - the program shows it.
- The import page's line downloads from that release: publish it **before** a site that shows the line goes live
  (the first time, before 1.0.0 is deployed - [operations.md](operations.md), "Deploying 1.0.0"). The workflow's
  `APP_DIR` and `paths` name the folder; a rename changes both.
- The site's own release (`anotoki-deploy`, the `deploy-builder` skill) takes `backend/` and `frontend/` only: the
  scanner never reaches the host.

## Working on it

`cd game-scanner && npm ci && npm test` (Node.js 20 or newer; no network, no game). `npm start` runs the program
from a terminal started as administrator (scans, GOOD files and logs stay in `scans/`, `exports/`, `logs/` -
git-ignored, and in the root `.prettierignore`). The `game-scanner` skill (`.claude/skills/game-scanner/`) is the way
to change it: what the tests simulate, the fixtures, what the real game taught, and the release.

- **The fixtures are a real account's screenshots, kept free of the player** (the owner's rule of 2026-10-27): the
  UID and the detail panel blacked out in the inventory's, the character screens cut down to what the readers look
  at, and the names the player gave characters re-lettered with glyphs of the same screenshots ("ivantelle",
  "Ivantelle", "Taren" - `test/support/character-fixtures.js`). No player's name or UID in any tracked file, and
  none in a commit message.
- The repository names no build site - the scanner neither: `backend/tools/check-names.php` reads its files too.
- What the scanner writes is a GOOD file like any other: a change to what it writes is checked against the site's
  reader (`backend/src/Services/Import/Files/GoodParser.php`, [import-formats.md](import-formats.md)) - the site
  replaces an account's weapons and artifacts with a file's, so the program warns before it makes a file without
  them.
