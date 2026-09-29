---
name: debug-build-sync
description: Diagnose and fix the build analyzer's build sync. Use whenever a source shows "failed" or "partial", builds are missing, outdated or wrong for a character, sync logs contain "! unknown weapon/set", a character name doesn't match the catalog, a site (genshin-builds.com, Prydwen, genshin.gg) changed its layout, requests get blocked, a sync stays "paused" or never finishes, or right after a game patch — even if the user only says "the builds for X look off".
---

# Debug the build sync

Most sync problems fall into a few buckets, each with a different fix. Identify the bucket from evidence
before changing code; a guessed parser change can silently drop data for every character.

| Bucket | Evidence | Fix |
| --- | --- | --- |
| Name mismatch | `! unknown weapon/set: X` in the run log, a duplicate catalog character | alias in `backend/config/app.php` (see `docs/name-matching.md`) |
| Parser breakage | `ERROR` lines, `--dry-run` shows many `changed` / missing fields, `characters_without_builds` jumps | update the scraper; the site changed its structure |
| Blocking / network | whole run `failed`, HTTP 403 / Cloudflare "Just a moment" / 429 | browser UA (`'browser' => true`), longer `delayMs()`, retry later |
| Missing character | character absent from builds | unreleased on the site, not listed yet, or no build there (`--check-names`) |
| Not running | data ages but no failed runs | nothing is scheduled: syncs run only when an admin starts them (Sources page, `sync-builds.php`); or a run is `running` but paused (step 1) |

The `sync-doctor` agent runs this diagnosis end to end (read-only) and returns a proposed fix; use it for
anything beyond a one-line alias. The UI shows sources as "Source N" and writes logs in neutral words; the
command line and the database use the internal keys (`prydwen.hsr`) - `php sync-builds.php` accepts both.

Syncs run in steps (`src/Services/Sync/SyncRunner`): a run stays `running` from its start until its last step,
and only step requests (an open Sources page, or `sync-builds.php`) advance it. See `docs/build-sources.md`
"Sync mechanics".

Queries go through a scratch PHP file outside the repo, run from the repo root with `php <file>`:

```php
<?php
require 'backend/vendor/autoload.php';
$app = App\Cli::app();                             // the configured installation (backend/config/*.local.php)
$db = $app->db();                                  // all(), one(), value(), column() with ? parameters
$out = fn ($x) => print(json_encode($x, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
```

## 1. Status, and runs still going

Each source's own status plus its last *full* run (small `--character` runs are flagged `partial_run` and
must not hide a failed full sync):

```php
foreach ($db->all('SELECT id, key, last_status, last_synced_at FROM build_sources ORDER BY game, key') as $s) {
    $run = $db->one("SELECT id, status, stats, log FROM sync_runs WHERE build_source_id = ?
        AND COALESCE((stats::jsonb)->>'partial_run', 'false') <> 'true' ORDER BY id DESC LIMIT 1", [$s['id']]);
    preg_match_all('/! unknown (weapon|set): (.+)/', $run['log'] ?? '', $m);
    $out([$s['key'], $s['last_status'] ?? 'never', $s['last_synced_at'], $run['id'] ?? null, $run['status'] ?? null,
        json_decode($run['stats'] ?? 'null', true)['errors'] ?? null, array_values(array_unique($m[2]))]);
}
echo $db->value("SELECT r.log FROM sync_runs r JOIN build_sources s ON s.id = r.build_source_id WHERE s.key = ? ORDER BY r.id DESC LIMIT 1", ['prydwen.hsr']);
```

In a log, `! unknown …` lines come *before* the `  <Character>: N build(s)` line they belong to. Times are UTC.

Runs still going - "paused" when nobody has stepped them for a while (nothing is lost; Resume on the Sources
page or `php sync-builds.php <source>` continues them), waiting when `next_step_at` is in the future (a crawl
delay or a retry after 429/5xx):

```php
$out($db->all("SELECT r.id, r.kind, COALESCE(s.key, r.game) AS what, (r.state::jsonb)->>'done' AS done,
    (r.state::jsonb)->>'total' AS total, r.last_step_at, r.next_step_at
    FROM sync_runs r LEFT JOIN build_sources s ON s.id = r.build_source_id WHERE r.status = 'running' ORDER BY r.id"));
```

## 2. Reproduce without hammering the sites

Pages are cached in `backend/storage/http-cache/<sha1(exact url)>.cache` and reused for `cache_hours` (12,
`config/fetch.local.php`). Add `--cache-hours=96` to diagnosis commands to stay offline.

```bash
cd backend
php sync-builds.php --dry-run --cache-hours=96                          # all sources: unchanged/changed/new, unknown names; writes nothing
php sync-builds.php prydwen.hsr --dry-run --cache-hours=96 --character=kafka -v
php sync-builds.php --check-names --cache-hours=96                      # list pages only
```

The parsed builds of one character, and the raw data of a cached page (use the exact URL from
`docs/build-sources.md`, e.g. with `https://www.`):

```php
$app->config->set('fetch.cache_hours', 96);
$source = $app->get(App\Services\Builds\BuildSourceRegistry::class)->get('prydwen.hsr');
foreach ($source->listCharacters() as $ref) {
    if ($ref->slug === 'kafka') { $out($source->fetchBuilds($ref)); }
}
$url = 'https://www.prydwen.gg/star-rail/characters/kafka';
$html = file_get_contents('backend/storage/http-cache/'.sha1($url).'.cache');
$out(App\Services\Builds\Parsing\NextFlight::findProps($html, 'statBuilds'));
```

For a live look, fetch the page once with a browser user agent into a scratch folder (not the repo):
`curl -sSL --compressed -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36' URL -o page.html`.

## 3. Fix

- **Alias**: add `'<Key::from(bad name)>' => '<catalog key>'` to `backend/config/app.php` with a comment for
  site typos, then run the alias-target check from `docs/name-matching.md`.
- **Parser**: first add a failing test that imitates the new page shape (synthetic, small; see
  `backend/tests/build_sync.test.php` - its `syncSource()` helper steps a run like the Sources page - and
  `backend/tests/parsing.test.php`), then change the scraper until it passes. Keep old shapes working if the
  site serves both.
- **Catalog gap** (brand-new character/weapon): `cd backend && php sync-catalog.php --force` (or "Refresh
  catalog" on the Sources page).
- **Not running / paused**: nothing is scheduled by design (the host has no cron or worker). Tell the user to
  press Sync all (or Resume) on the Sources page and keep the tab open, or run `php sync-builds.php` locally.
- **A run stuck `running`** (e.g. its source was removed from `config/app.php`): cancel it on the Sources page
  or `POST /api/sync-runs/{id}/cancel`.

## 4. Verify

```bash
cd backend && php tests/run.php
cd backend && php sync-builds.php --dry-run --cache-hours=96        # expected changes only, no unknown names
cd backend && php sync-builds.php <source> --force                   # writes; cached pages cost no requests
```

Re-run the step 1 status query: `success`, `errors` 0, no unknown names. Runs limited with
`--character`/`--limit` never update the source's "last synced" time, so do a full run before calling it
done. If the site's structure changed, update `docs/build-sources.md`.
