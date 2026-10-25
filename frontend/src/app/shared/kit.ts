import { EnvironmentProviders, Injector, inject } from '@angular/core';
import { provideAnotokiUi } from '@anotoki/lib/ui/config';
import { AuthService } from './auth';
import { SITE_ICONS } from './icons';
import { TranslationService } from './i18n/translation';

/**
 * The anotoki family's kit (@anotoki/lib/ui/*, each piece from its own entry point), set up for this site: its
 * words in the language on the page - the database's anotoki.* strings, so an editor's rewording on the
 * Translations page reaches them, the kit's own words under them -, the locale its dates write in (the translations
 * module's: en-GB, sk-SK - English in the admin panel), the account's date order and clock where the person chose them
 * (AuthService.preferences(), the IAM's migration 015: the kit's dates, @anotoki/lib/ui/dates, write in them; null for
 * nobody, a visitor, an IAM that keeps none - the language's own way then), and the icons the site's templates name
 * beyond the kit's own (shared/icons.ts).
 */
export function provideKit(): EnvironmentProviders {
  return provideAnotokiUi(() => {
    // The sign-in is asked for when a date is written, never as the kit's configuration is made: the sign-in reads the
    // kit's words, which read this configuration - a circle otherwise (Piano Academy's finding, anotoki-lib 0.11.0).
    const injector = inject(Injector);
    return { ...inject(TranslationService).forKit(), dateChoices: () => injector.get(AuthService).preferences(), icons: SITE_ICONS };
  });
}
