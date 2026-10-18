import { EnvironmentProviders, inject } from '@angular/core';
import { provideAnotokiTranslations } from '@anotoki/lib/translations';
import { AuthService } from '../auth';
import { MetaStore } from '../meta-store';
import { MIGRATIONS_ROUTE } from '../site-status';
import { en } from './en';

/** Where this site kept the language and its bundle before the library: a visitor's choice and cache survive. */
export const LANGUAGE_STORAGE = {
  /** The bar's choice (localStorage), and this tab's `?lang=` (sessionStorage): one name, two lifetimes. */
  language: 'build-analyzer-language',
  /** The last bundle, so a returning reader starts in their own language while the fresh one is on its way. */
  cache: 'build-analyzer-language-cache',
  /** What is typed on the Translations and Languages pages and not saved yet, for the tab. */
  drafts: 'build-analyzer-localization-drafts',
} as const;

/**
 * The anotoki library's translations (@anotoki/lib/translations), set up for this site:
 *
 * - its storage names (LANGUAGE_STORAGE), as this site had them;
 * - the English compiled into the app (en.ts) - read only while no bundle is in memory: between an upload and
 *   Apply pending, and while the server cannot be reached;
 * - the account (the anotoki IAM): its language is the token's `locale`; while the IAM keeps the person's
 *   choices (`AuthService.preferences()`, the IAM's migration 015) it decides, and a language chosen in the bar
 *   is saved to it - a refusal is said by the bar's language switcher (a language the IAM does not offer is no
 *   news: it stays this site's own for the visit); whose drafts the admin pages keep is the token's `sub`;
 * - nothing is asked while /api/meta has not answered (not set up, or out of reach): the pages read the cached
 *   strings or the compiled English then;
 * - the admin Translations and Languages pages (@anotoki/lib/translations/admin): the strings for EDITOR and
 *   ADMIN, the languages for ADMIN - the server checks it again -, and where the site's pages are.
 *
 * The English areas are the library's default - /admin and under it: the admin panel - and the locales its
 * British English and Slovak. The factory runs the first time the service needs it, never while it is made.
 */
export function provideTranslations(): EnvironmentProviders {
  return provideAnotokiTranslations(() => {
    const auth = inject(AuthService);
    const meta = inject(MetaStore);
    return {
      storageKeys: LANGUAGE_STORAGE,
      fallbackEnglish: en,
      account: {
        language: () => auth.user()?.locale ?? null,
        decides: () => auth.preferences() !== null,
        save: (code) => auth.savePreferences({ language: code }),
        userKey: () => auth.user()?.sub ?? null,
      },
      canAsk: () => meta.loaded(),
      admin: {
        allows: { strings: () => auth.isStaff(), languages: () => auth.isAdmin() },
        routes: {
          translations: '/admin/translations',
          languages: '/admin/languages',
          migrations: MIGRATIONS_ROUTE,
        },
        leads: {
          translations: "What the site itself says, in every language. Game terms and values are the games' own and are not here. This page stays English.",
        },
      },
    };
  });
}
