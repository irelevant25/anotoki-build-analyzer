import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ApplicationInitStatus, ErrorHandler } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, TitleStrategy } from '@angular/router';
import { ANOTOKI_AUTH_BROWSER, ANOTOKI_AUTH_CLIENT_FACTORY, AnotokiAuth, AnotokiAuthEnvironment, AnotokiAuthOptions, AnotokiInitResult } from '@anotoki/lib/auth';
import { FakeAuthClient, TestAnotokiAuth, TestUser, testAnotokiAuth } from '@anotoki/lib/auth/testing';
import { ANOTOKI_ERROR_REPORT } from '@anotoki/lib/error-report';
import { SiteStatus } from '@anotoki/lib/migrations';
import { SiteSettings } from '@anotoki/lib/site-settings';
import { TranslationBundle } from '@anotoki/lib/translations';
import { TEST_LANGUAGES } from '@anotoki/lib/translations/testing';
import { appConfig } from './app.config';
import { AuthService } from './shared/auth';
import { AppTitleStrategy } from './shared/i18n/title-strategy';
import { TranslationService } from './shared/i18n/translation';
import { Meta } from './api/models';
import { VISITOR_STORAGE_KEY, VisitorKey } from './shared/visitor-key';

/** /api/meta of a site with no IAM yet (nothing to sign in with). */
const META: Meta = { games: [], version: '0.1', auth: { issuer: '', client_id: '' } };
/** /api/meta as this site answers it: its IAM, visitors welcome. */
const IAM_META: Meta = {
  ...META,
  auth: {
    issuer: 'https://iam.example.test',
    client_id: 'build-analyzer',
    login_required: false,
    sign_in_available: true,
  },
};

const ENGLISH = { 'whatsNew.title': 'News' };
const SK_BUNDLE: TranslationBundle = {
  language: 'sk',
  languages: TEST_LANGUAGES,
  values: { 'whatsNew.title': 'Čo je nové' },
  english: ENGLISH,
};
const EN_BUNDLE: TranslationBundle = { language: 'en', languages: TEST_LANGUAGES, values: ENGLISH };

const MONA: TestUser = {
  sub: '7',
  preferred_username: 'mona',
  email: 'mona@example.test',
  locale: 'sk',
};
/** A sign-in hint as the IAM sets it: 16 random bytes in base64url. */
const HINT = 'abcdefghijklmnopqrstuv';

/** Everything already on its way has run. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

/** The IAM's client, in memory - whose init() (a callback finished, a session restored) ends when the test says. */
class SlowClient extends FakeAuthClient {
  release!: () => void;
  private readonly gate = new Promise<void>((resolve) => (this.release = resolve));

  override async init(): Promise<AnotokiInitResult> {
    await this.gate;
    return super.init();
  }
}

describe('the start of the app (app.config.ts)', () => {
  let requests: HttpTestingController;
  let browser: TestAnotokiAuth['browser'];
  let client: FakeAuthClient | null;

  /**
   * The app's own providers - the auth module as the site sets it up - with the IAM's client in memory (signed in
   * as `user`, or nobody) and a browser that only records where it was sent.
   */
  function start(user: TestUser | null = null, options: { slow?: boolean; cookie?: string } = {}): void {
    browser = testAnotokiAuth(options.cookie ?? '').browser;
    client = null;
    TestBed.configureTestingModule({
      providers: [
        ...appConfig.providers,
        provideHttpClientTesting(),
        { provide: ANOTOKI_AUTH_BROWSER, useValue: browser },
        {
          provide: ANOTOKI_AUTH_CLIENT_FACTORY,
          useValue: (clientOptions: AnotokiAuthOptions, environment: AnotokiAuthEnvironment) =>
            (client = options.slow ? new SlowClient(clientOptions, environment, user) : new FakeAuthClient(clientOptions, environment, user)),
        },
      ],
    });
    // Asking for anything builds the app's injector, which runs the initializer.
    requests = TestBed.inject(HttpTestingController);
  }

  const started = () => TestBed.inject(ApplicationInitStatus).donePromise;
  const translations = () => requests.match((request) => request.url.startsWith('/api/translations/'));
  /** The site settings (anotoki-lib 0.11.0), asked at the start beside the site's status: nothing switched, by default. */
  const settings = (body: object = { maintenance: false, announcement: null, pages: [] }) => requests.expectOne('/api/site-settings').flush(body);
  /** The site's status, asked at the start beside /api/meta: what the server says of it - and the site settings with it. */
  const site = (state: 'ready' | 'update_pending' | 'not_set_up', installed = false) => {
    requests.expectOne('/api/site-status').flush({ state, installed });
    settings();
  };

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
    expect(i18n.t('whatsNew.title')).toBe('News');
    // The Router builds the title strategy, which needs the service: no circle.
    expect(TestBed.inject(Router)).toBeTruthy();
    expect(TestBed.inject(TitleStrategy)).toBeInstanceOf(AppTitleStrategy);
    expect(TestBed.inject(SiteStatus).state()).toBe('ready');
    // No IAM named: nothing to sign in with, and no client made.
    expect(TestBed.inject(AnotokiAuth).state()).toBe('unavailable');
    expect(client).toBeNull();
  });

  it('reads the site settings beside the status, with no credentials, before the first page: the announcement, and the pages Page access governs', async () => {
    start();
    requests.expectOne('/api/site-status').flush({ state: 'ready', installed: false });
    const asked = requests.expectOne('/api/site-settings');
    expect(asked.request.headers.has('Authorization')).toBe(false);
    asked.flush({ maintenance: false, announcement: { level: 'warning', message: { en: 'Back at five.' } }, pages: [{ path: '/import', visibility: 'USER', blocked: false }] });
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(META);
    await settle();
    translations()[0].flush(EN_BUNDLE);
    await started();

    const siteSettings = TestBed.inject(SiteSettings);
    expect(siteSettings.announcement()).toEqual({ level: 'warning', message: { en: 'Back at five.' } });
    // Nobody signed in: a page kept for people signed in is not theirs; a page no row governs is anybody's.
    expect(siteSettings.mayNavigate('/import')).toBe(false);
    expect(siteSettings.mayNavigate('/builds/genshin')).toBe(true);
  });

  it('reads the boot configuration from /api/meta - asked once, with no credentials - and lets visitors in as the server says', async () => {
    start();
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    const meta = requests.expectOne('/api/meta');
    expect(meta.request.headers.has('Authorization')).toBe(false);
    expect(meta.request.headers.has('X-Visitor-Key')).toBe(false);
    meta.flush(IAM_META);
    await settle();
    translations()[0].flush(EN_BUNDLE);
    await started();

    const auth = TestBed.inject(AnotokiAuth);
    expect([auth.state(), auth.loginRequired(), auth.signInAvailable()]).toEqual(['visitor', false, true]);
    expect(client?.options).toEqual(
      expect.objectContaining({
        issuer: 'https://iam.example.test',
        clientId: 'build-analyzer',
        redirectUri: `${location.origin}/auth/callback`,
        postLogoutRedirectUri: `${location.origin}/`,
      }),
    );
    // Nobody signed in at the IAM in this browser (no hint): no hop.
    expect(client?.logins).toEqual([]);
  });

  it('asks for the words only once the sign-in has finished - in the language the token names', async () => {
    start(MONA, { slow: true });
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(IAM_META);
    await settle();

    // The IAM's client is still finishing the sign-in: who the reader is - and so their language - is not known yet.
    expect(client).not.toBeNull();
    expect(translations()).toEqual([]);

    (client as SlowClient).release();
    await settle();
    await settle();
    const asked = translations();
    expect(asked.map((request) => request.request.url)).toEqual(['/api/translations/sk']);
    // Public: sent without the token, though somebody is signed in.
    expect(asked[0].request.headers.has('Authorization')).toBe(false);
    asked[0].flush(SK_BUNDLE);
    await started();

    expect(TestBed.inject(AuthService).displayName()).toBe('mona');
    expect(TestBed.inject(TranslationService).language()).toBe('sk');
    expect(TestBed.inject(TranslationService).t('whatsNew.title')).toBe('Čo je nové');
    TestBed.tick();
    expect(document.documentElement.lang).toBe('sk');
  });

  it('signs a visitor who is signed in at the IAM in here too, with one silent hop before the first page (the sign-in hint)', async () => {
    start(null, { cookie: `anotoki_signed_in=${HINT}` });
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(IAM_META);
    await settle();
    await settle();

    // To the IAM without a prompt, back to the page the visitor opened, the silent way - whatever comes back, nothing is said.
    expect(client?.logins).toEqual([expect.objectContaining({ prompt: 'none', silent: true, returnTo: '/' })]);
    // The marker that keeps a hint from going round: this value tried once.
    expect(JSON.parse(localStorage.getItem('anotoki-auth:build-analyzer:hint') ?? 'null')).toEqual(expect.objectContaining({ value: HINT }));
    // The page is leaving: no words are asked for, and the start never ends.
    expect(translations()).toEqual([]);
  });

  it('makes no hop for the same hint twice, nor while the site requires a sign-in, nor without the IAM knowing the site', async () => {
    localStorage.setItem('anotoki-auth:build-analyzer:hint', JSON.stringify({ value: HINT, at: Date.now() - 3_600_000 }));
    start(null, { cookie: `anotoki_signed_in=${HINT}` });
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(IAM_META);
    await settle();
    translations()[0].flush(EN_BUNDLE);
    await started();
    expect(client?.logins).toEqual([]);
    expect(TestBed.inject(AnotokiAuth).state()).toBe('visitor');

    for (const auth of [
      { ...IAM_META.auth, login_required: true },
      { ...IAM_META.auth, sign_in_available: false },
    ]) {
      TestBed.resetTestingModule();
      localStorage.clear();
      start(null, { cookie: `anotoki_signed_in=${HINT}` });
      site('ready');
      requests.expectOne('changelog.json').flush([]);
      requests.expectOne('/api/meta').flush({ ...IAM_META, auth });
      await settle();
      translations()[0].flush(EN_BUNDLE);
      await started();
      expect(client?.logins).toEqual([]);
    }
  });

  it('moves what this browser imported as a visitor into the account once somebody is signed in - outside the sign-in, with no circle', async () => {
    const key = 'K'.repeat(43);
    localStorage.setItem(VISITOR_STORAGE_KEY, key);
    start(MONA);
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(IAM_META);
    await settle();
    translations()[0].flush(SK_BUNDLE);
    await started();
    TestBed.tick();
    await settle();

    const move = requests.expectOne('/api/visitor/move');
    expect(move.request.method).toBe('POST');
    expect(move.request.headers.get('Authorization')).toMatch(/^Bearer \S+$/);
    expect(move.request.headers.get('X-Visitor-Key')).toBe(key);
    // A session begun by a sign-in somebody clicked (no note of a silent one): the move as ever.
    expect(move.request.body).toEqual({ silent: false });
    move.flush({ found: true, moved: 1, kept: 0, imports: 1, held: false });
    await settle();

    expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBeNull();
    expect(TestBed.inject(VisitorKey).moved()).toEqual({
      found: true,
      moved: 1,
      kept: 0,
      imports: 1,
      held: false,
    });
  });

  it("sends a visitor's key on the visitor's own requests, and keeps the one an import brings", async () => {
    start();
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(IAM_META);
    await settle();
    translations()[0].flush(EN_BUNDLE);
    await started();

    const http = TestBed.inject(HttpClient);
    http.post('/api/imports/showcase', { game: 'genshin', uid: '700000001' }).subscribe();
    await settle();
    const first = requests.expectOne('/api/imports/showcase');
    expect([first.request.headers.has('Authorization'), first.request.headers.has('X-Visitor-Key')]).toEqual([false, false]);
    const key = 'V'.repeat(43);
    first.flush({ id: 1 }, { headers: { 'X-Visitor-Key': key } });
    await settle();
    expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBe(key);

    http.get('/api/accounts').subscribe();
    await settle();
    const next = requests.expectOne('/api/accounts');
    expect([next.request.headers.has('Authorization'), next.request.headers.get('X-Visitor-Key')]).toEqual([false, key]);
    next.flush([]);
  });

  it('asks for no words while the server is not set up - the site gate says so, with the setup page while never installed', async () => {
    start();
    site('not_set_up');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(
      {
        code: 'not_set_up',
        installed: false,
        message: 'This site is not set up yet. Its setup page connects it to its database.',
        setup: '/setup.php',
      },
      { status: 503, statusText: 'Service Unavailable' },
    );
    await started();

    expect(translations()).toEqual([]);
    const i18n = TestBed.inject(TranslationService);
    expect(i18n.ready()).toBe(false);
    expect(i18n.t('common.cancel')).toBe('Cancel');
    expect(i18n.t('whatsNew.title')).toBe("What's new");
    const status = TestBed.inject(SiteStatus);
    expect([status.state(), status.installed()]).toEqual(['not-set-up', false]);
    expect(TestBed.inject(AnotokiAuth).state()).toBe('unavailable');
  });

  it('asks for no words while the server cannot be reached - and reads the ones kept from the last visit', async () => {
    localStorage.setItem('build-analyzer-language-cache', JSON.stringify(SK_BUNDLE));
    start();
    requests.expectOne('/api/site-status').error(new ProgressEvent('error'));
    // The site settings cannot be read either: no announcement, every page anybody's - the start goes on.
    requests.expectOne('/api/site-settings').error(new ProgressEvent('error'));
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').error(new ProgressEvent('error'));
    await started();

    expect(translations()).toEqual([]);
    expect(TestBed.inject(TranslationService).language()).toBe('sk');
    expect(TestBed.inject(TranslationService).t('whatsNew.title')).toBe('Čo je nové');
    expect(TestBed.inject(SiteStatus).state()).toBe('unavailable');
  });

  it('an update waits: the start does not stop - the sign-in completes and the words are asked for, then the gate shows its page', async () => {
    start(MONA);
    site('update_pending');
    requests.expectOne('changelog.json').flush([]);
    // /api/meta is one of the paths the server keeps open while an update waits: it says where to sign in.
    requests.expectOne('/api/meta').flush(IAM_META);
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

    const unavailable = {
      message: 'The words are not in the database yet.',
      code: 'translations_unavailable',
    };
    translations()[0].flush(unavailable, { status: 503, statusText: 'Service Unavailable' });
    // A first visit's lost answer is asked once more, half a second later (the library's start) - then the page
    // starts on what it has.
    await new Promise((resolve) => setTimeout(resolve, 600));
    const again = translations();
    expect(again.map((request) => request.request.url)).toEqual(['/api/translations/en']);
    again[0].flush(unavailable, { status: 503, statusText: 'Service Unavailable' });
    await started();

    expect(translations()).toEqual([]);
    const i18n = TestBed.inject(TranslationService);
    expect(i18n.ready()).toBe(false);
    expect(i18n.t('whatsNew.title')).toBe("What's new");
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
    TestBed.inject(HttpClient)
      .get('/api/builds/genshin')
      .subscribe({ error: (error) => (failed = error.status) });
    await settle();
    requests.expectOne('/api/builds/genshin').flush(
      {
        code: 'update_pending',
        message: 'The site is being updated. Try again in a few minutes.',
      },
      { status: 503, statusText: 'Service Unavailable' },
    );
    expect(failed).toBe(503);
    expect(TestBed.inject(SiteStatus).state()).toBe('update-pending');
  });

  it("reports an error the page meets to the server's error log - scrubbed, through the app's HttpClient, with the person's token", async () => {
    start(MONA);
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(IAM_META);
    await settle();
    translations()[0].flush(SK_BUNDLE);
    await started();
    const console = vi.spyOn(globalThis.console, 'error').mockImplementation(() => undefined);

    const error = new TypeError('Cannot read x of mona@example.test');
    TestBed.inject(ErrorHandler).handleError(error);
    TestBed.inject(ErrorHandler).handleError(error);
    await settle();
    await settle();

    // Told to the console as Angular tells it, every time; reported once.
    expect(console).toHaveBeenCalledTimes(2);
    const [report] = requests.match('/api/client-errors');
    expect(report.request.method).toBe('POST');
    expect(report.request.context.get(ANOTOKI_ERROR_REPORT)).toBe(true);
    // The site's interceptors at work: the person's token, never the visitor key.
    expect(report.request.headers.get('Authorization')).toMatch(/^Bearer \S+$/);
    expect(report.request.headers.has('X-Visitor-Key')).toBe(false);
    expect(report.request.body).toEqual(
      expect.objectContaining({
        type: 'TypeError',
        message: 'Cannot read x of {email}',
        page: '/',
      }),
    );
    report.flush(null, { status: 204, statusText: 'No Content' });
    expect(requests.match('/api/client-errors')).toEqual([]);
  });

  it("reports a visitor's error without credentials, and an HTTP failure never", async () => {
    start();
    site('ready');
    requests.expectOne('changelog.json').flush([]);
    requests.expectOne('/api/meta').flush(IAM_META);
    await settle();
    translations()[0].flush(EN_BUNDLE);
    await started();
    vi.spyOn(globalThis.console, 'error').mockImplementation(() => undefined);

    const handler = TestBed.inject(ErrorHandler);
    handler.handleError(new HttpErrorResponse({ status: 500, url: '/api/accounts' }));
    handler.handleError(new Error('The dashboard broke'));
    await settle();
    await settle();

    const [report] = requests.match('/api/client-errors');
    expect([report.request.headers.has('Authorization'), report.request.headers.has('X-Visitor-Key')]).toEqual([false, false]);
    expect(report.request.body).toEqual(expect.objectContaining({ type: 'Error', message: 'The dashboard broke' }));
    report.flush(null, { status: 204, statusText: 'No Content' });
    expect(requests.match('/api/client-errors')).toEqual([]);
  });
});
