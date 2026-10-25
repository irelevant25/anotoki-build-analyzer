import { EnvironmentProviders, inject } from '@angular/core';
import { DEFAULT_OPEN_WHILE_CLOSED } from '@anotoki/lib/migrations';
import { provideAnotokiSiteSettings } from '@anotoki/lib/site-settings';
import { AuthService } from './auth';
import { SIGN_IN_FAILED_PATH } from './auth.guards';

/**
 * Where this browser keeps the mark of the announcement its reader hid - a mark of its words and its kind, never the
 * words: hidden until they change. The Privacy page names it (sites/main/legal/legal-facts.ts: the library's storage
 * preset 'announcement').
 */
export const ANNOUNCEMENT_KEY = 'build-analyzer-announcement';

/**
 * The administrators' way in while the site is closed for maintenance: the family's closed sign offers Sign in at this
 * address alone. A route of everybody's pages (sites/main/main.routes.ts) - so the router keeps the address while the
 * site is closed, where its "**" would have moved it to the dashboard -, which takes anybody to the dashboard while the
 * site is open to them.
 */
export const STAFF_ROUTE = '/staff';

/**
 * What anybody reaches while the site is closed for maintenance, each with everything under it: the family's - the
 * sign-in's way back (/auth: the callback), the legal pages, About and Support - and the screen of a sign-in that did
 * not work, at this site's own address (/sign-in, not under /auth). The server's reserved pages are the same
 * (App\Web\SitePages::RESERVED_PAGES).
 */
export const OPEN_WHILE_CLOSED: readonly string[] = [...DEFAULT_OPEN_WHILE_CLOSED, `/${SIGN_IN_FAILED_PATH}`];

/**
 * The anotoki family's site settings (@anotoki/lib/site-settings, anotoki-lib 0.11.0 - the owner's request of
 * 2026-10-25: genshin's switches on every site) wired to this site's sign-in: who the reader is - their roles from the
 * token, and whether anybody is signed in (a visitor is nobody: a page for people signed in is not theirs) - for Page
 * access, and where the announcement's mark is kept. The settings are read once as the app starts (app.config.ts:
 * SiteSettings.load(), beside where the site stands, before the router's first navigation - so a page Page access
 * closes is never drawn for a moment), and again after an administrator saves them (the library's admin page).
 *
 * What it draws: the announcement above everybody's pages' bar (<anotoki-announcement>, sites/main/app-layout), what
 * the administrators alone read above a page (<anotoki-page-notice>: the site is closed, the page is switched off or
 * kept for some readers - everybody's pages' frame, and the admin panel's for the first), and the guard on the public
 * pages (pageAccessGuard, a canMatch: a page closed to the reader falls through to the dashboard, as an unknown address
 * does). Getting in is the migrations module's: the gate draws the closed sign (shared/site-status.ts). Nothing here is
 * a security boundary: the server refuses a page's API paths itself (the library's PageAccessGate).
 */
export function provideSiteSettings(): EnvironmentProviders {
  return provideAnotokiSiteSettings(() => {
    const auth = inject(AuthService);
    return { roles: () => auth.roles(), signedIn: () => auth.signedIn(), storageKey: ANNOUNCEMENT_KEY };
  });
}
