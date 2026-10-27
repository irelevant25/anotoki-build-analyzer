/**
 * The project's own game scanner (game-scanner/ in this repository; the owner's request of 2026-10-27): a program
 * for Windows that reads a player's weapons, artifacts and characters from the game's screen into a GOOD file. The
 * import guide's file method shows the PowerShell line that starts it, with a Copy button, and links to its own
 * instructions.
 *
 * The line and the address are the code's, never a string an editor can reword: no string holds a web address
 * (SiteTranslations refuses one), and these two are the only addresses the import guide holds - the scanner's
 * release on the repository's page, published by hand by the workflow .github/workflows/game-scanner.yml (the
 * release "scanner"; game-scanner/tools/scanner.ps1 is the script it fetches), and the scanner's README.
 */

/** Pasted into PowerShell started as administrator: downloads the scanner, checks its SHA-256, runs it, deletes it. */
export const SCANNER_COMMAND = 'irm https://github.com/irelevant25/anotoki-build-analyzer/releases/download/scanner/scanner.ps1 | iex';

/** The scanner's own instructions, beside its source code. */
export const SCANNER_GUIDE = 'https://github.com/irelevant25/anotoki-build-analyzer/blob/main/game-scanner/README.md';
