# Build analyzer: frontend

Angular 21 on the anotoki family's UI kit (`@anotoki/lib`: its styles, its pieces, its shell - no Angular
Material, no CDK). See [DEV.md](../DEV.md) for setting up, running, testing and deploying, and
[docs/architecture.md](../docs/architecture.md) ("Frontend") for how it is organised.

```bash
bun ci                     # the packages, from bun.lock (npm start runs it first)
npm start                  # http://localhost:4600 (proxies /api and /setup.php to http://localhost:8400)
npx ng test --watch=false  # unit tests (Vitest)
npx ng build               # production build into dist/frontend/browser (bun run anotoki-deploy build uses it)
cd .. && bun run format:check  # Prettier on every file of both halves, at the root (bun install there first)
```

With npm, `npx -y npm@11 ci` installs from `package-lock.json` (npm 10.9.x has a resolver bug here); a change
of the dependencies updates both lock files.

Sign-in goes through the anotoki family's auth module, `@anotoki/lib/auth` (set up in `app.config.ts`:
`provideAnotokiAuth()` - the boot answer is `/api/meta`'s `auth`, this site's visitor key store, the failure
screen on `/sign-in`): the IAM's client (the tokens under the same keys as the IAM's SDK kept them), the callback
(`/auth/callback`), the interceptor, the guards, the sign-in hint (a visitor signed in at the IAM is signed in here
without a click) and the account's theme, date order and clock (`AuthService.preferences()`, saved with
`AuthService.savePreferences()`: signed in, the theme switch, the language menu and the dates follow the account,
which every anotoki site shares; a visitor's stay this device's - `shared/shell.ts`,
`shared/i18n/translations.config.ts`, `shared/i18n/date-patterns.ts`). `shared/auth.ts` is the pages' view of it
(`AuthService`, a facade); specs use `@anotoki/lib/auth/testing` or a fake `AuthService`.

The People page and the admin tools (the error log, the audit log, feedback, backups) are the family's pages
(`@anotoki/lib/people/page`, `@anotoki/lib/admin/*`, lazy, routed in `sites/admin/admin.routes.ts`, set up in
`app.config.ts`); the bar counts the new feedback beside Feedback (`FeedbackUnread`). Terms, Privacy and Contact
are the family's pages too (`@anotoki/lib/legal/pages`, lazy, on `/terms`, `/privacy`, `/contact` - anybody's, no
guard), in `sites/main/legal/legal-layout/` - everybody's pages' frame giving them this site's facts
(`sites/main/legal/legal-facts.ts`, every fact named with the code it rests on; the words `legal.*`, migration
011) -, set up in `shared/legal.ts`
(`provideLegal()`: the language, the database's words, the site's own fan clause); the footer links to them
above the fan clause. So are the About and Support pages (`@anotoki/lib/legal/about`, `/legal/support`, lazy, on
`/about`, `/support`, in the same frame; the library's words, titled by its keys), which the footer links first. The
footer is the family's (`<anotoki-site-footer>`, `@anotoki/lib/footer`, in both
layouts): the version, What's new (this site's dialog) and Feedback, which opens the visitors' feedback form
(`@anotoki/lib/feedback/form`, set up in `shared/feedback.ts`) in the kit's dialog, loaded with the first press -
offered, there and on the Contact page, only while a message can be sent (`feedbackOffered()`).
`public/robots.txt` keeps search engines off `/auth/`. The
errors the pages meet go to the server's error log: `provideAnotokiErrorReport()` (`@anotoki/lib/error-report`,
in `app.config.ts` beside `provideBrowserGlobalErrorListeners()`) is the app's `ErrorHandler` - the console hears
every error as before, and each is sent once, scrubbed, to `POST /api/client-errors` through the app's
`HttpClient` (the auth interceptor adds the person's token).

The site's words come through the family's translations module, `@anotoki/lib/translations`: its service, set
up for this site in `shared/i18n/translations.config.ts` (the storage names, the English compiled into the app as
the fallback, the account, the admin pages' rights) and typed by this site's keys in
`shared/i18n/translation.ts` (an unknown key is a compile error); the admin Translations and Languages pages are
the library's (`@anotoki/lib/translations/admin`, wrapped in `sites/admin/translations/` and `languages/`); specs
use `@anotoki/lib/translations/testing`.

What the site shows while it is not ready comes from the anotoki family's library, `@anotoki/lib/migrations`
(the npm package `@anotoki/lib`, installed from its GitHub release's tarball - `package.json`; DEV.md "The shared
library" says how to update it): the gate with its page - "The site is being updated", "A database update is
waiting" for the site's ADMIN, "not set up yet" -, which takes the router outlet as a marked template
(`<ng-template anotokiSitePages>`, `app.html`) and makes the pages only while it shows them, so nothing behind
its page runs; the interceptor that hears the server say so; and the admin panel's Migrations page
(`sites/admin/migrations/`, from `@anotoki/lib/migrations/page`). `shared/site-status.ts` wires them to this site
(who is ADMIN, how to sign in, the language on the page, the account's dates).

Every page is drawn with the family's kit (0.9.2): `src/styles.scss` takes `@anotoki/lib/styles` (the tokens,
Manrope, the base, utilities and tables) and sets this site's colours over the family's `--anotoki-*` tokens,
light and dark - never re-styling a kit piece; the pieces come each from its own entry point
(`@anotoki/lib/ui/button`, `/ui/dialog`, `/ui/forms` ...), never the `@anotoki/lib/ui` barrel; `shared/kit.ts`
gives the kit its words and the site's extra icons (`shared/icons.ts`), `shared/shell.ts` the shell its theme
(`build-analyzer-theme`, read before the first paint by the kit's `theme-boot.js` in `index.html`) and
languages. The top bar and the page's frame are the family's (`<anotoki-top-bar>`, `<anotoki-shell>`); the
kit's question and toasts sit once in `app.html`. The kit's newer pieces stand where the site drew its own: the
import wizard's stepper and disclosures, the score bars and a sync's progress (its progress bar), the characters'
link cards, a build's tab with its score as a badge, the element filter's segmented control with each element's
mark (`--el-mark-*`), and the family's icons in `shared/icons.ts`. What the kit has no piece for stays the site's,
from its tokens: `.tile` (a tile with actions inside, a piece of gear), `.section-head` and `details.disclosure`
(a sync's run on the Sources page) in `styles.scss`, the wizard's choice cards and file drop zone, and the grade
badges.

The folders are the anotoki family's, the genshin site's (the owner's decision of 2026-10-24): `api/`, `shared/`
for what both halves use, and `sites/` with the two halves - `main/`, everybody's pages, and `admin/`, the admin
panel -, each with its routes (`main.routes.ts`, `admin.routes.ts`), which `app.routes.ts` spreads into one list.

```text
src/app/
  app.ts, app.html, app.config.ts, app.routes.ts (the two halves' routes: /admin first, then everybody's)
  api/          api.ts (the family's ISSUED_AS: whose each request is), models.ts (the API's answers)
  shared/       auth.ts (the family's AnotokiAuth under the pages' names) + auth.guards.ts (sitePagesGuard) +
                sign-in.ts, feedback.ts (the family's form set up for this site, and whether a message can be sent
                now), legal.ts (the family's legal pages set up here), visitor-key.ts (somebody not signed in: the
                key, the move at sign-in), sign-in-kind.ts (whether the present sign-in was the IAM's hint's silent
                hop: the move asks with it), storage.ts, download.ts, meta-store.ts, format.ts, changelog.ts +
                changelog-dialog, kit.ts + icons.ts (the family's kit set up here), shell.ts (the family's shell:
                the theme - the account's while signed in, else this device's - and the languages),
                site-status.ts (the family's site gate and Migrations page, wired to this site), i18n/ (the
                site's words on @anotoki/lib/translations; date-patterns.ts: the account's date order and clock),
                localization-drafts.ts and build-editor-changes.ts (the sign-out's questions about unsaved
                strings and builds), char-avatar, stat-pipes.ts, top-bar/ (the anotoki family's bar,
                @anotoki/lib/shell, told this site's brand, sections and person: a phone's menu up to
                PHONE_BAR_MAX - in the admin panel the ADMIN's and an editor's rows each their own)
  sites/main/   main.routes.ts, app-layout/ (everybody's pages' frame), dashboard, import, account, character,
                builds; legal/ (legal-layout/ - the legal pages: everybody's frame with the facts - and
                legal-facts.ts); common/ (what these pages share: visitor-notice/, visitor-hero/, data-card/
                ("Your data"), build-detail, build-teams, item-card, grade-badge, score-bar, build-terms.ts,
                game-terms.ts, query-filters.ts)
  sites/admin/  admin.routes.ts, admin-layout/ (the admin panel: staff / signed out / no role), builds,
                build-editor, translations, languages, migrations - English, under /admin/; People and the admin
                tools are the family's pages, routed straight
```

Both frames are in the family's `<anotoki-shell>`, with the family's footer (`<anotoki-site-footer>`).

Every page outside `/admin/` is open to everybody - unless the IAM says this site requires a sign-in
(`AuthService.loginRequired`, from `/api/meta`): then `sitePagesGuard` (the family's `personOrVisitorGuard`) sends
anybody not signed in to the IAM first; a sign-in that came back without one is said once on `/sign-in`;
`/admin/` always asks for a sign-in (the family's `personGuard`), a visitor's too, and its ADMIN pages are the
ADMIN's (`adminGuard`). Whether a request is a person's or a visitor's is decided when `Api` issues it
(`ISSUED_AS`), never by whether a token is there when it leaves. How
the pieces work: [docs/architecture.md](../docs/architecture.md), "Frontend" and "Visitors".

The version in the footer and its "What's new" come from `public/changelog.json`; a new release adds an entry
at the top - its version MAJOR.MINOR.PATCH, the same in `package.json` and `backend/src/Version.php` - and What's
new opens by itself with the releases after the one this browser saw last, compared by their numbers
(`shared/changelog.ts`: "0.3", kept from before the versions had three numbers, is 0.3.0).

The browser check - the built app served by the backend on a throwaway database, signed in through the
stand-in IAM - is `node .claude/skills/verify-ui/scripts/run.mjs` from the repository's root (the `verify-ui`
skill).
