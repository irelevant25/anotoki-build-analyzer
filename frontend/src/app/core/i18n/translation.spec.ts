import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { from } from 'rxjs';
import { Api } from '../api';
import { AuthMode, AuthService } from '../auth';
import { MetaStore } from '../meta-store';
import { TranslationBundle } from '../models';
import { TranslationKey, en } from './en';
import { TEST_LANGUAGES, provideTestTranslations } from './testing';
import { CACHE_WAIT_MS, FIRST_WAIT_MS, TranslationService, isEnglishPath, languageCandidates, pickLanguage } from './translation';

const LANGUAGE_KEY = 'build-analyzer-language';
const CACHE_KEY = 'build-analyzer-language-cache';

/**
 * The database's English: real keys, worded differently from the compiled English (en/core.json says
 * "Theme") so a test can tell which of the two a page reads.
 */
const ENGLISH: Record<string, string> = {
  'theme.label': 'Appearance',
  'import.error.file_game_mismatch': 'This file contains {file_game} data, but you chose {chosen_game}.',
  'buildDetail.optionAny.one': '(any {count})',
  'buildDetail.optionAny.few': '(any {count})',
  'buildDetail.optionAny.other': '(any {count})',
  'nav.languages': 'Only in English',
  // Placeholders named like what every object inherits: filled only from what a parameter object has itself.
  'error.http': 'x {constructor} {toString} y',
};
const SLOVAK: Record<string, string> = {
  ...ENGLISH,
  'theme.label': 'Vzhľad',
  'import.error.file_game_mismatch': 'Súbor obsahuje údaje z hry {file_game}, ale zvolená hra je {chosen_game}.',
  'buildDetail.optionAny.one': '(ľubovoľný {count})',
  'buildDetail.optionAny.few': '(ľubovoľné {count})',
  'buildDetail.optionAny.other': '(ľubovoľných {count})',
};

const EN_BUNDLE: TranslationBundle = { language: 'en', languages: TEST_LANGUAGES, values: ENGLISH };
const SK_BUNDLE: TranslationBundle = { language: 'sk', languages: TEST_LANGUAGES, values: SLOVAK, english: ENGLISH };

/** The server: Slovak for `sk`, English - saying so - for anything else. */
function answer(code: string): Promise<unknown> {
  return Promise.resolve(code === 'sk' ? SK_BUNDLE : EN_BUNDLE);
}

/** The Slovak bundle as it is once somebody has reworded a string on the Translations page. */
function reworded(theme: string): TranslationBundle {
  return { ...SK_BUNDLE, values: { ...SLOVAK, 'theme.label': theme } };
}

/** The server out of reach - and, between an upload and Apply, the route's 503. */
const OFFLINE = new HttpErrorResponse({ status: 0 });
const UNAVAILABLE = new HttpErrorResponse({ status: 503, error: { message: 'The translations are not available yet.', code: 'translations_unavailable' } });

/** Everything already on its way has run: the answers of requests that were settled, and what follows them. */
function settled(): Promise<void> {
  return new Promise((done) => setTimeout(done));
}

/** A promise the test settles by hand: the server's answer, when the test says. */
function later<T>() {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
}

describe('languageCandidates() and pickLanguage(): which language a reader gets', () => {
  it('orders the wishes: this tab, the menu, the token, each browser tag and its first subtag, English', () => {
    const candidates = languageCandidates({ tab: 'de', chosen: 'fr', token: 'it', browser: ['sk-SK', 'cs'] });
    expect(candidates.map((candidate) => candidate.code)).toEqual(['de', 'fr', 'it', 'sk-sk', 'sk', 'cs', 'en']);
    expect(candidates.filter((candidate) => candidate.browser).map((candidate) => candidate.code)).toEqual(['sk-sk', 'sk', 'cs']);
  });

  it('lower-cases, and ignores whatever does not look like a language code', () => {
    const candidates = languageCandidates({ tab: 'SK', chosen: 'english', token: '../../etc', browser: ['zh-Hant-TW', 'fil', null, undefined, ''] });
    expect(candidates.map((candidate) => candidate.code)).toEqual(['sk', 'zh', 'en']);
  });

  it('takes the first wish that is on offer, and English when none is', () => {
    expect(pickLanguage(languageCandidates({ tab: 'de', chosen: 'sk', browser: ['en-GB'] }), ['en', 'sk'])).toBe('sk');
    expect(pickLanguage(languageCandidates({ tab: 'sk', chosen: 'en' }), ['en', 'sk'])).toBe('sk');
    expect(pickLanguage(languageCandidates({ token: 'sk', browser: ['en'] }), ['en', 'sk'])).toBe('sk');
    expect(pickLanguage(languageCandidates({ browser: ['de-AT', 'fr'] }), ['en', 'sk'])).toBe('en');
  });

  it('gives a Czech browser Slovak - while no Czech is on offer, and only at the browser step', () => {
    expect(pickLanguage(languageCandidates({ browser: ['cs-CZ', 'en'] }), ['en', 'sk'])).toBe('sk');
    expect(pickLanguage(languageCandidates({ browser: ['cs-CZ', 'en'] }), ['en', 'sk', 'cs'])).toBe('cs');
    expect(pickLanguage(languageCandidates({ tab: 'cs' }), ['en', 'sk'])).toBe('en');
    expect(pickLanguage(languageCandidates({ token: 'cs', browser: ['en'] }), ['en', 'sk'])).toBe('en');
    // The alias still counts for the browser when the same code was also asked for elsewhere.
    expect(pickLanguage(languageCandidates({ tab: 'cs', browser: ['cs'] }), ['en', 'sk'])).toBe('sk');
  });
});

describe('isEnglishPath(): the three pages that are English whatever the person reads', () => {
  it('is the address itself or anything under it, query and hash aside', () => {
    for (const address of ['/sources', '/sources/', '/sources/genshin-1', '/sources?run=3', '/sources#log', '/translations', '/translations/', '/translations?missing=sk', '/languages', '/languages/sk']) {
      expect(isEnglishPath(address), address).toBe(true);
    }
  });

  it('is no other address - not one that only begins or ends the same', () => {
    for (const address of ['/', '/import', '/builds/genshin', '/accounts/3/characters/hu-tao', '/sourcesx', '/source', '/translation', '/languages-old', '/builds/sources', '/x?next=/sources', '/sign-in?returnTo=/languages']) {
      expect(isEnglishPath(address), address).toBe(false);
    }
  });
});

describe('TranslationService', () => {
  /** What was asked for, in order. */
  let calls: string[] = [];
  /** What the server answers with - and, before it, the answers queued for the next requests. */
  let respond: (code: string) => Promise<unknown> = answer;
  let once: ((code: string) => Promise<unknown>)[] = [];
  /** Api.translations(): an Observable, as HttpClient gives one. */
  const api = {
    translations: (code: string) => {
      calls.push(code);
      return from((once.shift() ?? respond)(code));
    },
  };
  const loaded = signal(true);
  const mode = signal<AuthMode>('iam');
  const user = signal<{ locale: string | null } | null>(null);
  let warn: ReturnType<typeof vi.spyOn>;
  let browser: ReturnType<typeof vi.spyOn>;

  const fails = (error: unknown) => (): Promise<unknown> => Promise.reject(error);
  const gives = (value: unknown) => (): Promise<unknown> => Promise.resolve(value);

  function configure(): void {
    TestBed.configureTestingModule({
      providers: [
        { provide: Api, useValue: api },
        { provide: MetaStore, useValue: { loaded } },
        { provide: AuthService, useValue: { mode, user } },
      ],
    });
  }

  function service(): TranslationService {
    return TestBed.inject(TranslationService);
  }

  function cache(): TranslationBundle | null {
    return JSON.parse(localStorage.getItem(CACHE_KEY) ?? 'null');
  }

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    history.replaceState(null, '', '/');
    calls = [];
    respond = answer;
    once = [];
    loaded.set(true);
    mode.set('iam');
    user.set(null);
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    browser = vi.spyOn(window.navigator, 'languages', 'get').mockReturnValue(['en-GB']);
    configure();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('starts no request, and needs neither the API nor the metadata, while it is being built', () => {
    TestBed.resetTestingModule();
    const asked = vi.fn();
    TestBed.configureTestingModule({
      providers: [
        { provide: Api, useFactory: asked },
        { provide: MetaStore, useFactory: asked },
        { provide: AuthService, useValue: { mode, user } },
      ],
    });

    const i18n = TestBed.inject(TranslationService);
    TestBed.tick();

    expect(asked).not.toHaveBeenCalled();
    expect(calls).toEqual([]);
    // And it words a page all the same: the compiled English.
    expect(i18n.t('theme.label')).toBe('Theme');
  });

  describe('choosing the language as the site starts', () => {
    it('needs one request for a Slovak browser’s first visit: the server answers a regional code by its base language', async () => {
      browser.mockReturnValue(['sk-SK', 'sk']);
      respond = (code) => Promise.resolve(code === 'sk-sk' || code === 'sk' ? SK_BUNDLE : EN_BUNDLE);
      await service().init();

      expect(calls).toEqual(['sk-sk']);
      expect(service().language()).toBe('sk');
      expect(service().t('theme.label')).toBe('Vzhľad');
      expect(cache()?.language).toBe('sk');
    });

    it('asks for the first wish and, answered in English (an older server), settles on the list that came: two requests at most', async () => {
      browser.mockReturnValue(['sk-SK', 'en']);
      await service().init();

      expect(calls).toEqual(['sk-sk', 'sk']);
      expect(service().language()).toBe('sk');
      expect(service().languages()).toEqual(TEST_LANGUAGES);
      expect(service().t('theme.label')).toBe('Vzhľad');
    });

    it('starts in the database’s English when the language it settled on cannot be had', async () => {
      browser.mockReturnValue(['sk-SK']);
      once = [gives(EN_BUNDLE), fails(OFFLINE)];
      await service().init();

      expect(calls).toEqual(['sk-sk', 'sk']);
      expect(service().ready()).toBe(true);
      expect(service().language()).toBe('en');
      expect(service().t('theme.label')).toBe('Appearance');
    });

    it('needs one request when the first wish is on offer, or when nothing wished for is', async () => {
      browser.mockReturnValue(['sk']);
      await service().init();
      expect(calls).toEqual(['sk']);

      TestBed.resetTestingModule();
      configure();
      localStorage.clear();
      calls = [];
      browser.mockReturnValue(['de-DE', 'fr']);
      await service().init();
      expect(calls).toEqual(['de-de']);
      expect(service().language()).toBe('en');
    });

    it('gives a Czech browser Slovak', async () => {
      browser.mockReturnValue(['cs-CZ', 'cs', 'en']);
      await service().init();
      expect(service().language()).toBe('sk');
    });

    it('keeps ?lang= for this tab only, and takes it out of the address before the router reads it', async () => {
      history.replaceState(null, '', '/builds/genshin?tab=sets&lang=SK&x=a%20b#top');
      localStorage.setItem(LANGUAGE_KEY, 'en');
      await service().init();

      expect(service().language()).toBe('sk');
      expect(sessionStorage.getItem(LANGUAGE_KEY)).toBe('sk');
      expect(localStorage.getItem(LANGUAGE_KEY)).toBe('en');
      expect(location.pathname + location.search + location.hash).toBe('/builds/genshin?tab=sets&x=a%20b#top');
    });

    it('ignores a ?lang= that is not a language code: never stored, never sent - and still removed', async () => {
      history.replaceState(null, '', '/?lang=%3Cscript%3E');
      await service().init();

      expect(sessionStorage.getItem(LANGUAGE_KEY)).toBeNull();
      expect(location.search).toBe('');
      expect(calls).toEqual(['en-gb']);
      expect(service().language()).toBe('en');
    });

    it('keeps ?lang= and a choice for as long as the page lives where storage refuses to be written', async () => {
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('blocked');
      });
      browser.mockReturnValue(['en']);
      history.replaceState(null, '', '/?lang=sk');
      await service().init();

      // Nothing could be kept - and the link works all the same, with the parameter gone from the address.
      expect(sessionStorage.getItem(LANGUAGE_KEY)).toBeNull();
      expect(service().language()).toBe('sk');
      expect(location.search).toBe('');

      // A choice made here outlives the link - and the next start, whatever the browser would rather read.
      browser.mockReturnValue(['sk']);
      expect(await service().setLanguage('en')).toBe(true);
      expect(localStorage.getItem(LANGUAGE_KEY)).toBeNull();
      await service().init();
      expect(service().language()).toBe('en');
    });

    it('puts this tab before the menu’s choice, the choice before the token, the token before the browser', async () => {
      browser.mockReturnValue(['en']);
      user.set({ locale: 'sk' });
      await service().init();
      expect(service().language()).toBe('sk');
      // Used, not stored: a change on the IAM's account page arrives with the next token.
      expect(localStorage.getItem(LANGUAGE_KEY)).toBeNull();
      expect(sessionStorage.getItem(LANGUAGE_KEY)).toBeNull();

      localStorage.setItem(LANGUAGE_KEY, 'en');
      await service().init();
      expect(service().language()).toBe('en');

      sessionStorage.setItem(LANGUAGE_KEY, 'sk');
      await service().init();
      expect(service().language()).toBe('sk');
    });

    it('reads the token’s locale only where people sign in at the IAM', async () => {
      browser.mockReturnValue(['en']);
      mode.set('none');
      user.set({ locale: 'sk' });
      await service().init();

      expect(calls).toEqual(['en']);
      expect(service().language()).toBe('en');
    });

    it('adopts a changed locale of the token while nothing is stored here - without storing it', async () => {
      browser.mockReturnValue(['en']);
      await service().init();
      expect(service().language()).toBe('en');

      user.set({ locale: 'sk' });
      TestBed.tick();
      await vi.waitFor(() => expect(service().language()).toBe('sk'));
      expect(localStorage.getItem(LANGUAGE_KEY)).toBeNull();

      // Once somebody chose here, the token no longer decides.
      await service().setLanguage('en');
      user.set({ locale: 'en' });
      TestBed.tick();
      user.set({ locale: 'sk' });
      TestBed.tick();
      await settled();
      expect(service().language()).toBe('en');
    });

    it('does not follow the token before the start has run', async () => {
      user.set({ locale: 'sk' });
      service();
      TestBed.tick();
      await settled();

      expect(calls).toEqual([]);
      expect(service().language()).toBe('en');
    });
  });

  describe('the cache', () => {
    it('starts, asks the server and words the page when the cache is corrupt', async () => {
      for (const corrupt of [
        '{not json',
        '[]',
        'null',
        JSON.stringify({ language: 'en', languages: 'en', values: ENGLISH }),
        JSON.stringify({ language: 'EN', languages: TEST_LANGUAGES, values: ENGLISH }),
        JSON.stringify({ language: 'en', languages: [{ code: 'sk', name: 'Slovak', native_name: 'Slovenčina' }], values: ENGLISH }),
        JSON.stringify({ language: 'en', languages: [{ code: 'en', name: 'English' }], values: ENGLISH }),
        // The other projects' shape of a language: not this one's.
        JSON.stringify({ language: 'en', languages: [{ code: 'en', name: 'English', nativeName: 'English' }], values: ENGLISH }),
        JSON.stringify({ language: 'en', languages: TEST_LANGUAGES, values: { 'theme.label': 7 } }),
        JSON.stringify({ language: 'en', languages: TEST_LANGUAGES, values: ['Theme'] }),
        // A bundle whose values are empty is no bundle.
        JSON.stringify({ language: 'en', languages: TEST_LANGUAGES, values: {} }),
        JSON.stringify({ language: 'sk', languages: TEST_LANGUAGES, values: SLOVAK }),
      ]) {
        TestBed.resetTestingModule();
        configure();
        calls = [];
        // The last one is Slovak without its English: asked for in Slovak, so it is its shape that fails it.
        browser.mockReturnValue(corrupt.includes('"language":"sk"') ? ['sk'] : ['en']);
        localStorage.setItem(CACHE_KEY, corrupt);

        await service().init();

        expect(calls.length, corrupt).toBeGreaterThan(0);
        expect(service().ready(), corrupt).toBe(true);
        expect(service().t('theme.label'), corrupt).toBe(service().language() === 'sk' ? 'Vzhľad' : 'Appearance');
        // What the server sent has taken the corrupt cache's place.
        expect(cache()?.values['theme.label'], corrupt).toBeTruthy();
      }
    });

    it('is not used when it is in another language than the one chosen now', async () => {
      localStorage.setItem(CACHE_KEY, JSON.stringify(SK_BUNDLE));
      browser.mockReturnValue(['en']);
      const server = later<unknown>();
      respond = () => server.promise;

      const started = service().init();
      // No cache: nothing is shown in Slovak meanwhile, and the start waits for the server.
      expect(service().ready()).toBe(false);
      expect(localStorage.getItem(CACHE_KEY)).toBeNull();
      server.resolve(EN_BUNDLE);
      await started;
      expect(service().language()).toBe('en');
      expect(service().ready()).toBe(true);
    });

    it('follows a language offered again (or added) at once, not one visit late: it settles on the list that came, whatever answered', async () => {
      // Slovak was chosen here; while it was hidden, the visit was in English and that is what was kept.
      localStorage.setItem(LANGUAGE_KEY, 'sk');
      localStorage.setItem(CACHE_KEY, JSON.stringify({ language: 'en', languages: [TEST_LANGUAGES[0]], values: ENGLISH }));
      await service().init();

      // The kept language is asked for - and its answer names Slovak again.
      expect(calls).toEqual(['en', 'sk']);
      expect(service().language()).toBe('sk');
      expect(service().t('theme.label')).toBe('Vzhľad');
      expect(cache()?.language).toBe('sk');
    });

    it('follows a language hidden since the last visit: the cache is in force only until the answer', async () => {
      localStorage.setItem(LANGUAGE_KEY, 'sk');
      localStorage.setItem(CACHE_KEY, JSON.stringify(SK_BUNDLE));
      const english = { language: 'en', languages: [TEST_LANGUAGES[0]], values: ENGLISH };
      respond = gives(english);
      await service().init();

      expect(calls).toEqual(['sk']);
      expect(service().language()).toBe('en');
      expect(service().languages()).toEqual([TEST_LANGUAGES[0]]);
      expect(cache()).toEqual(english);
    });

    it('waits a second for the fresh bundle, then starts from the cache - and the answer replaces it when it comes', async () => {
      vi.useFakeTimers();
      localStorage.setItem(CACHE_KEY, JSON.stringify(reworded('Téma')));
      browser.mockReturnValue(['sk']);
      const server = later<unknown>();
      respond = () => server.promise;

      let started = false;
      const starting = service()
        .init()
        .then(() => (started = true));
      await vi.advanceTimersByTimeAsync(CACHE_WAIT_MS - 1);
      expect(started).toBe(false);
      await vi.advanceTimersByTimeAsync(1);
      await starting;

      expect(calls).toEqual(['sk']);
      expect(service().language()).toBe('sk');
      expect(service().t('theme.label')).toBe('Téma');

      vi.useRealTimers();
      server.resolve(SK_BUNDLE);
      await vi.waitFor(() => expect(service().t('theme.label')).toBe('Vzhľad'));
      expect(cache()?.values['theme.label']).toBe('Vzhľad');
    });

    it('does not wait the second out when the server answers, or fails, sooner', async () => {
      vi.useFakeTimers();
      localStorage.setItem(CACHE_KEY, JSON.stringify({ ...EN_BUNDLE, values: { 'theme.label': 'Look' } }));
      await service().init();
      expect(service().t('theme.label')).toBe('Appearance');

      respond = fails(OFFLINE);
      localStorage.setItem(CACHE_KEY, JSON.stringify({ ...EN_BUNDLE, values: { 'theme.label': 'Look' } }));
      await service().init();
      expect(service().t('theme.label')).toBe('Look');
    });

    it('with no cache waits at most three seconds, then starts on the compiled English - and the bundle is put in force when it arrives', async () => {
      vi.useFakeTimers();
      browser.mockReturnValue(['sk']);
      const server = later<unknown>();
      respond = () => server.promise;

      let started = false;
      const starting = service()
        .init()
        .then(() => (started = true));
      await vi.advanceTimersByTimeAsync(FIRST_WAIT_MS - 1);
      expect(started).toBe(false);
      await vi.advanceTimersByTimeAsync(1);
      await starting;

      // One request, still out. The page is readable meanwhile: English, from the app itself.
      expect(calls).toEqual(['sk']);
      expect(service().ready()).toBe(false);
      expect(service().language()).toBe('en');
      expect(service().t('theme.label')).toBe('Theme');
      expect(warn).not.toHaveBeenCalled();

      vi.useRealTimers();
      server.resolve(SK_BUNDLE);
      await vi.waitFor(() => expect(service().t('theme.label')).toBe('Vzhľad'));
      expect(service().language()).toBe('sk');
      expect(cache()?.language).toBe('sk');
    });

    it('reads the compiled English - one request, no key, no warning - when there is no cache and the route answers 503 or not at all', async () => {
      for (const failure of [UNAVAILABLE, OFFLINE]) {
        TestBed.resetTestingModule();
        configure();
        calls = [];
        respond = fails(failure);

        await service().init();

        expect(calls).toEqual(['en-gb']);
        expect(service().ready()).toBe(false);
        expect(service().languages()).toEqual([]);
        expect(service().has('theme.label')).toBe(true);
        expect(service().t('theme.label')).toBe('Theme');
        expect(service().t('signIn.failed', { code: 'state_mismatch' })).toBe('The sign-in did not complete: state_mismatch');
        expect(service().plural('buildDetail.optionAny', 2)).toBe('(any 2)');
        // Nobody chose this English: signing in does not hand it to the IAM.
        expect(service().signInLanguage()).toBeUndefined();
      }
      expect(warn).not.toHaveBeenCalled();
      expect(localStorage.getItem(CACHE_KEY)).toBeNull();
    });

    it('asks for nothing while the server cannot be asked (not set up, or out of reach): the cache if there is one', async () => {
      loaded.set(false);
      await service().init();
      expect(calls).toEqual([]);
      expect(service().ready()).toBe(false);
      expect(service().t('theme.label')).toBe('Theme');

      localStorage.setItem(CACHE_KEY, JSON.stringify(EN_BUNDLE));
      await service().init();
      expect(calls).toEqual([]);
      expect(service().ready()).toBe(true);
      expect(service().t('theme.label')).toBe('Appearance');
    });

    it('takes the cache in whatever language it is in while the server cannot be asked - and leaves it where it is', async () => {
      // A reader whose Slovak came from the token: nobody is signed in now, and the browser says English.
      localStorage.setItem(CACHE_KEY, JSON.stringify(SK_BUNDLE));
      browser.mockReturnValue(['en']);
      loaded.set(false);
      await service().init();

      expect(calls).toEqual([]);
      expect(service().language()).toBe('sk');
      expect(service().t('theme.label')).toBe('Vzhľad');
      expect(cache()).toEqual(SK_BUNDLE);

      // A corrupt one is removed all the same.
      localStorage.setItem(CACHE_KEY, '{not json');
      await service().init();
      expect(localStorage.getItem(CACHE_KEY)).toBeNull();
    });

    it('takes nothing from the server that is not a bundle - one whose values are empty is none', async () => {
      for (const notOne of [
        { language: 'en', languages: TEST_LANGUAGES, values: { 'theme.label': ['Theme'] } },
        { language: 'en', languages: TEST_LANGUAGES, values: {} },
        { language: 'sk', languages: TEST_LANGUAGES, values: {}, english: {} },
        { message: 'Not found.', code: 'not_found' },
        null,
      ]) {
        TestBed.resetTestingModule();
        configure();
        respond = gives(notOne);
        await service().init();

        expect(service().ready(), JSON.stringify(notOne)).toBe(false);
        expect(service().t('theme.label'), JSON.stringify(notOne)).toBe('Theme');
        expect(localStorage.getItem(CACHE_KEY), JSON.stringify(notOne)).toBeNull();
      }
    });
  });

  describe('asking again: a page that started without its strings gets them at a later navigation', () => {
    /** A start with no cache and no bundle to be had: the page reads the compiled English. */
    async function startWithoutWords(): Promise<void> {
      respond = fails(UNAVAILABLE);
      await service().init();
      expect(service().ready()).toBe(false);
      calls = [];
    }

    it('does not ask at the first navigation of a visit, which ends a moment after the start asked', async () => {
      browser.mockReturnValue(['sk']);
      await startWithoutWords();

      service().noteAddress('/');
      await settled();
      expect(calls).toEqual([]);
    });

    it('asks once at each later navigation while no bundle is in memory - and no more once one has come', async () => {
      browser.mockReturnValue(['sk']);
      await startWithoutWords();
      service().noteAddress('/');

      service().noteAddress('/import');
      await settled();
      expect(calls).toEqual(['sk']);
      expect(service().ready()).toBe(false);

      service().noteAddress('/builds/genshin');
      await settled();
      expect(calls).toEqual(['sk', 'sk']);

      // Apply was pressed: the next click brings the strings, in the reader's language.
      respond = answer;
      service().noteAddress('/');
      await settled();
      expect(calls).toHaveLength(3);
      expect(service().language()).toBe('sk');
      expect(service().t('theme.label')).toBe('Vzhľad');
      expect(cache()?.language).toBe('sk');

      service().noteAddress('/builds/genshin');
      service().noteAddress('/sources');
      await settled();
      expect(calls).toHaveLength(3);
    });

    it('asks again while the cached bundle is in force because its refresh failed', async () => {
      localStorage.setItem(CACHE_KEY, JSON.stringify({ ...EN_BUNDLE, values: { ...ENGLISH, 'theme.label': 'Look' } }));
      respond = fails(OFFLINE);
      await service().init();
      expect(service().t('theme.label')).toBe('Look');
      calls = [];
      service().noteAddress('/');

      service().noteAddress('/import');
      await settled();
      expect(calls).toEqual(['en']);
      expect(service().t('theme.label')).toBe('Look');

      respond = answer;
      service().noteAddress('/builds/genshin');
      await settled();
      expect(calls).toEqual(['en', 'en']);
      expect(service().t('theme.label')).toBe('Appearance');
      expect(cache()?.values['theme.label']).toBe('Appearance');

      service().noteAddress('/');
      await settled();
      expect(calls).toHaveLength(2);
    });

    it('never has two loads in flight: a navigation asks for nothing while an answer is still on its way', async () => {
      await startWithoutWords();
      service().noteAddress('/');
      const server = later<unknown>();
      respond = () => server.promise;

      service().noteAddress('/import');
      service().noteAddress('/builds/genshin');
      await settled();
      service().noteAddress('/');
      expect(calls).toHaveLength(1);

      server.resolve(EN_BUNDLE);
      await settled();
      expect(service().ready()).toBe(true);
      service().noteAddress('/builds/genshin');
      expect(calls).toHaveLength(1);
    });

    it('leaves a start that is still waiting for its fresh bundle alone', async () => {
      vi.useFakeTimers();
      localStorage.setItem(CACHE_KEY, JSON.stringify(reworded('Téma')));
      browser.mockReturnValue(['sk']);
      const slow = later<unknown>();
      respond = () => slow.promise;
      const starting = service().init();
      await vi.advanceTimersByTimeAsync(CACHE_WAIT_MS);
      await starting;
      vi.useRealTimers();

      // The page started from the cache; its refresh is still out - no navigation asks a second time.
      service().noteAddress('/');
      service().noteAddress('/import');
      await settled();
      expect(calls).toEqual(['sk']);

      slow.resolve(SK_BUNDLE);
      await settled();
      expect(service().t('theme.label')).toBe('Vzhľad');
      service().noteAddress('/builds/genshin');
      expect(calls).toHaveLength(1);
    });

    it('never overtakes a choice that is being loaded', async () => {
      await startWithoutWords();
      service().noteAddress('/');
      const slovak = later<unknown>();
      respond = () => slovak.promise;

      const choosing = service().setLanguage('sk');
      service().noteAddress('/builds/genshin');
      await settled();
      expect(calls).toEqual(['sk']);

      slovak.resolve(SK_BUNDLE);
      expect(await choosing).toBe(true);
      expect(service().language()).toBe('sk');
    });

    it('asks for nothing before the start has run, nor while the server cannot be asked', async () => {
      service().noteAddress('/');
      service().noteAddress('/import');
      await settled();
      expect(calls).toEqual([]);

      loaded.set(false);
      await service().init();
      service().noteAddress('/');
      service().noteAddress('/sources');
      await settled();
      expect(calls).toEqual([]);
      // Where the page is is noted all the same.
      expect(service().englishOnly()).toBe(true);
    });
  });

  describe('reload(): the strings again, after somebody changed them or the languages - or pressed Apply', () => {
    it('asks for the bundle of the language on the page again, and puts it in force and in the cache', async () => {
      localStorage.setItem(LANGUAGE_KEY, 'sk');
      await service().init();
      expect(service().t('theme.label')).toBe('Vzhľad');
      calls = [];

      respond = (code) => Promise.resolve(code === 'sk' ? reworded('Téma') : EN_BUNDLE);
      await service().reload();

      expect(calls).toEqual(['sk']);
      expect(service().language()).toBe('sk');
      expect(service().t('theme.label')).toBe('Téma');
      expect(cache()?.values['theme.label']).toBe('Téma');
    });

    it('brings the strings to a page that had none: after Apply on the Sources page the first wish is asked for', async () => {
      localStorage.setItem(LANGUAGE_KEY, 'sk');
      respond = fails(UNAVAILABLE);
      await service().init();
      service().noteAddress('/sources');
      expect(service().ready()).toBe(false);
      expect(service().t('theme.label')).toBe('Theme');
      calls = [];

      respond = answer;
      await service().reload();

      expect(calls).toEqual(['sk']);
      expect(service().ready()).toBe(true);
      expect(service().language()).toBe('sk');
      // Still English here - the Sources page is - and now the database's.
      expect(service().t('theme.label')).toBe('Appearance');
      service().noteAddress('/');
      expect(service().t('theme.label')).toBe('Vzhľad');
    });

    it('asks in the person’s language on the English pages too - its bundle carries the English they read', async () => {
      localStorage.setItem(LANGUAGE_KEY, 'sk');
      await service().init();
      service().noteAddress('/translations');
      expect(service().t('theme.label')).toBe('Appearance');
      calls = [];

      respond = gives({ ...SK_BUNDLE, english: { ...ENGLISH, 'theme.label': 'Look' } });
      await service().reload();

      expect(calls).toEqual(['sk']);
      expect(service().t('theme.label')).toBe('Look');
      service().noteAddress('/');
      expect(service().t('theme.label')).toBe('Vzhľad');
    });

    it('follows a language hidden a moment ago: the page goes to what the list that came still offers', async () => {
      localStorage.setItem(LANGUAGE_KEY, 'sk');
      await service().init();
      calls = [];

      respond = gives({ language: 'en', languages: [TEST_LANGUAGES[0]], values: ENGLISH });
      await service().reload();

      expect(calls).toEqual(['sk']);
      expect(service().language()).toBe('en');
      expect(service().languages()).toEqual([TEST_LANGUAGES[0]]);
      expect(cache()?.language).toBe('en');
    });

    it('follows a language offered a moment ago: the reader who chose it gets it without loading the page again', async () => {
      localStorage.setItem(LANGUAGE_KEY, 'sk');
      respond = gives({ language: 'en', languages: [TEST_LANGUAGES[0]], values: ENGLISH });
      await service().init();
      expect(service().language()).toBe('en');
      calls = [];

      respond = answer;
      await service().reload();

      expect(calls).toEqual(['en', 'sk']);
      expect(service().language()).toBe('sk');
      expect(service().languages()).toEqual(TEST_LANGUAGES);
      expect(cache()?.language).toBe('sk');
    });

    it('never rejects: the page stays as it is when the server cannot be had - and asks again at the next navigation', async () => {
      localStorage.setItem(LANGUAGE_KEY, 'sk');
      await service().init();
      // Fresh from the server: a navigation asks for nothing.
      service().noteAddress('/');
      service().noteAddress('/import');
      calls = [];

      respond = fails(OFFLINE);
      await expect(service().reload()).resolves.toBeUndefined();
      expect(service().language()).toBe('sk');
      expect(service().t('theme.label')).toBe('Vzhľad');
      expect(calls).toHaveLength(1);

      // Known to be behind now: the next click asks once more.
      respond = (code) => Promise.resolve(code === 'sk' ? reworded('Téma') : EN_BUNDLE);
      service().noteAddress('/builds/genshin');
      await settled();
      expect(calls).toHaveLength(2);
      expect(service().t('theme.label')).toBe('Téma');
    });

    it('is the load that counts: an answer still on its way from before the change is dropped', async () => {
      vi.useFakeTimers();
      localStorage.setItem(CACHE_KEY, JSON.stringify(SK_BUNDLE));
      browser.mockReturnValue(['sk']);
      const before = later<unknown>();
      once = [() => before.promise];
      const starting = service().init();
      await vi.advanceTimersByTimeAsync(CACHE_WAIT_MS);
      await starting;
      vi.useRealTimers();

      respond = gives(reworded('Téma'));
      await service().reload();
      expect(service().t('theme.label')).toBe('Téma');

      // The start's answer was made before the save: it must not put the old word back.
      before.resolve(SK_BUNDLE);
      await settled();
      expect(service().t('theme.label')).toBe('Téma');
      expect(cache()?.values['theme.label']).toBe('Téma');
    });
  });

  describe('setLanguage(): the bar’s menu', () => {
    it('loads the bundle, then switches and remembers - and the choice outlives the ?lang= of this tab', async () => {
      sessionStorage.setItem(LANGUAGE_KEY, 'en');
      await service().init();
      calls = [];

      const server = later<unknown>();
      respond = () => server.promise;
      const switching = service().setLanguage('sk');
      expect(service().language()).toBe('en');
      server.resolve(SK_BUNDLE);

      expect(await switching).toBe(true);
      expect(service().language()).toBe('sk');
      expect(service().signInLanguage()).toBe('sk');
      expect(localStorage.getItem(LANGUAGE_KEY)).toBe('sk');
      expect(sessionStorage.getItem(LANGUAGE_KEY)).toBeNull();
      expect(cache()?.language).toBe('sk');
    });

    it('lets the last choice win: going back to the language on the page drops the one still on its way', async () => {
      await service().init();
      const slow = later<unknown>();
      respond = (code) => (code === 'sk' ? slow.promise : Promise.resolve(EN_BUNDLE));

      const first = service().setLanguage('sk');
      // A change of mind, back to English: no request is needed - and it is a choice all the same.
      expect(await service().setLanguage('en')).toBe(true);
      slow.resolve(SK_BUNDLE);

      expect(await first).toBe(false);
      expect(service().language()).toBe('en');
      expect(service().t('theme.label')).toBe('Appearance');
      expect(localStorage.getItem(LANGUAGE_KEY)).toBe('en');
    });

    it('lets the last choice win with two loads in flight: the slower, earlier answer is dropped when it comes', async () => {
      const czech: TranslationBundle = {
        language: 'cs',
        languages: [...TEST_LANGUAGES, { code: 'cs', name: 'Czech', native_name: 'Čeština' }],
        values: { ...ENGLISH, 'theme.label': 'Vzhled' },
        english: ENGLISH,
      };
      await service().init();
      const slow = later<unknown>();
      respond = (code) => (code === 'sk' ? slow.promise : Promise.resolve(code === 'cs' ? czech : EN_BUNDLE));

      const first = service().setLanguage('sk');
      expect(await service().setLanguage('cs')).toBe(true);
      slow.resolve(SK_BUNDLE);

      expect(await first).toBe(false);
      expect(service().language()).toBe('cs');
      expect(service().t('theme.label')).toBe('Vzhled');
      expect(localStorage.getItem(LANGUAGE_KEY)).toBe('cs');
      expect(cache()?.language).toBe('cs');
    });

    it('is not undone by a start’s answer that comes late', async () => {
      vi.useFakeTimers();
      localStorage.setItem(CACHE_KEY, JSON.stringify(SK_BUNDLE));
      browser.mockReturnValue(['sk']);
      const slow = later<unknown>();
      respond = (code) => (code === 'sk' ? slow.promise : Promise.resolve(EN_BUNDLE));
      const starting = service().init();
      await vi.advanceTimersByTimeAsync(CACHE_WAIT_MS);
      await starting;
      vi.useRealTimers();
      expect(service().language()).toBe('sk');

      expect(await service().setLanguage('en')).toBe(true);
      slow.resolve(SK_BUNDLE);
      await settled();

      expect(service().language()).toBe('en');
      expect(cache()?.language).toBe('en');
    });

    it('never rejects: false, and the page stays as it is, when the strings cannot be had', async () => {
      await service().init();
      respond = fails(new HttpErrorResponse({ status: 500, error: { message: 'Something went wrong on the server.', code: 'server_error' } }));

      expect(await service().setLanguage('sk')).toBe(false);
      expect(service().language()).toBe('en');
      expect(service().t('theme.label')).toBe('Appearance');
      expect(localStorage.getItem(LANGUAGE_KEY)).toBeNull();
    });

    it('does not switch to a language the server no longer offers, and asks for nothing that is not a code', async () => {
      await service().init();
      calls = [];

      expect(await service().setLanguage('de')).toBe(false);
      expect(service().language()).toBe('en');
      expect(localStorage.getItem(LANGUAGE_KEY)).toBeNull();

      calls = [];
      expect(await service().setLanguage('../admin')).toBe(false);
      expect(calls).toEqual([]);
    });
  });
});

describe('TranslationService: wording', () => {
  let warn: ReturnType<typeof vi.spyOn>;

  function slovak(): TranslationService {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH })] });
    return TestBed.inject(TranslationService);
  }

  function english(): TranslationService {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [provideTestTranslations(ENGLISH)] });
    return TestBed.inject(TranslationService);
  }

  beforeEach(() => {
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
  });

  afterEach(() => vi.restoreAllMocks());

  it('fills placeholders in one pass: what a parameter holds is never filled again', () => {
    const i18n = english();
    expect(i18n.t('import.error.file_game_mismatch', { file_game: '{chosen_game}', chosen_game: 'Genshin Impact' })).toBe('This file contains {chosen_game} data, but you chose Genshin Impact.');
    expect(i18n.t('import.error.file_game_mismatch', { file_game: 'Star {file_game}', chosen_game: '{file_game}' })).toBe('This file contains Star {file_game} data, but you chose {file_game}.');
  });

  it('leaves a placeholder with no parameter as written', () => {
    const i18n = english();
    expect(i18n.t('import.error.file_game_mismatch')).toBe('This file contains {file_game} data, but you chose {chosen_game}.');
    expect(i18n.t('import.error.file_game_mismatch', { file_game: 'Genshin Impact' })).toBe('This file contains Genshin Impact data, but you chose {chosen_game}.');
    expect(i18n.t('import.error.file_game_mismatch', { file_game: null, chosen_game: 0 })).toBe('This file contains {file_game} data, but you chose 0.');
    // Not a name a parameter object merely inherits: `constructor` and `toString` are on every object.
    expect(i18n.t('error.http', { status: 1 })).toBe('x {constructor} {toString} y');
    expect(i18n.t('error.http', { constructor: 'a', toString: 'b' } as unknown as Record<string, string>)).toBe('x a b y');
  });

  it('with a bundle in memory gives a key it lacks back as the key, and warns once - the compiled English never fills the hole', () => {
    const i18n = english();
    // The compiled English has it; the bundle does not.
    expect(en['title.builds']).toBe('Builds');
    expect(i18n.t('title.builds')).toBe('title.builds');
    expect(i18n.t('title.builds', { name: 'x' })).toBe('title.builds');
    expect(i18n.has('title.builds')).toBe(false);
    expect(warn.mock.calls).toEqual([["[i18n] no string for 'title.builds'"]]);
  });

  it('has only the keys of the bundle - nothing an object would inherit', () => {
    const i18n = english();
    expect(i18n.has('theme.label')).toBe(true);
    expect(i18n.has('constructor')).toBe(false);
    expect(i18n.has('toString')).toBe(false);
    expect(i18n.has('__proto__')).toBe(false);
    expect(i18n.t('constructor' as TranslationKey)).toBe('constructor');
  });

  it('with no bundle reads the compiled English - and nothing an object would inherit either', () => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [{ provide: AuthService, useValue: { mode: signal('unavailable'), user: signal(null) } }] });
    const i18n = TestBed.inject(TranslationService);

    expect(i18n.ready()).toBe(false);
    expect(i18n.language()).toBe('en');
    for (const key of Object.keys(en) as TranslationKey[]) {
      expect(i18n.t(key), key).toBe(en[key]);
    }
    expect(i18n.t('error.http', { status: 502 })).toBe('Request failed (HTTP 502).');
    expect(i18n.around('signIn.failed', 'code')).toEqual(['The sign-in did not complete: ', '', true]);
    expect(warn).not.toHaveBeenCalled();

    expect(i18n.has('constructor')).toBe(false);
    expect(i18n.t('constructor' as TranslationKey)).toBe('constructor');
    expect(i18n.t('no.such' as TranslationKey)).toBe('no.such');
    expect(warn).toHaveBeenCalledTimes(2);
  });

  it('cuts a sentence around a placeholder before filling the halves', () => {
    const i18n = english();
    expect(i18n.around('import.error.file_game_mismatch', 'file_game', { chosen_game: 'Genshin Impact' })).toEqual(['This file contains ', ' data, but you chose Genshin Impact.', true]);
    // The cut is made in the stored string: a parameter cannot move it.
    expect(i18n.around('import.error.file_game_mismatch', 'chosen_game', { file_game: '{chosen_game}' })).toEqual(['This file contains {chosen_game} data, but you chose ', '.', true]);
    expect(slovak().around('import.error.file_game_mismatch', 'file_game', { chosen_game: 'Genshin Impact' })).toEqual(['Súbor obsahuje údaje z hry ', ', ale zvolená hra je Genshin Impact.', true]);
  });

  it('says when the cut was not made, so the page does not glue the value onto a sentence that left it out - or onto a key', () => {
    const i18n = english();
    // A string without the placeholder (another language may leave one out): the whole sentence, and nothing to mark up.
    expect(i18n.around('theme.label', 'name')).toEqual(['Appearance', '', false]);
    expect(i18n.around('import.error.file_game_mismatch', 'game', { file_game: 'A', chosen_game: 'B' })).toEqual(['This file contains A data, but you chose B.', '', false]);
    // No string at all: the key, as everywhere - and it warns.
    expect(i18n.around('title.builds', 'name')).toEqual(['title.builds', '', false]);
    expect(warn.mock.calls).toEqual([["[i18n] no string for 'title.builds'"]]);
  });

  it('picks the plural form English asks for', () => {
    const i18n = english();
    expect([0, 1, 2, 5, 12].map((count) => i18n.plural('buildDetail.optionAny', count))).toEqual(['(any 0)', '(any 1)', '(any 2)', '(any 5)', '(any 12)']);
  });

  it('picks the plural form Slovak asks for: 1, 2 to 4, and 0 with 5 and more', () => {
    const i18n = slovak();
    expect([0, 1, 2, 3, 4, 5, 12, 22].map((count) => i18n.plural('buildDetail.optionAny', count))).toEqual([
      '(ľubovoľných 0)',
      '(ľubovoľný 1)',
      '(ľubovoľné 2)',
      '(ľubovoľné 3)',
      '(ľubovoľné 4)',
      '(ľubovoľných 5)',
      '(ľubovoľných 12)',
      '(ľubovoľných 22)',
    ]);
    // A form the family does not have (Slovak "many", for fractions) is the family's "other".
    expect(i18n.plural('buildDetail.optionAny', 1.5)).toBe('(ľubovoľných 1,5)');
    // Inside a family the number is {count}, whatever else is handed in.
    expect(i18n.plural('buildDetail.optionAny', 3, { count: 99 })).toBe('(ľubovoľné 3)');
  });

  it('writes a count the way the language on the page writes numbers', () => {
    expect(english().number(11523)).toBe('11,523');
    expect(english().plural('buildDetail.optionAny', 11523)).toBe('(any 11,523)');
    // Slovak groups with a no-break space.
    expect(slovak().number(11523)).toBe('11 523');
    expect(slovak().plural('buildDetail.optionAny', 11523)).toBe('(ľubovoľných 11 523)');
    expect(slovak().number(87)).toBe('87');
  });

  it('is English on the three English pages, whatever the person reads - and says so on <html lang>', () => {
    const i18n = slovak();
    TestBed.tick();
    expect(i18n.effectiveLanguage()).toBe('sk');
    expect(i18n.locale()).toBe('sk-SK');
    expect(i18n.storedEnglishLang()).toBe('en');
    expect(document.documentElement.lang).toBe('sk');

    for (const address of ['/sources', '/sources/', '/sources?run=3', '/translations', '/translations#theme', '/languages', '/languages/sk']) {
      i18n.noteAddress(address);
      expect(i18n.englishOnly(), address).toBe(true);
    }
    TestBed.tick();
    expect(i18n.language()).toBe('sk');
    expect(i18n.effectiveLanguage()).toBe('en');
    expect(i18n.locale()).toBe('en-GB');
    expect(i18n.storedEnglishLang()).toBeNull();
    expect(i18n.t('theme.label')).toBe('Appearance');
    expect(i18n.plural('buildDetail.optionAny', 3)).toBe('(any 3)');
    expect(i18n.number(11523)).toBe('11,523');
    // Signing in still says the person's own language.
    expect(i18n.signInLanguage()).toBe('sk');
    expect(document.documentElement.lang).toBe('en');

    for (const address of ['/', '/import', '/builds/genshin', '/sourcesx', '/builds/sources', '/x?next=/sources']) {
      i18n.noteAddress(address);
      expect(i18n.englishOnly(), address).toBe(false);
    }
    TestBed.tick();
    expect(i18n.t('theme.label')).toBe('Vzhľad');
    expect(document.documentElement.lang).toBe('sk');
  });

  it('switches between the bundles a spec was given, with no HTTP', async () => {
    const i18n = slovak();
    expect(await i18n.setLanguage('en', false)).toBe(true);
    expect(i18n.t('theme.label')).toBe('Appearance');
    expect(await i18n.setLanguage('sk', false)).toBe(true);
    expect(i18n.t('theme.label')).toBe('Vzhľad');
  });

  it('has an init() and a reload() in a spec that answer at once and leave the language the spec chose', async () => {
    const i18n = slovak();
    await expect(i18n.init()).resolves.toBeUndefined();
    await expect(i18n.reload()).resolves.toBeUndefined();
    expect(i18n.language()).toBe('sk');
    expect(i18n.t('theme.label')).toBe('Vzhľad');
  });
});
