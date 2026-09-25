---
name: add-import-format
description: Add or fix a way to import a player's Genshin Impact / Honkai Star Rail account into hoyo-analyzer — new exporter or scanner file formats (a new GOOD version, yas, a Fribbels save file, another packet-capture tool), a HoYoLAB cookie import, or repairing Enka / GOOD / Reliquary Archiver parsing when fields changed. Use whenever imports fail, data is missing after an import (talents, rolls, sets, Traveler/Trailblazer gear), or the user wants another way to get their characters in.
---

# Add or fix an import format

Every import path ends in the same place: a parser turns raw JSON into a `ParsedAccount`, and
`AccountImporter` resolves catalog references and writes the account. Keeping parsers free of database
work is what lets one importer serve Enka, GOOD and scanner files; keep it that way.

Read `docs/import-formats.md` first (exact field mappings of every supported format, tools, HoYoLAB notes)
and `docs/data-model.md` (what "full inventory" means for existing data).

## 1. Understand the format from real samples

Get a real export or API response (the user's own, a tool's test fixtures on GitHub, or the tool's source
code). Map every field you need: characters (level, ascension, constellation/eidolon, talents), weapons
(level, refinement/superimposition, location), items (set, slot, rarity, level, main stat, substats with
values and roll counts if present, location), account (UID, nickname). Note how "equipped on" is expressed
(character key, in-game ID, "Traveler").

## 2. Write the parser (`backend/app/Services/Import/...`)

- A class with `public static function matches(array $json): bool` and `public function parse(array $json): ParsedAccount`.
- `new ParsedAccount(game, source, fullInventory, uid, nickname, …, meta: ['tool' => …])`.
  `fullInventory: true` means the file lists *every* item, so the importer replaces all gear; use `false`
  for partial data (showcases), which only replaces gear of the listed characters.
- References: prefer in-game IDs (`['external_id' => '1005']`, they match the yatta catalog), fall back to
  names (`['name' => 'KaedeharaKazuha']`), or give a canonical key for special cases (`['key' => 'traveleranemo']`).
- Stats: map to canonical keys (`docs/stats-and-slots.md`); percentages in percent units (Enka ratios ×100);
  drop unknown substats with a warning instead of guessing. Fill `main_value` from the source, else
  `MainStatValues::compute()`.
- Throw `ImportException` with a sentence the player can act on ("…turn on Show Character Details…");
  it is shown in the wizard as is. Collect soft problems in `$account->warnings`.

## 3. Wire it in

- Files: add detection to `ImportService::fromFile()` (the `match` order matters — most specific first).
- New online source: a client class like `Enka/EnkaClient` (errors mapped to messages, response caching,
  polite delays through `Fetcher`), a method on `ImportService`, a controller action + route in `routes/api.php`
  with validation and a throttle, and `Api` method in `frontend/src/app/core/api.ts`.
- Credentials (e.g. HoYoLAB cookies): ask the user before building this. Never log or return them, store them
  encrypted (`Crypt`) or not at all, and say in the wizard what they grant.
- CLI: `accounts:import` routes UIDs to Enka and files to `fromFile`; extend it if the new path needs it.

## 4. Test

Follow `backend/tests/Feature/ImportTest.php`: seed the catalog with `Tests\Support\SeedsCatalog`, build a
small synthetic JSON with the real field names, and assert the stored account, items (slot, main stat,
substats with rolls, equipped character), talents and `ImportRun` summary. Mock remote APIs with `Http::fake`
and bind a fake `EnkaStore` like the existing test does, so tests never hit the network.

```bash
scripts/artisan.sh test && (cd backend && ../scripts/php.sh vendor/bin/pint)
scripts/artisan.sh accounts:import <game> path/to/real-sample.json      # then look at the account in the UI
```

## 5. User-facing guide and docs

- Wizard content: `frontend/src/app/pages/import/import-guides.ts` (`METHODS` pros/cons, `GUIDES` steps with
  download links, tips, troubleshooting, `fileHint`). A brand-new method key also needs handling in
  `import-wizard.ts/.html`.
- Update `docs/import-formats.md`, the README import table, and `docs/decisions.md` for notable choices.
- Check the wizard visually with the `verify-ui` skill.
