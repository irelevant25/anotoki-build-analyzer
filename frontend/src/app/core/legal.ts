import { EnvironmentProviders, inject } from '@angular/core';
import { DEFAULT_LEGAL_PATHS, provideAnotokiLegal } from '@anotoki/lib/legal';
import { TranslationKey } from './i18n/en';
import { TranslationService } from './i18n/translation';

/**
 * The addresses of the pages the footer links - About, Support and the legal pages, in its order: the library's own
 * paths (app.routes.ts puts each page there, the footer's links go there).
 */
export const LEGAL_PAGE_PATHS: readonly string[] = [DEFAULT_LEGAL_PATHS.about, DEFAULT_LEGAL_PATHS.support, DEFAULT_LEGAL_PATHS.terms, DEFAULT_LEGAL_PATHS.privacy, DEFAULT_LEGAL_PATHS.contact];

/**
 * The library's legal words this site says in its own (`legal.*`, migration 011), each read from the site's key in
 * place of the library's: the fan clause on Terms and Contact - the library's names Genshin Impact alone, and this
 * site is about Honkai: Star Rail too. Each key is written out whole, so the backend's scan of the pages finds it
 * used.
 */
export const SITE_LEGAL_WORDS: Readonly<Record<string, TranslationKey>> = {
  'anotoki.legal.fanHoyoverse': 'legal.fanClause',
};

/**
 * How this site plugs into the anotoki family's legal pages (@anotoki/lib/legal, anotoki-lib 0.6.0: Terms, Privacy
 * and Contact on the lazy routes /terms, /privacy and /contact - app.routes.ts - and the links to them in the
 * family's footer, <anotoki-site-footer> in layouts/app-layout and layouts/admin-layout) and, since 0.8.0, its About
 * and Support pages on /about and /support, which the footer links first. provideAnotokiLegal() runs it in an
 * injection context.
 *
 * - The language: the one on the page (English in the admin panel, as the panel is - effectiveLanguage()).
 * - The words: the library's (`anotoki.legal.*`, `anotoki.legalLinks.*`, `anotoki.about.*`, `anotoki.support.*`) -
 *   the database's string of a library key, an owner's rewording on the Translations page, before the library's
 *   built-in English and Slovak - but for the few this site says itself (SITE_LEGAL_WORDS), its own key's string.
 *   That Slovak addresses nobody ("ty" or "Vy"), so it reads well beside this site's informal Slovak.
 * - The paths: the library's own; the operator: the family's (Code Hero s.r.o., its address kept in two parts in
 *   the library).
 * - The facts - what this site keeps and for how long, its cookies and browser storage, who else handles data, the
 *   anotoki account's pages - are read by the pages alone, and their frame gives them (core/legal-facts.ts,
 *   layouts/legal-layout): none of it is in the first load, which the footer's links share with every page.
 */
export function provideLegal(): EnvironmentProviders {
  return provideAnotokiLegal(() => {
    const i18n = inject(TranslationService);
    return {
      language: () => i18n.effectiveLanguage(),
      translate: (key: string) => (Object.hasOwn(SITE_LEGAL_WORDS, key) ? i18n.t(SITE_LEGAL_WORDS[key]) : i18n.libraryWord(key)),
      facts: () => {
        throw new Error("The legal pages' facts come with their frame (layouts/legal-layout, core/legal-facts.ts).");
      },
    };
  });
}
