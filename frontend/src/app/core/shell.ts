import { EnvironmentProviders, inject } from '@angular/core';
import { provideAnotokiShell } from '@anotoki/lib/shell';
import { AuthService } from './auth';
import { TranslationService } from './i18n/translation';

/**
 * Where this device keeps the theme chosen while nobody's account keeps one - the key the site always used, which
 * the kit's theme-boot.js reads before the first paint too (index.html's data-storage-key says the same).
 */
export const THEME_KEY = 'build-analyzer-theme';

/**
 * The anotoki family's shell (@anotoki/lib/shell), set up for this site: the top bar's theme switch and language
 * chooser, and the ThemeService behind the switch.
 *
 * - The theme is the account's while somebody is signed in with an IAM that keeps it (the IAM's migration 015,
 *   the owner's decision of 2026-10-06): one theme on every anotoki site, the admin panel included, shown from
 *   the account and saved to it from the switch - one save at a time, the latest last; a refusal puts the
 *   account's back, and the switch says so. Signed out, and while the IAM keeps none, it is this device's.
 * - The languages are the translations module's (TranslationService.forShell()): a choice in the bar switches
 *   once its words are here and is saved to the account where it keeps one; the chooser says when the words
 *   could not be loaded, or the account did not take the choice.
 */
export function provideShell(): EnvironmentProviders {
  return provideAnotokiShell(() => {
    const auth = inject(AuthService);
    return {
      theme: {
        storageKey: THEME_KEY,
        account: () => auth.preferences()?.theme ?? null,
        save: (theme) => auth.savePreferences({ theme }),
        // The pages' own grounds (styles.scss), for the browser's own bars.
        themeColor: { light: '#f6f5fb', dark: '#121218' },
      },
      languages: inject(TranslationService).forShell(),
    };
  });
}
