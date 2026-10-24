import { Routes } from '@angular/router';
import { ADMIN_ROUTES } from './sites/admin/admin.routes';
import { MAIN_ROUTES } from './sites/main/main.routes';

/**
 * A `title` here is a translation key on the pages members read: the title strategy words it in the
 * language on the page and adds the brand (shared/i18n/title-strategy.ts). The admin panel - /admin/builds (and a
 * character's builds editor, /admin/builds/:game/:key), /admin/translations, /admin/languages, /admin/people,
 * /admin/migrations, /admin/errors, /admin/audit, /admin/feedback, /admin/backups - keeps a plain English title
 * ("Migrations · Admin panel · Build Analyzer").
 * A route without one (the dashboard) is the brand alone.
 *
 * Every page outside /admin is open to everybody: somebody not signed in uses the site as a visitor
 * (shared/visitor-key.ts), and the pages say what is kept for them - unless the IAM says this site requires a
 * sign-in: then they ask for one first (sitePagesGuard, on every child of the AppLayout - the anotoki family's
 * personOrVisitorGuard). The admin panel always asks for a sign-in (personGuard); its layout tells editors and
 * admins from the rest, the ADMIN pages are ADMIN's (adminGuard: an editor goes to the Builds page), and the
 * server decides.
 *
 * The legal pages - Terms, Privacy, Contact: the anotoki family's (@anotoki/lib/legal/pages) with this site's
 * facts, which their frame gives them (sites/main/legal/legal-layout: everybody's pages' frame) - are anybody's,
 * also while the IAM requires signing in here: no guard, nothing of anybody's game accounts on them. Each is a path
 * of its own with the frame as its parent - never one parent with an empty path holding the three: the router takes
 * an address it has read whole ("/") for such a parent when none of its children match, and would draw the empty
 * frame at "/" with no guard (the survey's lesson, 2026-10-17; app.routes.spec.ts). They come before everybody's
 * pages and the "**" at the end, which would take them. Their paths are the library's (DEFAULT_LEGAL_PATHS,
 * shared/legal.ts), the footer's links go there.
 *
 * The About and Support pages (anotoki-lib 0.8.0: @anotoki/lib/legal/about, /legal/support - the same on every
 * anotoki site, in the library's words) are anybody's too, in the same frame, the same way; the footer links them
 * first. Their titles are the library's own keys, the words the pages' headings say (shared/i18n/title-strategy.ts).
 *
 * The routes come in two halves, as on every anotoki site (the genshin site's folders, the owner's decision of
 * 2026-10-24): the admin panel's - /admin, its frame and its pages - in sites/admin/admin.routes.ts, and every other
 * address, the public site's, in sites/main/main.routes.ts. Both are spread in here as they stand, neither a
 * loadChildren of its own, so the first load and every lazy chunk are what they were. /admin comes first: the public
 * site's routes end with the "**", which would take it.
 */
export const routes: Routes = [...ADMIN_ROUTES, ...MAIN_ROUTES];
