# Decisions

Short records of choices that shape the code. Add a new entry (newest first) when you make a similar call.
Older entries describe the Laravel version as it was; where a setting has since moved, the entry says so.

**2026-10-20 — Version 0.5.0, released once, at the end (the owner's decision).**
Star Rail's removal, the builds without sources, the catalog from genshin.anotoki.eu and the builds browser's filters
(the entries below) are one release, built phase by phase on this computer and deployed only once all of it was done:
genshin.anotoki.eu first (its migrations 030 and 031, its catalog endpoint), then this site - the names file uploaded
once, the release, one Apply of 014-019 at once, Refresh catalog, the first builds file imported (docs/operations.md,
"Deploying 0.5.0"). Between the Apply and that import the site has no builds for a few minutes - accepted, as nothing
needed carrying over: the owner was the site's only user (the live backup of 2026-10-19: one person, two accounts). The
backups made before 014 and 016 hold Star Rail and the old builds tables: they are read offline only
(`php backup.php --inspect`, `--extract`) - a restore would bring back what 014-016 removed. What's new says what a
player notices: builds by what they are for, with the constellation they need; the best build following the player's
constellation; the builds page's filters; Star Rail no longer kept.

**2026-10-20 — The site reads no build site: an editor imports one merged file per game (the owner's decision; version 0.5.0).**
The repository is public, and its scrapers named where the builds came from. Instead the build sites are read now and
then on the owner's computer, politely (robots.txt obeyed, each site's own wait, every page kept), by untracked tools
in the git-ignored `local/` - one per site, and one that merges them into a file per game (docs/builds.md says what the
file must be) -, and an EDITOR imports that file on the admin panel's Builds page (`/admin/builds`): a dry run's
preview first (the counts, the characters skipped, every change, a warning when the file is older than the last one
imported), then Import or Cancel; Export beside it, the recent imports and the catalog below. No "Source N", no sync
runs, no Sources page: migration 016 drops `build_sources`, `sync_runs`, the old `builds` and `cache_locks`, and the
stepped runs and their locks, `FetchDeferred`, the fetcher's step mode, the catalog's create mode,
`fetch.step_seconds` / `stale_hours`, `sync-builds.php` and the bar's sync widget went with them. A request stays short
without steps: an import is one transaction of a file of 8 MB at most, a catalog refresh one list.
- *The builds file, version 2, read strictly* (`BuildFile`): every field and no other; the game's roles, slots, stats,
  talents and set kinds; a slot's main stats among those it can roll; `min_rank` and `from_rank` within the game's
  constellations; a build's identity once in a file, a stat's target once per `from_rank`; 5000 builds, 8 MB. One
  problem refuses the whole file - a version 1 file (one source's builds) with a sentence of its own. Every text is
  made neutral on the way in and out, and what the export writes the import reads, as for the sources' files of
  2026-09-29.
- *The file is the whole truth for its game* (`BuildImport`): once imported, the game's builds are exactly the file's -
  a character it lacks has none left - but for characters the catalog lacks, skipped and named (a file of none it knows
  is refused rather than emptying the builds). One transaction under an advisory lock (another import meanwhile: 409
  `busy`, nothing written), recorded in `build_imports` - what it did, who imported it - and in the audit log
  (`builds.imported`); the file's times are never later than now here.
- *Ids kept by identity*: a build the file has again - the same character, role, qualifier without regard to case and
  `min_rank` - keeps its id, so a link to it (`?build=ID`) outlives the import; another `min_rank` is another build.

**2026-10-20 — A build is a variation of a character: its role, a qualifier, the constellation it needs (the owner's decisions; version 0.5.0).**
Without sources a build is no longer "Source 2's": it is what it is for. A role (`main_dps`, `sub_dps`, `support` -
`Game::roles()`), a short `qualifier` only where a character has two builds of one role ("Melt": at most 40 characters,
one line, neutral; '' otherwise) and `min_rank`, the constellation it needs (0-6); a stat target can start from a
constellation (`from_rank`). A character's builds come in their order of preference, the first the main one; a page
writes a variation "Main DPS · Melt · C4+" (`core/i18n/variation-words.ts`), the role in the reader's language.
- *Constellations now* (the owner's decision): in the file and the analysis from the start, the local tools taking them
  from what the build sites say of a build's constellation and from their tables of a stat by constellation; a site's
  team section may add more later. The analysis follows the player's constellation: the best build is the best scored
  of those it reaches, the earlier on a tie; one it does not reach is judged all the same and shown locked ("needs
  C4"), the best only when none is reached (the lowest `min_rank`, `applies: false`); of a stat's targets, the one from
  the highest constellation reached counts (`BuildEvaluator::targetsFor()`). A build without substats is scored by its
  own main stats (`ItemScorer::fallbackSubstats()`), never by another build's; the consensus of sources went.
- *Hand edits later, so the identity is stable now* (the owner's decision): editors will adjust builds by hand in a
  later round, and an import will then ask per build whether the file or the edit stands. That needs one name for a
  build in a file, in the table and in an edit - (character, role, lower(qualifier), `min_rank`), unique in `builds`
  (016's index; the qualifier '' rather than null, as the host's PostgreSQL 14 has no `NULLS NOT DISTINCT`) - and an
  import plan of one entry per identity, where that choice will go.

**2026-10-20 — The local tools pick by a ranking of the build sites; a real date beats it (the owner's decision).**
Where two build sites have a build for the same role and constellation, the merge takes the one of the site ranked
higher in the tools' own settings - unless both carry a real date of their last update: then the newer wins. Only one
site gave real page dates when this was decided, so the ranking settles most picks. The others only fill gaps -
talents, stat targets (another role's for Energy Recharge alone) and substats (from the same role); never weapons,
sets, main stats or notes - and the export's report names every borrow. The rule is the untracked tools'; the file
carries its result, naming no site.

**2026-10-20 — The repository names no build site; its history stays as it is (the owner's decisions; version 0.5.0).**
The repository is public, and its tree named the build sites - the scrapers, a page of these docs, two skills, two
agents, `Neutral`'s list, alias comments, tests, entries of this file. Now no tracked file, commit message, branch or
PR names one: the scrapers and what served them went to the untracked `local/`, the docs, skills and agents about them
were deleted or rewritten, and the old entries below say "a build site". The git history is left as it is (the owner's
decision): only the current tree is cleaned.
- *The names in an untracked settings file*: the owner's `backend/config/neutral.local.php`
  (`['names' => [...], 'tree_words' => [...]]`, by hand; uploaded to the host once, like `cloudflare.local.php`), read
  once through `Config::readOptionalFile()` - a broken or empty file is no file, never a looser rule. `Neutral` adds
  its `names` to its own list of services and tools (`Neutral::localNames()`; the tests set none), so the API's
  answers, the strings' check and the release's check keep them out as before.
- *A check of the tracked tree*: `php backend/tools/check-names.php` reads every file git tracks or `git add -A` would
  add for the `names` (whole words; in a file's path however the path joins their words) and the `tree_words`
  (anywhere: typos, page parts, labels that would give a site away), says where it found one - never the list - and
  fails; `--staged` reads the index, `--message=<file>` a commit message (git's comment lines aside). The owner's
  untracked hooks run it - `pre-commit` with `--staged`, `commit-msg` with the message -, so neither a staged file
  nor a commit message can name a site. Whether git answered is git's exit status: no untracked file, or nothing
  staged, is an empty list, not a failure. A clean run is part of "done".
- *An applied migration's comment edited* (an explicit exception to "never edit an applied migration"): a comment line
  of 002 named a site in an example, and now gives a neutral one - as do the column comments of the Laravel schema
  fixtures (`tests/fixtures/laravel/schema-{6,7,8}.sql`). Inert: the migration engine records a file by its name
  alone, with no checksum, and a comment runs nothing. Anything more than a comment is a new migration, as ever.

**2026-10-20 — Star Rail removed, the shape for more games kept (the owner's decision; version 0.5.0).**
The site is Genshin Impact's alone. Star Rail's code went - its showcase and scanner parsers, its scrapers and catalog
lists, its stats, talents, import kinds and words - and its data: migration 014 deletes its accounts (and all that
hangs on them), imports, sync runs, build sources and catalog rows and tightens every `game` CHECK to 'genshin'
(`game_sets.kind` to 'artifact'); 015 retires 56 keys and rewords what still named both games. The shape stays ready
for another game: `App\Enums\Game` keeps one case, and every `$game === Game::Genshin ? X : Y` became a method of it
(`maxRank()`, `rankPrefix()`, `roles()`, `setKinds()`, `setPieceCounts()`, `weaponLabel()`, `talentKeys()` ...); the
routes keep `{game}`, the frontend one `GAMES` list (`core/format.ts`). A second game is a case with its arms and its
parsers, and a migration widening the CHECKs. What a player meets: GOOD files and the showcase by UID only; an
accounts file holding a Star Rail account refused (`bad_entry`); the import guide without its Game step (four steps);
the library's fan clause, which names Genshin Impact alone.

**2026-10-20 — The catalog from genshin.anotoki.eu; this site keeps its own while that one is closed (the owner's decision; version 0.5.0).**
The third-party provider of 2026-09-24 is no longer asked. The owner's genshin.anotoki.eu got the game's in-game IDs
(its migrations 030 and 031: a `game_id` per character, weapon and artifact set) and a public endpoint made for this
site (`GET /api/catalog/{characters,weapons,artifacts}`: bare JSON lists with an ETag), read through a provider per
game (`CatalogProvider`, `Providers\GenshinCatalog`; `config/app.php` `catalog.genshin`). Imports resolve as before:
the in-game ID is `external_id`, the Travelers one entry per element (`traveler{element}`, `{lowest ID}-{element}`,
both twins' IDs in `meta.ids`), the scheme imports always used. Migration 018 adds a character's region, version and
the version's name.
- *Where each list stands, in the cache table* (`catalog-state:{game}:{list}`, which a restore leaves as it is): its
  ETag, when the provider last confirmed it, when it last changed, the last failure and why. A refresh asks with the
  last ETag: 304 is "unchanged", nothing written; 401, 423 and 503 are that site's switches, "closed"; 404
  "unavailable"; anything else, or more than 5 MB or 5000 entries, "failed". A changed list is written in one
  transaction, each row found again by its in-game ID, then by its key - a renamed entry keeps its row and everything
  linked to it -, and no row is ever deleted; one refresh of a list at a time. Closed or failing, genshin.anotoki.eu
  costs freshness alone: this site keeps its catalog, and the Builds page says each list's last problem.
- *Kept fresh by ordinary traffic* (no cron): after an answer, at most every five minutes, one list not confirmed
  within `catalog.refresh_days` (3) is refreshed, a failed one asked again after `retry_minutes` (60) (`CatalogUpkeep`,
  `upkeep.catalog`); by hand, Refresh catalog on the Builds page or `sync-catalog.php`.
- *A builds import refreshes the lists that name what the catalog lacks*: a file naming a character or a weapon the
  catalog does not know has that list refreshed first - once per `catalog.import_refresh_minutes` (60), the interval
  an account's import shares - and only what is still unknown is skipped.
- *AVIF icons*: genshin.anotoki.eu's icons are AVIF, so the icon route serves them (`Icons::type()`: a first `ftyp`
  box naming avif or avis, of a box's size). An icon address is kept only on the API's own host (https; http for this
  computer alone), with no user part, backslash or character beyond printable ASCII. A browser without AVIF shows no
  icons, as on genshin.anotoki.eu.

**2026-10-20 — Filters on the builds browser alone, kept in the address (the owner's decision; version 0.5.0).**
The builds browser (`/builds/genshin`) filters its characters by name, element, weapon type, rarity, region and
version: the kit's segmented controls (All first, only what its characters have, an element's colour mark) and
selects (the versions newest first, "6.1 (Luna II)"), Reset, "Showing N of M". No other page takes these (the account
page keeps its element filter alone). The filters live in the address (`core/query-filters.ts`): a filtered list is a
place, not a mode - a link opens it filtered, and coming back from a character finds it as it was left; written with
`replaceUrl`, so a filter is no step of the history (Back leaves the page, never keystroke by keystroke), and nothing
is kept in the browser to go stale. Migration 019 has their 14 words. The anotoki library has no such helper - worth
asking it for.

**2026-10-20 — The browser check starts with the catalog marked fresh, so it never asks genshin.anotoki.eu.**
The upkeep refreshes a stale list after an answer, and the check's throwaway database had never refreshed one: the
check's first answers sent a real request to genshin.anotoki.eu - the network, which a check never reaches. Now
`tests/tools/browser-db.php` writes each list's state as refreshed a moment ago, and the Builds page's tour checks
that no list's refresh failed on the way. The tests keep `upkeep.catalog` off and fake the provider at its addresses;
the check never presses Refresh catalog or a showcase import.

**2026-10-19 — A release is `frontend/` and `backend/`, each replaced whole; the state is beside them, never in one
(the owner's decision).** An upload of `dist/build-analyzer/*` over the old files (FTP never deletes) left every
earlier build's hashed bundles and removed PHP files on the host, mixed with `config/*.local.php` and `storage/` -
nothing could be deleted wholesale, and nobody could tell what was stale. Now anotoki-lib's anotoki-deploy builds
the release from `backend/composer.json`'s `extra["anotoki-deploy"]` (the same tool for every site), puts it up
beside the live one, checks it and swaps it in by renames (the one before kept for a rollback); the folder's
`.htaccess` - the switch, as Japanese Academy has run since 2026-10-04 - sends every request into `frontend/`, so
`backend/`, `config/` and `storage/` are never served. The PHP finds its state with one rule,
`Config::stateRoot()`: with `release.json` in `backend/`, the folder above it; in a checkout, `backend/` itself.
`tools/build.php` went; what of it was this site's own - the frontend names no other site, service or tool - is
`tools/check-release.php`, run with every release's frontend build. `setup.php` is in `backend/` (served at
`/setup.php` by `frontend/index.php`) and still deletes itself; the Installer keeps the code's folder
(`vendor/`) and the state's apart, and makes `config/` and `storage/` if they are missing.

**2026-10-18 — anotoki-lib 0.6.3, both halves: the family's footer in place of this site's own, and Feedback only where a message can be sent (the owner's round 3; version 0.4.0, unreleased).**
- *The family's footer* (the owner, after the deploy: "I like the footer of builder the most. The order, texts and
  the top border."): the library's `<anotoki-site-footer>` is a copy of this site's footer, so it takes its place
  in both layouts with nothing to see changed - the top border, "Version 0.4.0 · What's new · Feedback", Terms ·
  Privacy · Contact, the fan clause (`fan="hoyoverse"`); What's new is still this site's dialog. Feedback's dialog is
  the library's now, named by the button ("Feedback", where the site's dialog showed the form's own title).
  `layouts/footer/` and `shared/feedback-dialog/` went; migration 013 moves their three words under the library's
  keys (`anotoki.footer.*`) the 010 way - every string, writer and time, never over an owner's rewording of the
  library's key - and copies What's new's, whose site key stays the dialog's title.
- *Feedback only where a message can be sent* (the survey's finding, for every site): the form's request says
  nothing of whom it is for, so the auth interceptor sends it as the page is shown - the person's with their token,
  the visitor's while the page is shown to one; the server (`optionalSignIn()`) takes one from anybody only while
  the IAM lets visitors in here, so somebody signed out while it requires a sign-in would press Send into "Sign in to
  send a message". Feedback in the footer and the Contact page's form are offered only to a person signed in or a
  visitor, while the site is ready (`feedbackOffered()`: an update waiting answers it 503) - not on a legal page, the
  sign-in failure's screen or the admin panel's "You are signed out" while sign-in is required. The Privacy page
  says what a message holds to everybody: only the Contact page's frame leaves the form out (`legalPagesConfig()`).
  Since anotoki-lib 0.7.2 the form says its message is anybody's (`issuedAs('anybody')`); the server's rule, and so
  this one, stand.

**2026-10-18 — After the deploy: a cloudflare.local.php that names no usable secret fails closed too; 008's regression test and a rule for every gate; setup's notice decided by the installer; the neutral-names rule's one exception; the status page bare (the owner's round 3; version 0.4.0, unreleased).**
- *A present cloudflare.local.php without a usable secret fails closed* (the owner's "do as you think is the best"
  of 2026-10-18, asked whether to tighten the rule after the deploy; this changes the entry below): a file read
  cleanly that names no secret - blank, no array, a misspelt or another key, a value that is not a string - is a
  secret no request carries now (`Config::unmatchableSecret()`), as a broken one is, never "no secret": each is a
  slip in the file, and "no secret" believes `CF-Connecting-IP` from every Cloudflare customer's Worker. Deleting
  the file is the one way to switch the secret off; anything in its place, a folder too, counts as the file. Said in
  PHP's error log once a day by the file's name, never its value. `tests/settings_files.test.php` runs the attack on
  every case (a Worker's forged address from Cloudflare's ranges - with the file's own value, or an empty header -
  is the edge, for the visitors' limit and the admin tools alike), and the file deleted.
- *008's regression test, and a rule for every gate*: `tests/migrations.test.php` applies 008 - and the rest of 0.4
  after it - on a throwaway database whose ICU collation skips punctuation (`en-US-u-ka-shifted`: "accounts" before
  "account_weapons", "builds" before "build_sources", as the live database's en_US.utf8 sorts them), with the
  Laravel leftovers 008 drops; on the file as it was before the fix (c021d6d) it fails with the live Apply's very
  error. Skipped, and said so, where the server cannot make such a database (before PostgreSQL 15, or without ICU).
  The rule (CLAUDE.md, data-model.md, the reviewer): a gate that compares two lists compares them as sets, or sorts
  both sides `COLLATE "C"`.
- *Setup's broken-file notice, decided by the installer*: `Installer::passedOver($local)` names a broken file on this
  computer and none on a real host (anybody may open the page there before the token), where setup.php alone
  decided it, untested; `tests/settings_files.test.php` holds both.
- *The neutral-names rule's one exception* (CLAUDE.md, architecture.md, the reviewer): the legal pages name who else
  handles the data, as the law asks - WebSupport s.r.o., Cloudflare, Umami - and still no build source, tool or
  the showcase service.
- *The status page is bare*: while an update waits, the database is out of reach or the site is not set up, the
  gate's page stands alone - no bar, no footer, nothing of the frame -, since the layouts that draw them are routes
  inside the gate's template, never made behind its page. It was so already; `app.spec.ts` now holds it with the
  real layouts, the frame back with the site and on the one page the ADMIN reaches meanwhile, Migrations.

**2026-10-18 — anotoki-lib 0.6.2, both halves: Terms, Privacy and Contact with this site's facts; every settings file read cleanly or not at all, its first five bytes first; a broken cloudflare.local.php fails closed; the kit's newer pieces where the site drew its own (the owner's round 3, for every anotoki site; version 0.4.0, unreleased).**
Both halves on 0.6.2 (`anotoki/lib ^0.6.0` - `^0.5.0` never takes a 0.6 -, the 0.6.2 tarball in both lock files;
package-lock.json's entry given bun.lock's version, address and integrity by hand, as the IAM did).
- *The legal pages* (the owner's decisions of 2026-10-13, the library's `@anotoki/lib/legal` and `/legal/pages`): on
  `/terms`, `/privacy` and `/contact`, each a lazy route of its own whose parent is the frame `layouts/legal-layout`
  - never one parent with an empty path holding the three, which the router would take for "/" with no guard (the
  survey's lesson of 2026-10-17, `app.routes.spec.ts`) -, before everybody's pages and the "**" at the end. The
  frame is everybody's pages' frame (`<app-app-layout>`, its router outlet draws the page) with this site's facts
  (`core/legal-facts.ts`), so the facts load with the pages, out of the first load. No guard: what the site keeps
  is said before anybody signs in, also while the IAM requires signing in here. The operator is the library's
  default (Code Hero s.r.o.); the hosting company WebSupport s.r.o. (the owner, 2026-10-18), Cloudflare, and the
  public showcase service a UID import asks - described, never named (the site names no other service).
- *The facts are the code's* (migration 011, English and informal Slovak): the record of a person, the game accounts
  and imports (they stay, tied to nobody's name, when the anotoki account is deleted - the owner's rule: the data
  stays), the editors' traces (who synced, who saved a word), the limits, the showcase answers kept a few minutes,
  the site's own log (`storage/logs/app.log`), visitors (30 days), feedback, the audit log (the editors' too, so in
  the site's words), the backups (one on each day the site is used, plain, the library's keeping), every cookie and
  stored item by name, Umami. The library's fan clause names Genshin Impact alone: Terms and Contact read the site's
  `legal.fanClause`, naming both games, in its place (`SITE_LEGAL_WORDS`, `core/legal.ts`), and the footer says its
  first sentence (`footer.fanMade`, genshin's words). Every address is the code's, never a string (the 0.6.0
  review): `tests/translation_catalogue.test.php` holds 011's strings free of any, `legal-facts.spec.ts` shows a
  rewording changing a link's words, never its address. Terms · Privacy · Contact are the family's
  `<anotoki-legal-links>` in the site's own footer, under the version's row, on every page - the admin panel's too,
  in English there.
- *Every settings file is read cleanly or not at all* (`Config::readLocalFile()`; the family's lesson of 2026-10-13,
  Japanese Academy's review of 2026-10-18): a file that does not start with `<?php` is passed over uncompiled - a
  byte-order mark or a blank line before `<?php declare(strict_types=1);` was a compile-time fatal nothing catches,
  every request a 500 -, what a file prints is caught and never sent, one PHP cannot read is no file; each said in
  PHP's error log once a day, by its fault and byte count, never its text. The required files' leave the site not
  set up (the database's password never in any answer, the status's included); `fetch.local.php`'s defaults stand
  in; `client_address.local.php` trusts no proxy - never Cloudflare's ranges in its place; setup reads its files the
  same way (on this computer it names a broken one, never a value, and keeps none of its password).
- *A broken cloudflare.local.php fails closed* (this changes the rule of 2026-10-13 below, the IAM's since that
  day): a file that cannot be read cleanly is a secret no request carries - every request from Cloudflare's ranges
  its edge, a Worker's forged `CF-Connecting-IP` the edge's even with the file's own value -, never "not set up"
  (the file is optional: the site keeps running) and never "no secret" (which believes CF-Connecting-IP from every
  Cloudflare customer). A file read cleanly that names no secret - blank, another key, no array - is none, as
  without the file (since the entry above, it fails closed too). `tests/settings_files.test.php` holds every case,
  and PHP's own fatal error without the guard.
- *The kit's newer pieces where the site drew its own* (anotoki-lib 0.6.0's; this changes what 2026-10-11 below
  kept as the site's; migration 012): the import guide's row of steps is the kit's stepper - it says which step of
  how many is in hand and that a step is done, in the library's words, a step done a way back to it; its name is
  the site's `importWizard.steps`, and the old row's `stepper.completed` goes -; the guide's trouble list and paste
  box, a build's notes and the account's import history are the kit's disclosure (the history's table made only
  while it is open); the score bars and a sync's progress are the kit's progress bar (a score as the page writes
  it, a part not judged "n/a" to the ear too, its fill the grade's colour; a sync before its total is known
  moving on its own); a character on the account page and in the builds is the kit's link card; a build's tab
  shows its score as the kit's badge, heard "score 87" after its name (`character.tabScore`), the build closest to
  the setup with the kit's check icon, a filled badge and its words; the account's element filter is the kit's
  segmented control in a labelled group, each element's mark before its name, where it was the kit's select -
  the marks `--el-mark-*`, 3:1 on the control in both themes (ten darker in light; dark's own colours reach it);
  the site's eight icons are the family's drawings (`@anotoki/lib/ui/icons`). Kept, as the kit has no piece for
  them: the grade badge (a letter on its grade's tint), a tile with actions inside it or a piece of gear
  (`.tile`), the guide's choice cards and its file drop zone, and a sync's run on the Sources page (`<details>`:
  its summary holds the run's state dot and a line of its own, where the kit's disclosure has a heading of words).
- *Along with it*: the grade letters and a recommendation's mark reach 4.5:1 on every ground of their theme (the
  letter on its 14% tint too) - darker in light, lighter in dark where dark fell short (`styles.scss`,
  `$site-colours`); `@angular/forms` gone (nothing imported it); five key descriptions that told of a snack bar
  describe the kit's toasts (011); the library's 0.6.0 changes in the site's tests - a restore's
  `after_restore_error` in the library's fixed words, the error log saying what failed; a badged link heard
  "Feedback, 3 new".

**2026-10-13 — anotoki-lib PHP 0.5.2 (Angular 0.5.0): CF-Connecting-IP believed only through the owner's zone - the origin secret (the owner's decision, for every anotoki site).**
Cloudflare's published ranges are every Cloudflare customer's, not the owner's zone alone: anybody's Worker can open
a connection to the host from them and write a `CF-Connecting-IP` of its choosing - a new address for every request,
past every limit counted per address (new visitors an hour, the imports per address, the feedback's and the pages'
reports' senders). So a Transform Rule of the owner's zone adds a secret header, `X-Anotoki-Origin`, to every request
through it, and the site, given the same value, believes `CF-Connecting-IP` only on a request that carries it (the
library's `ClientAddress::of()`, compared in constant time); any other request from Cloudflare counts as the edge it
came from - coarser, a Worker's made-up visitors sharing the edge's one bucket, never looser.
- *One setting, the same file in every site*: the optional `config/cloudflare.local.php`
  (`['origin_secret' => ...]`, `Config::ORIGIN_SECRET_FILE`), by hand - setup.php neither writes nor reads it
  (`tests/setup.test.php`), the upload never carries it. Its `origin_secret` alone is taken; only a string with
  something in it is a secret (`Config::originSecret()`, trimmed) - anything else is none, the site as before. A
  file that returns no array is a problem, as for every other file here ("not set up" until mended) - read apart
  from them, so that what PHP throws while running it (a parse error's message can quote the file) is never
  repeated: the problem names the file alone. The secret is never shown or logged. (Since 2026-10-18, above: one
  that cannot be read cleanly, or names no usable secret, fails closed and the site keeps running; only deleting the
  file switches the secret off.)
- *Every reading of an address takes it*: `App\Web\ClientAddress::counted()` (the site's counters) and
  `AdminTools::context()`'s `originSecret` (the feedback's limits, the error log's client hash). The audit log
  keeps no address, so it needs none.
- *Only the PHP half changed*: `vendor/` at v0.5.2, the frontend on the 0.5.0 tarball; no migration.

**2026-10-12 — anotoki-lib 0.5.0: a restore does again what was deleted since its backup, sealed backups, the pages' errors in the error log, one reading of an address (the owner's round; version 0.4.0, unreleased).**
Both halves on 0.5.0 (`anotoki/lib ^0.5.0`, the 0.5.0 tarball in both lock files).
- *The People page's delete runs in the library's transaction, on the connection it is given* (`People::delete()`):
  `SitePeople::deleteData()` began a transaction of its own on the site's connection (`Db::transaction()`, the
  container's `Users` and `Translations`), which the library now refuses - it holds one, with the audit entry
  inside - and which in a restore would wait on the restore's own locks while the restore waited on it
  (PostgreSQL ends such a restore after a minute). It does all its work on the `$pdo` it is handed - the person's
  row `FOR UPDATE` and its delete (the accounts and imports go by their keys), the strings' writer, the syncs'
  logs - and begins, commits or rolls back nothing; the person's feedback is the library's to forget in the same
  transaction (the site's own `forgetPeople()` call went). One hook, `SitePeople::deleteHook()`, for the page and
  for a restore (its request null there, which changes nothing here). The scrub's hooks write on the scrub's
  connection too (`Feedback::forgetPeopleOn()`, an `AuditLog` on it): after a restore the library runs the scrub
  on the restore's connection. `tests/people.test.php` runs the delete on a connection that holds every table it
  touches locked, as a restore does, with a lock timeout on the site's own.
- *A restore does again what was deleted since its backup* (`People::restoreHooks()` in the Backups' options, in
  place of the site's `AdminTools::scrubAfterRestore()`): inside its transaction, before anybody sees the rows,
  every `person.data_deleted` (the site's hook, on the restore's connection) and `feedback.deleted` the audit log
  has from the backup's snapshot on - each entry written in its delete's own transaction now (`_txid`), so no
  delete is made without one; then, committed, the scrub of the IAM's deleted accounts at once, as before - one
  that cannot finish is the answer's `after_restore_error` (the error log has it with its cause) and the next
  request checks again (`defer`: the site's runner). What the restore of 0.4.1 left "for the library's 0.4.2" (the
  entry of 2026-10-11 below) is done: a delete is not brought back, and a sequence never moves back. No `then:`:
  nothing of the site's own needs doing after a restore.
- *Only a backup made here is restored here*: every plain backup is sealed under a key from
  `storage/anotoki-lib.key` - but where that key can be neither read nor made, the backup is still made, unsealed
  (the error log says so); one without this installation's seal - that one, every backup made before 0.5.0, one
  brought from the host to this computer - is listed and read offline (`php backup.php --inspect`, `--extract`),
  never restored by the page or `--restore` (422 `archive_invalid`). Nothing is public yet, so no backup of before
  needs restoring. The list names each backup's maker: `AdminContext`'s `names` (`AdminTools::names()`, which the
  audit log's and feedback's routes now take from the context too).
- *The pages' errors in the error log* (the owner's decision of 2026-10-11, every site; the entry of 2026-10-11
  below waited for it): `ErrorLogRoutes::client()` on `POST /api/client-errors`, behind `optionalSignIn()` - the
  person from their token, counted by account; while the IAM requires sign-in here, anybody not signed in is
  refused (401), as on every public route - and the site gate (not one of its open paths: closed while an update
  waits). The library's
  limits: 20 an hour from one sender, 500 a day from the visitors and 500 from the people signed in. In the app,
  `provideAnotokiErrorReport()` beside `provideBrowserGlobalErrorListeners()`, through the app's `HttpClient`: the
  site has no interceptor of its own to teach about `ANOTOKI_ERROR_REPORT`, and the library's treat a report as
  any request (the person's token, the site's status heard).
- *One reading of an address* (the owner's rule of 2026-10-11): `App\Web\ClientAddress` reads by the library's
  `Support\ClientAddress` over the same proxies (`client_address.trusted_proxies`, Cloudflare's 22 ranges - the
  library's `ClientAddress::CLOUDFLARE`, which a test holds them to), and `AdminContext` takes that list
  (`trustedProxies`) in place of the site's `clientKey`: the feedback's limits and the error log's client hash
  count a visitor behind Cloudflare as the site's own counters do. The site keeps its keyed counter key
  (`countedKey()`). The header is `CF-Connecting-IP` and never a setting (the security review of 2026-10-12): the
  site's `client_ip_header` is gone, and `config/client_address.local.php` names the proxies alone - a local
  `X-Real-IP` or `True-Client-IP`, which Cloudflare passes through as the visitor wrote them, would have let every
  visitor pick their own bucket. Nor may that file trust a range wider than /8 (IPv6: /16) - Cloudflare's are /13
  and longer: the site is "not set up" until it is narrowed (genshin's security review of 2026-10-12). Two changes of behaviour, both stricter or equal: a header that is not one
  address - a list a proxy appends to, such as `X-Forwarded-For` - is no longer read from the right (the entry of
  2026-10-04) but never believed, so the proxy's own address counts: the owner's decision of 2026-10-11 - only
  `CF-Connecting-IP`, and only on a connection from Cloudflare's ranges; and a Cloudflare edge's IPv4 address
  written as IPv6 (`::ffff:…`) is now known as Cloudflare's.
- *The command line runs what it leaves for after the answer* (`App\Cli::app()`: when the command ends): a
  restore by `php backup.php` that could not check the IAM's deleted accounts gives the upkeep's hour back, so the
  next request checks again - as the restore says.
- *The error log tells a state from a failure by the answer's code* (the family's rule, 0.5.0's `expected`; the
  owner's "remove every workaround 0.5.0 makes unnecessary", every site): the site's own rule of 0.4.1 - every 503
  left out, since each was then a decided "not now" - is gone. Every 5xx is written but an answer whose code says
  a state the site is in (`AdminTools::EXPECTED_ANSWERS`: the library's `update_pending`, `not_set_up`,
  `feature_unavailable`, and this site's of the same kind, a waiting migration's table - `visitors_unavailable`,
  `translations_unavailable`, `people_unavailable`), so a 503 that is a failure is seen: feedback's
  `storage_unavailable` (its limits cannot be counted), the move's `visitor_move_retry`.
- *zlib is a requirement*: the backups are compressed with it, so the setup page checks it
  (`Installer::EXTENSIONS`) beside the other extensions, and the docs name it.
- *Feedback's reply address*: the library keeps only what the form sends; the server-side default that made this
  site hand it the sender's id alone (the entry of 2026-10-11 below) is gone, and the site still hands it the id
  alone. The limits count a signed-in sender by their account, in a day's budget apart from the visitors'.
- What's new: nothing - none of it is something a person using the site notices.

**2026-10-11 — The site's own pages on the anotoki family's kit; Angular Material gone (the owner's round; version 0.4.0, unreleased).**
- *The kit everywhere*: every page, piece and dialog is the family's kit (anotoki-lib 0.4.1: `@anotoki/lib/styles`
  and the `@anotoki/lib/ui/*` pieces, each from its own entry point - never the `@anotoki/lib/ui` barrel, so a
  page brings only what it uses) or the site's own drawn from the kit's tokens; the top bar, the page's frame and
  the theme are the family's shell (`@anotoki/lib/shell`). `@angular/material`, `@angular/cdk`, Roboto and
  Material Symbols left `package.json` (both lock files agree). The first load is 550 kB (158 kB sent), it was 726
  kB (190 kB) - and the 4 MB icon font and Roboto are no longer fetched at all. Considered, and not chosen:
  Material kept under the kit (two looks, two sets of tokens, and its icon font).
- *One library palette, the site's colours by its tokens* (the owner's rule of 2026-10-07): `styles.scss` sets the
  family's `--anotoki-*` tokens, light and dark, to the colours the site wore with Material's violet theme (the
  violet #7d00fa / #d5baff, its greys and surfaces, the theme's red and outline, its cyan as the person's
  initial's second colour) and never re-styles a kit piece. Two are darker than before, to reach WCAG AA where
  the old ones did not: the light muted ink #625f6e (5:1 on every ground; #6b6878 was 4.4:1 on `surface-3`) and
  `border-strong`, every control's edge (3.6:1 at the least). Every text token is 4.5:1 or more on every ground,
  every edge and the focus ring 3:1, in both themes. Success and warning stay the family's. The site's own
  meanings - grades, severities, rarity, elements - keep their colours, the same in both themes.
- *The family's top bar, not the site's copy of it*: the bar the site had drawn after the survey's with Material's
  pieces (`shared/brand`, `shared/theme-toggle`, `shared/language-switcher`, `core/theme.ts`, its own menus) is
  the library's `<anotoki-top-bar>` in `<anotoki-shell>`, told the site's brand, sections, person, menu items and
  its one widget, the sync this tab drives; its menus, keys and fold are the library's, tested there (Tab never
  to `<body>` with them). In the admin panel its brand is the family's short form at every width - "Analyzer"
  with "admin panel" under it, the app's whole name in the link's name. The count of new feedback is the family
  bar's badge: the link reads "Feedback 1" (the site's "Feedback, 1 new" has no place in the library's link yet).
  Where it folds was measured again in the family's bar (`run.mjs --measure`): the member pages' row needs 818 px
  at the most (an admin with a sync running), so `PHONE_BAR_MAX` stays 880 (a scrollbar, and a language added
  later); the admin panel's 1236 px, so `ADMIN_PHONE_BAR_MAX` is 1270, was 1320 (a 1280 px screen has the whole
  row now); an editor's 610 px, so `EDITOR_PHONE_BAR_MAX` is 640, was 720.
  Migration 010 moves the twelve words the site had for its bar under the library's keys with every rewording -
  the site's Slovak "Správca" and its "System" stay what the bar says - and retires the two that only Material's
  stepper said.
- *What the kit has no piece for stays the site's, from its tokens*: the import wizard's row of steps (buttons back
  to a step done, `aria-current="step"`), the disclosures (`<details>`: the import history, a build's notes, a
  sync's log), a tile that is a link (an account, a character, a build), the score bars and grade badges, a
  sync's progress (a native `<progress>`); the account page's element filter is the kit's select (its segmented
  control has no colour marks). The question before a delete is the kit's `ConfirmService` (no `window.confirm`),
  a passing message its toast, What's new and feedback its dialog. These are worth asking the library for.

**2026-10-11 — Visitors' addresses through Cloudflare, the move after an automatic sign-in, and the security review's fixes (the owner's decisions; version 0.4.0, unreleased).**
- *The real visitor address, through Cloudflare - and never kept* (the owner's decision): the site is behind
  Cloudflare, so `client_address.trusted_proxies` in `config/app.php` are Cloudflare's published ranges
  (cloudflare.com/ips-v4 and /ips-v6, fetched 2026-10-11) and `CF-Connecting-IP` is believed only on a
  connection from one of them; anywhere else the connection's own address counts. The per-address limits stay as
  they were. The address itself is never kept: the counters in the `cache` table are keyed by an HMAC of
  `ClientAddress::counted()` under the installation's key (the library's `Keys`, `address-counters`), so neither
  the table nor a backup holds an address (the security review found them there by address). An installation not
  behind Cloudflare overrides the list in `config/client_address.local.php`. Considered, and not chosen: reading
  no address at all, with one limit for all visitors together.
- *The move after an automatic sign-in* (the owner's decision): after the IAM's sign-in hint's silent hop, a
  browser's visitor data moves into the account only when the account has nothing here yet - no game account, no
  import run: its first time, after playing as a visitor. An account with data here never takes in a browser's
  visitor data without its person's click: nothing moves (`held`), the visitor and its key stay as they are on
  this browser, and the next sign-in the person clicks moves them. A sign-in the person clicks moves, as before.
  Decided on the server (`POST /api/visitor/move` `{silent}`; `Users::moveVisitor()` asks under both rows'
  locks); the page knows how the present sign-in began from the auth module's client - its callback's `silent` -
  noted at that page load and kept with the session (`core/sign-in-kind.ts`).
- *The security review of 2026-10-11*: a cleared reply address keeps none (the entry below); the People page's
  Delete also blanks the person's feedback address and browser and the syncs' logs' "Started by <name>" (the
  scrub's own hooks, in its transaction); a restore runs the scrub of the IAM's deleted accounts at once and says
  when it could not finish (the survey's way); an import by nobody is JSON or a 415 (a form any page could send
  would make a visitor and run an import from the reader's address); 008 drops Laravel's six tables in one
  statement, so one of them pointing at another is no obstacle while a site table pointing at one still stops it.
  Two things a restore does wait for the library's 0.4.2, and the docs say so: it brings back what was deleted on
  the People page since the backup (it does not replay those deletes), and it moves the restored tables' sequences
  back (an id given again, which the kept audit log may name).

**2026-10-11 — Signing in, people and the admin tools through anotoki-lib 0.4.1 (the owner's round; version 0.4.0, unreleased).**
Every anotoki site signs in, keeps its people and runs its admin tools one way now - the library's modules
(`Anotoki\Lib\Auth`, `People`, `ErrorLog`, `Audit`, `Feedback`, `Backups`; `@anotoki/lib/auth`, `/people`,
`/admin`, `/feedback`). 0.4.1, never 0.4.0 (whose Feedback page search fails on PHP 8.2).
- *What moved to the library*: the IAM's SDKs (`src/Support/Iam/*`, `core/iam/*` - gone), the token check
  (`IamGuard` - gone: the library's `Identify`, `AccessTokens`, `RequirePerson`, `RequireRole`, `RequireCaller`),
  the IAM's sign-in switch (`SignInPolicy`, `IamSignInPolicy` - gone: `SignInRule`, the same file in
  `storage/cache/iam/`), the person's row (`UserProvisioner` - gone: `PeopleRecorder` through `RecordPerson`), the
  browser's client, interceptor, guards and the saves a sign-in waits for (`core/auth.interceptor.ts`,
  `core/saves-in-flight.ts` - gone), the sign-in page and the callback page (`pages/sign-in/*` - gone: the
  library's `<anotoki-sign-in-failure>` on `/sign-in` in the site's frame, `<anotoki-auth-callback>` on
  `/auth/callback`), the People page (`pages/admin/people/*`, `routes/people.php`'s own code - gone:
  `PeopleRoutes::admin()` and `@anotoki/lib/people/page`), the tests' stand-in IAM (`tests/tools/fake-iam.php`,
  `tests/support/FixedSignInPolicy.php` - gone: the library's `TestIam` and `stand-in-iam.php`).
- *What stayed this site's*: the visitors (`App\Auth\Visitors`, the key store `core/visitor-key.ts`, handed to the
  library as its `visitors`), the route groups' checks in this site's words (`api/middleware/auth.php`: the same
  six functions, now the library's pieces in order - `App\Web\Pipeline`), `throttle()`, `AuthService` as a facade
  over `AnotokiAuth` under the names the pages always used, `sitePagesGuard` (the family's
  `personOrVisitorGuard`, and a visitor's failed sign-in said once on `/sign-in`), the People page's columns
  (game accounts, imports, last import), its delete and its scrub hooks (`App\People\SitePeople`).
- *A bad token answers `invalid_token`* (the library's `Identify`), no longer `sign_in_required`; the pages word
  every 401 alike.
- *Automatic sign-in by the IAM's hint* (the owner's decision for the sites that let visitors in): a visitor
  signed in at the IAM is signed in here before the first page is drawn, with one silent hop (the library's
  `AnotokiAuth.init()`; loop protection in localStorage); signed out there is signed out here at the next page
  load. `robots.txt` says `Disallow: /auth/`; the callback and the failure screen say `noindex`.
- *People*: the library reads this site's own `users` through a mapping (`name`, `created_at`, `last_seen_at` -
  a person's too now, at most every five minutes - `updated_at`, `nameFallback: "user {sub}"`); 007 adds
  `anonymized_at`. The scrub (after an answer, at most hourly) makes an account the IAM deleted nobody's here:
  `deleted-{id}`, a sync's log says "a deleted person", the feedback and audit copies are blanked; the game
  accounts and imports stay (the owner's rule). The People page's Delete asks only its question - no proof of
  a recent sign-in - and every delete is in the audit log (`person.data_deleted`).
- *What the Laravel version left goes* (the owner's decisions of 2026-10-11 - "the email would be bad": no
  anotoki site holds an email address, nor the address an old session came from, nor an old job's payload,
  which can hold personal data): 008 drops, where 001 kept them, Laravel's login tables - `laravel_users` (old
  addresses and password hashes), `laravel_password_reset_tokens` (addresses, reset tokens), `laravel_sessions`
  (network addresses, browsers) - and its queue's, which nothing here reads - `laravel_jobs`,
  `laravel_job_batches`, `laravel_failed_jobs` - with their sequences. Only `laravel_migrations` stays, the record
  of what that version ran (nobody's data). The backup made before that Apply still holds them: the owner deletes
  that backup once the update is known to be good.
- *The admin tools are the ADMIN's alone* (the owner's decision): the error log (`storage/logs/errors-*.jsonl`;
  every 5xx but 503 - every 503 here is a decided "not now", the gate's or a feature's -, 404, 405, 413, 415,
  429; never a body, a query, a header or a token; who asked from the token, also for an exception
  `jsonErrors()` answers - `App\Auth\RequestCaller`), the audit log (no address kept), feedback (the visitors'
  form at the foot of every page; no mail - the admin panel counts what is new beside Feedback), backups (COPY,
  daily after an answer, before every update and every restore, by hand; making, downloading, deleting and
  restoring ask for a sign-in of the last ten minutes, "Sign in again"). No file explorer here.
- *Backups have no maximum age* (the owner's decision of 2026-10-11, for every site): a backup is kept until it is
  deleted - a manual one only by the owner, on the Backups page (the page flags one older than 90 days, and
  deletes nothing); the automatic ones by the library's rotation by count (the newest 14 daily, 5 made before an
  update, 5 before a restore), never by age. A person the IAM deleted stays in the backups made before the scrub
  until those backups go.
- *Feedback's reply address is the sender's choice* (the owner's decision of 2026-10-11, for every site): for
  somebody signed in the page writes their account's address into the form's address field, and what they leave
  there is the only address kept with the message - that one, another they typed, or none once they cleared the
  field; "send it without my name" keeps neither their id nor an address. The server never fills an empty field
  from the token: it hands the library the sender's id alone (the security review of 2026-10-11 - the library
  fills an empty address from what it is given). Nothing of where a message came from is kept (no network address,
  no hash); the limits count apart, a day, in `storage/cache`.
- *Errors the pages meet* go to the error log with the library's 0.4.2 (its Angular reporter): this site does not
  mount `ErrorLogRoutes::client()` before then.
- *Migrations*: the library's `anotoki_admin` set first (the audit log's and feedback's tables), this site's
  007-009, then `anotoki_translations` (its 003-004: the sign-in screen's and the form's words) and
  `anotoki_people`. 009 moves the five sign-in words this site had under the library's `anotoki.signIn.*` keys
  with every rewording, writer and time (the site keeps saying what it said), retires three, and adds the
  feedback button's and the form's four places.
- *The admin panel's bar*: the ADMIN has nine sections now, so the ADMIN's row folds at `ADMIN_PHONE_BAR_MAX`
  and an editor's two at `EDITOR_PHONE_BAR_MAX` (`layouts/top-bar`), each measured.
- What's new (0.4.0, unreleased): the automatic sign-in, the feedback form, and the sign-in failure's screen.

**2026-10-11 — The site's words through anotoki-lib 0.3.0's translations module; Translations and Languages are the family's pages (the owner's decision; version 0.4.0).**
Every anotoki site keeps its words one way now, and this site's service is the one the library's grew from.
- *What moved to the library*: the strings' routes (`TranslationsRoutes::bundle`, `strings`, `languages`), every
  check of a string, the command line (`TranslationsCommand`: the same `translations.php` and flags), the
  browser's service, pipes and the language switcher's state (`forShell()`), the kit's words (`forKit()`), the
  admin Translations and Languages pages (`@anotoki/lib/translations/admin`, a lazy chunk), their drafts and
  the unsaved-changes guard. Gone: the repository, presenter, check and editor of the strings, the site's
  grid, cell and owner-error components, its guard and its test helpers.
- *What stayed this site's*: its keys and their English (compiled into the app, `en/*.json` - the library
  service's fallback) and Slovak, the released languages (en, sk), the check that no string or language name
  names another site (`SiteTranslations::namesAnotherSite()`, the library's `extraCheck`), the storage names a
  visitor's choice lives under, the rights (EDITOR the strings, ADMIN the languages: `requireRole` on the
  library's route groups), its error body `{message, code}`, and the typed view of the service
  (`core/i18n/translation.ts`: the same injection token, an unknown key still a compile error).
- *One wire format*: snake_case, as this site spoke already (the IAM's). What the library answers differently:
  415 for a body that is not JSON, 413 over 1 MiB, 422 `empty_body`, 400 `invalid_json`, sentences without the
  full stop, a DELETE that reads no body, the admin routes working once the tables are there (the bundle 503
  until there is English), and a first visit's lost bundle asked again after 500 ms.
- *Every rewording survives*: 006 moves `language.button` and `language.notLoaded` to the library's
  `anotoki.language.*` with every string, its time and its writer, before the library's 002 runs - which writes
  a library key's strings only while it has none. The gates' running totals count the site's own keys only
  (`name NOT LIKE 'anotoki.%'`): the library adds its own when it likes.
- *The admin panel's requests stay the person's*: the library's pages issue theirs without `ISSUED_AS`, so the
  interceptor takes `/api/admin/*` as the person's (`isAdminRequest()`) - never the visitor key.
- *The first load*: the sign-out question about drafts loads the admin entry only when the tab holds drafts, and
  every kit piece comes from its own entry point (`@anotoki/lib/ui/config` …), never the barrel. 702.49 kB to
  696.18 kB (an estimated 177.97 to 180.47 kB sent); the two pages are a chunk of their own (about 96 kB).
- *Two sets on the Migrations page*: the library's `anotoki_translations` runs after `build_analyzer`, so the
  page names each file with its set. The kit's alert heading did not break "Stopped at
  build_analyzer/004_….sql" on a phone; the page's wrapper breaks a word where it would overflow until the kit
  does.
- No What's new line: the pages people use read as they did (the language button's name and words included).

**2026-10-08 — anotoki-lib 0.2.0 in the frontend: only what it requires; the site's own pages move onto the kit later.**
The gate's pages are a marked template (`<ng-template anotokiSitePages>`, `SitePagesDirective` in `app.ts`):
0.2.0 takes no other template for the pages, and an unmarked one draws nothing. The Migrations page comes from
its own entry point (`@anotoki/lib/migrations/page`, imported by `pages/admin/migrations`), so it rides in that
lazy route's chunk. The specs and the browser check read the kit's markup (`[data-confirm] h2`,
`anotoki-dialog[data-sql]`, the result's `.heading` and `.result-text`). The waiting page now reloads without
first telling the gate the site is ready (`SiteStatus.checkForReload()`); nothing here worked around the old
reopening, so nothing went - What's new still waits while `SiteStatus.blocked()`, as its dialog is the shell's and
would stand over the gate's page. Both pages are drawn with the library's kit, which reads more `--anotoki-*`
names than 0.1 did, and a name left out takes the family's blue palette: `styles.scss` maps the kit's surfaces
(`surface-2`, `surface-3`, `surface-raised`: the dialogs and their footers, a hovered button, a quiet badge),
`border-strong` (Material's outline, 3:1), `link` and `focus` (the primary) and the hovers (`primary-hover`,
`danger-hover`, drawn toward the text colour) to this site's; the soft grounds of its notes and badges, and the
inks on them, keep the family's (4.5:1). The first load grew by 10 kB (692.40 kB to 702.49 kB, against a 1 MB
budget): the page left it, but `@anotoki/lib/ui` came in whole with the status page's button, since a module
that the first load and a lazy chunk share goes into the first load. `vendor/anotoki/lib` stays 0.1.0 (`^0.1`):
0.2.0 did not change the PHP half. No What's new line: nobody using the site sees anything new.

**2026-10-07 — Versions have three numbers, and What's new is for the people using the site (the owner's rule; version 0.4.0).**
The owner: a version is MAJOR.MINOR.PATCH - big changes, new features, bug fixes - and What's new holds only
what means something to the people using the site, nothing technical or for developers.
- *Renamed, not renumbered*: the releases are 0.1.0, 0.2.0 and 0.3.0 (`frontend/public/changelog.json`), and the
  next is 0.4.0 - signed in, the theme, the language and the dates follow the anotoki account and the bar saves
  to it; while an update waits the site says it is being updated, in the reader's language, instead of an
  error, and comes back by itself. The visitor counter, the shared library and the development servers of the
  same days are not in it. `backend/src/Version.php` (what `/api/meta` says, and what an accounts file carries)
  and `frontend/package.json` (0.0.0 until now) say the same; `tests/auth.test.php` holds the three together.
- *The version a browser saw* (`build-analyzer-version-seen`, kept as "0.3" before the rename) is compared by
  its numbers (`compareVersions()`, `core/changelog.ts`: part by part, a missing part 0), so "0.3" is 0.3.0 and
  nobody is shown an old note again. What's new opens with the releases after the one seen, and with nothing
  when this one or a later one was seen (a release taken back); a seen value that is not a version counts as a
  first visit - the newest release alone, as before. Rejected: comparing the strings ("0.3" is not "0.3.0", and
  "0.10.0" would come before "0.9.0").

**2026-10-07 — anotoki-lib 0.1.1: the gate makes the pages only while the site is open; this site's own guard for that went.**
0.1.0's `<anotoki-site-gate>` showed its content - the router outlet - or its own page, but content handed to a
component is made by Angular whether it shows or not: closed, the pages behind the gate's page were built, sent
their requests (each a 503) and left what those said in the services they share - a sync's "stopped" for the
Sources page after an Apply in this tab, a snack bar over the gate's page. This site kept them out itself
(`pagesMade` in `app.ts`: the outlet made only while the site was ready, or for the ADMIN on the Migrations
route - the gate's condition, a little wider, so that the gate never opened on nothing). 0.1.1 takes the pages
as a template and makes them only while it shows them
(`<anotoki-site-gate><ng-template><router-outlet /></ng-template></anotoki-site-gate>`), so `pagesMade` went and
the gate's condition is the only one - narrower than the old one: the ADMIN on the Migrations route of a site
that is not set up or out of reach no longer has that page made behind the gate's. Kept, because the template
does not reach them: What's new waits while `SiteStatus.blocked()` (`app.ts` - its dialog is the shell's,
outside the gate, and would stand over the gate's page); `/api/site-status` goes without a token or a key
(`core/auth.interceptor.ts`); the boot asks for the status beside `/api/meta` and never stops on an update. The
ADMIN's waiting page now asks again by itself too, as the visitors' does. Angular only: `vendor/anotoki/lib`
stays at 0.1.0 (`^0.1`). `app.spec.ts` holds that no page is made behind the gate's page, and the browser check
that a waiting page asks the API for nothing but the open paths.

**2026-10-07 — One behaviour while the database is not ready: the anotoki family's library, anotoki-lib 0.1.0 (the owner's decision, for the whole family).**
The owner: "When there is a new migration, some sites behave differently than others. Japanese won't let me go
to the admin or anywhere if I am not logged in, but I cannot log in because a migration is waiting. Genshin
gives me no warning. Piano and survey show me a setup error with buttons to the setup page or the migrations
page. This needs to be consistent. I do not want logged-in users or visitors to see the error about what is
going on. The admin should see it. If the setup was already done it should never offer that button again,
especially as setup.php is no longer there." The migrations went into one library, `irelevant25/anotoki-lib`
(Composer `anotoki/lib`, npm `@anotoki/lib`, one version, installed from GitHub), and every site takes its
engine and its behaviour. Here:
- *A waiting migration closes the site* - which reverses the 2026-10-04 choice that nothing here waits for one:
  the site gate (`api/middleware/site_gate.php`) answers every path 503 `update_pending` (`Retry-After: 60`) but
  `/api/meta` (where people sign in), a language's strings and the Migrations page's API (`SITE_OPEN_PATHS` -
  `siteOpenPaths()` since 2026-10-11 -,
  each a route exactly - never a prefix, which a crafted `…/migrations/../x` would get past; nothing else is
  opened, so no route runs on tables a waiting file has yet to make). Everybody reads "The site
  is being updated" in their language - no error, no code, only a quiet Sign in while nobody is signed in - and
  the page comes back by itself; the site's ADMIN reads "A database update is waiting" and reaches the
  Migrations page, the one page that works then. `translations_unavailable` and `visitors_unavailable` stay
  behind the gate, a second line.
- *Signing in works while an update waits* (the Japanese site's deadlock): the boot does not stop - `/api/meta`
  and the strings stay open, the sign-in and its callback complete with the IAM, the router navigates - and the
  Migrations routes judge ADMIN from the token alone (`requireTokenRole()`: no row of `users` read or made, so
  even a database with nothing in it lets its ADMIN apply everything from the browser). Same 401 / 403 bodies.
  `/api/site-status` reads no credentials and answers anybody in every state - also while the IAM requires
  sign-in, which otherwise leaves nobody only meta, the strings and the icons: a 401 there would read on the
  pages as "not available".
- *Not set up*: the setup page is offered only while the site was never installed (`storage/installed.lock`,
  which setup.php writes on a host); once installed, "The site is not available right now" - never the button.
- *The engine is the library's*: `src/Db/Migrator.php` went; `src/Db/Migrations.php` keeps this site's own - one
  set (`build_analyzer`), the Laravel table moved aside in `prepare`, the `build_analyzer.*` settings in
  `beforeFile`, the advisory-lock number the old engine took (an older copy and this one never apply at once).
  The bookkeeping table stays exactly as it was (a test holds a new database's to the old shape). The `migrate`
  row in `cache_locks` went: one apply at a time is the library's advisory lock, 409 `busy` (was
  `migrations_busy`), whoever applies. The routes' shapes are the library's (`{sets, applied, pending, missing}`;
  `{applied, failed, error, note}` with `{set, name}` files; a file's SQL), as are the 503 bodies.
- *Frontend*: the site's "Not set up yet" page and its Migrations page went - the library's gate around the router
  outlet, its status page and its Migrations page took their place, in this site's colours (the `--anotoki-*`
  properties from the `--app-*` and Material tokens, light and dark) and with the account's dates. The Sources
  page's "updates waiting" note went too: nothing behind the gate could show it. The old page's six words
  (`notSetUp.*`) are deleted by migration 005, as the catalogue test asks of a key nothing uses.
- *Rejected*: keeping this site open while an update waits (the 2026-10-04 choice: one behaviour for the family,
  and a route on a half-migrated database is a risk); the old page's words for the gate's page (the family's are
  the same everywhere, in English and Slovak, and the library takes overrides should a language ever need its own).

**2026-10-06 — The theme, the dates and the language are the account's while somebody is signed in (the owner's decision, for the whole family).**
The owner: "it should be in the iam only and apply for every project/application"; asked, a site's own
controls save to the account for a signed-in person, and one theme everywhere - every site, every area of it,
the admin panel included (the IAM's migration 015 and its `decisions.md`). Here, with the IAM's browser SDK
1.2.0 (`user().preferences`, `savePreferences()`):
- *Only where the IAM keeps them.* `AuthService.preferences()` is null for a visitor, and for anybody signed in
  at an IAM before 015 or without the `profile` scope: then everything is as it was - the device's theme and
  language, the page's own dates - and nothing is saved anywhere else (the IAM would answer 503). The release
  needs no migration here and no order against the IAM's: it works with either.
- *The theme* (`core/theme.ts`, as the IAM's own ThemeService): the account's from the first frame (the sign-in
  settles before the first page is drawn), followed when it changes, saved from the toggle - shown at once,
  one save at a time with the latest choice last (two in flight could land in either order), never flicking
  back while its own save is on its way, and put back when the account refuses it. What is on the page is
  still written to `build-analyzer-theme`, so the next load starts in it; a visitor's device keeps whatever
  was last shown there.
- *Telling the person about a refusal* uses the words of a failed request (`preferencesProblem()`:
  `error.offline`, `error.sign_in_required`, `error.unknown`), as the IAM's pages do: no new string, so no
  migration and no keys on the page between an upload and Apply. A sentence of its own ("the theme could not
  be saved to your anotoki account") would be clearer, and is a migration away if wanted.
- *The language*: while the account keeps the preferences, its `locale` comes before the menu's remembered
  choice - otherwise a device's old choice would outvote one made since on another anotoki site; only this
  tab's `?lang=` and a choice of this visit come first. The menu's choice is saved to the account, one at a
  time, the latest last; taken, the account decides again. A language the IAM does not offer
  (`invalid_request`) stays this site's own for the visit, silently; any other refusal keeps the page in the
  chosen language for the visit too - it has its words here, and losing them because the IAM is out of reach
  would be worse - and a snack bar says why, as the IAM's own bar does. The device still remembers the menu's
  choice, for when nobody is signed in. Before, the device's choice always came first.
- *Dates*: where a date or a time is written for a person - What's new's days (`localDate: 'day'`), when a
  migration was applied and when a builds file was exported (the admin panel) - the account's order and clock,
  where it has chosen them, the page's own way where not (`core/i18n/date-patterns.ts`, the IAM's formats).
  Relative times have neither; the sync runs' log lines are stored text in UTC, shared by every editor, and stay.
- *The stand-in IAM* gives a person `preferences` when told to and answers `POST /api/oidc/preferences` as the
  IAM does, so the browser check saves across origins with the real SDK. The survey's copy of the file does not
  have this yet: the two should be brought alike again when the survey takes SDK 1.2.0.

**2026-10-06 — The family's visitor counter, without the query string (the owner's request, for the whole family).**
`frontend/src/index.html` loads Umami's script from `umami.anotoki.eu` - the family's own, so "nothing that is
not ours" still holds: nothing names it to a person, and `backend/tools/build.php`'s name check has nothing to find. It
sets no cookie and stores nothing in the browser. It is told to leave the query string and the fragment out
(`data-exclude-search`, `data-exclude-hash`): the sign-in comes back to `/auth/callback?code=...&state=...`, and
the script may read the address before the app has run - a counter that kept it would hold every sign-in's code.
It is `async`, not the snippet's `defer`: a deferred script and the app's module run in order, so the app would
wait for the counter - for as long as a slow or unreachable `umami.anotoki.eu` takes to give up. The paths still
go, which is what the counter is for. The site sends no Content-Security-Policy for its pages, so nothing else
changes (one added later must allow `https://umami.anotoki.eu` in `script-src` and `connect-src`); the browser
check's headless browser is not counted (Umami answers it and keeps nothing). The price: the script runs in the
app's pages, beside the sign-in's tokens in localStorage, so the site trusts whoever controls what
`umami.anotoki.eu` serves that far.

**2026-10-05 — Whether sign-in is required is the IAM's switch, read by the server (the owner's request, for the whole family).**
- *The IAM decides, per application*: "Sign-in required" on its admin panel, and which roles may sign in. This
  site keeps no setting of its own; it reads `GET {issuer}/api/public/applications/build-analyzer` through the
  IAM's PHP SDK 1.1.0 (`anotoki_iam_login_required()`, vendored unchanged) - one reader for the five sites, so
  the rule cannot drift: a 404 (an unknown or switched-off application) and an IAM never reached both mean
  **required** (fail closed - an open site is the IAM's word, never a guess); an IAM out of reach after a read
  keeps the last answer; kept a minute in `storage/cache/iam/` (a folder only the site writes), one request
  asking at a time. `App\Auth\SignInPolicy` wraps it only so that the tests can put a fixed answer in its place
  (no test calls a real issuer); the reader's own cases run against the stand-in IAM and the SDK's file seam.
- *The server relays it* (`/api/meta`, `auth.login_required`): the page never asks the IAM.
- *Required means nobody and every visitor key get 401 `sign_in_required`* everywhere but meta, the strings and
  the icons; the key is refused before it is looked up, so a refused request neither sees, counts, makes nor
  sweeps a visitor. *The move at sign-in still takes a key*: switching sign-in on stops a key from being
  somebody, not from being handed in with a token - what a visitor imported comes into the account when they
  sign in, and switching it off again within 30 days brings the key back.
- *Only `Authorization: Bearer` counts as credentials*: a `Basic` header (a host behind a password file sends it
  with every request) is no header, so it neither refuses a visitor nor makes anybody somebody.
- *MEMBER is an ordinary person*: every account holds it (IAM 010); no check here needs or refuses it. Taking it
  away blocks signing in, not visiting while sign-in is not required - to keep somebody out, require sign-in.
- *In the browser, whose a request is is decided when it is issued* (`Api` marks it, `ISSUED_AS`), never by
  whether a token is there when it leaves: before, a signed-in person's import whose token could not be
  refreshed went out as a visitor's and could make a visitor of the person's data. A person's request with no
  token is now never sent (a local 401 `sign_in_required`). The key goes, and a new one is kept, only on a
  visitor's request while sign-in is not required; while it is required the key is kept, never sent (the
  move at the next sign-in still takes it). Every page asks for a sign-in first while required
  (`siteSignInGuard`; unknown = required); a sign-in the IAM refused is shown once on the sign-in page even
  while visiting (else a person whose MEMBER was taken would see nothing); `/admin` stays sign-in only; and
  a sign-in waits for the writes on their way (a visitor's first import brings the key in its answer).
**2026-10-04 — "Admin panel", and a Migrations page of its own (the owner's request, for the whole family).**
- *Named "Admin panel" everywhere*, as on the IAM, Piano and Japanese: the person's menu ("Admin panel" /
  "Administrátorský panel", `topbar.admin`), the brand beside the mark in the panel ("anotoki admin panel", as
  the IAM's: the new key `brand.admin`; on a phone still "Analyzer"), its link's name, which names the app
  ("anotoki Build Analyzer: the admin panel", `topbar.homeAdmin`),
  the tab titles ("Migrations · Admin panel · Build Analyzer", `title-strategy.ts`) and the docs. 004 was not
  applied anywhere yet, so its strings and its gate (67 keys added, 458 in all) changed in place, with the
  compiled English. "Back to Build Analyzer" stays.
- *The IAM's Migrations page, in this site's pieces* (`/admin/migrations`, ADMIN only - server and
  `adminOnlyGuard`; a link in the panel's bar): Pending in the order they will run, Applied with their dates,
  Apply pending after a confirmation; after it "Up to date", "There was nothing to apply" or "Stopped at
  <file>" with what went first and the database's error. The API took the IAM's shape: `GET` {applied: [{name,
  applied_at}], pending: [{name}]}, `POST .../apply` {applied, failed, error} - 200 even when a file failed (the
  page shows where it stopped; the IAM answers 500, whose body its page cannot show), 409 `migrations_busy`
  under the `migrate` lock. One transaction per file, the first failure stops the run, no copy of the tables
  is taken (there was none before either - the page and the docs say to back up first). After the review:
  each file's transaction first takes a PostgreSQL advisory lock and looks again whether the file is recorded,
  so an apply that outlives the 300 s `migrate` lock, or `php migration.php` meanwhile, waits and then runs
  nothing twice (before, `ON CONFLICT DO NOTHING` recorded a data migration applied twice as once). The
  `migrate` lock stays: it answers "busy" at once instead of a wait. (Since 2026-10-07 the page, the engine and
  the lock are anotoki-lib's - see there.)
- *Apply left the Sources page*: it only says that updates wait, with a link to Migrations, for an admin.
  (Since 2026-10-07 a waiting migration closes the site and the note is gone - see there.)
  Nothing on this site waits for a pending migration (no "being updated" page): members keep using it, the
  routes that need the new tables answer 503 `translations_unavailable` / `visitors_unavailable`, and those
  sentences (and the Languages / Translations pages, which link it for an admin) now name the Migrations page.
- *The bar has a breakpoint per area*: the panel's row (five sections) needs 954 px with a sync running, so
  `ADMIN_PHONE_BAR_MAX` = 960 is its own and the member pages keep 880; the scss follows the bar's classes
  (`is-phone`, `is-nameless`) instead of a media query, because one query cannot know the area. Rejected:
  "anotoki Build Analyzer - admin panel" beside the mark (the first try: 1075 px with a sync, so a 1024 px
  laptop got the phone's bar and a 1280 px screen no name - the review); one breakpoint for every page
  (members on a 900 px tablet would lose their row).
- *The browser check takes `SITE_PORT` / `IAM_PORT`* (8590-8599 only): checks of several sites run at once.

**2026-10-04 — After the review of 0.3: what a request found may be gone by the time it writes.**
- *004's gate asks Slovak only of its own keys*: the Translations grid deletes a string whose cell is emptied,
  so on a database with real use an older key may have no Slovak on purpose; a gate demanding it of every key
  would make Apply fail until somebody retyped it. English is asked of every key (it cannot be cleared). The
  header now says what the file really does: additive schema, new strings that never write over reworded
  ones, retired keys deleted with their strings (reworded ones too), 13 descriptions rewritten.
- *Every writer answers the lock's "no such row"*: an owner's row can go between the request finding it and
  the import writing (a visitor moved at sign-in in another tab, or swept; a person's data deleted on the
  People page). `Users::lockForWrite()`'s false is now 401 (`visitor_unknown`, or for a person
  `sign_in_required` - their next request, the page's retry with a fresh token, starts them afresh), with
  nothing written and no failed run recorded, instead of a foreign-key 500. Rejected: 404 for a person (the
  page would word it as a missing page), or re-making the person's row inside the import (it would undo the
  admin's deletion behind their back). The target account and a visitor's account count are read again under
  the lock.
- *A forwarding list is read from the right*: `client_address.client_ip_header` is meant to be one address
  the proxy writes whole (`CF-Connecting-IP`); should it ever be set to `X-Forwarded-For`, its leftmost entry
  is whatever the visitor wrote, so the first address from the right that is not a trusted proxy is counted.
- *The size of a body is bounded by PHP*, not by `me.file_max_mb` / `visitors.file_max_kb`: those are checked
  after PHP and Slim's body parser have the body. The docs say so now; a Content-Length check before the parser
  was not added (`post_max_size` already bounds it, and the host sets that).

**2026-10-04 — The family's top bar; 004 closed; version 0.3; a browser check on a throwaway database.**
The anotoki sites share one bar (the owner's decision of the family's design; the survey's bar is the
reference, and this site keeps Angular Material underneath).

- *The menus are this app's own markup, not `mat-menu`*: the family's rule is that Tab or Shift+Tab from a
  menu item closes the menu with the focus on its button (`preventDefault()`), so the next Tab moves on from
  there and the focus never falls to `<body>`. A spec test of exactly that, with `mat-menu`, came back
  `defaultPrevented: false` - it closes and focuses its trigger, then leaves the Tab to the browser, which
  moves the focus on past the trigger. So the survey's markup (one `openMenu` signal, focus in after the next
  render, arrows / Home / End, Escape and an outside click), with Material's icons and button inside. The
  language's menu keeps the same Tab rule (the survey's language menu let the focus go on; here it does not).
- *The breakpoint is this site's own*: 880 px, measured with the real labels (`run.mjs --measure`): the row
  without the person's name needs 772 px in English and 781 in Slovak signed out, 799 for an admin with a sync
  running, and 872 in the admin area (four English sections) with a sync running. Rejected: the survey's 760,
  under which the admin area's row overlaps. The name shows from 1081 px (+200, as the survey's 760 / 960) and
  is what gives way. On a phone the brand is the mark and `brand.short` ("Analyzer": "Build Analyzer" does not
  fit beside the language, the theme and the menu button at 360 px), and the sync spinner - an app widget left
  of the language on a desktop - is an item of the menu and a dot on its button.
- *Where things went*: the toolbar's links are the bar's sections (Dashboard with `/accounts/` under it,
  Import, Builds - the same for nobody, a visitor and a person); "Admin" moved into the person's menu (and
  "Back to Build Analyzer" in the admin area, whose sections are the bar's links there, English, staff only);
  the theme and the language left the "Settings" menu for controls of their own; the version and What's new
  moved into a footer on every page. Sign out asks first about strings typed on the Translations and Languages
  pages and not saved (in the reader's language), instead of the browser's own "Leave site?".
- *004 is closed*: the bar's 21 keys (English and informal Slovak) joined it, the 8 keys the toolbar used
  retired, and the descriptions that told of the toolbar reworded; the gate checks all of it (457 keys). Its
  header now says how it is applied on a host: upload, Apply at once (ADMIN), then IAM 007.
- *Version 0.3* (the owner: the builder adds the entry and the version): one changelog entry in plain words -
  the Slovak pages (which shipped without one), using the app without an account, a member's own data as a
  file, the one bar and the footer. Nothing in it says anyone can sign in: that is true only once IAM 007 is
  live, after the upload.
- *The browser check runs on a throwaway database*: `BUILD_ANALYZER_TEST_CONFIG` names a settings folder that
  stands in for `config/` and `storage/`, honoured only for a `build_analyzer_test_` database (anything else
  is "not set up", never the owner's database) - the one environment variable the site reads, and only the
  check sets it. Rejected: a copy of `backend/` with its own `config/` (vendor and all, every run), and pointing
  the check at the owner's database (the owner's data, and their migration 004 not applied). playwright-core
  stays in the temp folder (the earlier screenshot scripts already used it), never in the repository.

**2026-10-04 — The frontend in step: IAM only, three kinds of reader, the admin area under `/admin/`.** The
frontend follows the backend of the three entries below.

- *No sign-in mode*: `AuthService` has `available` (the server said where its IAM is), `signedIn`, `isStaff`,
  `isAdmin`; MEMBER, `isMember`, `usesSignIn` and `LOCAL_OWNER` are gone. Every Sign in button goes through
  `signInOrSayWhy()`: the IAM's pages in the reader's language, and a snack bar when the browser refuses the
  storage the SDK needs (`storage_unavailable`) - the survey's way, so a button never silently does nothing.
- *Who looks*: nobody, a visitor, somebody signed in - one computed state on the dashboard, and the notice on the
  visitor's pages. Nobody's pages ask for no own data (the server would only answer 401), and an account page
  opened by nobody shows the ways in, not an error.
- *The key in the browser*: localStorage (every tab shares it), read again at send time and followed through
  the `storage` event; the first key stays - a second tab's first import never replaces it (rejected: last key
  wins, which strands the first visitor's data). Sent only without a token; the move is the one request with
  both.
- *The move outside AuthService*: an effect in `VisitorKey` on `auth.signedIn()` (rejected: calling it from
  `AuthService.init()`, a DI cycle, NG0200 - VisitorKey reads AuthService). A 200, found or not, ends the key;
  a network error or a 5xx keeps it and asks again (Retry-After, else 2 / 10 / 30 s, then the next page load).
- *The admin area* is `/admin/...` behind `signInGuard` (the only guarded route), English, with a layout that
  tells staff, the signed-out and the role-less apart; editors see Sources and Translations, admins also
  Languages and People. The old addresses redirect. Its sections live in that layout until the shared top bar
  takes them.
- *Words*: the new strings are in migration 004's strings part, the form of 003, with the keys this retires
  (`error.local_only`, `error.no_access`, `signIn.noAccess`, `signIn.anotherAccount`, `dashboard.backendHint`,
  `nav.translations`, `nav.languages`) deleted there; the Slovak avoids gendered past forms ("Používaš stránku bez
  prihlásenia", not "Nie si prihlásený"), and a number that would need agreement stands after a colon.

**2026-10-04 — A person's own accounts as a file: the newer copy wins, and an import never deletes (the
owner's words: "builder-analyzer project can have import/export for user's data as well as japanese or piano
project has", and "logically the import/export available only for signin users").** `GET /api/me/export`,
`POST /api/me/import` (`src/Services/Transfer/`), for a signed-in person only - `requireSignIn()`, so a visitor
is refused like nobody, as the visitor decision above already said.

- *Never delete*: the file is a backup and a way between copies, not a mirror. An account the file lacks may be
  one made here after the export, or on purpose kept out of it; deleting it would lose data nobody asked to
  lose, and the account page deletes an account when somebody wants that. So an import only adds or replaces.
- *The newer copy wins, as a whole*: an account here and in the file is the same game account read at two
  moments. `last_import_at` says which reading is newer; that copy's contents - characters, weapons, items -
  stand, the other's do not mix in. Merging entry by entry would invent a state the game never had (gear on
  two characters, an artifact the player has since salvaged). A tie keeps this copy, so the same file twice
  changes nothing. What is not the game's state is kept from both: the file's import runs that are not here
  are added, and meta keys the file does not carry (it carries only the neutral ones) stay.
- *Matching without a UID by when the account was made*: a file import has no UID, and nothing else names the
  account across copies; `created_at` travels in the file and comes back on an added account. Written and
  compared in UTC to the second (the critique's point: two installations, two time zones, different
  precision), so a re-import or a copy elsewhere finds the same account instead of adding a duplicate.
- *Strict and neutral like the builds file*: keys, never ids; `showcase` / `file`, never a tool; refused whole
  with a kind and the first problem's path. The export never writes what the import would refuse (a value of
  another shape stays behind; past `me.file_max_mb` or the caps it says so instead), so every export imports.
- *Taking turns*: the import first takes the person's `users` row `FOR UPDATE`, and so does every game import
  (`AccountImporter`) and the move at sign-in, so two writers of one person's accounts never interleave. A dry
  run reads only and takes nothing.
- *Rejected*: an import that mirrors the file (deletes what it lacks); merging entry by entry; matching an
  account without a UID by its nickname (people rename them; two file accounts can share one); keeping the
  person's id in the file (another copy's ids mean nothing, and a file must never point at anybody).

**2026-10-04 — The visitor account: the one deliberate second identity in the family (the owner's words of
2026-10-02: visitors get "pretty much everything a member see", their data in the database under a visitor
identity "with key or hash ... generated and saved in localstorage", "transfered from visitor to member" on
sign-in; import / export of one's data and other special features need a sign-in).** Everywhere else in the
anotoki family a request is a person of the IAM or nobody. Here a third kind exists on purpose, and only here:

- *What it is*: a row of `users` with a **negative id** from the sequence `visitor_ids` (migration 004). An IAM
  id is always positive (`anotoki_iam_user_id()`), so the two kinds can never meet, and a CHECK holds them
  apart (a negative id has a key's hash and a last-seen time; a positive one neither). `accounts` and
  `import_runs` already point at `users` with cascades, so a visitor's data is a member's data in shape - the
  same routes, the same `Accounts::owned()` isolation, the same analysis - and goes with its row.
- *The key*: 32 random bytes made by the server only, base64url (43 characters). It leaves the server once, in
  the `X-Visitor-Key` **response header** of the import that made the visitor (`Cache-Control: no-store`;
  never in a body, a URL or a log), and comes back in the `X-Visitor-Key` request header. The row keeps only
  its SHA-256 (256 bits: a plain lookup is safe). Why a header and not a cookie: nothing rides along on a
  request another site makes, and the API has no CORS, so no other site can send it.
- *Who a request is*: a token (present means it must verify - a bad or expired one is 401 and never falls back
  to the key), else a visitor key (unknown or malformed: 401 `visitor_unknown`), else nobody. Only
  `requireOwner()` reads the key, so a visitor reaches its own accounts and imports and nothing else: no role,
  no admin area, no syncs, no export / import of one's data, no other row.
- *Made lazily*: only by `POST /api/imports/showcase` or `/file` sent with neither token nor key, and only once
  the import's throttles let it through (`makeVisitor()` is the innermost middleware); when that first import
  fails, the visitor is deleted again and its counts given back. Page views never make one.
- *Bounded*: new visitors an hour per address (10; an IPv6 address counts by its /64) and from everywhere (60),
  visitors at once (1000) - past any, 429 `too_many_visitors`, whose sentence says to sign in; a visitor keeps
  5 game accounts and imports files of 5 MB (422 `visitor_limit`); a visitor's imports count per address, so a
  fresh key starts nothing over, and all visitors' showcase imports share 20 a minute. The address is
  `REMOTE_ADDR` unless a trusted proxy (`client_address` in `config/app.php`, the IAM's pattern) names it;
  `X-Forwarded-For` alone is never believed.
- *Swept*: unseen for 30 days (`last_seen_at`, written at most once an hour) - 20 at a time, the statement
  allowed 2 s, skipping rows another request holds, after the answer has gone out (`App\Web\AfterResponse`),
  inside a try/catch that logs: the clean-up never fails the request it runs in. It runs when a visitor is
  made and at most once an hour on an own-data request; never on a public page. No cron: FTP-only hosting.
- *Moved at sign-in*: `POST /api/visitor/move` with the token **and** the key, in one transaction with both
  rows locked (`FOR UPDATE`, the visitor's first): a game account both have (same game and UID) stays the
  person's as it is and the visitor's copy goes; everything else becomes the person's; the visitor row goes. A
  key nobody has is `{found: false}` (asked again safely); a lock not had in 5 s, a unique violation or a
  serialization failure is 503 `visitor_move_retry` with nothing moved.
- *A pending 004* (upload done, Apply not yet): the visitor paths answer 503 `visitors_unavailable` - like the
  strings' routes before 003 - and the clean-up does nothing; members are untouched.
- *Rejected*: a cookie (rides along cross-site); a client-made key (guessable, collisions); keeping the data in
  the browser only (the owner asked for the database); a separate visitors table (every account query would
  need a second owner column - the negative id reuses the one isolation the code already has).

**2026-10-04 — The IAM is the only way in; EDITOR and ADMIN; the root holds only the project's own (the anotoki
family made one way, the owner's decisions of 2026-10-01 and 10-02: "There is no need from project to support no
iam alternative/version of the projects. The IAM should be the only option."; "Every project should be
available for every user not just admin or editor.").** The backend's part, first of the port's stages:

- *Sign-in mode "none" is gone* (`AuthMode`, `IamGuard::localOwner()` / `fromOwnPages()`, the `local_only`
  refusal, setup's choice and its owner's-id field, `local_user_id` in the CLI and the migration engine). Who a
  request is comes from a verified IAM token only. A `mode` / `local_user_id` left in an older
  `auth.local.php` is ignored - a test proves a leftover "none" opens nothing. The owner's own copy signs in
  through their local IAM like any other.
- *Credentials that are sent must verify*: a request with an `Authorization` header is that person or a 401
  `sign_in_required` - on public routes too - and is never answered as if it had sent nothing. Why: the next
  stage adds a second way in (a browser's visitor key, for requests without a token), and an expired token
  must never quietly fall back to it (the plan's critique).
- *Roles*: ADMIN and EDITOR; MEMBER is gone (`User::withRoles()` keeps `Role::STAFF` only, so a leftover
  MEMBER is just "signed in"). A signed-in person with no role uses their own accounts and imports. EDITOR:
  syncs, the sync history, a source's builds file (export / import - they go with the syncs), the strings
  grid and a language's strings file, the languages' list. ADMIN also: sources on / off, migrations, adding /
  renaming / switching / deleting a language, and the People routes. Each check is on a route *group*
  (`requireRole(...Role::STAFF)` / `requireRole(Role::ADMIN)`), so a route added later cannot forget it; the
  tests go through every route for each role. 403 `editor_only` / `admin_only`; `no_access` is gone.
- *Public*: the builds browser and the sources' list (`optionalSignIn()`; who started a run only for editors),
  as visitors are to see what members see.
- *People* (ADMIN): `GET /api/admin/people`, `DELETE /api/admin/people/{id}` - deleting a person's data
  deletes their row, and with it their accounts and import runs; the syncs they started and the strings they
  saved stay, by nobody; their anotoki account is untouched.
- *Setup asks for the database and the IAM only* (and the token on a host): defaults `anotoki_builder`, port
  5432; "Test connection" in the family's words (the survey's); the saved values are shown on this computer
  only. The fetch settings left the form: `fetch.local.php` is optional (defaults in `Config::FETCH_DEFAULTS`)
  and setup writes it with the defaults only when it is missing. `import-account.php --user` is required.
- *The root*: `AGENTS.md` and `dev.ps1` deleted (every command is a plain one in DEV.md);
  `scripts/build-dist.php` → `backend/tools/build.php`, `scripts/php.sh` → `backend/tools/php.sh` (reads
  `BUILD_ANALYZER_PHP` only). `.vscode/` is gitignored (its one tracked file leaves git with the next commit
  that untracks it, the file itself staying on disk).
- *Tests*: every mode-none test became a token test; `tests/tools/fake-iam.php` (the survey's stand-in IAM) is
  here, and `stand_in_iam.test.php` signs in through it as a browser does. Throwaway servers use 8590-8599.

The 2026-09-28 entries below on sign-in and its hardening describe the "none" mode as it was.

**2026-10-01 — The member pages in English and Slovak, their words in the database (owner's decisions: "translate
only text. The stuff from the game, like attack or ATK and the like, leave as they are everywhere").** The site's
own words are rows (migration 003: `languages`, `translation_keys`, `translations`; 406 keys), like the genshin
site's, because the host has no shell and every upload overwrites the build: the owner rewords a string on the
Translations page and adds or hides a language on the Languages page, in the browser. One migration holds the
tables and every string, so no database ever has the tables without their words. English is the fallback
string by string, and Slovak is informal ("ty"). A key the database lacks shows as the key, on purpose: the
owner wants to see which one is missing. The English compiled into the app (`core/i18n/en.ts` and its parts) is
the list of keys and the last resort only - shown while no bundle is in memory (before 003 is applied, or while
a first visit waits), never to fill one missing string - and a test holds it equal to the seeded English. What
the server tells a member - a recommendation, an import's error or warning, a failed request - is a kind with
params that the page words, so the page follows the reader's language; the English sentence stays beside it for
the command line, the tests and rows stored before kinds (shown in English, with `lang="en"`). The game's words
stay as the game writes them in every language: stat, slot, talent, set, weapon and character names, "Lv.",
"4pc", UID, grades, and the game's notation ("62.4%", "R1", what `MetaStore.formatStat()` writes). They are
never keys; they reach a sentence as a placeholder or stand before a colon, so Slovak never has to decline an
English word. The admin's Sources page and the owner's two pages stay English (their code untouched,
`<html lang="en">`), as do the server's admin-only sentences. A new language starts hidden, so its strings can be
written before readers are offered it. The neutral-names rule covers every string, seeded or reworded
(`TranslationCheck` - since 2026-10-11 `SiteTranslations::namesAnotherSite()`, the anotoki library's last check -,
422 `names_another_site`). Each installation's database holds its own strings; a language
is carried between installations by export and import. The browser check at 390 px also found two phone-layout
defects older than this work and fixed them in CSS: the toolbar's links painted over the buttons beside them
(under 720 px the icon-only links and the user button drop Material's 64 px minimum width and the bar's padding
is 8 px), and the account page's import history cut its last column to a few letters a line (under 600 px a row
wraps and that text takes its own line). Neither showed in the page's scroll width; comparing the boxes did.

**2026-09-29 — A source's builds move between installations as a file (owner's request: "every source should
have buttons for import and export").** The live site could not sync two of its sources: the site behind them
answers the hosting server 403 (bot protection that refuses datacenter addresses), while the owner's computer
syncs them fine. So every source can be exported to a file and imported from one - synced where its site
answers, imported where it does not - and for any source the file is also a backup. The file names no site
(the source is its key, page addresses stay behind, copied text goes through `Neutral`). An import makes the
source's builds exactly the file's instead of merging, so a file always means one state and removed builds do
not linger; it writes through `BuildSync`'s own `write()` and `finish()` (the same content hash, created /
updated / unchanged, `changed_at`, a builds run of the source), so imported builds behave like synced ones, and
the source counts as synced when the file's builds were, which keeps "Sync all" away from it for
`stale_hours`. The file carries no page address, so an imported build keeps the one this installation has for
it (and hashes with it, as a sync does), and a new build has none. The file is read strictly - exactly the
fields and shapes a sync stores, whole numbers where the app counts, the game's set kinds, slots, stat keys and
talents, set pieces as syncs make them (each set once, 2 or 4 pieces), caps on sizes - because the analysis
reads every build for every user; one problem refuses the whole file. Every text is made neutral on the way in
as well as out, so a file edited by hand cannot bring a site's name into the recommendations; and whatever an
export writes, an import reads (neutral text kept within the caps, variants it makes alike told apart with
"#2"). Characters the catalog lacks are skipped, as in account imports (an uploaded file never adds to the
shared catalog), and a file of nothing but such characters is refused rather than emptying the source. The
import runs under the sync step lock and waits for a sync of its source to end, so no step writes over it
halfway. (Since 2026-10-20 a builds file holds a game's builds, not a source's - format version 2, see there.)

**2026-09-29 — The backend without Laravel; the app named "build analyzer" (owner's decision).** The backend
is rewritten in the shape of the other anotoki sites (the genshin site, the IAM): Slim 4 for routing and PSR-7
messages and nothing else from a framework, a front controller (`api/index.php`) with route files and
middleware, a small parameterised PDO layer, plain model and repository classes, and SQL migrations applied by
the same engine as theirs (`migration.php`, the Sources page's "Apply", `setup.php`), with `DO $$` verification
gates in the migrations that change data. PostgreSQL only: SQLite was the Laravel version's local default,
and one database engine everywhere means the migrations are the schema. Configuration is three required PHP
files (`config/{database,auth,fetch}.local.php`) written by `setup.php`, a web installer that every copy runs,
the owner's own included - no `.env`, no environment variables, no committed defaults: an installation nobody
set up answers `503 not_set_up` and the app says so. On this computer `setup.php` needs no token (a nonce
instead), can be run again, and offers sign-in "switched off"; on a host it proves FTP access with a token,
always turns sign-in on, locks itself and deletes itself. A database made by the Laravel version is adopted
in place by `001_adopt_laravel_database.sql` (with every row, its framework tables kept aside as `laravel_*`),
and ends up identical to a new one - tested against that version's three schemas. The command-line tools are
plain scripts (`sync-builds.php`, `sync-catalog.php`, `import-account.php`), the tests a small runner of their
own (`php tests/run.php`: throwaway databases, fixtures, a fake network), ports 8400 (API) and 4600 (app).
Content hashes and name keys are computed exactly as before, so stored builds do not look changed.

At the same time the app got its neutral name, "build analyzer" ("Build Analyzer" as a title), everywhere -
code, configuration, docs, tooling - and the UI stopped naming anything that is not ours: build sources are
"Source 1", "Source 2" … (numbered by their order in `config/app.php`, API key `{game}-{n}`), the showcase
service and the export tools are described but never named or linked, texts that came from elsewhere are
cleaned on the way out (`Support/Neutral`), icons are served from `/api/icons/…` instead of the provider's
addresses, and the fonts are bundled instead of loaded from a font service. `scripts/build-dist.php` refuses a
frontend build that names one of those sites. The version and its changelog (`frontend/public/changelog.json`)
show in the app, as on the genshin site.

**2026-09-28 — Hardening from the review of the sign-in / hosting change.** Auth mode "none" trusted any request
from 127.0.0.1, but every other site open in the owner's browser connects from there too, and Laravel's default
CORS allowed all origins on `api/*`: any page could read and change the owner's data. Now the API sends no CORS
headers (the app is same-origin and the dev server proxies), and in "none" mode the guard also refuses a
cross-site `Sec-Fetch-Site`, an `Origin` or a `Host` that is not this computer (the last also stops DNS
rebinding). Other fixes: a step is never shorter than 12 s (`SyncRunner::MIN_STEP_SECONDS`), because the fetcher
keeps 8 s for a request and a shorter step would loop without ever fetching; imports (web requests outside a
step) wait at most 10 s per request with one retry after at most 2 s; the owners migration refused to roll back,
before changing anything, while two users held the same UID; the installer writes its token and config 0644
where PHP runs as another user than the FTP account, since 0600 would lock the owner out; the migrations button
reports a failing migration instead of a bare 500; tests refuse stray HTTP requests; members no longer see who
started a sync.

**2026-09-27 — Deployable on FTP-only shared hosting (owner's decision).** The host has Apache, PHP 8.4 and
PostgreSQL but no shell, cron, queue worker or long-running process. So: the built Angular app is served at the
site root and the API at `/api` from one origin. `scripts/build-dist.php` assembles exactly what to upload
(`vendor/` included, no configuration, tests or `node_modules`). A web installer replaces a shell for the first
install, protected like the genshin site's `setup.php`: a random token that only FTP can read, then a form; it
builds the database before writing the configuration, writes it with owner-only permissions, then a lock, then
deletes itself, and refuses to go on when a probe file in `storage/` can be downloaded. Later schema changes go
through an ADMIN-only "Apply" button (`POST /api/admin/migrations/apply`). Cache and locks live in the database,
which needs no worker. (The Laravel-specific parts - a `public/` document root, `.env`, `deploy/install.php` -
went with the rewrite of 2026-09-29.)

**2026-09-27 — Syncs run in steps driven by the admin's browser (owner's decision: "the analyzer does not
need a queue worker or scheduler. It will be run manually by admin clicking on some button").** The queue job,
the schedule and `runInBackground` are gone. Starting a sync only creates `sync_runs` rows; each
`POST /api/sync/step` takes a global step lock, picks the run that is due, and works on it for at most the step
budget (20 s, now `step_seconds` in `config/fetch.local.php`) - well inside shared-host request limits - saving
after every character. The fetcher keeps each site's last request time in the cache, so crawl delays (one build
site's 10 s) also hold between requests, and a wait that does not fit into the step becomes a `FetchDeferred`: the
run records `next_step_at` and the browser waits `wait_ms` before the next step, so a step fetches one or two of
that site's pages. Retries after 429/5xx also move to later steps instead of sleeping. Closing the tab pauses a run
(nothing else advances it); Resume continues from the stored state. The catalog refresh became a run too (one
list per unit), so it follows the same rules, and a stale catalog is refreshed before the build runs of its
game. The command-line tools loop over the same steps, so the owner's local copy and the host behave the same;
the Windows daily task was dropped with the scheduler. (Since 2026-10-20 the site syncs no builds and the steps are
gone - see there.)

**2026-09-27 — Every person has their own accounts; imports never add to the shared catalog.** `accounts`
and `import_runs` carry `user_id` (existing rows became the owner's, `local_user_id`), `(user_id, game, uid)`
is unique so two people can import the same UID, an account id in a path only finds the signed-in user's
accounts (others' are a 404, like missing ones) and `account_id` in imports is checked against them. The
catalog, builds, sources and sync runs stay shared. An uploaded file is untrusted input for that shared catalog:
before, an unknown name in a file became a new catalog row for everyone - a junk "Emblem X" set would make
"Emblem" ambiguous for every user and for the build sync, and a file could create thousands of rows. Now
imports only look names up; unknown characters, weapons and sets are skipped with a warning (status
"partial") until an admin refreshes the catalog. This applies to admins' imports too: the risk is the same
whoever uploads. An import may still refresh one catalog list when the data names an in-game ID the catalog
lacks (a character newer than the last sync), because that data comes from the catalog provider rather than
from the file; at most once per game and list per 60 minutes (now `catalog.import_refresh_minutes`) across all
users, and before the import's write transaction. Only the build sync still creates characters (new releases
appear on build sites first; since 2026-10-20 nothing does - the catalog's refresh alone writes the catalog). The
showcase import throttle (10 a minute) is per user.

**2026-09-27 — Sign-in through the anotoki IAM.** All anotoki sites sign people in at iam.anotoki.eu (OpenID
Connect, code flow with PKCE); this app is its client `build-analyzer` (access policy `members`, roles ADMIN
and MEMBER). The frontend uses the IAM's vendored browser SDK (`core/iam/`) behind an `AuthService` with
signals, a functional interceptor (bearer on this site's `/api` only, one retry with `refresh()` after a 401)
and route guards; the backend verifies each access token locally with the IAM's vendored PHP verifier (v1.0.1:
RS256 only, `typ at+jwt`, issuer, audience, expiry, JWKS cached on disk). Users are created just in time with
the IAM user id as `users.id` and `preferred_username` as the name; roles come from each token and are never
stored. The mode is installation configuration (now `mode` in `config/auth.local.php`): anything but "none"
asks for sign-in (a server is never open by default, and a typo counts as "iam"). With "none" - the owner's own
copy - every request acts as `local_user_id` with every role and the UI shows no sign-in controls; as a second
safety net that mode answers only 127.0.0.1 / ::1 (tightened on 2026-09-28 to the app's own pages), since a copy
started with sign-in off must not serve the network. (Behind a reverse proxy on the same machine every client
looks local, which is why "none" is for the owner's computer only.) `GET /api/meta` tells the frontend the mode,
the issuer and the client id before anything is drawn.

**2026-09-25 — A dry run for the build sync.** A trial run of the `sync-doctor` agent showed that read-only
diagnosis needed a way to replay cached pages against stored builds without writing, so the sync command
gained `--dry-run` (same normalization and hash as real syncs; today `php sync-builds.php --dry-run
--cache-hours=96`). A sync timezone setting added at the same time went with the schedule on 2026-09-27. (Since
2026-10-20 there is no sync, and no such command.)

**2026-09-25 — Project AI setup.** `CLAUDE.md` + `docs/` knowledge base + project skills and agents in `.claude/`.
The bash helper `scripts/php.sh` makes every session use PHP 8.4+ without hard-coded paths.

**2026-09-24 — Inventory suggestions never make set bonuses worse.** Per-slot swaps keep the target option's
fraction (and, when no recommended set is worn, the current 2pc/4pc bonuses); switching sets is shown as a
set plan instead of misleading single-piece hints.

**2026-09-24 — Build sources: two build sites for Genshin, and for HSR the second of them with a third (Genshin's
third opinion as well).** Chosen for structured embedded data and freshness. Rejected: a site of hand-written tables
behind bot blocking, a site of prose that had gone stale, and the first chosen site's HSR pages (a copy of those
hand-written tables). The second site's Genshin pages of usage statistics alone become "Most used" builds so every
character is covered. (Since 2026-10-20 the site reads no build site, and Star Rail is gone - see there.)

**2026-09-24 — Catalog from yatta.moe, not Enka's store.** yatta has current lists for both games with IDs
that match Enka and scanner exports; Enka's legacy name files stopped at 6.2. Enka's `store/gi/avatars.json`
is used only for talent skill order. (Since 2026-10-20 the catalog comes from genshin.anotoki.eu - see there.)

**2026-09-24 — Canonical keys = normalized English names.** One key space for GOOD keys, display names and
slugs; aliases + unique fuzzy matching cover nicknames and typos. A shorter catalog key never matches a
longer name (new alt characters like "Himeko • Nova").

**2026-09-24 — Build details as JSON columns.** Builds are always read whole and compared in PHP; JSON keeps
the schema small.

**2026-09-24 — Import methods: the showcase (UID) + exporter files; the official web account deferred.** No
credentials needed. An import through the game's official web account needs the player's session cookies and
a changing request signature; revisit on request.

**2026-09-24 — Angular 21 + Angular Material, PHP backend.** The user's stack preference (Angular, PHP).
Angular 21 because the installed Node (22.15) is too old for Angular 22. PHP 8.4+ required for the native
`Dom\HTMLDocument` parser. (The backend started on Laravel 13; see 2026-09-29. Since 2026-10-21 nothing reads a
page and the `dom` extension is no longer asked for; PHP 8.4 stays the floor.)

**2026-09-24 — Politeness first.** Per-host delays (10 s for one build site, as its robots.txt asks), 12 h page
cache, a stale-hours guard so manual runs never re-hit a site the same day, and runs limited to a few characters
never mark a source as synced. (Since 2026-10-20 the owner's local tools read the build sites, as politely - see
there.)
