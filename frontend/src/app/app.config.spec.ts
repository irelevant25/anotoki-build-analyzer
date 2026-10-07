import { HttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ApplicationInitStatus } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, TitleStrategy } from '@angular/router';
import { SiteStatus } from '@anotoki/lib/migrations';
import { appConfig } from './app.config';
import { AUTH_CLIENT_FACTORY, AuthService } from './core/auth';
import { AnotokiAuth, AnotokiInitResult, AnotokiUser } from './core/iam/anotoki-auth';
import { TEST_LANGUAGES } from './core/i18n/testing';
import { AppTitleStrategy } from './core/i18n/title-strategy';
import { TranslationService } from './core/i18n/translation';
import { Meta, TranslationBundle } from './core/models';
import { VISITOR_STORAGE_KEY, VisitorKey } from './core/visitor-key';

const META: Meta = { games: [], version: '0.1', auth: { issuer: null, client_id: null } };
const IAM_META: Meta = { ...META, auth: { issuer: 'https://iam.example.test', client_id: 'build-analyzer' } };

const ENGLISH = { 'theme.label': 'Appearance' };
const SK_BUNDLE: TranslationBundle = { language: 'sk', languages: TEST_LANGUAGES, values: { 'theme.label': 'Vzhľad' }, english: ENGLISH };
const EN_BUNDLE: TranslationBundle = { language: 'en', languages: TEST_LANGUAGES, values: ENGLISH };

const MONA: AnotokiUser = { sub: '7', preferred_username: 'mona', email: 'mona@example.test', email_verified: true, locale: 'sk', roles: [], preferences: null };

/** Everything already on its way has run. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

/** A stand-in for the IAM client whose init() ends when the test says. */
function fakeClient(user: AnotokiUser | null) {
  let finish!: (result: AnotokiInitResult) => void;
  const started = new Promise<AnotokiInitResult>((resolve) => (finish = resolve));
  const client = {
    version: 'test',
    init: vi.fn(() => started),
    login: vi.fn(async () => undefined),
    register: vi.fn(async () => undefined),
    logout: vi.fn(async () => undefined),
    getAccessToken: vi.fn(async () => 'token-1'),
    refresh: vi.fn(async () => 'token-2'),
    fetch: vi.fn(),
    savePreferences: vi.fn(async () => ({ locale: user?.locale ?? null, preferences: user?.preferences ?? null })),
    onChange: vi.fn(() => () => undefined),
    isAuthenticated: () => user !== null,
    user: () => user,
    roles: () => user?.roles ?? [],
    hasRole: (role: string) => user?.roles.includes(role) ?? false,
    accountUrl: () => 'https://iam.example.test/account',
    issuer: () => 'https://iam.example.test',
    clientId: () => 'build-analyzer',
  } satisfies AnotokiAuth;
  return { client, finish: () => finish({ returnTo: null, error: null }) };
}

describe('the start of the app (app.config.ts)', () => {
  let requests: HttpTestingController;

  function start(client: AnotokiAuth = fakeClient(null).client): void {
    TestBed.configureTestingModule({
      providers: [...appConfig.providers, provideHttpClientTesting(), { provide: AUTH_CLIENT_FACTORY, useValue: () => client }],
    });
    // Asking for anything builds the app's injector, which runs the initializer.
    requests = TestBed.inject(HttpTestingController);
  }

  const started = () => TestBed.inject(ApplicationInitStatus).donePromise;
  const translations = () => requests.match((request) => request.url.startsWith('/api/translations/'));
  /** The site's status, asked at the start beside /api/meta: what the server says of it. */
  const site = (state: 'ready' | 'update_pending' | 'not_set_up', installed = false) =>
    requests.expectOne('/api/site-status').flush({ state, installed });

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

  it('gets the words after /api/meta and the sign-in, before the first page - and the Router can be built beside them', async () => {
    start();
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(META);
    await settle();

    const asked = translations();
    expect(asked.map((request) => request.request.url)).toEqual(['/api/translations/en']);
    expect(asked[0].request.headers.has('Authorization')).toBe(false);
    asked[0].flush(EN_BUNDLE);
    await started();

    const i18n = TestBed.inject(TranslationService);
    expect(i18n.ready()).toBe(true);
    expect(i18n.t('theme.label')).toBe('Appearance');
    // The Router builds the title strategy, which needs the service: no circle.
    expect(TestBed.inject(Router)).toBeTruthy();
    expect(TestBed.inject(TitleStrategy)).toBeInstanceOf(AppTitleStrategy);
    expect(TestBed.inject(SiteStatus).state()).toBe('ready');
  });

  it('asks for the words only once the sign-in has finished - in the language the token names', async () => {
    const { client, finish } = fakeClient(MONA);
    start(client);
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(IAM_META);
    await settle();

    // The IAM client is still finishing the sign-in: who the reader is - and so their language - is not known yet.
    expect(client.init).toHaveBeenCalledTimes(1);
    expect(translations()).toEqual([]);

    finish();
    await settle();
    const asked = translations();
    expect(asked.map((request) => request.request.url)).toEqual(['/api/translations/sk']);
    // Public: sent without the token, though somebody is signed in.
    expect(asked[0].request.headers.has('Authorization')).toBe(false);
    expect(client.getAccessToken).not.toHaveBeenCalled();
    asked[0].flush(SK_BUNDLE);
    await started();

    expect(TestBed.inject(AuthService).displayName()).toBe('mona');
    expect(TestBed.inject(TranslationService).language()).toBe('sk');
    expect(TestBed.inject(TranslationService).t('theme.label')).toBe('Vzhľad');
    TestBed.tick();
    expect(document.documentElement.lang).toBe('sk');
  });

  it('moves what this browser imported as a visitor into the account once somebody is signed in - outside the sign-in, with no circle', async () => {
    const key = 'K'.repeat(43);
    localStorage.setItem(VISITOR_STORAGE_KEY, key);
    const { client, finish } = fakeClient(MONA);
    start(client);
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(IAM_META);
    finish();
    await settle();
    translations()[0].flush(SK_BUNDLE);
    await started();
    TestBed.tick();
    await settle();

    const move = requests.expectOne('/api/visitor/move');
    expect(move.request.method).toBe('POST');
    expect(move.request.headers.get('Authorization')).toBe('Bearer token-1');
    expect(move.request.headers.get('X-Visitor-Key')).toBe(key);
    move.flush({ found: true, moved: 1, kept: 0, imports: 1 });
    await settle();

    expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBeNull();
    expect(TestBed.inject(VisitorKey).moved()).toEqual({ found: true, moved: 1, kept: 0, imports: 1 });
  });

  it('asks for no words while the server is not set up - the site gate says so, with the setup page while never installed', async () => {
    start();
    site('not_set_up');
    requests.expectOne('changelog.json').flush([]);
    requests
      .expectOne('/api/meta')
      .flush(
        { code: 'not_set_up', installed: false, message: 'This site is not set up yet. Its setup page connects it to its database.', setup: '/setup.php' },
        { status: 503, statusText: 'Service Unavailable' },
      );
    await started();

    expect(translations()).toEqual([]);
    const i18n = TestBed.inject(TranslationService);
    expect(i18n.ready()).toBe(false);
    expect(i18n.t('theme.label')).toBe('Theme');
    expect(i18n.t('signIn.notConfigured')).toBe('Sign-in is not configured on this server.');
    const status = TestBed.inject(SiteStatus);
    expect([status.state(), status.installed()]).toEqual(['not-set-up', false]);
  });

  it('asks for no words while the server cannot be reached - and reads the ones kept from the last visit', async () => {
    localStorage.setItem('build-analyzer-language-cache', JSON.stringify(SK_BUNDLE));
    start();
    requests.expectOne('/api/site-status').error(new ProgressEvent('error'));
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').error(new ProgressEvent('error'));
    await started();

    expect(translations()).toEqual([]);
    expect(TestBed.inject(TranslationService).language()).toBe('sk');
    expect(TestBed.inject(TranslationService).t('theme.label')).toBe('Vzhľad');
    expect(TestBed.inject(SiteStatus).state()).toBe('unavailable');
  });

  it('an update waits: the start does not stop - the sign-in completes and the words are asked for, then the gate shows its page', async () => {
    const { client, finish } = fakeClient(MONA);
    start(client);
    site('update_pending');
    requests.expectOne('changelog.json').flush([]);
    // /api/meta is one of the paths the server keeps open while an update waits: it says where to sign in.
    requests.expectOne('/api/meta').flush(IAM_META);
    finish();
    await settle();
    translations()[0].flush(SK_BUNDLE);
    await started();

    expect(TestBed.inject(SiteStatus).state()).toBe('update-pending');
    expect(TestBed.inject(AuthService).displayName()).toBe('mona');
    expect(TestBed.inject(TranslationService).language()).toBe('sk');
  });

  it('starts on the compiled English when the words are not there yet (an update waits, and brings the tables)', async () => {
    start();
    site('update_pending');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(META);
    await settle();

    translations()[0].flush({ message: 'The translations are not available yet.', code: 'translations_unavailable' }, { status: 503, statusText: 'Service Unavailable' });
    await started();

    expect(translations()).toEqual([]);
    const i18n = TestBed.inject(TranslationService);
    expect(i18n.ready()).toBe(false);
    expect(i18n.t('theme.label')).toBe('Theme');
    expect(i18n.languages()).toEqual([]);
    expect(TestBed.inject(SiteStatus).state()).toBe('update-pending');
  });

  it('hears an answer that says an update waits, whichever request it was', async () => {
    start();
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(META);
    await settle();
    translations()[0].flush(EN_BUNDLE);
    await started();

    // Uploaded meanwhile: the next request is closed, and the site gate takes over.
    let failed = 0;
    TestBed.inject(HttpClient).get('/api/builds/genshin').subscribe({ error: (error) => (failed = error.status) });
    await settle();
    requests
      .expectOne('/api/builds/genshin')
      .flush({ code: 'update_pending', message: 'The site is being updated. Try again in a few minutes.' }, { status: 503, statusText: 'Service Unavailable' });
    expect(failed).toBe(503);
    expect(TestBed.inject(SiteStatus).state()).toBe('update-pending');
  });
});
