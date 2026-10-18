import { Routes } from '@angular/router';
import {
  AuthCallbackComponent,
  SignInFailureComponent,
  adminGuard,
  personGuard,
} from '@anotoki/lib/auth';
import { anotokiUnsavedChangesGuard } from '@anotoki/lib/translations';
import { CALLBACK_PATH, SIGN_IN_FAILED_PATH, sitePagesGuard } from './core/auth.guards';
import { AppLayout } from './layouts/app-layout/app-layout';

/**
 * A `title` here is a translation key on the pages members read: the title strategy words it in the
 * language on the page and adds the brand (core/i18n/title-strategy.ts). The admin panel - /admin/sources,
 * /admin/translations, /admin/languages, /admin/people, /admin/migrations, /admin/errors, /admin/audit,
 * /admin/feedback, /admin/backups - keeps a plain English title ("Migrations · Admin panel · Build Analyzer").
 * A route without one (the dashboard) is the brand alone.
 *
 * Every page outside /admin is open to everybody: somebody not signed in uses the site as a visitor
 * (core/visitor-key.ts), and the pages say what is kept for them - unless the IAM says this site requires a
 * sign-in: then they ask for one first (sitePagesGuard, on every child of the AppLayout - the anotoki family's
 * personOrVisitorGuard). The admin panel always asks for a sign-in (personGuard); its layout tells editors and
 * admins from the rest, the ADMIN pages are ADMIN's (adminGuard: an editor goes to the Sources page), and the
 * server decides.
 *
 * The legal pages - Terms, Privacy, Contact: the anotoki family's (@anotoki/lib/legal/pages) with this site's
 * facts, which their frame gives them (layouts/legal-layout: everybody's pages' frame) - are anybody's, also while
 * the IAM requires signing in here: no guard, nothing of anybody's game accounts on them. Each is a path of its own
 * with the frame as its parent - never one parent with an empty path holding the three: the router takes an address
 * it has read whole ("/") for such a parent when none of its children match, and would draw the empty frame at "/"
 * with no guard (the survey's lesson, 2026-10-17; app.routes.spec.ts). They come before everybody's pages and the
 * "**" at the end, which would take them. Their paths are the library's (DEFAULT_LEGAL_PATHS, core/legal.ts), the
 * footer's links go there.
 */

/** The legal pages' frame, loaded with the first of them that is opened (and the site's facts with it, out of the first load). */
const legalFrame = () => import('./layouts/legal-layout/legal-layout').then((m) => m.LegalLayout);

export const routes: Routes = [
  // The legal pages: anybody's, each a path of its own in the frame that gives them the site's facts (see above).
  {
    path: 'terms',
    loadComponent: legalFrame,
    children: [
      {
        path: '',
        title: 'title.terms',
        loadComponent: () => import('@anotoki/lib/legal/pages').then((m) => m.TermsPageComponent),
      },
    ],
  },
  {
    path: 'privacy',
    loadComponent: legalFrame,
    children: [
      {
        path: '',
        title: 'title.privacy',
        loadComponent: () => import('@anotoki/lib/legal/pages').then((m) => m.PrivacyPageComponent),
      },
    ],
  },
  {
    path: 'contact',
    loadComponent: legalFrame,
    children: [
      {
        path: '',
        title: 'title.contact',
        loadComponent: () => import('@anotoki/lib/legal/pages').then((m) => m.ContactPageComponent),
      },
    ],
  },
  {
    // Everybody's pages: the top bar with the app's sections, the page, the footer.
    path: '',
    component: AppLayout,
    canActivateChild: [sitePagesGuard],
    children: [
      {
        path: '',
        pathMatch: 'full',
        loadComponent: () =>
          import('./pages/dashboard/dashboard-page').then((m) => m.DashboardPage),
      },
      {
        path: 'import',
        title: 'title.import',
        loadComponent: () => import('./pages/import/import-wizard').then((m) => m.ImportWizard),
      },
      {
        path: 'accounts/:id',
        title: 'title.account',
        loadComponent: () => import('./pages/account/account-page').then((m) => m.AccountPage),
      },
      {
        path: 'accounts/:id/characters/:key',
        title: 'title.character',
        loadComponent: () =>
          import('./pages/character/character-page').then((m) => m.CharacterPage),
      },
      { path: 'builds', pathMatch: 'full', redirectTo: 'builds/genshin' },
      {
        path: 'builds/:game',
        title: 'title.builds',
        loadComponent: () => import('./pages/builds/builds-page').then((m) => m.BuildsPage),
      },
      {
        path: 'builds/:game/:key',
        title: 'title.buildCharacter',
        loadComponent: () =>
          import('./pages/builds/build-character-page').then((m) => m.BuildCharacterPage),
      },
      {
        // The screen of a sign-in that came back without one, could not start, or cannot be set up (the anotoki
        // family's <anotoki-sign-in-failure>: why, from the failure's code alone - Try again and Back), in the
        // site's frame. The guards send the browser here (the auth module's failureRoute); opened by hand, with
        // nothing to say, it goes on to the dashboard.
        path: SIGN_IN_FAILED_PATH,
        title: 'title.signIn',
        component: SignInFailureComponent,
      },
      {
        // Where the IAM sends the browser back after signing in. The auth module finishes the sign-in before the
        // router starts and replaces this address with the page the person started from; the page itself
        // ("Signing you in…", <anotoki-auth-callback>) shows only while that happens, or when the address is
        // opened by hand. Search engines are asked not to index it, and robots.txt keeps them off /auth/.
        path: CALLBACK_PATH,
        title: 'title.signingIn',
        component: AuthCallbackComponent,
      },
    ],
  },
  {
    path: 'admin',
    canActivate: [personGuard],
    loadComponent: () => import('./layouts/admin-layout/admin-layout').then((m) => m.AdminLayout),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'sources' },
      {
        path: 'sources',
        title: 'Build sources',
        loadComponent: () =>
          import('./pages/admin/sources/sources-page').then((m) => m.SourcesPage),
      },
      {
        path: 'translations',
        title: 'Translations',
        canDeactivate: [anotokiUnsavedChangesGuard],
        loadComponent: () =>
          import('./pages/admin/translations/translations-page').then((m) => m.TranslationsPage),
      },
      {
        path: 'languages',
        title: 'Languages',
        canActivate: [adminGuard],
        canDeactivate: [anotokiUnsavedChangesGuard],
        loadComponent: () =>
          import('./pages/admin/languages/languages-page').then((m) => m.LanguagesPage),
      },
      {
        // The anotoki family's People page, from its own entry point (a lazy chunk; app.config.ts sets it up).
        path: 'people',
        title: 'People',
        canActivate: [adminGuard],
        loadComponent: () => import('@anotoki/lib/people/page').then((m) => m.PeoplePageComponent),
      },
      {
        path: 'migrations',
        title: 'Migrations',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('./pages/admin/migrations/migrations-page').then((m) => m.MigrationsPage),
      },
      // The anotoki family's admin tools, each from its own entry point (lazy chunks; app.config.ts sets them up),
      // for the site's ADMIN alone - the server refuses anybody else (403 admin_only).
      {
        path: 'errors',
        title: 'Error log',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('@anotoki/lib/admin/errors').then((m) => m.ErrorLogPageComponent),
      },
      {
        path: 'audit',
        title: 'Audit log',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('@anotoki/lib/admin/audit').then((m) => m.AuditLogPageComponent),
      },
      {
        path: 'feedback',
        title: 'Feedback',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('@anotoki/lib/admin/feedback').then((m) => m.FeedbackPageComponent),
      },
      {
        path: 'backups',
        title: 'Backups',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('@anotoki/lib/admin/backups').then((m) => m.BackupsPageComponent),
      },
    ],
  },
  // The admin pages' addresses before the admin panel had one of its own.
  { path: 'sources', pathMatch: 'full', redirectTo: 'admin/sources' },
  { path: 'translations', pathMatch: 'full', redirectTo: 'admin/translations' },
  { path: 'languages', pathMatch: 'full', redirectTo: 'admin/languages' },
  { path: '**', redirectTo: '' },
];
