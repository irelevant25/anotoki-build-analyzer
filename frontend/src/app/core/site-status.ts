import { formatDate } from '@angular/common';
import { EnvironmentProviders, inject } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { provideAnotokiMigrations } from '@anotoki/lib/migrations';
import { AuthService } from './auth';
import { DateChoices, OwnDateParts, chosenMoment } from './i18n/date-patterns';
import { TranslationService } from './i18n/translation';
import { signInOrSayWhy } from './sign-in';

/** Where the site stands, {state, installed}: public, asked before anything else (the server's site gate). */
export const SITE_STATUS_URL = '/api/site-status';
/** The Migrations page's API (ADMIN): the files, one file's SQL, Apply. */
export const MIGRATIONS_API = '/api/admin/migrations';
/** The admin panel's route that shows the family's Migrations page. */
export const MIGRATIONS_ROUTE = '/admin/migrations';

/** The Migrations page's own way of writing a day and a time - as this site's page wrote them before. */
const OWN_PARTS: OwnDateParts = {
  day: (date) => formatDate(date, 'd MMM y', 'en-US'),
  time: (date) => formatDate(date, 'HH:mm', 'en-US'),
};

/**
 * When a migration was applied, as the Migrations page writes it (the admin panel is English): "6 Oct 2026,
 * 14:30" in the reader's time zone - or in the account's date order and clock where the person has chosen them,
 * as every anotoki site writes dates: "2026-10-06 2:30 pm". What is not a date comes back as it came.
 */
export function appliedAt(iso: string, chosen: DateChoices | null, locale: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) {
    return iso;
  }
  return (
    chosenMoment(date, chosen, locale, OWN_PARTS) ?? formatDate(date, 'd MMM y, HH:mm', 'en-US')
  );
}

/**
 * The anotoki family's one behaviour while the site is not ready (@anotoki/lib/migrations, the owner's decision
 * of 2026-10-07), wired to this site: who is the site's ADMIN (the token's roles), who is signed in, how one
 * signs in (to the IAM and back to the page they are on), the language on the page, and the Migrations page's
 * dates. After an Apply that leaves nothing to apply, the site's words are read again: the strings may only now
 * exist, or have new keys.
 *
 * The rest of it lives where it acts: siteStatusInterceptor among the HTTP interceptors and SiteStatus.check()
 * at the start (app.config.ts), the gate around the router outlet (app.html), the Migrations page on
 * /admin/migrations (pages/admin/migrations), the --anotoki-* colours (styles.scss).
 */
export function provideSiteStatus(): EnvironmentProviders {
  return provideAnotokiMigrations(() => {
    const auth = inject(AuthService);
    const i18n = inject(TranslationService);
    const snackBar = inject(MatSnackBar);
    return {
      statusUrl: SITE_STATUS_URL,
      apiBase: MIGRATIONS_API,
      migrationsRoute: MIGRATIONS_ROUTE,
      isAdmin: () => auth.isAdmin(),
      isSignedIn: () => auth.signedIn(),
      signIn: () => signInOrSayWhy(auth, i18n, snackBar),
      // The language actually on the page: English in the admin panel, the reader's everywhere else.
      language: () => i18n.effectiveLanguage(),
      formatDate: (iso: string) => appliedAt(iso, auth.preferences(), i18n.locale()),
      onUpToDate: () => i18n.reload(),
    };
  });
}
