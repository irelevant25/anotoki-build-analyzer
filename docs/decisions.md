# Decisions

Short records of choices that shape the code. Add a new entry (newest first) when you make a similar call.
Older entries describe the Laravel version as it was; where a setting has since moved, the entry says so.

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
  `migrate` lock stays: it answers "busy" at once instead of a wait.
- *Apply left the Sources page*: it only says that updates wait, with a link to Migrations, for an admin.
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
(`TranslationCheck`, 422 `names_another_site`). Each installation's database holds its own strings; a language
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
halfway.

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
after every character. The fetcher keeps each site's last request time in the cache, so crawl delays (Prydwen's
10 s) also hold between requests, and a wait that does not fit into the step becomes a `FetchDeferred`: the run
records `next_step_at` and the browser waits `wait_ms` before the next step, so a step fetches one or two
Prydwen pages. Retries after 429/5xx also move to later steps instead of sleeping. Closing the tab pauses a run
(nothing else advances it); Resume continues from the stored state. The catalog refresh became a run too (one
list per unit), so it follows the same rules, and a stale catalog is refreshed before the build runs of its
game. The command-line tools loop over the same steps, so the owner's local copy and the host behave the same;
the Windows daily task was dropped with the scheduler.

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
appear on build sites first). The showcase import throttle (10 a minute) is per user.

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
--cache-hours=96`). A sync timezone setting added at the same time went with the schedule on 2026-09-27.

**2026-09-25 — Project AI setup.** `CLAUDE.md` + `docs/` knowledge base + project skills and agents in `.claude/`.
The bash helper `scripts/php.sh` makes every session use PHP 8.4+ without hard-coded paths.

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
the schema small.

**2026-09-24 — Import methods: the showcase (UID) + exporter files; the official web account deferred.** No
credentials needed. An import through the game's official web account needs the player's session cookies and
a changing request signature; revisit on request.

**2026-09-24 — Angular 21 + Angular Material, PHP backend.** The user's stack preference (Angular, PHP).
Angular 21 because the installed Node (22.15) is too old for Angular 22. PHP 8.4+ required for the native
`Dom\HTMLDocument` parser. (The backend started on Laravel 13; see 2026-09-29.)

**2026-09-24 — Politeness first.** Per-host delays (Prydwen 10 s per robots.txt), 12 h page cache, a stale-hours
guard so manual runs never re-hit a site the same day, and runs limited to a few characters never mark a
source as synced.
