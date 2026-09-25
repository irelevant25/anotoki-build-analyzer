import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: '',
    title: 'Hoyo Analyzer',
    loadComponent: () => import('./pages/dashboard/dashboard-page').then((m) => m.DashboardPage),
  },
  {
    path: 'import',
    title: 'Import account · Hoyo Analyzer',
    loadComponent: () => import('./pages/import/import-wizard').then((m) => m.ImportWizard),
  },
  {
    path: 'accounts/:id',
    title: 'Account · Hoyo Analyzer',
    loadComponent: () => import('./pages/account/account-page').then((m) => m.AccountPage),
  },
  {
    path: 'accounts/:id/characters/:key',
    title: 'Character analysis · Hoyo Analyzer',
    loadComponent: () => import('./pages/character/character-page').then((m) => m.CharacterPage),
  },
  { path: 'builds', pathMatch: 'full', redirectTo: 'builds/genshin' },
  {
    path: 'builds/:game',
    title: 'Builds · Hoyo Analyzer',
    loadComponent: () => import('./pages/builds/builds-page').then((m) => m.BuildsPage),
  },
  {
    path: 'builds/:game/:key',
    title: 'Character builds · Hoyo Analyzer',
    loadComponent: () => import('./pages/builds/build-character-page').then((m) => m.BuildCharacterPage),
  },
  {
    path: 'sources',
    title: 'Build sources · Hoyo Analyzer',
    loadComponent: () => import('./pages/sources/sources-page').then((m) => m.SourcesPage),
  },
  { path: '**', redirectTo: '' },
];
