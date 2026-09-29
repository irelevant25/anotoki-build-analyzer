---
name: add-build-source
description: Add a new website as a build source (scraper) to the build analyzer's build sync, or replace one. Use whenever the user wants builds from another site (game8, KeqingMains, Mobalytics, a new Prydwen section, a new game…), says "can we also use X for builds", wants a second opinion source, or a site needs a rewritten scraper after a redesign — even if they don't say "scraper".
---

# Add a build source

A build source is a PHP class that lists a site's characters and turns each character page into
`ScrapedBuild` objects. `BuildSync` does everything else (name resolution, storage, change tracking,
run logs), and the Sources page and analyzer pick new sources up automatically - as "Source N" of their game,
never by the site's name. So the work is: find where the site keeps its data, map it faithfully, prove it with
a test, then run it politely.

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

## 2. Implement `backend/src/Services/Builds/Sources/<Site><Game>.php`

```php
namespace App\Services\Builds\Sources;

class ExampleHsr implements BuildSource
{
    // Built by App::make(): any class the container knows can be a parameter (SetBonusGroups, Catalog…).
    public function __construct(protected readonly Fetcher $fetcher, protected readonly Config $config) {}

    public function key(): string { return 'example.hsr'; }              // <site>.<game>, stored in build_sources
    public function game(): Game { return Game::Hsr; }
    public function name(): string { return 'Example'; }                  // internal: the command line and the docs
    public function baseUrl(): string { return 'https://example.gg'; }
    public function delayMs(): int { return 2000; }                       // ≥ robots.txt crawl delay

    public function listCharacters(): array { /* → list<CharacterRef(slug, name, url, released, meta: element)> */ }
    public function fetchBuilds(CharacterRef $character): array { /* → list<ScrapedBuild> */ }

    protected function fetch(string $url): string
    {
        return $this->fetcher->get($url, [
            'browser' => true,                                   // only if the site blocks the default UA
            'delay_ms' => $this->delayMs(),
            'cache_hours' => (int) $this->config->get('fetch.cache_hours', 12),
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
- `notes` plain text, `sourceUrl` (kept for the command line; the API never sends it), `updatedAt`
  (`DateTimeImmutable`, e.g. `Clock::parse()`), `gameVersion`.

Parsing helpers: `Parsing/AstroIslands::find($html, 'Component')`, `Parsing/NextFlight::findProps($html, 'key')`,
`GenshinGgSource::decodeBundle($js)`, and PHP 8.4's `Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR)`
with `querySelectorAll()` for plain HTML. Treat unreleased characters as `released: false` so they are skipped.

Register the class in `backend/config/app.php` → `sources`, **at the end of its game's entries**: the sources of
a game are numbered in that order ("Source 1", "Source 2" …, API key `hsr-2`), and the numbers of the existing
ones must not move. Messages you write (exceptions, log lines) must not name the site either - they reach the
Sources page (`Neutral::text()` cleans what slips through, but don't rely on it).

## 3. Prove it with a test

Copy the pattern of `backend/tests/build_sync.test.php`: `servePages()` replaces the fetcher with a fake that
returns small synthetic pages (a few lines imitating the site's real structure, not copied site content),
`T::$app->config->set('sources', [YourSource::class])`, then `syncSource()` (a run started and stepped by
`SyncRunner`, as the Sources page does) and assertions on the stored build (weapons, set pieces and counts,
main stats, substats, a target) and on the neutral name ("Source 1") in `build_sources.name`. Add a test to
`backend/tests/parsing.test.php` if you wrote a new page-data helper.

A source must stay step-friendly: one `fetchBuilds()` call fetches the character's page(s) and returns without
writing anything (storing is `BuildSync`'s job), and every request goes through `Fetcher` with the site's
`delay_ms` - that is what lets a step stop before a crawl delay and resume the character in the next request.

```bash
cd backend && php tests/run.php
```

## 4. Try it on the real site, gently

```bash
cd backend
php sync-builds.php example.hsr --check-names                              # list only: names the catalog can't match
php sync-builds.php example.hsr --character=<slug> --character=<slug2> --force
```

Look at what was stored with a scratch PHP file (outside the repo, run from the repo root):

```php
<?php
require 'backend/vendor/autoload.php';
$db = App\Cli::app()->db();
echo json_encode($db->one("SELECT b.* FROM builds b JOIN build_sources s ON s.id = b.build_source_id WHERE s.key = ? ORDER BY b.id DESC LIMIT 1", ['example.hsr']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
```

Fix unmatched names with aliases (`docs/name-matching.md`), then run the full sync in the background
(`run_in_background`) and check the run: 0 errors, no `! unknown` lines (snippet in the `debug-build-sync`
skill). After later parser tweaks, `php sync-builds.php example.hsr --dry-run --cache-hours=96` replays the
cached pages and shows exactly which builds your change affects, without writing anything.

## 5. Finish

Update `docs/build-sources.md` (a section like the existing ones) and `docs/decisions.md`. Mention the source's
request delay to the user if it makes a full sync (which runs while the admin's Sources tab is open) noticeably
longer.
