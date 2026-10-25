import { EnvironmentProviders, inject } from '@angular/core';
import { Router } from '@angular/router';
import { provideAnotokiMigrations } from '@anotoki/lib/migrations';
import { TranslationService as LibraryTranslations, siteStatusWords } from '@anotoki/lib/translations';
import type { AnotokiDateChoices } from '@anotoki/lib/ui/config';
import { formatDateTime } from '@anotoki/lib/ui/dates';
import { AuthService } from './auth';
import { TranslationService } from './i18n/translation';
import { signInOrSayWhy } from './sign-in';
import { OPEN_WHILE_CLOSED, STAFF_ROUTE } from './site-settings';

/** Where the site stands, {state, installed}: public, asked before anything else (the server's site gate). */
export const SITE_STATUS_URL = '/api/site-status';
/** The Migrations page's API (ADMIN): the files, one file's SQL, Apply. */
export const MIGRATIONS_API = '/api/admin/migrations';
/** The admin panel's route that shows the family's Migrations page. */
export const MIGRATIONS_ROUTE = '/admin/migrations';

/**
 * When a migration was applied, as the Migrations page writes it - and the People page and the admin tools their
 * dates (app.config.ts): "6 Oct 2026, 14:30" in the reader's time zone - or in the account's date order and clock
 * where the person has chosen them, as every anotoki site writes dates: "2026-10-06 2:30 pm". The family's dates
 * (@anotoki/lib/ui/dates' formatDateTime(), the short day) in the page's locale - English as en-GB in the admin panel,
 * so September is "Sept", as the family writes it. What is not a date comes back as it came.
 */
export function appliedAt(iso: string, chosen: AnotokiDateChoices | null, locale: string): string {
  return formatDateTime(iso, { locale, choices: chosen });
}

/**
 * The anotoki family's one behaviour while the site is not ready (@anotoki/lib/migrations, the owner's decision
 * of 2026-10-07), wired to this site: who is the site's ADMIN (the token's roles), who is signed in, how one
 * signs in (to the IAM and back to the page they are on - or, from the closed sign's Sign in at the staff route, back
 * to the dashboard), the language on the page and its words (the database's anotoki.siteStatus.* strings, the
 * library's own under them), and the Migrations page's dates. After an Apply that leaves nothing to apply, the
 * site's words are read again: the strings may only now exist, or have new keys.
 *
 * While the site's ADMIN has closed the site for maintenance (anotoki-lib 0.11.0: the site settings' Getting in,
 * shared/site-settings.ts), the gate draws the family's closed sign for everybody but that ADMIN, who uses the site as
 * it is; the sign offers Sign in at STAFF_ROUTE alone, and the pages of OPEN_WHILE_CLOSED stay anybody's.
 *
 * The rest of it lives where it acts: siteStatusInterceptor among the HTTP interceptors and SiteStatus.check()
 * at the start (app.config.ts), the gate around the router outlet (app.html), the Migrations page on
 * /admin/migrations (sites/admin/migrations), the --anotoki-* colours (styles.scss).
 */
export function provideSiteStatus(): EnvironmentProviders {
  return provideAnotokiMigrations(() => {
    const auth = inject(AuthService);
    const i18n = inject(TranslationService);
    // The same service, as the library's own function takes it (this site's view of it types the keys).
    const words = inject(LibraryTranslations);
    const router = inject(Router);
    return {
      statusUrl: SITE_STATUS_URL,
      apiBase: MIGRATIONS_API,
      migrationsRoute: MIGRATIONS_ROUTE,
      isAdmin: () => auth.isAdmin(),
      isSignedIn: () => auth.signedIn(),
      signIn: (returnTo?: string) => signInOrSayWhy(auth, router, returnTo),
      staffRoute: STAFF_ROUTE,
      openWhileClosed: OPEN_WHILE_CLOSED,
      // The language actually on the page: English in the admin panel, the reader's everywhere else.
      language: () => i18n.effectiveLanguage(),
      formatDate: (iso: string) => appliedAt(iso, auth.preferences(), i18n.locale()),
      onUpToDate: () => i18n.reload(),
      // The status page's words from the database (anotoki.siteStatus.*: an editor's rewording reaches them), in
      // the language on the page; read whenever the page draws, so it follows a change of either.
      get words() {
        return siteStatusWords(words);
      },
    };
  });
}
