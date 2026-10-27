# Genshin Impact scanner for builder.anotoki.eu

Reads your weapons, artifacts and characters from the game and makes a GOOD file to import on
[builder.anotoki.eu/import](https://builder.anotoki.eu/import). It works the way a player would: it opens each
item and each character in the game, takes a screenshot and reads it - nothing is read from the game's files or
memory, and nothing about you is sent anywhere (the only request it makes fetches the game's names from
genshin.anotoki.eu). Any other tool that writes a GOOD file works with the site as well.

## Run it

You need Windows, the game in English, in full screen on your main screen at a 16:9 resolution (1920x1080,
2560x1440, 3840x2160), and PowerShell started **as administrator** (right-click the Start button, then
"Terminal (Admin)" or "Windows PowerShell (Admin)") - the game runs as administrator, so Windows blocks the clicks
and key presses of programs that don't. Paste:

```powershell
irm https://github.com/irelevant25/anotoki-build-analyzer/releases/download/scanner/scanner.ps1 | iex
```

The script downloads the scanner (about 60 MB: Node.js and this program), checks it against its SHA-256, unpacks
it into a folder in TEMP and runs it; when the program ends it deletes that folder.

The program then:

1. checks that it runs as administrator and that the screen is 16:9 - and says what to change if not;
2. asks for the names of the characters you named yourself (the game shows those names instead of theirs): your
   Traveler, the Wanderer, the Manekin and the Manekina - exactly as the game shows them, capital letters too;
3. shows what is left to scan - weapons, artifacts, characters, in any order. Open one in the game (B for the
   inventory: the Weapons or Artifacts tab scrolled to the top; C for the characters: any one, any tab) and press
   **Ctrl+S** in the game. Hands off the mouse and keyboard while it scans; Ctrl+S again stops it. It beeps when
   it is done, and says what it read - or what went wrong and how to fix it (another tab open, the list not at the
   top, the game not in English, ...);
4. when everything is scanned, makes the GOOD file - in your Downloads folder, and on the clipboard;
5. opens builder.anotoki.eu/import if you like: choose the file there or paste it;
6. once you are back and press Enter, deletes the GOOD file and everything it saved. Press any key to exit.

A scan of a big inventory takes a while (about 15 minutes for 1800 artifacts); the characters take a couple of
minutes. What the game's filters hide in the inventory isn't scanned.

## What it reads

- **Weapons and artifacts**: it clicks every card of the open tab, row by row, and reads the detail panel: name,
  level, refinement or set, slot, main stat and substats (with unactivated ones), rarity, lock, who wears it. Rows
  of identical items, "New" badges and the end of the list are handled; every scroll is measured.
- **Characters**: it goes through the character screen three times with E - Attributes, Constellation, Talents -
  and reads the name, level and ascension (the stars under the name), the unlocked constellations (the icons'
  rings) and the Normal Attack, Skill and Burst levels (the levels raised by constellations, in cyan, are taken
  back to the base level GOOD stores). It waits until each tab has faded in.
- The names are matched against the game's current names (from genshin.anotoki.eu's catalog, or the copy in
  `data/names.json` without a connection), so a letter the OCR misreads is corrected. Each item is checked
  (stats that can be rolled, a level within its ascension...); what doesn't read cleanly is reported. A character
  that doesn't read cleanly is left out of the file - the site keeps what it has for them, while a wrong level,
  constellation or talent would replace the right one.

Everything is read while the scan goes on, so no screenshots pile up on the disk. builder.anotoki.eu replaces
all your weapons and artifacts with the file's on import, so the program warns before it makes a file without
them.

## Develop

```
npm install            # Node.js 20 or newer
npm start              # the program; its scans go to scans/, the GOOD file to exports/, the log to logs/ - kept
npm start -- --keep-images   # also writes every screenshot (to read them again after a reader changed)
npm run good [-- <scan folders>] [-- --fresh]   # a GOOD file again from scan folders (ocr.json, or the screenshots)
npm test               # the tests (no network, no game)
npm run names          # refreshes data/names.json from genshin.anotoki.eu
npm run package -- --node="C:\Program Files\nodejs\node.exe"   # the release in dist/ (see below)
```

Start the terminal as administrator for `npm start`, as for the released program.

- `src/index.js` - the entry point: the console, the hotkey, the clipboard, the browser; `src/session.js` - what
  the player goes through; `src/ui.js` - the console.
- `src/scanner.js` (the inventory), `src/character-scanner.js` (the character screen), `src/grid.js`,
  `src/screens.js`, `src/input.js` (mouse, keyboard, screen) - the scans; `src/config.js` - every position, colour
  and timing (measured at 2560x1440, scaled to other 16:9 screens).
- `src/reader.js` - reads the screenshots as the scans hand them over; `src/good/` - the readers (`panel.js`,
  `character.js` with OCR, `character-screen.js` from the pixels: ascension stars, talent digits by templates,
  constellation rings), the parsing and checks (`parse.js`, `stats.js`, `names.js`) and the GOOD file
  (`good-file.js`); `src/catalog.js` - the current names.

Tests: `test/*.test.js` run the real scanners against simulated screens (`test/support/fake-game.js`,
`fake-character-screen.js`: identical rows, "New" badges, lost clicks, tabs fading in, look-alike characters) and
the readers against real screenshots (`test/fixtures/`: inventory screenshots with the detail panel and the UID
blacked out, character screens cut down to what the readers look at, two detail panels). `test/session.test.js`
goes through the whole program with a scripted console.

## Release

`npm run package` builds `dist/scanner-win-x64.zip` - the official `node.exe` (signed by the OpenJS Foundation)
with its LICENSE and `app/` (the program and its production modules) - and `dist/scanner.ps1` from
`tools/scanner.ps1` with the zip's address and SHA-256 written in; it unpacks the zip and runs the self-test
(`src/selftest.js`) with its `node.exe` first. The GitHub workflow `.github/workflows/game-scanner.yml` (at the
repository's root) runs the tests and the build on every push that changes this folder, and - run by hand - publishes
both files in the release `scanner`, replacing the previous ones. Bump `version` in `package.json` for a new
release (the program shows it). builder.anotoki.eu's import page shows the PowerShell line above (the file method's
guide: `frontend/src/app/sites/main/import/scanner.ts`), so the release must exist before a site that shows it goes
live. If this folder is ever renamed, change `APP_DIR` and the `paths` filter in the workflow too.

The test fixtures are screenshots of a real account, kept free of the player: the UID and the detail panel are
blacked out, and the names a player gives characters (the Traveler, the Wanderer, the Manekins) are made up - see
`test/support/character-fixtures.js`. A new fixture is cut down the same way before it is committed.
