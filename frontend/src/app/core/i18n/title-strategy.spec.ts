import { TestBed } from '@angular/core/testing';
import { Title } from '@angular/platform-browser';
import { Route, Router, TitleStrategy, provideRouter } from '@angular/router';
import { aboutKey } from '@anotoki/lib/legal/about';
import { supportKey } from '@anotoki/lib/legal/support';
import { isAdminArea } from '@anotoki/lib/translations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { routes } from '../../app.routes';
import { isTranslationKey } from './en';
import { ADMIN_PANEL, AppTitleStrategy, BRAND } from './title-strategy';
import { TranslationService } from './translation';

// The About page's title is the library's word, from the bundle; the Support page's is not in these bundles.
const ENGLISH = { 'title.import': 'Import account', 'title.builds': 'Builds', 'anotoki.about.title': 'About anotoki' };
const SLOVAK = { 'title.import': 'Import účtu', 'title.builds': 'Buildy', 'anotoki.about.title': 'O projekte anotoki' };

describe('AppTitleStrategy: the browser tab’s title', () => {
  async function at(url: string) {
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        provideRouter([
          // Routes that draw nothing: only their titles matter here.
          { path: '', pathMatch: 'full', children: [] },
          { path: 'import', title: 'title.import', children: [] },
          { path: 'builds/:game', title: 'title.builds', children: [] },
          { path: 'admin/sources', title: 'Build sources', children: [] },
          { path: 'admin/migrations', title: 'Migrations', children: [] },
          { path: 'sign-in', title: 'title.signIn', children: [] },
          { path: 'about', title: 'anotoki.about.title', children: [] },
          { path: 'support', title: 'anotoki.support.title', children: [] },
        ]),
        { provide: TitleStrategy, useClass: AppTitleStrategy },
      ],
    });
    const router = TestBed.inject(Router);
    const go = async (next: string): Promise<string> => {
      await router.navigateByUrl(next);
      TestBed.tick();
      return TestBed.inject(Title).getTitle();
    };
    return { title: await go(url), go, i18n: TestBed.inject(TranslationService) };
  }

  let warn: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
  });

  afterEach(() => vi.restoreAllMocks());

  it('words a route’s key in the language on the page and adds the brand', async () => {
    expect((await at('/import')).title).toBe('Import účtu · Build Analyzer');
  });

  it('is the brand alone for a route without a title', async () => {
    const { title, go } = await at('/');
    expect(title).toBe(BRAND);
    expect(await go('/builds/genshin')).toBe('Buildy · Build Analyzer');
    expect(await go('/')).toBe('Build Analyzer');
  });

  it('keeps an English page’s title as it is written, says it is the admin panel’s - and tells the service the page is English', async () => {
    const { title, go, i18n } = await at('/admin/sources');
    expect(title).toBe('Build sources · Admin panel · Build Analyzer');
    expect(i18n.englishOnly()).toBe(true);
    expect(i18n.effectiveLanguage()).toBe('en');
    expect(await go('/admin/migrations?x=1')).toBe(`Migrations · ${ADMIN_PANEL} · ${BRAND}`);
    expect(i18n.englishOnly()).toBe(true);
    expect(i18n.effectiveLanguage()).toBe('en');

    expect(await go('/import?step=2')).toBe('Import účtu · Build Analyzer');
    expect(i18n.englishOnly()).toBe(false);
  });

  it('follows the language: switching it renames the tab without a navigation', async () => {
    const { title, i18n } = await at('/builds/hsr');
    expect(title).toBe('Buildy · Build Analyzer');

    expect(await i18n.setLanguage('en', { remember: false })).toBe(true);
    TestBed.tick();
    expect(TestBed.inject(Title).getTitle()).toBe('Builds · Build Analyzer');
  });

  it('shows a key the bundle lacks as the key, like every other string', async () => {
    expect((await at('/sign-in')).title).toBe('title.signIn · Build Analyzer');
    expect(warn.mock.calls).toEqual([["[i18n] no string for 'title.signIn'"]]);
  });

  it('words the library’s key of its own pages (About, Support) from the bundle, in the language on the page - and is the brand alone while the bundle has no word for it, never the key', async () => {
    const { title, go, i18n } = await at('/about');
    expect(title).toBe('O projekte anotoki · Build Analyzer');

    expect(await i18n.setLanguage('en', { remember: false })).toBe(true);
    TestBed.tick();
    expect(TestBed.inject(Title).getTitle()).toBe('About anotoki · Build Analyzer');

    expect(await go('/support')).toBe(BRAND);
    expect(warn).not.toHaveBeenCalled();
  });
});

describe('the routes’ titles', () => {
  /** Every route with its whole path - the admin panel's pages are its layout's children. */
  const flat = (list: Route[], parent = ''): Route[] =>
    list.flatMap((route) => {
      const path = [parent, route.path ?? ''].filter(Boolean).join('/');
      return [{ ...route, path }, ...flat(route.children ?? [], path)];
    });
  const titled = flat(routes as Route[]).filter((route) => route.title !== undefined);

  it('are keys on the pages members read - on About and Support the library’s, which their headings say -, and plain English in the admin panel', () => {
    const library: Record<string, string> = { about: aboutKey('title'), support: supportKey('title') };
    for (const route of titled) {
      const english = isAdminArea(`/${route.path}`);
      expect(typeof route.title, route.path).toBe('string');
      if (Object.hasOwn(library, route.path!)) {
        expect(route.title, route.path).toBe(library[route.path!]);
      } else {
        expect(isTranslationKey(route.title as string), route.path).toBe(!english);
      }
    }
    expect(titled.map((route) => route.path)).toEqual(expect.arrayContaining(['about', 'support']));
    expect(titled.map((route) => route.path)).toEqual(expect.arrayContaining(['admin/sources', 'admin/translations', 'admin/languages', 'admin/people', 'admin/migrations']));
  });

  it('leave the dashboard with the brand alone, and carry the brand nowhere themselves', () => {
    expect((routes as Route[]).find((route) => route.path === '')?.title).toBeUndefined();
    expect(titled.filter((route) => String(route.title).includes(BRAND) || String(route.title).includes(ADMIN_PANEL))).toEqual([]);
  });
});
