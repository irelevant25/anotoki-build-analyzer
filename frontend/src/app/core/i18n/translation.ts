import { AbstractType } from '@angular/core';
import { TranslationService as AnotokiTranslationService } from '@anotoki/lib/translations';
import { LibraryKey } from '@anotoki/lib/ui/config';
import { PluralKey, TranslationKey } from './en';

export { FALLBACK_LANGUAGE } from '@anotoki/lib/translations';

/**
 * What fills a string's {placeholders}. Built up by the wording tables (import-words.ts,
 * recommendation-words.ts), so not read-only - and so what the library's service takes as well.
 */
export type TranslationParams = Record<string, string | number | null | undefined>;

/**
 * Something to say, as state holds it: a key and what fills it - never the sentence, which is worded
 * where it is shown (`wording.key | translate: wording.params`, or the errorText pipe) and so follows the
 * language.
 */
export interface Wording {
  key: TranslationKey;
  params?: TranslationParams;
}

/**
 * A sentence as the page shows it, with the `lang` its element carries: null for the site's own words
 * (the page's language), `en` for English that was stored as data - shown where there is no key to word
 * it with - while the page is in another language. `<span [attr.lang]="worded.lang">{{ worded.text }}</span>`.
 */
export interface Worded {
  text: string;
  lang: 'en' | null;
}

/** A key a page may ask for: the site's own (en.ts), or one of the anotoki library's words (`anotoki.*`). */
export type WordKey = TranslationKey | LibraryKey;

/** What this site's code may ask the service for: its own keys and plural families, typed. */
interface TypedWords {
  t(key: WordKey, params?: TranslationParams): string;
  plural(key: PluralKey, count: number, params?: TranslationParams): string;
  around(key: WordKey, placeholder: string, params?: TranslationParams): [before: string, after: string, found: boolean];
  aroundPlural(key: PluralKey, count: number, placeholder: string, params?: TranslationParams): [before: string, after: string, found: boolean];
}

/**
 * The site's words: the anotoki family's TranslationService (@anotoki/lib/translations), seen with this site's
 * keys. The service is the library's - the language the pages are read in and its bundle from
 * GET /api/translations/{code} with English under it, the family's ranking of the wishes, the account's
 * language, the cache and the start's waits, plurals, `around()`, the English areas (the admin panel) - and
 * this is the same object under the same injection token, typed so that a key that does not exist is a
 * compile error in every class, as the typed pipes (translate.ts, translate-plural.ts) make it one in every
 * template:
 *
 *   private readonly i18n = inject(TranslationService);   // the library's service, this site's keys
 *
 * How the library is set up for this site is translations.config.ts (provideTranslations()).
 */
export type TranslationService = Omit<AnotokiTranslationService, keyof TypedWords> & TypedWords;
export const TranslationService = AnotokiTranslationService as unknown as AbstractType<TranslationService>;
