---
name: add-import-format
description: Add or fix a way to import a player's Genshin Impact account into the build analyzer — new exporter or scanner file formats (a new GOOD version, yas, another packet-capture tool), an import through the game's official web account (cookies), or repairing showcase / GOOD parsing when fields changed. Use whenever imports fail, data is missing after an import (talents, constellations, rolls, sets, Traveler gear), or the user wants another way to get their characters in.
---

# Add or fix an import format

Every import path ends in the same place: a parser turns raw JSON into a `ParsedAccount`, and
`AccountImporter` resolves catalog references and writes the account. Keeping parsers free of database
work is what lets one importer serve the showcase and GOOD files - and the next format; keep it that way.

Two ways in today, both Genshin Impact's: the **Character Showcase by UID** (`Import/Enka/EnkaClient` +
`EnkaGenshinParser`) and **GOOD files** (`Import/Files/GoodParser`, the one format `ImportService::fromFile()`
detects). Star Rail's parsers went in 0.5.0 (docs/decisions.md, 2026-10-20): a format of another game starts with
that game's case in `App\Enums\Game` and a migration widening the `game` CHECKs.

Read `docs/import-formats.md` first (exact field mappings of every supported format, the tools players use,
notes on the official web account) and `docs/data-model.md` (what "full inventory" means for existing data).

## 1. Understand the format from real samples

Get a real export or API response (the user's own, a tool's test fixtures, or the tool's source code). Map every
field you need: characters (level, ascension, constellation, talents), weapons (level, refinement, location),
items (set, slot, rarity, level, main stat, substats with values and roll counts if present, location), account
(UID, nickname). The constellation matters to the analysis: it decides which of a character's builds apply
(`min_rank`) and which stat targets hold (`from_rank`), and a character read without one counts as C0. Note how
"equipped on" is expressed (character key, in-game ID, "Traveler"). Keep samples outside the repository.

## 2. Write the parser (`backend/src/Services/Import/...`)

- A class with `public static function matches(array $json): bool` and `public function parse(array $json): ParsedAccount`.
- `new ParsedAccount(game, source, fullInventory, uid, nickname, …, meta: ['tool' => …])`. `source` is the
  internal format key (`enka`, `good`); the API reports anything but `enka` as `file`, and
  leaves `meta.tool` out. `fullInventory: true` means the file lists *every* item, so the importer replaces all
  gear; use `false` for partial data (showcases), which only replaces gear of the listed characters.
- References: prefer in-game IDs (`['external_id' => '1005']`, they match the catalog), fall back to names
  (`['name' => 'KaedeharaKazuha']`), or give a canonical key for special cases (`['key' => 'traveleranemo']`).
  The importer only looks references up - it never adds to the shared catalog - so an unresolvable reference is
  skipped with a warning; an unknown in-game ID may refresh that catalog list from genshin.anotoki.eu first (once
  per game and list every `catalog.import_refresh_minutes`, for every user together). Prefer IDs for exactly this
  reason: the catalog's `external_id` is the game's own (the Travelers `{lowest ID}-{element}`, both twins' IDs in
  `meta.ids`).
- Stats: map to canonical keys (`docs/stats-and-slots.md`); percentages in percent units (showcase ratios
  ×100); drop unknown substats with a warning instead of guessing. Fill `main_value` from the source, else
  `MainStatValues::compute()`.
- A problem is a **kind with params** that the wizard words in the reader's language; the English sentence
  beside it ("…turn on Show Character Details…", short and actionable) is for the command line, the tests and
  the fallback. Throw `new ImportException($message, $kind, $params)`; collect soft problems with
  `$account->warn($kind, $params, $message)` (never by writing to `$account->warnings`). Neither may name a
  website, service or tool: say "the showcase service", "a GOOD file", "the export" (the UI names nothing that
  is not ours; `Neutral::text()` is only a safety net).
- Reuse a kind of `src/Services/Import/ImportKinds.php` (`ERRORS`, `WARNINGS`) when the sentence is the same.
  A new one touches, together: its row in `ImportKinds` (the names of its params, in order; params flat -
  `Game::label()`, `Game::weaponLabel()`, `ImportKinds::ref()`, numbers as numbers; a clause the sentence may
  or may not have is a kind of its own, never an English fragment in a param); its tail in
  `frontend/src/app/core/i18n/dynamic-keys.ts` (`import.error.` / `import.warning.`); its row in
  `core/i18n/import-words.ts`; its English in `core/i18n/en/core.json`; and a **new string migration** (003's
  pattern: the key with a description, the English and informal Slovak, `ON CONFLICT DO NOTHING`, a `DO $$`
  gate). Game terms and the player's names stay as they are, first and before a colon - no Slovak word agrees
  with one. `docs/import-formats.md`, "Errors and warnings are kinds", has the whole picture.

## 3. Wire it in

- Files: add detection to `ImportService::fromFile()` (the `match` order matters — most specific first; today it
  holds `GoodParser::matches()` alone, and anything else is `file_unknown_format`).
- Every import is for one user: `ImportService` methods take the `User $owner` and pass it to
  `AccountImporter::import($parsed, $owner, $target)`; a target account must be the owner's (in
  `api/routes/imports.php` the `$target` closure checks `account_id` with `Accounts::findOwned()`), and failures
  go to `recordFailure(…, $owner, …)`.
- New online source: a client class like `Import/Enka/EnkaClient` (errors mapped to neutral messages, response
  caching in the cache table, polite delays through `Fetcher`, no long sleeps inside a web request, its address
  in `config/app.php`), a method on `ImportService`, a route in `api/routes/imports.php` with `Web\Input`
  validation, `requireOwner($app, mayMakeVisitor: true)` on the group and `->add(makeVisitor($app))->add(throttle(...))`
  on the route (the throttle counts a visitor per address; `makeVisitor` innermost, so a refused import makes no
  visitor), the visitor caps (`$roomForAccount`, `$smallEnough`), and an `Api` method in
  `frontend/src/app/core/api.ts`. The route path must not carry the service's name (the showcase import is
  `/api/imports/showcase`).
- Credentials (e.g. the official web account's cookies): ask the user before building this. Never log or return
  them, store them encrypted (with a key in a `config/*.local.php` that `setup.php` writes) or not at all,
  scoped to the user, and say in the wizard what they grant.
- CLI: `import-account.php` (`src/Commands/ImportAccount.php`) routes UIDs to the showcase and files to
  `fromFile` for `--user` (required: an IAM user id); extend it if the new path needs it.

## 4. Test

Follow `backend/tests/import.test.php`: `seedGenshinCatalog()`, `seedUser(1, 'owner')`, a
small synthetic JSON with the real field names, and assertions on the stored account (and its `user_id`), items
(slot, main stat, substats with rolls, equipped character), talents and the import run's summary (its
`warning_kinds` beside the `warnings`; a refusal's `code` and `params`). The tests read every throw and `warn()`
site in `src/` against `ImportKinds`, and `tests/translation_catalogue.test.php` fails until the kind, its
declared tail, the compiled English and the migration's strings agree. API calls need
a token: `(new Client)->asIam(1)`. Remote APIs are faked with `T::$http->fake([...])` (it fails the test on any
request it was not told about) and the Genshin store with a fake `EnkaStore`, as the existing tests do.
`backend/tests/isolation.test.php` shows the per-user checks a new endpoint should pass too, and
`backend/tests/neutral.test.php` that nothing it answers names another site.

```bash
cd backend && php tests/run.php
cd backend && php import-account.php genshin path/to/real-sample.json --user=<your IAM id>   # then look at the account in the UI
```

## 5. User-facing guide and docs

- Wizard content: `frontend/src/app/pages/import/import-guides.ts` (`METHODS` pros/cons, `GUIDES` steps, tips,
  troubleshooting, `fileHint`) - describe tools by what they do ("an inventory exporter that saves the GOOD
  format"), with no names and no links. Every text there is a key written out whole; a new or reworded one is
  its English in `core/i18n/en/import.json` plus a string migration with English and informal Slovak (an
  applied migration is never edited: a reworded string is a new migration plus the same change there). A
  brand-new method key also needs handling in
  `import-wizard.ts/.html` and `ImportKind` in `core/models.ts`.
- Update `docs/import-formats.md`, the README import table, and `docs/decisions.md` for notable choices.
- Check the wizard visually with the `verify-ui` skill.
