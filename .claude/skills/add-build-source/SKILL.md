---
name: add-build-source
description: Add a new website as a build source (scraper) to hoyo-analyzer's daily build sync, or replace one. Use whenever the user wants builds from another site (game8, KeqingMains, Mobalytics, a new Prydwen section, a new game…), says "can we also use X for builds", wants a second opinion source, or a site needs a rewritten scraper after a redesign — even if they don't say "scraper".
---

# Add a build source

A build source is a PHP class that lists a site's characters and turns each character page into
`ScrapedBuild` objects. `BuildSync` does everything else (name resolution, storage, change tracking,
run logs), and the Sources page and analyzer pick new sources up automatically. So the work is: find where
the site keeps its data, map it faithfully, prove it with a test, then run it politely.

Background reading: `docs/build-sources.md` (existing scrapers, sync mechanics, rejected sites and why),
`docs/stats-and-slots.md` (stat keys and the label parsers you will call), `docs/data-model.md` (the stored shape).

## 1. Research before code

Delegate to the `build-source-researcher` agent (it knows the parsing helpers and the politeness rules), or
do the same yourself:

- robots.txt (crawl delay, disallowed paths) and whether a plain browser user agent gets through.
- Where the data lives, best first: embedded JSON (Astro islands, Next.js RSC payload, Nuxt payload, Gatsby
  page-data, a JSON API the page itself calls, a JS bundle) → consistent HTML → prose. Prose-only or
  hand-written tables that vary per page are why game8 and KeqingMains were rejected; tell the user if the
  site is in that category instead of forcing it.
- Which fields exist: variants, weapons (ranked, refinement), set options (4pc / 2+2 / flex groups, planar
  sets for HSR), main stats per slot, substat priority with ties, talents/traces, stat goals, update date.
- Freshness: are the newest characters there?

Save samples outside the repository (session scratchpad or system temp), never under `backend/` or `frontend/`.

## 2. Implement `backend/app/Services/Builds/Sources/<Site><Game>.php`

```php
class ExampleHsr implements BuildSource
{
    public function __construct(protected readonly Fetcher $fetcher) {}   // add SetBonusGroups etc. if needed

    public function key(): string { return 'example.hsr'; }              // <site>.<game>, stored in build_sources
    public function game(): Game { return Game::Hsr; }
    public function name(): string { return 'Example'; }
    public function baseUrl(): string { return 'https://example.gg'; }
    public function delayMs(): int { return 2000; }                       // ≥ robots.txt crawl delay

    public function listCharacters(): array { /* → list<CharacterRef(slug, name, url, released, meta: element)> */ }
    public function fetchBuilds(CharacterRef $character): array { /* → list<ScrapedBuild> */ }

    protected function fetch(string $url): string
    {
        return $this->fetcher->get($url, [
            'browser' => true,                                   // only if the site blocks the default UA
            'delay_ms' => $this->delayMs(),
            'cache_hours' => (int) config('hoyo.http.cache_hours'),
        ]);
    }
}
```

Mapping into `ScrapedBuild` (read `ScrapedBuild.php` for the exact types):

- `variant`: the site's build name (unique per character; `prettyName()` in `GenshinBuildsGenshin` fixes ALL-CAPS), `role`, `recommended`.
- `weapons`: ordered best first, `['name', 'refinement' => int|null, 'note']`. Names stay display names.
- `sets`: ordered options `['kind' => artifact|relic|planar, 'choose' => null|int, 'pieces' => [['names' => [...], 'label', 'count' => 4|2]], 'note']`.
  A piece with several names means "any of these"; stat groups ("2pc ATK% set") come from `SetBonusGroups::names()`.
- `mainStats` per variable slot and `substats`: canonical keys with priorities. Use `Stats::parsePriorityList()`
  for strings with `>` / `=`, `Stats::ordered()` for plain ordered lists (crit pairs tie), `Stats::parse()` for single labels.
- `talents`: `Talents::parsePriority()` / `Talents::ordered()`. `statTargets`: `StatTargets::extract($game, $html)`.
- `notes` plain text, `sourceUrl`, `updatedAt` (Carbon), `gameVersion`.

Parsing helpers: `Parsing/AstroIslands::find($html, 'Component')`, `Parsing/NextFlight::findProps($html, 'key')`,
`GenshinGgSource::decodeBundle($js)`, and PHP 8.4's `Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR)`
with `querySelectorAll()` for plain HTML. Treat unreleased characters as `released: false` so they are skipped.

Register the class in `backend/config/hoyo.php` → `sources`.

## 3. Prove it with a test

Copy the pattern of `backend/tests/Feature/BuildSyncTest.php`: a fake `Fetcher` bound in the container that
returns small synthetic pages (a few lines imitating the site's real structure, not copied site content),
`config(['hoyo.sources' => [YourSource::class]])`, then run `BuildSync` and assert the stored build (weapons,
set pieces and counts, main stats, substats, a target). Add a unit test next to `tests/Unit/PageDataParsingTest.php`
if you wrote a new page-data helper.

```bash
scripts/artisan.sh test && (cd backend && ../scripts/php.sh vendor/bin/pint)
```

## 4. Try it on the real site, gently

```bash
scripts/artisan.sh builds:sync example.hsr --check-names                          # list only: names the catalog can't match
scripts/artisan.sh builds:sync example.hsr --character=<slug> --character=<slug2> --force
scripts/artisan.sh tinker --execute='echo App\Models\Build::whereHas("source", fn($q)=>$q->where("key","example.hsr"))->latest("id")->first()->toJson(JSON_PRETTY_PRINT);'
```

Fix unmatched names with aliases (`docs/name-matching.md`), then run the full sync in the background
(`run_in_background`) and check the run: 0 errors, no `! unknown` lines (snippet in the `debug-build-sync` skill).
After later parser tweaks, `HOYO_HTTP_CACHE_HOURS=96 scripts/artisan.sh builds:sync example.hsr --dry-run`
replays the cached pages and shows exactly which builds your change affects, without writing anything.

## 5. Finish

Update `docs/build-sources.md` (a section like the existing ones), the README sources table and
`docs/decisions.md`. Mention the source's request delay to the user if it makes the daily sync longer.
