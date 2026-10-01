import { Routes } from '@angular/router';
import { adminGuard, memberGuard } from './core/auth.guards';
import { unsavedChangesGuard } from './core/unsaved-changes.guard';

/**
 * A `title` here is a translation key on the pages members read: the title strategy words it in the
 * language on the page and adds the brand (core/i18n/title-strategy.ts). The English pages - /sources,
 * and the owner's /translations and /languages - keep a plain English title. A route without one (the
 * dashboard) is the brand alone.
 */
export const routes: Routes = [
  {
    path: '',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/dashboard/dashboard-page').then((m) => m.DashboardPage),
  },
  {
    path: 'import',
    title: 'title.import',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/import/import-wizard').then((m) => m.ImportWizard),
  },
  {
    path: 'accounts/:id',
    title: 'title.account',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/account/account-page').then((m) => m.AccountPage),
  },
  {
    path: 'accounts/:id/characters/:key',
    title: 'title.character',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/character/character-page').then((m) => m.CharacterPage),
  },
  { path: 'builds', pathMatch: 'full', redirectTo: 'builds/genshin' },
  {
    path: 'builds/:game',
    title: 'title.builds',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/builds/builds-page').then((m) => m.BuildsPage),
  },
  {
    path: 'builds/:game/:key',
    title: 'title.buildCharacter',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/builds/build-character-page').then((m) => m.BuildCharacterPage),
  },
  {
    path: 'sources',
    title: 'Build sources',
    canActivate: [adminGuard],
    loadComponent: () => import('./pages/sources/sources-page').then((m) => m.SourcesPage),
  },
  {
    path: 'translations',
    title: 'Translations',
    canActivate: [adminGuard],
    canDeactivate: [unsavedChangesGuard],
    loadComponent: () => import('./pages/translations/translations-page').then((m) => m.TranslationsPage),
  },
  {
    path: 'languages',
    title: 'Languages',
    canActivate: [adminGuard],
    canDeactivate: [unsavedChangesGuard],
    loadComponent: () => import('./pages/languages/languages-page').then((m) => m.LanguagesPage),
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
    loadComponent: () => import('./pages/sign-in/auth-callback-page').then((m) => m.AuthCallbackPage),
  },
  { path: '**', redirectTo: '' },
];
