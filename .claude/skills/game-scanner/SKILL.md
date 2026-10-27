---
name: game-scanner
description: Change, fix or release the project's own Genshin Impact game scanner (game-scanner/ - a Node.js program for Windows that reads a player's weapons, artifacts and characters from the game's screen into a GOOD file) - its scans, its readers (OCR and pixels), the GOOD file it writes, the console session, its tests and fixtures, the PowerShell script and the zip, the release workflow. Use whenever the scanner misreads something, a scan stops, skips or loops, the game's screens changed, a new screen size or language is wanted, a fixture is added, or the scanner is to be released.
---

# The game scanner

`game-scanner/` is a program players run on their own PC: pasted in PowerShell as administrator, a script downloads
it, checks it and runs it from TEMP; it scans the game the way a player looks at it and writes the GOOD file the site
imports. Read first: `game-scanner/README.md` (how it works inside, how to run and release it) and
`docs/game-scanner.md` (what the rest of the project needs - the import page's line, the release, the fixtures' rule).
`src/config.js` holds every position, colour and timing (measured at 2560x1440, scaled to other 16:9 screens).

## 1. Reproduce it in the simulation first

The scanner can't be run against the game from here; only the owner can. So every fix starts as a failing test:

- `test/support/fake-game.js` (the inventory: a grid that scrolls, identical rows, "New" badges, lost clicks, the end
  of the list) and `test/support/fake-character-screen.js` (the character screen: tabs that fade in, E that shows
  the next character late, look-alike characters, sparkles drifting over the data) run the real scanners
  (`test/scanner.test.js`, `test/character-scanner.test.js`); `test/session.test.js` goes through the whole program
  with a scripted console.
- The readers run on real screenshots (`test/fixtures/`, `test/reader.test.js`, `character.test.js`,
  `character-screen.test.js`); the parsing and checks on what the OCR read (`parse.test.js`).
- Make the simulation do what the game did (from the owner's log in `logs/`, or a screenshot the scan saved in
  `scans/.../debug/`), see the test fail, then fix. `npm test` (about a minute; no network, no game).

What the real game taught (keep the simulation honest about it):

- Tabs and characters **fade in**: wait until a tab reads the same twice, never a fixed delay.
- **Sparkles and bubbles** sit on text anywhere: read values from pixels where possible (the ascension stars, the
  talent digits by templates, the constellation rings) and treat OCR as the fallback.
- The selection frame stays on its card slot when the list scrolls; rows of identical items make a scroll of a row
  or more unmeasurable - every scroll is measured.
- Two characters can look alike: the two Manekins share every screen but the header, and two names a capital letter
  apart differ by ~260-325 header pixels (`characterChangedPixels` 200 tells them apart; one header across tabs
  differs by up to ~155).
- Speed matters to the owner (1800 artifacts take about 15 minutes): don't add waits a measurement can replace.
- Two runs of the same scan, compared, are a good check before handing a change over.

## 2. Fixtures carry nothing of the player

The fixtures are screenshots of the owner's real account (the owner's rule of 2026-10-27, docs/decisions.md):

- the UID (bottom right) and the inventory's detail panel blacked out, character screens cut down to what the
  readers look at (the rest black);
- names a player gave characters (the Traveler, the Wanderer, the Manekin, the Manekina) are made up - re-lettered
  with glyphs of the same screenshots ("ivantelle", "Ivantelle", "Taren"; `test/support/character-fixtures.js`);
- look at every new image before it is committed (the Read tool shows it), and grep the tree for the player's names
  and UID: none in a fixture, a test, a comment, a doc or a commit message. Logs, scans, GOOD files and the
  screenshots taken while working (`/first_*.png`, `/last_*.png`, `/mid_*.png`) are git-ignored - keep them so.

## 3. The GOOD file is the site's input

What the scanner writes is read by `backend/src/Services/Import/Files/GoodParser.php` (docs/import-formats.md): keys
in PascalCase from the game's English names (`data/names.json`, refreshed from genshin.anotoki.eu by `npm run names`;
the program fetches the current lists when it starts), base talent levels (the levels raised by constellations
taken back), `location` "Traveler" for the Traveler's gear. A character that doesn't read cleanly is left out (the
site keeps what it had); the site replaces an account's weapons and artifacts with a file's, so the program warns
before it writes a file without them. A change to the file's shape is checked against the parser's tests.

## 4. Release

1. Raise `version` in `game-scanner/package.json` (and the root entry of its `package-lock.json`); the program shows
   it, the release title carries it.
2. `npm test`, and `npm run package -- --node="C:\Program Files\nodejs\node.exe"` to build the zip and run its
   self-test with its own `node.exe` (the workflow downloads the official one by `NODE_VERSION`).
3. Commit and push to `main` (only when the owner asks): `.github/workflows/game-scanner.yml` tests and builds it.
4. The owner publishes: GitHub → Actions → Game scanner → Run workflow - the release `scanner`, both files replaced.

The import page's line (`frontend/src/app/sites/main/import/scanner.ts`), the README, `tools/package.mjs`'s default
address and the workflow all name the release `scanner` and the file `scanner.ps1`: change them together, or not at
all. The scanner names no build site (`php backend/tools/check-names.php` reads it too).

## 5. Done

`npm test` passes; a new behaviour the simulation can't prove is written down for the owner's real run (what to
do, what to look for); `game-scanner/README.md` and `docs/game-scanner.md` say what changed; a released change has
its version raised.
