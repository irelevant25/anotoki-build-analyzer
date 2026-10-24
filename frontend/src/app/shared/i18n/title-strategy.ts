import { Injectable, effect, inject, signal } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { RouterStateSnapshot, TitleStrategy } from '@angular/router';
import { isAdminArea } from '@anotoki/lib/translations';
import { isTranslationKey } from './en';
import { TranslationService } from './translation';

/** The app's name: a brand, the same in every language. */
export const BRAND = 'Build Analyzer';

/** What the admin panel's titles say between the page and the brand (English, as the panel). */
export const ADMIN_PANEL = 'Admin panel';

/**
 * The browser tab's title: "Builds · Build Analyzer" - a page's own title first, where a tab shows the
 * start of it; the brand alone for a route without one (the dashboard). A page of the admin panel says so
 * between the two: "Translations · Admin panel · Build Analyzer".
 *
 * A route's `title` is a translation key on the pages members read, and plain English on the English
 * pages (the admin panel's); either works here. The title follows the language: switching
 * it renames the tab without a navigation. With no bundle in memory the key is worded from the compiled
 * English, like everything else. The About and Support pages - the anotoki family's, in the library's words -
 * are titled by the library's keys their headings say (`anotoki.about.title`, `anotoki.support.title`): the
 * database's string, an owner's rewording too; with no bundle in memory there is none, and the tab is the brand.
 *
 * It also tells the translation service where the page is - the English pages are English - because that
 * service cannot ask the Router itself (the Router builds this strategy, and this strategy needs that
 * service).
 */
@Injectable()
export class AppTitleStrategy extends TitleStrategy {
  private readonly _title = inject(Title);
  private readonly _i18n = inject(TranslationService);

  /** The current route's title as the route wrote it: a key (the site's, or the library's), or English. Undefined: the route has none. */
  private readonly _page = signal<string | undefined>(undefined);
  /** Whether the page is the admin panel's (/admin/...: the English pages). */
  private readonly _admin = signal(false);

  constructor() {
    super();
    effect(() => {
      const page = this._page();
      const brand = this._admin() ? `${ADMIN_PANEL} · ${BRAND}` : BRAND;
      const words = page === undefined ? null : isTranslationKey(page) ? this._i18n.t(page) : page.startsWith('anotoki.') ? this._i18n.libraryWord(page) : page;
      this._title.setTitle(words ? `${words} · ${brand}` : brand);
    });
  }

  /**
   * After every navigation. Saying where the page is also lets the service ask for its strings again
   * when it has none from the server (noteAddress()).
   */
  override updateTitle(snapshot: RouterStateSnapshot): void {
    this._i18n.noteAddress(snapshot.url);
    this._admin.set(isAdminArea(snapshot.url));
    this._page.set(this.buildTitle(snapshot));
  }
}
