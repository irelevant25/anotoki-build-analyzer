# Decisions

Short records of choices that shape the code. Add a new entry (newest first) when you make a similar call.

**2026-09-25 — `builds:sync --dry-run` and a schedule timezone.** A trial run of the `sync-doctor` agent showed
that read-only diagnosis needed a way to replay cached pages against stored builds without writing, so the
command gained `--dry-run` (same normalization and hash as real syncs). Scheduled times now follow
`HOYO_SYNC_TIMEZONE` so "04:30" means the same as the Windows task; data stays in UTC.

**2026-09-25 — Project AI setup.** `CLAUDE.md` + `docs/` knowledge base + project skills and agents in `.claude/`.
Bash helpers `scripts/php.sh` / `scripts/artisan.sh` so every session uses PHP 8.4+ without hard-coded paths.

**2026-09-24 — Inventory suggestions never make set bonuses worse.** Per-slot swaps keep the target option's
fraction (and, when no recommended set is worn, the current 2pc/4pc bonuses); switching sets is shown as a
set plan instead of misleading single-piece hints.

**2026-09-24 — Build sources: genshin-builds.com + Prydwen (Genshin), Prydwen + genshin.gg (HSR, and GI as a third
opinion).** Chosen for structured embedded data and freshness. game8 (hand-written tables, bot blocking),
KeqingMains (prose, stale) and genshin-builds HSR (game8 copy) rejected. Prydwen "analytics-only" Genshin
pages become "Most used" builds so every character is covered.

**2026-09-24 — Catalog from yatta.moe, not Enka's store.** yatta has current lists for both games with IDs
that match Enka and scanner exports; Enka's legacy name files stopped at 6.2. Enka's `store/gi/avatars.json`
is used only for talent skill order.

**2026-09-24 — Canonical keys = normalized English names.** One key space for GOOD keys, display names and
slugs; aliases + unique fuzzy matching cover nicknames and typos. A shorter catalog key never matches a
longer name (new alt characters like "Himeko • Nova").

**2026-09-24 — Build details as JSON columns.** Builds are always read whole and compared in PHP; JSON keeps
the schema small and works in SQLite and PostgreSQL alike.

**2026-09-24 — Import methods: Enka (UID) + exporter files; HoYoLAB deferred.** No credentials needed.
HoYoLAB needs the player's session cookies and a changing request signature; revisit on request.

**2026-09-24 — Laravel 13 + Angular 21 + Angular Material.** The user's stack preference (Angular, PHP).
Laravel for migrations (SQLite → PostgreSQL), scheduler, queue and HTTP client. Angular 21 because the
installed Node (22.15) is too old for Angular 22. PHP 8.4+ required for the native `Dom\HTMLDocument` parser.

**2026-09-24 — Politeness first.** Per-host delays (Prydwen 10 s per robots.txt), 12 h page cache, a stale-hours
guard so manual runs never re-hit a site the same day, and runs limited to a few characters never mark a
source as synced.
