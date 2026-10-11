import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  ANOTOKI_TRANSLATIONS_CONFIG,
  ANOTOKI_TRANSLATION_BUNDLE,
  AnotokiTranslationsConfig,
  TranslationBundle,
  TranslationService as LibraryTranslationService,
} from '@anotoki/lib/translations';
import { TEST_LANGUAGES } from '@anotoki/lib/translations/testing';
import { AuthService } from '../auth';
import { AnotokiPreferenceChanges, AnotokiUser } from '@anotoki/lib/auth';
import { MetaStore } from '../meta-store';
import { en } from './en';
import { TranslationService } from './translation';
import { LANGUAGE_STORAGE, provideTranslations } from './translations.config';

const ENGLISH = { 'theme.label': 'Appearance', 'title.builds': 'Builds' };
const SLOVAK = { 'theme.label': 'Vzhľad', 'title.builds': 'Buildy' };
const EN_BUNDLE: TranslationBundle = { language: 'en', languages: TEST_LANGUAGES, values: ENGLISH };
const SK_BUNDLE: TranslationBundle = {
  language: 'sk',
  languages: TEST_LANGUAGES,
  values: SLOVAK,
  english: ENGLISH,
};

const MONA: AnotokiUser = {
  sub: '7',
  preferred_username: 'mona',
  email: 'mona@example.test',
  email_verified: true,
  locale: 'sk',
  roles: [],
  preferences: { theme: 'auto', date_format: null, time_format: null },
};

/** Everything already on its way has run. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

/**
 * Never called: what a compile of this file holds. The keys are this site's own (en.ts) and the library's words;
 * a key that does not exist is a compile error in a class as in a template - an unused @ts-expect-error would be
 * one too, so these stay true.
 */
export function typedKeys(i18n: TranslationService): string[] {
  return [
    i18n.t('title.builds'),
    i18n.t('anotoki.language.button', { name: 'Slovenčina', code: 'SK' }),
    i18n.plural('buildDetail.optionAny', 2),
    // @ts-expect-error a key no migration wrote
    i18n.t('title.nothing'),
    // @ts-expect-error the key 006 moved under the library's name
    i18n.t('language.button'),
    // @ts-expect-error one string asked for as a plural family
    i18n.plural('title.builds', 2),
  ];
}

/** Who is signed in, as the site asks AuthService (the auth module's view) - set by the test. */
function fakeAuth() {
  const user = signal<AnotokiUser | null>(null);
  const roles = computed(() => user()?.roles ?? []);
  return {
    user,
    preferences: computed(() => user()?.preferences ?? null),
    isAdmin: computed(() => roles().includes('ADMIN')),
    isStaff: computed(() => roles().includes('ADMIN') || roles().includes('EDITOR')),
    savePreferences: (changes: AnotokiPreferenceChanges) =>
      Promise.resolve({
        locale: changes.language ?? null,
        preferences: user()?.preferences ?? null,
      }),
  };
}

describe("the site's words: the anotoki library's translations, set up for this site", () => {
  let requests: HttpTestingController;
  let auth: ReturnType<typeof fakeAuth>;

  function start(): void {
    auth = fakeAuth();
    TestBed.configureTestingModule({
      providers: [
        provideTranslations(),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: AuthService, useValue: auth },
      ],
    });
    requests = TestBed.inject(HttpTestingController);
  }

  const config = (): AnotokiTranslationsConfig => TestBed.inject(ANOTOKI_TRANSLATIONS_CONFIG);
  const i18n = (): TranslationService => TestBed.inject(TranslationService);
  const bundles = () => requests.match((request) => request.url.startsWith('/api/translations/'));

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    history.replaceState(null, '', '/');
    vi.spyOn(window.navigator, 'languages', 'get').mockReturnValue(['en']);
  });

  afterEach(() => {
    requests.verify();
    vi.restoreAllMocks();
  });

  it("is the library's service itself, under the library's token - this site's view of it only types the keys", () => {
    start();
    expect(i18n()).toBe(TestBed.inject(LibraryTranslationService));
    expect(typeof typedKeys).toBe('function');
  });

  it("keeps the language, its cache and the drafts under the names this site always had: a visitor's choice survives the upgrade", () => {
    start();
    expect(config().storageKeys).toEqual({
      language: 'build-analyzer-language',
      cache: 'build-analyzer-language-cache',
      drafts: 'build-analyzer-localization-drafts',
    });
    expect(config().storageKeys).toBe(LANGUAGE_STORAGE);
  });

  it("reads the English compiled into the app while no bundle is in memory - and the library's words in theirs", () => {
    start();
    expect(config().fallbackEnglish).toBe(en);
    expect(i18n().ready()).toBe(false);
    expect(i18n().t('title.builds')).toBe(en['title.builds']);
    expect(i18n().t('anotoki.language.button', { name: 'English', code: 'EN' })).toBe(
      'Language: English (EN)',
    );
  });

  it('asks for nothing while /api/meta has not answered, and starts from the cached bundle in the language it is in', async () => {
    localStorage.setItem('build-analyzer-language', 'sk');
    localStorage.setItem('build-analyzer-language-cache', JSON.stringify(SK_BUNDLE));
    start();

    await i18n().init();
    expect(bundles()).toEqual([]);
    expect(i18n().language()).toBe('sk');
    expect(i18n().t('theme.label')).toBe('Vzhľad');
  });

  it('once /api/meta has answered, asks for the bundle - marked as the bundle, for the interceptors - and keeps it for the next visit', async () => {
    start();
    TestBed.inject(MetaStore).loaded.set(true);
    const started = i18n().init();
    await settle();

    const asked = bundles();
    expect(asked.map((request) => request.request.url)).toEqual(['/api/translations/en']);
    expect(asked[0].request.context.get(ANOTOKI_TRANSLATION_BUNDLE)).toBe(true);
    asked[0].flush(EN_BUNDLE);
    await started;

    expect(i18n().t('theme.label')).toBe('Appearance');
    expect(JSON.parse(localStorage.getItem('build-analyzer-language-cache') ?? 'null')).toEqual(
      EN_BUNDLE,
    );
  });

  it("follows the account: its language (the token's locale), its say while the IAM keeps the person's choices, a choice saved to it", async () => {
    start();
    const save = vi
      .spyOn(auth, 'savePreferences')
      .mockResolvedValue({ locale: 'en', preferences: MONA.preferences });
    const account = config().account!;
    expect([account.language(), account.decides?.(), account.userKey?.()]).toEqual([
      null,
      false,
      null,
    ]);

    auth.user.set(MONA);
    expect([account.language(), account.decides?.(), account.userKey?.()]).toEqual([
      'sk',
      true,
      '7',
    ]);
    auth.user.set({ ...MONA, preferences: null });
    expect(account.decides?.()).toBe(false);
    auth.user.set(MONA);

    TestBed.inject(MetaStore).loaded.set(true);
    const started = i18n().init();
    await settle();
    bundles()[0].flush(SK_BUNDLE);
    await started;
    expect(i18n().language()).toBe('sk');

    const chosen = i18n().setLanguage('en');
    await settle();
    bundles()[0].flush(EN_BUNDLE);
    expect(await chosen).toBe(true);
    await settle();
    expect(save).toHaveBeenCalledExactlyOnceWith({ language: 'en' });
    expect(localStorage.getItem('build-analyzer-language')).toBe('en');
  });

  it('lets editors reword the strings and admins keep the languages - the server checks it again', () => {
    start();
    const allows = config().admin!.allows!;
    const who = () => [allows.strings!(), allows.languages!()];

    expect(who()).toEqual([false, false]);
    auth.user.set({ ...MONA, roles: ['MEMBER'] });
    expect(who()).toEqual([false, false]);
    auth.user.set({ ...MONA, roles: ['EDITOR'] });
    expect(who()).toEqual([true, false]);
    auth.user.set({ ...MONA, roles: ['ADMIN'] });
    expect(who()).toEqual([true, true]);
    expect(config().admin!.routes).toEqual({
      translations: '/admin/translations',
      languages: '/admin/languages',
      migrations: '/admin/migrations',
    });
  });
});
