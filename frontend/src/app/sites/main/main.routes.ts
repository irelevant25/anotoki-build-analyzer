import { inject } from '@angular/core';
import { CanActivateFn, Router, Routes } from '@angular/router';
import { AuthCallbackComponent, SignInFailureComponent } from '@anotoki/lib/auth';
import { SiteStatus } from '@anotoki/lib/migrations';
import { pageAccessGuard } from '@anotoki/lib/site-settings';
import type { LibraryKey } from '@anotoki/lib/ui/config';
import { CALLBACK_PATH, SIGN_IN_FAILED_PATH, sitePagesGuard } from '../../shared/auth.guards';
import { STAFF_ROUTE } from '../../shared/site-settings';
import { AppLayout } from './app-layout/app-layout';

/** The legal pages' frame, loaded with the first of them that is opened (and the site's facts with it, out of the first load). */
const legalFrame = () => import('./legal/legal-layout/legal-layout').then((m) => m.LegalLayout);

/**
 * The staff route (/staff, shared/site-settings.ts' STAFF_ROUTE): the administrators' way in while the site is closed
 * for maintenance. While the site is not open to this reader the address stays - the router never takes it for an
 * unknown one, whose "**" would move it to the dashboard - and the family's gate draws the closed sign over it, with
 * Sign in there alone (back to the dashboard afterwards). Open - the site ready, or the site's ADMIN signed in while it
 * is closed, who uses it as it is - there is nothing at the address: the dashboard.
 */
export const staffRouteGuard: CanActivateFn = () => inject(SiteStatus).blocked() || inject(Router).createUrlTree(['/']);

/**
 * The public site's addresses - every one but /admin/**: About, Support and the legal pages, each in the frame that
 * gives them the site's facts; the staff route; the admin pages' addresses from before the admin panel had one of its
 * own; and everybody's pages in their frame (the AppLayout, behind sitePagesGuard), ending with the "**". The map of
 * every address, its guards and its titles is app.routes.ts's, which lists these after /admin, as they stand and in
 * this order.
 *
 * Page access (the site settings, shared/site-settings.ts) governs everybody's pages - the dashboard, the import, an
 * account and a character of it, the builds browser and a character's builds: the family's pageAccessGuard, a canMatch
 * on each, so a page closed to the reader falls through to the "**" and the dashboard, as an unknown address does -
 * never a redirect that says the page exists. Where the dashboard itself is closed to them, the router follows no
 * second redirect, and the last "**" draws the page that says it is not open (sites/main/not-open). The legal pages,
 * About, Support, the staff route, the sign-in's way back and its failure's screen are nobody's to govern (the
 * library's reserved pages and this site's, App\Web\SitePages::RESERVED_PAGES - the server refuses a row for one).
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
  // The administrators' way in while the site is closed (the closed sign's Sign in): nothing of its own to draw - while
  // the site is open to the reader the guard sends them to the dashboard, and while it is closed the gate draws over it.
  { path: STAFF_ROUTE.slice(1), canActivate: [staffRouteGuard], children: [] },
  // The admin pages' addresses before the admin panel had one of its own (the Sources page's is the Builds page's now) -
  // before everybody's pages, whose "**" would take them.
  { path: 'sources', pathMatch: 'full', redirectTo: 'admin/builds' },
  { path: 'translations', pathMatch: 'full', redirectTo: 'admin/translations' },
  { path: 'languages', pathMatch: 'full', redirectTo: 'admin/languages' },
  {
    // Everybody's pages: the top bar with the app's sections, the page, the footer - each page governed by Page access.
    path: '',
    component: AppLayout,
    canActivateChild: [sitePagesGuard],
    children: [
      {
        path: '',
        pathMatch: 'full',
        canMatch: [pageAccessGuard],
        loadComponent: () => import('./dashboard/dashboard-page').then((m) => m.DashboardPage),
      },
      {
        path: 'import',
        title: 'title.import',
        canMatch: [pageAccessGuard],
        loadComponent: () => import('./import/import-wizard').then((m) => m.ImportWizard),
      },
      {
        path: 'accounts/:id',
        title: 'title.account',
        canMatch: [pageAccessGuard],
        loadComponent: () => import('./account/account-page').then((m) => m.AccountPage),
      },
      {
        path: 'accounts/:id/characters/:key',
        title: 'title.character',
        canMatch: [pageAccessGuard],
        loadComponent: () => import('./character/character-page').then((m) => m.CharacterPage),
      },
      { path: 'builds', pathMatch: 'full', redirectTo: 'builds/genshin' },
      {
        path: 'builds/:game',
        title: 'title.builds',
        canMatch: [pageAccessGuard],
        loadComponent: () => import('./builds/builds-page').then((m) => m.BuildsPage),
      },
      {
        path: 'builds/:game/:key',
        title: 'title.buildCharacter',
        canMatch: [pageAccessGuard],
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
      // An unknown address - or a page closed to the reader - goes to the dashboard.
      { path: '**', redirectTo: '' },
      // Reached only where the dashboard is closed to the reader too (the router follows one redirect, no second).
      { path: '**', title: 'title.notOpen', loadComponent: () => import('./not-open/not-open').then((m) => m.NotOpen) },
    ],
  },
];
