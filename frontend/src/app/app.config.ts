import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, inject, provideAppInitializer, provideBrowserGlobalErrorListeners } from '@angular/core';
import { TitleStrategy, provideRouter, withComponentInputBinding, withInMemoryScrolling } from '@angular/router';
import { provideAnotokiAdmin } from '@anotoki/lib/admin';
import { ANOTOKI_AUTH_CLIENT_FACTORY, AnotokiAuth, anotokiAuthInterceptor, provideAnotokiAuth } from '@anotoki/lib/auth';
import { provideAnotokiErrorReport } from '@anotoki/lib/error-report';
import { SiteStatus, siteStatusInterceptor } from '@anotoki/lib/migrations';
import { provideAnotokiPeople } from '@anotoki/lib/people';

import { routes } from './app.routes';
import { AuthService } from './shared/auth';
import { SIGN_IN_FAILED_PATH } from './shared/auth.guards';
import { Changelog } from './shared/changelog';
import { provideFeedback } from './shared/feedback';
import { provideKit } from './shared/kit';
import { provideLegal } from './shared/legal';
import { AppTitleStrategy } from './shared/i18n/title-strategy';
import { TranslationService } from './shared/i18n/translation';
import { provideTranslations } from './shared/i18n/translations.config';
import { MetaStore } from './shared/meta-store';
import { provideShell } from './shared/shell';
import { signInKindClientFactory } from './shared/sign-in-kind';
import { MIGRATIONS_ROUTE, SITE_STATUS_URL, appliedAt, provideSiteStatus } from './shared/site-status';
import { VisitorKey } from './shared/visitor-key';

/** "1 visitor", "3 game accounts" - the People page is English, as the whole admin panel. */
function counted(n: number, noun: string): string {
  return `${n} ${noun}${n === 1 ? '' : 's'}`;
}

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding(), withInMemoryScrolling({ scrollPositionRestoration: 'top' })),
    // siteStatusInterceptor first: it hears every answer that says the site is not set up or is being updated
    // (and the request still fails as it did), whoever the request was for. Then the anotoki family's auth
    // interceptor: who each request of this site's API is - the person's token, or the visitor's key.
    provideHttpClient(withFetch(), withInterceptors([siteStatusInterceptor, anotokiAuthInterceptor])),
    // The errors the pages meet, into the server's error log (@anotoki/lib/error-report: POST /api/client-errors) -
    // scrubbed before they leave the page, each once, at most five a page load, never an HTTP failure - through
    // the app's HttpClient, so the interceptors above treat a report as any request of this site's: the person's
    // token while somebody is signed in (the server counts a person by their account), and the site's status heard.
    // With provideBrowserGlobalErrorListeners() above, window's errors and promises nobody caught come too.
    provideAnotokiErrorReport(),
    // Signing in, through the anotoki IAM (@anotoki/lib/auth): the boot configuration is /api/meta's `auth`, read
    // once with the game metadata (MetaStore); this site lets visitors in, with its own visitor key store, so the
    // IAM's sign-in hint signs a person who is signed in there in here too, with one silent hop (the hint is on
    // by default: the IAM's anotoki_signed_in). The callback is /auth/callback; a sign-in that came back without
    // one is said on /sign-in, in the site's frame; an editor sent from an ADMIN page goes to the Builds page.
    provideAnotokiAuth(() => {
      const i18n = inject(TranslationService);
      const meta = inject(MetaStore);
      return {
        config: () => meta.load().then((answer) => answer?.auth ?? null),
        visitors: () => inject(VisitorKey),
        // The IAM's pages, and the callback's and the failure's words, in the language read here - only once a
        // bundle is in memory; without one, the IAM chooses for itself.
        language: () => i18n.signInLanguage(),
        // An owner's rewording of the library's sign-in words (anotoki.signIn.*), from the database.
        translate: (key: string) => i18n.libraryWord(key),
        // Read with no credentials at all: where the site stands, the strings of a language, the boot answer.
        api: { skip: [SITE_STATUS_URL, '/api/translations/', '/api/meta'] },
        failureRoute: `/${SIGN_IN_FAILED_PATH}`,
        forbiddenRoute: '/admin/builds',
      };
    }),
    // The module's own client, with what its callback says noted: whether the sign-in was the hint's silent hop
    // (shared/sign-in-kind.ts) - the move of a visitor's data after a sign-in asks the server with that.
    { provide: ANOTOKI_AUTH_CLIENT_FACTORY, useFactory: signInKindClientFactory },
    // The site's words: the anotoki library's translations, set up for this site (shared/i18n/translations.config.ts).
    provideTranslations(),
    // The anotoki family's kit draws every page (@anotoki/lib/ui/*): its words in the language on the page, from the
    // database's anotoki.* strings, and the site's icons (shared/kit.ts).
    provideKit(),
    // The family's shell (@anotoki/lib/shell): the top bar's theme switch and language chooser - the account's
    // theme while it keeps one, else this device's; the translations module's languages (shared/shell.ts).
    provideShell(),
    // Where the site stands, and what is shown while it is not ready (shared/site-status.ts).
    provideSiteStatus(),
    // The People page (ADMIN; @anotoki/lib/people/page on /admin/people): everybody the IAM signed in here, with
    // their game accounts, imports and last import, and Delete - the site's game accounts and imports of the
    // person, behind the page's question; their anotoki account stays. Visitors are counted, never listed.
    provideAnotokiPeople(() => {
      const auth = inject(AnotokiAuth);
      const site = inject(AuthService);
      const i18n = inject(TranslationService);
      const meta = inject(MetaStore);
      return {
        me: () => auth.sub(),
        isAdmin: () => auth.isAdmin(),
        lead: 'Everybody who has signed in here, and what they keep: game accounts and imports. Deleting it leaves their anotoki account as it is.',
        delete: {
          // Nothing to delete for somebody with no game account and no import.
          allowed: (person) => person['accounts'] !== 0 || person['imports'] !== 0,
        },
        visitorsLine: (visitors) =>
          `${counted(visitors.count, 'visitor')} with ${counted(Number(visitors['accounts'] ?? 0), 'game account')} - ` + `each deleted ${meta.visitorDays()} days after its last visit.`,
        migrationsRoute: MIGRATIONS_ROUTE,
        formatDate: (iso: string) => appliedAt(iso, site.preferences(), i18n.locale()),
      };
    }),
    // The admin tools' pages (ADMIN; @anotoki/lib/admin/errors, /audit, /feedback, /backups on /admin/...): a
    // backup is made, downloaded, deleted or restored after a sign-in of the last ten minutes - "Sign in again".
    provideAnotokiAdmin(() => {
      const auth = inject(AnotokiAuth);
      const site = inject(AuthService);
      const i18n = inject(TranslationService);
      return {
        isAdmin: () => auth.isAdmin(),
        signInAgain: () => auth.signIn({ prompt: 'login' }),
        migrationsRoute: MIGRATIONS_ROUTE,
        formatDate: (iso: string) => appliedAt(iso, site.preferences(), i18n.locale()),
      };
    }),
    // The visitors' feedback form, opened from the foot of every page (shared/feedback.ts).
    provideFeedback(),
    // The anotoki family's legal pages - Terms, Privacy, Contact on /terms, /privacy, /contact - and the footer's
    // links to them (shared/legal.ts); the site's facts come with the pages' frame (sites/main/legal/legal-layout).
    provideLegal(),
    // Words a route's title in the language on the page, and tells the translation service where the page is.
    { provide: TitleStrategy, useClass: AppTitleStrategy },
    provideAppInitializer(async () => {
      // Everything is injected before the first await: inject() does not work after one.
      const auth = inject(AnotokiAuth);
      const i18n = inject(TranslationService);
      const siteStatus = inject(SiteStatus);
      const meta = inject(MetaStore);
      const changelog = inject(Changelog);
      // The visitor key, read from storage - and, once somebody is signed in with one held, the move of what
      // the visitor imported into their account (an effect there).
      inject(VisitorKey);
      // Where the site stands (ready, not set up, an update waiting, out of reach), beside /api/meta - where
      // this server's IAM is - and the changelog, which gives the version shown. Nothing here stops on an
      // update that waits: /api/meta and the strings stay open then, so the sign-in (or its callback)
      // completes before the first page is drawn, and the gate around the router outlet shows the page that
      // says the site is being updated - with Sign in, how an administrator gets to the Migrations page.
      await Promise.all([meta.load(), changelog.load(), siteStatus.check()]);
      // The callback finished, a session restored and followed - or, for a visitor signed in at the IAM, the
      // hint's one silent hop (the page then leaves, and this never resolves).
      await auth.init();
      // The language and its strings, which need to know who is signed in (the token's locale) and what
      // the address is (the sign-in has put it back by now). Nothing is asked for while /api/meta has not
      // answered: the pages then read the cached strings, or the English compiled into the app.
      await i18n.init();
    }),
  ],
};
