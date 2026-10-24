import { EnvironmentProviders, inject } from '@angular/core';
import { DEFAULT_LEGAL_PATHS, provideAnotokiLegal } from '@anotoki/lib/legal';
import { TranslationService } from './i18n/translation';

/**
 * The addresses of the pages the footer links - About, Support and the legal pages, in its order: the library's own
 * paths (app.routes.ts puts each page there, the footer's links go there).
 */
export const LEGAL_PAGE_PATHS: readonly string[] = [DEFAULT_LEGAL_PATHS.about, DEFAULT_LEGAL_PATHS.support, DEFAULT_LEGAL_PATHS.terms, DEFAULT_LEGAL_PATHS.privacy, DEFAULT_LEGAL_PATHS.contact];

/**
 * How this site plugs into the anotoki family's legal pages (@anotoki/lib/legal, anotoki-lib 0.6.0: Terms, Privacy
 * and Contact on the lazy routes /terms, /privacy and /contact - app.routes.ts - and the links to them in the
 * family's footer, <anotoki-site-footer> in sites/main/app-layout and sites/admin/app.ts) and, since 0.8.0, its About
 * and Support pages on /about and /support, which the footer links first. provideAnotokiLegal() runs it in an
 * injection context.
 *
 * - The language: the one on the page (English in the admin panel, as the panel is - effectiveLanguage()).
 * - The words: the library's (`anotoki.legal.*`, `anotoki.legalLinks.*`, `anotoki.about.*`, `anotoki.support.*`) -
 *   the database's string of a library key, an owner's rewording on the Translations page, before the library's
 *   built-in English and Slovak (the fan clause too: the library's names Genshin Impact, the one game here since
 *   0.5.0). That Slovak addresses nobody ("ty" or "Vy"), so it reads well beside this site's informal Slovak.
 * - The paths: the library's own; the operator: the family's (Code Hero s.r.o., its address kept in two parts in
 *   the library).
 * - The facts - what this site keeps and for how long, its cookies and browser storage, who else handles data, the
 *   anotoki account's pages - are read by the pages alone, and their frame gives them (sites/main/legal/legal-facts.ts,
 *   sites/main/legal/legal-layout): none of it is in the first load, which the footer's links share with every page.
 */
export function provideLegal(): EnvironmentProviders {
  return provideAnotokiLegal(() => {
    const i18n = inject(TranslationService);
    return {
      language: () => i18n.effectiveLanguage(),
      translate: (key: string) => i18n.libraryWord(key),
      facts: () => {
        throw new Error("The legal pages' facts come with their frame (sites/main/legal/legal-layout, sites/main/legal/legal-facts.ts).");
      },
    };
  });
}
