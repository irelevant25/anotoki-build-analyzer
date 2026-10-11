import { Routes } from '@angular/router';
import { anotokiUnsavedChangesGuard } from '@anotoki/lib/translations';
import { adminOnlyGuard, signInGuard, siteSignInGuard } from './core/auth.guards';
import { AppLayout } from './layouts/app-layout/app-layout';

/**
 * A `title` here is a translation key on the pages members read: the title strategy words it in the
 * language on the page and adds the brand (core/i18n/title-strategy.ts). The admin panel - /admin/sources,
 * /admin/translations, /admin/languages, /admin/people, /admin/migrations - keeps a plain English title
 * ("Migrations · Admin panel · Build Analyzer"). A route without one (the dashboard) is the brand alone.
 *
 * Every page outside /admin is open to everybody: somebody not signed in uses the site as a visitor
 * (core/visitor-key.ts), and the pages say what is kept for them - unless the IAM says this site requires a
 * sign-in: then they ask for one first (siteSignInGuard, on every child of the AppLayout). The admin panel
 * always asks for a sign-in (signInGuard); its layout tells editors and admins from the rest, and the
 * server decides.
 */
export const routes: Routes = [
  {
    // Everybody's pages: the top bar with the app's sections, the page, the footer.
    path: '',
    component: AppLayout,
    canActivateChild: [siteSignInGuard],
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
        path: 'sign-in',
        title: 'title.signIn',
        loadComponent: () => import('./pages/sign-in/sign-in-page').then((m) => m.SignInPage),
      },
      {
        // Where the IAM sends the browser back after signing in. The IAM client finishes the sign-in in the
        // app initializer and replaces this address with the page the person started from; the page itself
        // only shows while that happens (or if someone opens the address by hand).
        path: 'auth/callback',
        title: 'title.signingIn',
        loadComponent: () =>
          import('./pages/sign-in/auth-callback-page').then((m) => m.AuthCallbackPage),
      },
    ],
  },
  {
    path: 'admin',
    canActivate: [signInGuard],
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
        canActivate: [adminOnlyGuard],
        canDeactivate: [anotokiUnsavedChangesGuard],
        loadComponent: () =>
          import('./pages/admin/languages/languages-page').then((m) => m.LanguagesPage),
      },
      {
        path: 'people',
        title: 'People',
        canActivate: [adminOnlyGuard],
        loadComponent: () => import('./pages/admin/people/people-page').then((m) => m.PeoplePage),
      },
      {
        path: 'migrations',
        title: 'Migrations',
        canActivate: [adminOnlyGuard],
        loadComponent: () =>
          import('./pages/admin/migrations/migrations-page').then((m) => m.MigrationsPage),
      },
    ],
  },
  // The admin pages' addresses before the admin panel had one of its own.
  { path: 'sources', pathMatch: 'full', redirectTo: 'admin/sources' },
  { path: 'translations', pathMatch: 'full', redirectTo: 'admin/translations' },
  { path: 'languages', pathMatch: 'full', redirectTo: 'admin/languages' },
  { path: '**', redirectTo: '' },
];
