---
name: debug-build-sync
description: Diagnose and fix hoyo-analyzer's build sync. Use whenever a source shows "failed" or "partial", builds are missing, outdated or wrong for a character, sync logs contain "! unknown weapon/set", a character name doesn't match the catalog, a site (genshin-builds.com, Prydwen, genshin.gg) changed its layout, requests get blocked, the daily sync stopped running, or right after a game patch — even if the user only says "the builds for X look off".
---

# Debug the build sync

Most sync problems fall into a few buckets, each with a different fix. Identify the bucket from evidence
before changing code; a guessed parser change can silently drop data for every character.

| Bucket | Evidence | Fix |
| --- | --- | --- |
| Name mismatch | `! unknown weapon/set: X` in the run log, a duplicate catalog character | alias in `backend/config/hoyo.php` (see `docs/name-matching.md`) |
| Parser breakage | `ERROR` lines, `--dry-run` shows many `changed` / missing fields, `characters_without_builds` jumps | update the scraper; the site changed its structure |
| Blocking / network | whole run `failed`, HTTP 403 / Cloudflare "Just a moment" / 429 | browser UA (`'browser' => true`), longer `delayMs()`, retry later |
| Missing character | character absent from builds | unreleased on the site, not listed yet, or no build there (`--check-names`) |
| Not running | data ages but no failed runs | no `schedule:work` and no Windows task (step 1) |

The `sync-doctor` agent runs this diagnosis end to end (read-only) and returns a proposed fix; use it for
anything beyond a one-line alias. Commands below assume the repo root as working directory.

## 1. Status and scheduling

Each source's own status plus its last *full* run (small `--character` runs are flagged `partial_run` and
must not hide a failed full sync):

```bash
scripts/artisan.sh tinker --execute='foreach (App\Models\BuildSource::all() as $s) { $r = App\Models\SyncRun::where("build_source_id", $s->id)->latest("id")->get()->first(fn ($x) => empty($x->stats["partial_run"])); preg_match_all("/! unknown (weapon|set): (.+)/", $r?->log ?? "", $m); echo $s->key, " | source ", $s->last_status ?? "never", " @ ", $s->last_synced_at?->toDateTimeString() ?? "-", " UTC | last full run #", $r?->id ?? "-", " ", $r?->status ?? "-", " errors=", $r?->stats["errors"] ?? "-", " | unknown: ", implode(", ", array_unique($m[2])) ?: "none", "\n"; }'
scripts/artisan.sh tinker --execute='echo App\Models\SyncRun::whereHas("source", fn($q)=>$q->where("key","prydwen.hsr"))->latest("id")->first()->log;'
```

In a log, `! unknown …` lines come *before* the `  <Character>: N build(s)` line they belong to. Times are UTC.
Missed daily runs leave no trace in the database; check the scheduler from PowerShell:
`Get-ScheduledTask -TaskName 'Hoyo Analyzer daily build sync'` and
`Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -match 'schedule:work'`.

## 2. Reproduce without hammering the sites

Pages are cached in `backend/storage/app/private/http-cache/<sha1(exact url)>.cache` and reused for
`HOYO_HTTP_CACHE_HOURS` (12). Prefix diagnosis commands with `HOYO_HTTP_CACHE_HOURS=96` to stay offline.

```bash
HOYO_HTTP_CACHE_HOURS=96 scripts/artisan.sh builds:sync --dry-run                 # all sources: unchanged/changed/new, unknown names; writes nothing
HOYO_HTTP_CACHE_HOURS=96 scripts/artisan.sh builds:sync prydwen.hsr --dry-run --character=kafka -v
HOYO_HTTP_CACHE_HOURS=96 scripts/artisan.sh builds:sync --check-names             # list pages only
HOYO_HTTP_CACHE_HOURS=96 scripts/artisan.sh tinker --execute='$s = app(App\Services\Builds\BuildSourceRegistry::class)->get("prydwen.hsr"); $ref = collect($s->listCharacters())->firstWhere("slug", "kafka"); echo json_encode($s->fetchBuilds($ref), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);'
```

Raw data from a cached page (use the exact URL from `docs/build-sources.md`, e.g. with `https://www.`):

```bash
scripts/artisan.sh tinker --execute='$url = "https://www.prydwen.gg/star-rail/characters/kafka"; $html = Illuminate\Support\Facades\Storage::disk("local")->get("http-cache/".sha1($url).".cache"); echo json_encode(App\Services\Builds\Parsing\NextFlight::findProps($html, "statBuilds"), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);'
```

For a live look, fetch the page once with a browser user agent into a scratch folder (not the repo):
`curl -sSL --compressed -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36' URL -o page.html`.
Long tinker code is easier from a file: `scripts/artisan.sh tinker --execute="$(cat /tmp/check.php)"`.

## 3. Fix

- **Alias**: add `'<Key::from(bad name)>' => '<catalog key>'` with a comment for site typos, then run the
  alias-target check from `docs/name-matching.md`.
- **Parser**: first add a failing test that imitates the new page shape (synthetic, small; see
  `tests/Feature/BuildSyncTest.php` and `tests/Unit/PageDataParsingTest.php`), then change the scraper until it
  passes. Keep old shapes working if the site serves both.
- **Catalog gap** (brand-new character/weapon): `scripts/artisan.sh catalog:sync --force`.
- **Not scheduled**: tell the user; `.\dev.ps1 install-task` registers the daily Windows task (ask first — it
  changes their system), or `.\dev.ps1 start` runs the scheduler while open.

## 4. Verify

```bash
scripts/artisan.sh test
HOYO_HTTP_CACHE_HOURS=96 scripts/artisan.sh builds:sync --dry-run        # expected changes only, no unknown names
scripts/artisan.sh builds:sync <source> --force                           # writes; cached pages cost no requests
```

Re-run the step 1 status query: `success`, `errors=0`, `unknown: none`. Runs limited with
`--character`/`--limit` never update the source's "last synced" time, so do a full run before calling it done.
If the site's structure changed, update `docs/build-sources.md`.
