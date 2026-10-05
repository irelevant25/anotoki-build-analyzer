import { TestBed } from '@angular/core/testing';
import { Title } from '@angular/platform-browser';
import { Route, Router, TitleStrategy, provideRouter } from '@angular/router';
import { routes } from '../../app.routes';
import { isTranslationKey } from './en';
import { provideTestTranslations } from './testing';
import { ADMIN_PANEL, AppTitleStrategy, BRAND } from './title-strategy';
import { TranslationService, isEnglishPath } from './translation';

const ENGLISH = { 'title.import': 'Import account', 'title.builds': 'Builds' };
const SLOVAK = { 'title.import': 'Import účtu', 'title.builds': 'Buildy' };

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

    expect(await i18n.setLanguage('en', false)).toBe(true);
    TestBed.tick();
    expect(TestBed.inject(Title).getTitle()).toBe('Builds · Build Analyzer');
  });

  it('shows a key the bundle lacks as the key, like every other string', async () => {
    expect((await at('/sign-in')).title).toBe('title.signIn · Build Analyzer');
    expect(warn.mock.calls).toEqual([["[i18n] no string for 'title.signIn'"]]);
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

  it('are keys on the pages members read, and plain English in the admin panel', () => {
    for (const route of titled) {
      const english = isEnglishPath(`/${route.path}`);
      expect(typeof route.title, route.path).toBe('string');
      expect(isTranslationKey(route.title as string), route.path).toBe(!english);
    }
    expect(titled.map((route) => route.path)).toEqual(expect.arrayContaining(['admin/sources', 'admin/translations', 'admin/languages', 'admin/people', 'admin/migrations']));
  });

  it('leave the dashboard with the brand alone, and carry the brand nowhere themselves', () => {
    expect((routes as Route[]).find((route) => route.path === '')?.title).toBeUndefined();
    expect(titled.filter((route) => String(route.title).includes(BRAND) || String(route.title).includes(ADMIN_PANEL))).toEqual([]);
  });
});
