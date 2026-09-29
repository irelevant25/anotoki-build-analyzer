import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import {
  ApplicationConfig,
  inject,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import { MatIconRegistry } from '@angular/material/icon';
import { provideRouter, withComponentInputBinding, withInMemoryScrolling } from '@angular/router';

import { routes } from './app.routes';
import { AuthService } from './core/auth';
import { authInterceptor } from './core/auth.interceptor';
import { Changelog } from './core/changelog';
import { MetaStore } from './core/meta-store';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding(), withInMemoryScrolling({ scrollPositionRestoration: 'top' })),
    provideHttpClient(withFetch(), withInterceptors([authInterceptor])),
    provideAppInitializer(async () => {
      inject(MatIconRegistry).setDefaultFontSetClass('material-symbols-outlined');
      const auth = inject(AuthService);
      // /api/meta says how this server signs people in (or that it has not been set up); the sign-in (or
      // its callback) completes before the first page is drawn. The changelog gives the version shown.
      const [meta] = await Promise.all([inject(MetaStore).load(), inject(Changelog).load()]);
      await auth.init(meta?.auth ?? null);
    }),
  ],
};
