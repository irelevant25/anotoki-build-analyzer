import { EnvironmentProviders, inject } from '@angular/core';
import { provideAnotokiUi } from '@anotoki/lib/ui/config';
import { SITE_ICONS } from './icons';
import { TranslationService } from './i18n/translation';

/**
 * The anotoki family's kit (@anotoki/lib/ui/*, each piece from its own entry point), set up for this site: its
 * words in the language on the page - the database's anotoki.* strings, so an editor's rewording on the
 * Translations page reaches them, the kit's own words under them - and the icons the site's templates name
 * beyond the kit's own (shared/icons.ts).
 */
export function provideKit(): EnvironmentProviders {
  return provideAnotokiUi(() => ({ ...inject(TranslationService).forKit(), icons: SITE_ICONS }));
}
