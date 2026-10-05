---
name: verify-ui
description: Check the build analyzer in a real browser, end to end, on a throwaway database with the stand-in IAM - somebody new, a visitor's first import and its key, two tabs, the move into an account at sign-in (also when Sign in is pressed while the first import is on its way), a refused sign-in while visiting, sign-in required by the IAM (every page to the IAM first, the key kept and moved), a member's export and import, an editor and an admin in the admin panel, the family's top bar at 360 / 390 / 880 / 881 / 1280 px in light and dark with its phone menu and a real Tab press, the Slovak pages at 390 px with no key where a word should be, and the served path (X-Visitor-Key through the router, the visitors' clean-up after an answer). Use after any change to Angular templates, styles, the bar, sign-in, visitors, the site's strings or API responses the UI renders, before saying a UI change works, when a page renders blank or looks broken, or when the user asks to see, check or screenshot the app.
---

# Verify the UI

Type checks and unit tests don't show overflowing columns, empty panels, a bar whose pieces paint over each
other, or focus that falls to `<body>`; a browser does. Sign-in is the IAM's only, and the backend lets in
only tokens it can verify - so the check signs in through the **stand-in IAM**
(`backend/tests/tools/fake-iam.php`) against a **throwaway database**, never the owner's.

## 1. Run it

From the repository's root (Git Bash; `PHP` = a PHP 8.4+ binary when `php` on PATH is older):

```bash
node .claude/skills/verify-ui/scripts/run.mjs             # builds the frontend first
node .claude/skills/verify-ui/scripts/run.mjs --no-build  # the build in frontend/dist is current
node .claude/skills/verify-ui/scripts/run.mjs --keep      # leave the work folder (screenshots, logs, the export)
node .claude/skills/verify-ui/scripts/run.mjs --measure   # only: what the bar's row needs, in English and Slovak
```

`run.mjs`:

1. refuses to start when 8590 or 8597 already answers (another check, or somebody's server: look first) - or
   run it on other throwaway ports, each in 8590-8599: `SITE_PORT=8593 IAM_PORT=8596 node .../run.mjs`
   (several agents checking several sites at once each take their own);
2. installs `playwright-core` into `<temp>/pw` once (`npx -y npm@11 install --prefix <temp>/pw playwright-core` -
   never into the repository; npm 10 crashes on install here) and drives the installed Edge headlessly;
3. `npx ng build` (unless `--no-build`);
4. `php tests/tools/browser-db.php make <work>/config http://127.0.0.1:8597` (from `backend/`): the database
   `build_analyzer_test_browser` made afresh from the migrations (004 included), the tests' small catalog
   (`tests/support/seed.php`) and one build for Hu Tao - nothing is ever fetched from a live site - and a
   settings folder (`database.local.php`, `auth.local.php`, `storage/`) outside the repository;
5. starts the stand-in IAM on **8597** and the site on **8590** (`php -S 127.0.0.1:8590 router.php`: the built
   app and the API on one origin, as on the host) with `BUILD_ANALYZER_TEST_CONFIG=<work>/config` - honoured
   only for a `build_analyzer_test_` database (`App\Config`; anything else is "not set up"), and its storage
   (the log) is the folder's, never `backend/storage`;
6. runs `scripts/flow.mjs`, then stops both servers with their process trees, drops the database, checks the
   ports are free and deletes the work folder (unless `--keep`: it holds a database login - delete it after).

Every step prints `ok` or `FAIL`; the exit code is 0 only when all passed. **Look at the screenshots**
(`<work>/shots`, printed at the start; open them with the Read tool) - the checks prove geometry and words,
not that a page looks right.

## 2. What the flow checks

| Tour | What |
| --- | --- |
| Nobody | the dashboard's hero and sources, no `/api/accounts` request; the bar's links, Sign in; the footer's version and What's new |
| A visitor | the wizard's file import (a GOOD sample, file imports only - never a showcase, never the network): 201 with `X-Visitor-Key` (43 characters), the key in `localStorage['build-analyzer-visitor']`, a visitor row with a negative id; the notice and the account on the dashboard; a second tab of the same browser sees it |
| Two tabs first | two tabs open before any key, both import: the stored key stays the first one |
| The move | Sign in (the stand-in IAM signs in `mona`, id 7, MEMBER only: an ordinary person): the snack bar, the key gone, the visitor row gone, the account `mona`'s, the "Your data" card |
| Own data | Export (the file's name, `build-analyzer.accounts`, no ids), Import the same file (the preview: 0 new, 0 replaced, 1 kept), Confirm |
| The bar | 360, 390, `PHONE_BAR_MAX` (880), 881 and 1280 px; light and dark; English and Slovak; signed in and out: the pieces' boxes never overlap or leave the screen, no page scroll, a phone's bar up to 880 and a desktop's from 881; at 1280 px the language button shows its code and its accessible name holds it ("Jazyk: Slovenčina (SK)" - WCAG 2.5.3, Label in Name) |
| Keyboard | the phone menu at 390 px (Slovak: its items, the focus on the first), a **real** Tab and Shift+Tab press - the menu closes and the focus is on the menu button, never `<body>`; the person's menu at 1280 px, End then Tab |
| Slovak | `/`, `/import`, `/builds/genshin`, `/builds/genshin/hutao`, an account and a character at 390 px: `<html lang="sk">` and no key of `core/i18n/en/*.json` in the page's text |
| Admin panel | a person without a role: the card, no sections; EDITOR (`eddie`, id 8): Sources and Translations only, `<html lang="en">`, no language chooser, the role pill and "Back to Build Analyzer", Languages and People send them to Sources, and the server refuses them `/api/admin/people` and `/api/admin/migrations` (403 `admin_only`); ADMIN (`ada`, id 1): the person's menu leads into the "Admin panel", all five sections (Migrations too), the brand "anotoki admin panel" (its link named "anotoki Build Analyzer: the admin panel"), the titles "People · Admin panel · Build Analyzer", People with the visitors line; Migrations sends an editor to Sources |
| The admin panel's bar | 390, `ADMIN_PHONE_BAR_MAX` (960), 961, 1024 and 1280 px, light and dark: it fits, a phone's bar up to 960 and a desktop's from 961 (a 1024 px laptop has the whole row), the person's name whole at 1280 |
| Migrations | ADMIN: nothing pending and every file applied in order, Apply pending disabled; then 004's row deleted (as if just uploaded): the Sources page's note with the link (no Apply there), the page lists 004 as pending, Apply pending asks first, and re-running 004 fails - "Stopped at 004_...", "Nothing was applied", rolled back, the database's error - also at 390 px with no sideways scroll; the row is put back after |
| Served path | through `router.php`: an import with no sign-in answers its key in `X-Visitor-Key` and the key opens that visitor's accounts; a visitor aged 45 days goes after the next own-data answer (the after-answer sweep, `AfterResponse`), the log says so and holds no key |
| Sign in while saving | a visitor's first import held back 1.5 s and Sign in pressed meanwhile: the page leaves for the IAM only after the answer, so the key is kept and moves into `sam`'s account (id 10) |
| A refused sign-in | the stand-in's `deny` while visiting: back on the sign-in page with "The sign-in was not completed" and the page asked for as `returnTo`; the bar's Builds goes on visiting; `/admin` goes to the IAM all the same and, refused, shows the panel's "You are not signed in" with no section |
| Sign-in required | the stand-in's `login_required: true` and the site's kept answer deleted (`<work>/config/storage/cache/iam/`): `/api/meta` relays it, a visitor's key gets 401 `sign_in_required`; a page load goes to the IAM by itself - refused first: the sign-in page, the key kept in the browser - then `rita` (id 11) comes back to `/builds/genshin` with the visitor's account moved and the key gone; somebody new never sees the visitors' dashboard; switched off again: the hero |

Who signs in: `POST http://127.0.0.1:8597/_test/state` with `{"user": {"id", "username", "roles"}}` (the
stand-in's header says the rest: `deny`, `down`, `refuse`, `ttl`, and `login_required` / `application_missing` - what
it answers at `/api/public/applications/build-analyzer`, which the site reads to know whether sign-in is required:
false by default, so the check runs with visitors welcome; the site keeps that answer a minute in its settings
folder's `storage/cache/iam/`). The token carries `<username>@example.test`.

`--measure` prints what the bar's row needs without the person's name (the padding, the brand, the links, the
controls and the gaps): signed out and as an admin, in English and Slovak, and the admin panel. That is how
`PHONE_BAR_MAX` (880, `layouts/top-bar/top-bar.ts`: the member pages' widest row is 799 px, an admin with a
sync running) and `ADMIN_PHONE_BAR_MAX` (960: the admin panel's row with a sync running is 954 px, the same whichever language was chosen - the panel reads English) were
chosen. Change the bar's words or pieces -> measure again.

## 3. Adding to the flow

`flow.mjs` is plain playwright-core: `newContext()` (one per person - storage of its own; What's new marked
seen), `open(page, path)` (waits for the bar and the network), `signIn(page, user)`, `checkBar(page, label)`,
`keysShown(page)`, `sql(query, params)` (the throwaway database, through `browser-db.php sql`), `shot(page,
name)`. Never press Sync, Resume or a showcase import: they would fetch live sites. The browser's locale is
`en-GB` - this computer's is Slovak, and a check must not depend on it; ask for Slovak with `?lang=sk` (kept
for the tab).

## 4. Quick looks against your own servers

`scripts/screenshot.sh` (headless Edge, a profile of its own) and `scripts/viewport.cjs` (a true phone
viewport through the same playwright-core: `NODE_PATH="$TMPDIR/pw/node_modules" node .../viewport.cjs URL
OUT.png 390`) still take a picture of a running site - the owner's `ng serve` on 4600 or a server of your own
on 8590-8599. They cannot sign in: the backend refuses a session faked in `localStorage`. Never stop a server
on 4200-4700 or 8000-8500 without reading its command line first.

Known artifacts that are *not* bugs: empty boxes instead of character icons (the seeded catalog has no icon
addresses - nothing is fetched); "Source 2" holding the one build (the seeded row is the second Genshin
source); a red Vite overlay right after an edit in `ng serve` (stale - rebuild).
