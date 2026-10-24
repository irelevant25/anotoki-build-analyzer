import { Routes } from '@angular/router';
import { AuthCallbackComponent, SignInFailureComponent } from '@anotoki/lib/auth';
import type { LibraryKey } from '@anotoki/lib/ui/config';
import { CALLBACK_PATH, SIGN_IN_FAILED_PATH, sitePagesGuard } from '../../shared/auth.guards';
import { AppLayout } from './app-layout/app-layout';

/** The legal pages' frame, loaded with the first of them that is opened (and the site's facts with it, out of the first load). */
const legalFrame = () => import('./legal/legal-layout/legal-layout').then((m) => m.LegalLayout);

/**
 * The public site's addresses - every one but /admin/**: About, Support and the legal pages, each in the frame that
 * gives them the site's facts; everybody's pages in theirs (the AppLayout, behind sitePagesGuard); the admin pages'
 * addresses from before the admin panel had one of its own; and the "**" at the end. The map of every address, its
 * guards and its titles is app.routes.ts's, which lists these after /admin, as they stand and in this order.
 *
 * Spread there, never loaded as a chunk of their own: everybody's pages' frame stays in the first load, as it always
 * was, and each page keeps its own lazy chunk.
 */
export const MAIN_ROUTES: Routes = [
  // About, Support and the legal pages: anybody's, each a path of its own in the frame that gives them the site's facts (see app.routes.ts).
  {
    path: 'about',
    loadComponent: legalFrame,
    children: [
      {
        path: '',
        title: 'anotoki.about.title' satisfies LibraryKey,
        loadComponent: () => import('@anotoki/lib/legal/about').then((m) => m.AboutPageComponent),
      },
    ],
  },
  {
    path: 'support',
    loadComponent: legalFrame,
    children: [
      {
        path: '',
        title: 'anotoki.support.title' satisfies LibraryKey,
        loadComponent: () => import('@anotoki/lib/legal/support').then((m) => m.SupportPageComponent),
      },
    ],
  },
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
        loadComponent: () => import('./dashboard/dashboard-page').then((m) => m.DashboardPage),
      },
      {
        path: 'import',
        title: 'title.import',
        loadComponent: () => import('./import/import-wizard').then((m) => m.ImportWizard),
      },
      {
        path: 'accounts/:id',
        title: 'title.account',
        loadComponent: () => import('./account/account-page').then((m) => m.AccountPage),
      },
      {
        path: 'accounts/:id/characters/:key',
        title: 'title.character',
        loadComponent: () => import('./character/character-page').then((m) => m.CharacterPage),
      },
      { path: 'builds', pathMatch: 'full', redirectTo: 'builds/genshin' },
      {
        path: 'builds/:game',
        title: 'title.builds',
        loadComponent: () => import('./builds/builds-page').then((m) => m.BuildsPage),
      },
      {
        path: 'builds/:game/:key',
        title: 'title.buildCharacter',
        loadComponent: () => import('./builds/build-character-page').then((m) => m.BuildCharacterPage),
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
  // The admin pages' addresses before the admin panel had one of its own (the Sources page's is the Builds page's now).
  { path: 'sources', pathMatch: 'full', redirectTo: 'admin/builds' },
  { path: 'translations', pathMatch: 'full', redirectTo: 'admin/translations' },
  { path: 'languages', pathMatch: 'full', redirectTo: 'admin/languages' },
  { path: '**', redirectTo: '' },
];
