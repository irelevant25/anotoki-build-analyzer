import { Routes } from '@angular/router';
import { adminGuard, personGuard } from '@anotoki/lib/auth';
import { anotokiUnsavedChangesGuard } from '@anotoki/lib/translations';

/**
 * The admin panel's addresses, /admin/**: its frame (AdminApp, sites/admin/app.ts - the family's admin frame, behind
 * personGuard) and its pages - Builds and a character's builds editor, Translations; for the ADMIN (adminGuard)
 * Languages, the site settings, People, Migrations and the admin tools. The map of every address, its guards and its
 * titles is app.routes.ts's, which lists this first.
 *
 * Spread there, never loaded as a chunk of its own: the routes stay in the first load, as they always were, and the
 * frame and each page keep their own lazy chunks.
 */
export const ADMIN_ROUTES: Routes = [
  {
    path: 'admin',
    canActivate: [personGuard],
    loadComponent: () => import('./app').then((m) => m.AdminApp),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'builds' },
      {
        path: 'builds',
        title: 'Builds',
        loadComponent: () => import('./builds/admin-builds-page').then((m) => m.AdminBuildsPage),
      },
      {
        // A character's builds changed by hand (the builds editor, editors and admins): leaving it with changes not
        // saved asks first - the route's guard asks the page.
        path: 'builds/:game/:key',
        title: 'Edit builds',
        canDeactivate: [anotokiUnsavedChangesGuard],
        loadComponent: () => import('./build-editor/build-editor-page').then((m) => m.BuildEditorPage),
      },
      // The Sources page's address, before the builds came in files: an old bookmark lands on the Builds page.
      { path: 'sources', pathMatch: 'full', redirectTo: 'builds' },
      {
        path: 'translations',
        title: 'Translations',
        canDeactivate: [anotokiUnsavedChangesGuard],
        loadComponent: () => import('./translations/translations-page').then((m) => m.TranslationsPage),
      },
      {
        path: 'languages',
        title: 'Languages',
        canActivate: [adminGuard],
        canDeactivate: [anotokiUnsavedChangesGuard],
        loadComponent: () => import('./languages/languages-page').then((m) => m.LanguagesPage),
      },
      {
        // The anotoki family's Site settings page (anotoki-lib 0.11.0, from its own entry point - a lazy chunk;
        // app.config.ts gives it the languages a message is written in): a card for each part the site offers - Getting
        // in, Announcement, Page access - each opening its dialog. The server's part is App\Web\SitePages.
        path: 'site-settings',
        title: 'Site settings',
        canActivate: [adminGuard],
        loadComponent: () => import('@anotoki/lib/admin/site-settings').then((m) => m.SiteSettingsPageComponent),
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
        loadComponent: () => import('./migrations/migrations-page').then((m) => m.MigrationsPage),
      },
      // The anotoki family's admin tools, each from its own entry point (lazy chunks; app.config.ts sets them up),
      // for the site's ADMIN alone - the server refuses anybody else (403 admin_only).
      {
        path: 'errors',
        title: 'Error log',
        canActivate: [adminGuard],
        loadComponent: () => import('@anotoki/lib/admin/errors').then((m) => m.ErrorLogPageComponent),
      },
      {
        path: 'audit',
        title: 'Audit log',
        canActivate: [adminGuard],
        loadComponent: () => import('@anotoki/lib/admin/audit').then((m) => m.AuditLogPageComponent),
      },
      {
        path: 'feedback',
        title: 'Feedback',
        canActivate: [adminGuard],
        loadComponent: () => import('@anotoki/lib/admin/feedback').then((m) => m.FeedbackPageComponent),
      },
      {
        path: 'backups',
        title: 'Backups',
        canActivate: [adminGuard],
        loadComponent: () => import('@anotoki/lib/admin/backups').then((m) => m.BackupsPageComponent),
      },
    ],
  },
];
