import { Routes } from '@angular/router';
import { adminGuard, memberGuard } from './core/auth.guards';

export const routes: Routes = [
  {
    path: '',
    title: 'Build Analyzer',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/dashboard/dashboard-page').then((m) => m.DashboardPage),
  },
  {
    path: 'import',
    title: 'Import account · Build Analyzer',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/import/import-wizard').then((m) => m.ImportWizard),
  },
  {
    path: 'accounts/:id',
    title: 'Account · Build Analyzer',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/account/account-page').then((m) => m.AccountPage),
  },
  {
    path: 'accounts/:id/characters/:key',
    title: 'Character analysis · Build Analyzer',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/character/character-page').then((m) => m.CharacterPage),
  },
  { path: 'builds', pathMatch: 'full', redirectTo: 'builds/genshin' },
  {
    path: 'builds/:game',
    title: 'Builds · Build Analyzer',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/builds/builds-page').then((m) => m.BuildsPage),
  },
  {
    path: 'builds/:game/:key',
    title: 'Character builds · Build Analyzer',
    canActivate: [memberGuard],
    loadComponent: () => import('./pages/builds/build-character-page').then((m) => m.BuildCharacterPage),
  },
  {
    path: 'sources',
    title: 'Build sources · Build Analyzer',
    canActivate: [adminGuard],
    loadComponent: () => import('./pages/sources/sources-page').then((m) => m.SourcesPage),
  },
  {
    path: 'sign-in',
    title: 'Sign in · Build Analyzer',
    loadComponent: () => import('./pages/sign-in/sign-in-page').then((m) => m.SignInPage),
  },
  {
    // Where the IAM sends the browser back after signing in. The IAM client finishes the sign-in in the
    // app initializer and replaces this address with the page the person started from; the page itself
    // only shows while that happens (or if someone opens the address by hand).
    path: 'auth/callback',
    title: 'Signing in · Build Analyzer',
    loadComponent: () => import('./pages/sign-in/auth-callback-page').then((m) => m.AuthCallbackPage),
  },
  { path: '**', redirectTo: '' },
];
