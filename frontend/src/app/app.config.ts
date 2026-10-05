import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import {
  ApplicationConfig,
  inject,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { MatIconRegistry } from '@angular/material/icon';
import { TitleStrategy, provideRouter, withComponentInputBinding, withInMemoryScrolling } from '@angular/router';

import { routes } from './app.routes';
import { AuthService } from './core/auth';
import { authInterceptor } from './core/auth.interceptor';
import { Changelog } from './core/changelog';
import { AppTitleStrategy } from './core/i18n/title-strategy';
import { TranslationService } from './core/i18n/translation';
import { MetaStore } from './core/meta-store';
import { VisitorKey } from './core/visitor-key';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding(), withInMemoryScrolling({ scrollPositionRestoration: 'top' })),
    provideHttpClient(withFetch(), withInterceptors([authInterceptor])),
    // Words a route's title in the language on the page, and tells the translation service where the page is.
    { provide: TitleStrategy, useClass: AppTitleStrategy },
    provideAppInitializer(async () => {
      inject(MatIconRegistry).setDefaultFontSetClass('material-symbols-outlined');
      // Everything is injected before the first await: inject() does not work after one.
      const auth = inject(AuthService);
      const i18n = inject(TranslationService);
      // The visitor key, read from storage - and, once somebody is signed in with one held, the move of what
      // the visitor imported into their account (an effect there: AuthService never reads VisitorKey).
      inject(VisitorKey);
      // /api/meta says where this server's IAM is (or that it has not been set up); the sign-in (or
      // its callback) completes before the first page is drawn. The changelog gives the version shown.
      const [meta] = await Promise.all([inject(MetaStore).load(), inject(Changelog).load()]);
      await auth.init(meta?.auth ?? null);
      // The language and its strings, which need to know who is signed in (the token's locale) and what
      // the address is (the sign-in has put it back by now). Nothing is asked for while /api/meta has not
      // answered: the pages then read the cached strings, or the English compiled into the app.
      await i18n.init();
    }),
  ],
};
