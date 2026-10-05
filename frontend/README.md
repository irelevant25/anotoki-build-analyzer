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
(`anotoki-auth.mjs` + `anotoki-auth.d.ts`, v1.1.1): copy both files from `anotoki-iam/sdk/js/` to update it.

```text
src/app/
  core/        api.ts (ISSUED_AS: whose each request is), models.ts, auth.ts + auth.interceptor.ts +
               auth.guards.ts + sign-in.ts (the IAM only), saves-in-flight.ts (a sign-in waits for them),
               visitor-key.ts (somebody not signed in: the key, the move at sign-in), storage.ts, download.ts,
               meta-store.ts, changelog.ts, sync-driver.ts, theme.ts, i18n/ (the site's words), iam/ (the vendored SDK)
  layouts/     top-bar/ (the anotoki family's bar: one row, a phone's menu up to 880 px), footer/ (version,
               What's new), app-layout/ (everybody's pages), admin-layout/ (the admin panel: staff / signed out / no role)
  shared/      brand/ (the anotoki mark, public/anotoki.png), theme-toggle/, language-switcher/, visitor-notice/,
               visitor-hero/, data-card/ ("Your data"), build-detail, item-card, confirm-dialog, ...
  pages/       dashboard, import, account, character, builds, sign-in, not-set-up;
               admin/ (sources, translations, languages, people, migrations - English, under /admin/)
```

Every page outside `/admin/` is open to everybody - unless the IAM says this site requires a sign-in
(`AuthService.loginRequired`, from `/api/meta`): then `siteSignInGuard` sends anybody not signed in to sign in
first; `/admin/` always asks for a sign-in (`signInGuard`), a visitor's too. Whether a request is a person's or
a visitor's is decided when `Api` issues it (`ISSUED_AS`), never by whether a token is there when it leaves. How
the pieces work: [docs/architecture.md](../docs/architecture.md), "Frontend" and "Visitors".

The version in the footer and its "What's new" come from `public/changelog.json`; a new release adds an entry
at the top (and the same version in `backend/src/Version.php`).

The browser check - the built app served by the backend on a throwaway database, signed in through the
stand-in IAM - is `node .claude/skills/verify-ui/scripts/run.mjs` from the repository's root (the `verify-ui`
skill).
