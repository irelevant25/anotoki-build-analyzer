---
name: verify-ui
description: Check the build analyzer in a real browser, end to end, on a throwaway database with the anotoki family's stand-in IAM - somebody new, the sign-in hint (somebody signed in at the IAM is signed in here with one silent hop and no click, and signed out here once signed out there; one quiet try for a hint with no session behind it; robots.txt keeping search engines off /auth/), a visitor's first import and its key, two tabs, the move into an account at sign-in (also when Sign in is pressed while the first import is on its way), feedback from the foot of the page (the family's form in a dialog, in Slovak at 390 px, light and dark, kept with nothing of where it came from; a signed-in person's address written in), a refused sign-in while visiting (the family's failure screen, light and dark, 1280 and 390 px, Slovak too), sign-in required by the IAM (every page to the IAM first, the key kept and moved), a member's export and import, an editor and an admin in the admin panel (the family's People page; the library's Translations and Languages pages - a string reworded and read back; the admin tools - error log (a page's own error thrown and reported into it), audit log, feedback with its count in the bar, backups with their makers' names; light and dark, 1280 and 390 px; an editor refused every ADMIN page), the family's top bar at 360 / 390 / 880 / 881 / 1280 px in light and dark with its phone menu and a real Tab press, the admin panel's bar for an ADMIN and for an editor, the Slovak pages at 390 px with no key where a word should be, the account's theme, dates and language (an IAM that keeps them: shown at sign-in, saved from the bar across origins, a refusal put back), the site while a database update waits (the anotoki-lib site gate: "being updated" for a visitor and a member, the ADMIN's way to the Migrations page, Sign in from the waiting page, Apply and where it stopped, the waiting page coming back by itself) and while it is not set up (the setup page only while never installed), and the served path (X-Visitor-Key through the router, the visitors' clean-up after an answer). Use after any change to Angular templates, styles, the bar, the theme, dates, sign-in, visitors, feedback, the admin tools, the site's strings or API responses the UI renders, before saying a UI change works, when a page renders blank or looks broken, or when the user asks to see, check or screenshot the app.
---

# Verify the UI

Type checks and unit tests don't show overflowing columns, empty panels, a bar whose pieces paint over each
other, or focus that falls to `<body>`; a browser does. Sign-in is the IAM's only, and the backend lets in
only tokens it can verify - so the check signs in through the anotoki family's **stand-in IAM** (anotoki-lib's
`backend/vendor/anotoki/lib/backend/testing/stand-in-iam.php`) against a **throwaway database**, never the owner's.

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
   `build_analyzer_test_browser` made afresh from every migration (anotoki-lib's engine), the tests' small catalog
   (`tests/support/seed.php`) and one build for Hu Tao - nothing is ever fetched from a live site - and a
   settings folder (`database.local.php`, `auth.local.php`, `storage/`) outside the repository;
5. writes the stand-in's key (`Anotoki\Lib\Auth\Testing\TestIam::create()`, into `<work>/stand-in-iam`), starts
   the stand-in IAM on **8597** - and tells it this site lets visitors in (`login_required: false`; its own default
   is true) - and the site on **8590** (`php -S 127.0.0.1:8590 router.php`: the built
   app and the API on one origin, as on the host) with `BUILD_ANALYZER_TEST_CONFIG=<work>/config` - honoured
   only for a `build_analyzer_test_` database (`App\Config`; anything else is "not set up"), and its storage
   (the log, the error log, the backups the admin tools' tour makes) is the folder's, never `backend/storage`;
6. runs `scripts/flow.mjs`, then stops both servers with their process trees, drops the database, checks the
   ports are free and deletes the work folder (unless `--keep`: it holds a database login - delete it after).

Every step prints `ok` or `FAIL`; the exit code is 0 only when all passed. **Look at the screenshots**
(`<work>/shots`, printed at the start; open them with the Read tool) - the checks prove geometry and words,
not that a page looks right.

## 2. What the flow checks

| Tour | What |
| --- | --- |
| Nobody | the dashboard's hero and sources, no `/api/accounts` request; the bar's links, Sign in; the footer's version and What's new |
| The sign-in hint | `hana` (id 13) signed in at the stand-in itself (`/_test/sign-in`, as on another anotoki site): `/builds/genshin` signs her in here with one silent hop (`prompt=none`) and no click, on the page opened, the bar naming her, the site knowing her, the hint marked tried (`anotoki-auth:build-analyzer:hint`); signed out there (`/_test/sign-out`): a visitor here at the next page load; a hint with no session this site may use (`interaction`): one quiet try, no failure screen, no second try for the same hint; `robots.txt` says `Disallow: /auth/` |
| A visitor | the wizard's file import (a GOOD sample, file imports only - never a showcase, never the network): 201 with `X-Visitor-Key` (43 characters), the key in `localStorage['build-analyzer-visitor']`, a visitor row with a negative id; the notice and the account on the dashboard; a second tab of the same browser sees it |
| Two tabs first | two tabs open before any key, both import: the stored key stays the first one |
| The move | Sign in (the stand-in IAM signs in `mona`, id 7, MEMBER only: an ordinary person): the kit's toast saying so, the key gone, the visitor row gone, the account `mona`'s, the "Your data" card |
| Own data | Export (the file's name, `build-analyzer.accounts`, no ids), Import the same file (the preview: 0 new, 0 replaced, 1 kept), Confirm |
| The bar | 360, 390, `PHONE_BAR_MAX` (880), 881 and 1280 px; light and dark; English and Slovak; signed in and out: the pieces' boxes never overlap or leave the screen, no page scroll, a phone's bar up to 880 and a desktop's from 881; at 1280 px the language button shows its code and its accessible name holds it ("Jazyk: Slovenčina (SK)" - WCAG 2.5.3, Label in Name) |
| Keyboard | the phone menu at 390 px (Slovak: its items, the focus on the first), a **real** Tab and Shift+Tab press - the menu closes and the focus is on the menu button, never `<body>`; the person's menu at 1280 px, End then Tab |
| Slovak | `/`, `/import`, `/builds/genshin`, `/builds/genshin/hutao`, an account and a character at 390 px: `<html lang="sk">` and no key of `core/i18n/en/*.json` in the page's text |
| Feedback | at 390 px in Slovak, Feedback at the foot of `/import` ("Spätná väzba") opens the family's form (`@anotoki/lib/feedback/form`) in a dialog titled in Slovak: nobody signed in - no "without my name", no key, no sideways scroll; sent: the thanks, and the row in `anotoki_feedback` - the words, `sk`, `/import`, no account, no address; light and dark at 390 and 1280 px (screenshots `22-*`); mona, signed in: her account's address written in and "Send without my name" offered |
| The account's preferences | at 390 px, `pia` (id 12), whose account at the stand-in keeps a theme, dates and clock (`preferences`: dark, `ymd_dash`, 24 h - the IAM's migration 015; everybody else's IAM keeps none, so the other tours see the site's own settings): automatic (the device's theme) while visiting; signed in, the account's dark theme (`<html data-theme="dark">`, the family's switch on Dark), written to `build-analyzer-theme` too; What's new's dates as `2026-10-04`; the switch's Light shown at once and saved to the account across origins (`POST /api/oidc/preferences` at the stand-in - its state says `light`); Slovak from the language menu, saved too (the state's `locale`); a reload keeps both; with the stand-in `down`, Dark is put back to Light and the switch says so under itself (the family's note, `08c-*`), the account untouched |
| Admin panel | a person without a role: the card, no sections; EDITOR (`eddie`, id 8): Sources and Translations only, `<html lang="en">`, no language chooser, the role pill and "Back to Build Analyzer", Languages, People and Migrations send them to Sources, and the server refuses them `/api/admin/people` and `/api/admin/migrations` (403 `admin_only`); ADMIN (`ada`, id 1): the person's menu leads into the "Admin panel", all nine sections (Sources, Translations, Languages, People, Migrations, Errors, Audit, Feedback, Backups), the family's short brand "Analyzer" with "admin panel" under it (its link named "anotoki Build Analyzer: the admin panel"), the titles "People · Admin panel · Build Analyzer"; People - the family's page (`@anotoki/lib/people/page`) - lists mona with Delete their data, the reader's own row "you", the visitors apart, at 1280 and 390 px, light and dark (`10-*`) |
| The admin panel's bar | the ADMIN's nine sections at 390, 1024, 1280, `ADMIN_PHONE_BAR_MAX`, one px more and 200 more, light and dark: it fits, a phone's bar up to `ADMIN_PHONE_BAR_MAX` and a desktop's after it, the person's name whole at the widest; an editor's two sections at `EDITOR_PHONE_BAR_MAX`, one px more and 200 more, likewise |
| Translations and Languages | the anotoki library's pages (`@anotoki/lib/translations/admin`) in the admin panel's frame, English (`<html lang="en">`), titled "Translations · Admin panel · Build Analyzer": each language's coverage, the library's own keys in the grid (`anotoki.language.button`, moved there by 006), an admin led on to Languages; `topbar.import`'s Slovak reworded and saved - the Slovak bundle has it, the database says ADMIN `ada` (id 1) saved it -, then put back; Languages: English the fallback, Slovak "Released with the site"; both at 1280 and 390 px, light and dark, no sideways scroll (screenshots `17-*`, `18-*`); an editor (`eddie`) has the grid and no way on to Languages; `/builds/genshin` in Slovak, dark, at 1280 and 390 px with no key in the page |
| Admin tools | ADMIN: Errors, Audit, Feedback and Backups - the family's pages (`@anotoki/lib/admin/*`) in the admin panel, English, titled "Error log · Admin panel · Build Analyzer" and so on - at 1280 and 390 px, light and dark, no sideways scroll, the site's dark colours (`19-*`); the count of new feedback beside Feedback in the bar (the link reads "Feedback 1": the family's bar has no words of a count's own); the error log has the 404 asked for a moment before, and an error thrown on purpose in ada's page (window's error event) - the family's reporter sends it through the app's `HttpClient` with her token to `POST /api/client-errors` (204), and the log has it as the browser's, scrubbed (`{email}`), by her account, on its page; the audit log the string ada saved, by her name; the visitor's feedback listed, and opened it is read and the count goes; Back up now asks first (a sign-in older than ten minutes: Sign in again, and back), and the backup is made, audited and listed with its maker's name; an editor is sent to Sources from each, and the server refuses each (403 `admin_only`) |
| The pages' errors | after every tour: the error log holds no error a page met (`source: client`) but the one thrown on purpose - the reporter would have written any |
| Migrations | ADMIN, the family's page (`@anotoki/lib`) in the admin panel: nothing pending and every file applied in order - the library's `anotoki_admin` (the audit log's and feedback's tables), this site's set, then the library's `anotoki_translations` and `anotoki_people`; the site's files by their names, the library's with their set (`anotoki_admin/001_…`, `006_…`, `anotoki_people/001_…`) -, Apply pending disabled, View shows a file's SQL (005) |
| An update waits (the site gate) | 004's row deleted, as if just uploaded: `/api/site-status` says `update_pending`, every other path 503 with `Retry-After: 60`; a visitor reads "The site is being updated" (Slovak at 390 px, dark too), no error, code, setup or Migrations, only a quiet Sign in; mona, signed in, the same with no button; ada (ADMIN) on `/admin/sources` reads "A database update is waiting" and Open Migrations leads to the page with 004 pending (by its name alone, the site's one set's), in the admin frame; behind each waiting page no page is made - the visitor's, mona's and ada's ask the API for nothing but `/api/meta`, `/api/site-status` and the strings (`OPEN_WHILE_WAITING`); somebody signed out presses Sign in on the waiting page and comes back to `/` as ada, then to Migrations; Apply pending asks first (Cancel focused), and re-running 004 fails - "Stopped at 004_...", "Nothing was applied", rolled back, the database's error - also at 390 px (and dark) with no sideways scroll; the row put back, the visitor's waiting page reloads by itself within 30 s |
| Not set up | `auth.local.php` moved out of the settings folder: "This site is not set up yet" with Open the setup page (`/setup.php`) and Try again; then `storage/installed.lock` written there: "The site is not available right now", never the setup page (the API's 503 bodies likewise); both put back |
| Served path | through `router.php`: an import with no sign-in answers its key in `X-Visitor-Key` and the key opens that visitor's accounts; a visitor aged 45 days goes after the next own-data answer (the after-answer sweep, `AfterResponse`), the log says so and holds no key |
| Sign in while saving | a visitor's first import held back 1.5 s and Sign in pressed meanwhile: the page leaves for the IAM only after the answer, so the key is kept and moves into `sam`'s account (id 10) |
| A refused sign-in | the stand-in's `deny` while visiting: back from the IAM on `/sign-in`, the family's failure screen (`anotoki-sign-in-failure`) - "Signing in did not work", "The sign-in was not completed. …", Try again and Back, `noindex` - in the site's frame, titled "Sign in · Build Analyzer", at 1280 and 390 px, light and dark; Back goes on to the page the sign-in started on, visiting, and the next page is the visitor's again (said once); in Slovak, the site's own words moved under the library's keys by 009 ("Prihlásenie sa nedokončilo.", Skúsiť znova, Späť); `/admin` goes to the IAM all the same and, refused, the failure screen with no section of the panel |
| Sign-in required | the stand-in's `login_required: true` and the site's kept answer deleted (`<work>/config/storage/cache/iam/`): `/api/meta` relays it, `/api/site-status` still answers anybody, a visitor's key gets 401 `sign_in_required`; a page load goes to the IAM by itself - refused first: the failure screen, the key kept in the browser - then Try again brings `rita` (id 11) back to `/builds/genshin` with the visitor's account moved and the key gone; somebody new never sees the visitors' dashboard; `/privacy` is read all the same, with no trip to the IAM (the legal pages are anybody's) - with no Feedback at its foot, and Contact gives the address alone (no message can be sent by somebody signed out then); switched off again: the hero |
| The legal pages | the footer's About · Support · Terms · Privacy · Contact (the family's `<anotoki-legal-links>`, their addresses) and the fan clause under them; Privacy from the footer in the site's frame, titled "Privacy policy · Build Analyzer", the open link marked; Contact's Send feedback opens the family's form in a dialog; Terms, Privacy and Contact in English (light 1280, dark 390) and Slovak (dark 1280, light 390): `<html lang>`, no key, no sideways scroll, the dark theme where asked, Terms and Contact with the site's fan clause naming both games, Privacy with the site's six parts and the library's five, its storage, WebSupport s.r.o. first among the three who handle data, and "your anotoki account" linked to the stand-in IAM's `/privacy` (screenshots `23-*`) |
| The kit's newer pieces | `mona` signed in, in English (light 1280, dark 1280) and Slovak (dark 390, light 390): the account's element filter the kit's radio group, every element's mark 3:1 on the control (measured on surface and surface-2), its characters link cards with the kit's progress bars, its import history closed (its table not made) and opened; hutao's build tab with its score as the kit's badge, marked the closest (icon, filled badge, heard after the name), the five score bars; the import guide's stepper (two steps done, a way back to each) and its trouble list opened - in English on to the file's step and its paste box; the builds' link cards; no key, no sideways scroll, the dark theme where asked (screenshots `24-*`) |

Who signs in: `POST http://127.0.0.1:8597/_test/state` with `{"user": {"id", "username", "roles"}}` (the
stand-in's header says the rest: `session`, `interaction`, `deny`, `down`, `refuse`, `ttl`, `hint_cookie`,
`anonymized`, `answers`, and `login_required` / `application_missing` - what it answers at
`/api/public/applications/build-analyzer`, which the site reads to know whether sign-in is required: true by the
stand-in's default, false from `run.mjs` on, so the check runs with visitors welcome; the site keeps that answer a
minute in its settings folder's `storage/cache/iam/`). `GET /_test/sign-in?return=URL` and
`GET /_test/sign-out?return=URL` sign somebody in or out at the IAM itself, as another anotoki site would, with
the sign-in hint's cookie (`anotoki_signed_in`); `GET /_test/state` has `log`, the last requests, to count the
silent hops by. The token carries `<username>@example.test`; a user given `locale` and `preferences`
(`{theme, date_format, time_format}`) has them in the tokens, as an IAM with its migration 015 sends them, and
`POST /api/oidc/preferences` changes them (the state's `user`, and each session of theirs) - without
`preferences` the stand-in is an IAM before 015 and answers that address 503.

`--measure` prints what the bar's row needs without the person's name (the padding, the brand, the links, the
controls and the gaps): signed out and as an admin, in English and Slovak, and the admin panel for the ADMIN
(with a count of new feedback - it posts one first) and for an editor. That is how `PHONE_BAR_MAX` (880,
`layouts/top-bar/top-bar.ts`: in the family's bar the member pages' widest row is 818 px, an admin with a sync
running - 803 signed out in Slovak -, and room for a scrollbar and a language added later),
`ADMIN_PHONE_BAR_MAX` (1270: the ADMIN's nine sections need 1186 px, 1236 px with a sync running, the same
whichever language was chosen - the panel reads English - and room for a scrollbar and a two-digit count) and
`EDITOR_PHONE_BAR_MAX` (640: an editor's two sections need 560 px, 610 px with a sync running) were chosen.
Change the bar's words or pieces, or take a library version that changes the bar -> measure again.

## 3. Adding to the flow

`flow.mjs` is plain playwright-core: `newContext()` (one per person - storage of its own; What's new marked
seen), `open(page, path)` (waits for the bar and the network), `openGated(page, path)` (waits for the site
gate's page instead, while the site is not ready) and `gatePage(page)` (its title, words, buttons and links),
`darkSurface(page, selector)`, `signIn(page, user)`, `checkBar(page, label)`,
`keysShown(page)`, `sql(query, params)` (the throwaway database, through `browser-db.php sql`), `shot(page,
name)`. The pages are the family's kit and the bar the family's (`@anotoki/lib/shell`): the flow reads them by
the kit's class names (`anotoki-top-bar__link`, `anotoki-top-bar__person-button`, ...), named once at its top -
`BAR`, `BAR_LINK`, `SIGN_IN`, `PERSON_BUTTON`, `PERSON_NAME`, `MENU_BUTTON`, `BAR_MENU` (the phone's or the
person's menu while open), `LANGUAGE_BUTTON`, `LANGUAGE_MENU`, `THEME_SWITCH`, `DIALOG` (`anotoki-dialog`, round
a native `<dialog>`), `TOAST`; a new library version that renames one changes it there. A tour that closes the site (a migration row deleted, a settings file moved) puts it back in a `finally`. Never press Sync, Resume or a showcase import: they would fetch live sites. The browser's locale is
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
