# Build analyzer: frontend

Angular 21 + Angular Material. See [DEV.md](../DEV.md) for setting up, running, testing and
deploying, and [docs/architecture.md](../docs/architecture.md) ("Frontend") for how it is organised.

```bash
bun ci                     # the packages, from bun.lock (npm start runs it first)
npm start                  # http://localhost:4600 (proxies /api and /setup.php to http://localhost:8400)
npx ng test --watch=false  # unit tests (Vitest)
npx ng build               # production build into dist/frontend/browser (backend/tools/build.php uses it)
```

With npm, `npx -y npm@11 ci` installs from `package-lock.json` (npm 10.9.x has a resolver bug here); a change
of the dependencies updates both lock files.

Sign-in goes through the anotoki IAM's browser SDK, vendored unchanged in `src/app/core/iam/`
(`anotoki-auth.mjs` + `anotoki-auth.d.ts`, v1.2.0): copy both files from `anotoki-iam/sdk/js/` to update it.
Since 1.2.0 it also brings the account's theme, date order and clock (`AuthService.preferences()`) and saves a
signed-in person's choices to the account (`AuthService.savePreferences()`): signed in, the theme toggle, the
language menu and the dates follow the account, which every anotoki site shares; a visitor's stay this
device's (`core/theme.ts`, `core/i18n/translation.ts`, `core/i18n/date-patterns.ts`).

What the site shows while it is not ready comes from the anotoki family's library, `@anotoki/lib/migrations`
(the npm package `@anotoki/lib`, installed from its GitHub release's tarball - `package.json`; DEV.md "The shared
library" says how to update it): the gate with its page - "The site is being updated", "A database update is
waiting" for the site's ADMIN, "not set up yet" -, which takes the router outlet as a template (`app.html`) and
makes the pages only while it shows them, so nothing behind its page runs; the interceptor that hears the
server say so; and the admin panel's Migrations page (`pages/admin/migrations/`). `core/site-status.ts`
wires them to this site (who is ADMIN, how to sign in, the language on the page, the account's dates), and
`styles.scss` maps this site's colours onto the library's `--anotoki-*` properties, light and dark.

```text
src/app/
  core/        api.ts (ISSUED_AS: whose each request is), models.ts, auth.ts + auth.interceptor.ts +
               auth.guards.ts + sign-in.ts (the IAM only), saves-in-flight.ts (a sign-in waits for them),
               visitor-key.ts (somebody not signed in: the key, the move at sign-in), storage.ts, download.ts,
               meta-store.ts, changelog.ts, sync-driver.ts, theme.ts (the account's while signed in, else this
               device's), site-status.ts (the family's site gate and Migrations page, wired to this site),
               i18n/ (the site's words; date-patterns.ts: the account's date order and clock), iam/
               (the vendored SDK)
  layouts/     top-bar/ (the anotoki family's bar: one row, a phone's menu up to 880 px), footer/ (version,
               What's new), app-layout/ (everybody's pages), admin-layout/ (the admin panel: staff / signed out / no role)
  shared/      brand/ (the anotoki mark, public/anotoki.png), theme-toggle/, language-switcher/, visitor-notice/,
               visitor-hero/, data-card/ ("Your data"), build-detail, item-card, confirm-dialog, ...
  pages/       dashboard, import, account, character, builds, sign-in;
               admin/ (sources, translations, languages, people, migrations - English, under /admin/)
```

Every page outside `/admin/` is open to everybody - unless the IAM says this site requires a sign-in
(`AuthService.loginRequired`, from `/api/meta`): then `siteSignInGuard` sends anybody not signed in to sign in
first; `/admin/` always asks for a sign-in (`signInGuard`), a visitor's too. Whether a request is a person's or
a visitor's is decided when `Api` issues it (`ISSUED_AS`), never by whether a token is there when it leaves. How
the pieces work: [docs/architecture.md](../docs/architecture.md), "Frontend" and "Visitors".

The version in the footer and its "What's new" come from `public/changelog.json`; a new release adds an entry
at the top - its version MAJOR.MINOR.PATCH, the same in `package.json` and `backend/src/Version.php` - and What's
new opens by itself with the releases after the one this browser saw last, compared by their numbers
(`core/changelog.ts`: "0.3", kept from before the versions had three numbers, is 0.3.0).

The browser check - the built app served by the backend on a throwaway database, signed in through the
stand-in IAM - is `node .claude/skills/verify-ui/scripts/run.mjs` from the repository's root (the `verify-ui`
skill).
