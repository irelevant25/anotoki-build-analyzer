import { HttpClient, HttpContext, HttpErrorResponse, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Api, CARRIES_VISITOR_KEY, issuedAs } from './api';
import { AuthService } from './auth';
import { authInterceptor } from './auth.interceptor';
import { SavesInFlight } from './saves-in-flight';
import { VisitorKey } from './visitor-key';

/** Lets the interceptor's token promise settle before looking at the requests. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const KEY_A = 'A'.repeat(43);
const KEY_B = 'B'.repeat(43);

describe('authInterceptor', () => {
  let http: HttpClient;
  let requests: HttpTestingController;
  let auth: {
    signedIn: ReturnType<typeof signal<boolean>>;
    loginRequired: ReturnType<typeof signal<boolean>>;
    accessToken: () => Promise<string | null>;
    refreshedToken: () => Promise<string | null>;
  };
  let visitor: { held: string | null; current: () => string | null; remember: (key: string) => void; forget: (key: string, lost?: boolean) => void };

  beforeEach(() => {
    auth = {
      signedIn: signal(true),
      loginRequired: signal(false),
      accessToken: vi.fn(async () => 'old-token'),
      refreshedToken: vi.fn(async () => 'new-token'),
    };
    visitor = {
      held: null,
      current: vi.fn(() => visitor.held),
      remember: vi.fn(),
      forget: vi.fn(),
    };
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: AuthService, useValue: auth },
        { provide: VisitorKey, useValue: visitor },
      ],
    });
    http = TestBed.inject(HttpClient);
    requests = TestBed.inject(HttpTestingController);
  });

  afterEach(() => requests.verify());

  /** Nobody signed in: the site's state, and no token to be had. */
  function signedOut(): void {
    auth.signedIn.set(false);
    auth.accessToken = vi.fn(async () => null);
  }

  /** Signed in as far as the page knows - but the token is gone (the refresh failed, or another tab signed out). */
  function tokenLost(): void {
    auth.accessToken = vi.fn(async () => null);
  }

  describe('a person\'s request', () => {
    it('sends the access token to the API of this site only', async () => {
      http.get('/api/accounts').subscribe();
      http.get('https://images.example.test/icon.png').subscribe();
      await settle();

      expect(requests.expectOne('/api/accounts').request.headers.get('Authorization')).toBe('Bearer old-token');
      expect(requests.expectOne('https://images.example.test/icon.png').request.headers.has('Authorization')).toBe(false);
    });

    it('retries once with a fresh token when the API refuses the current one', async () => {
      let result: unknown;
      http.get('/api/accounts').subscribe((body) => (result = body));
      await settle();

      requests.expectOne('/api/accounts').flush({ message: 'Sign in to continue.' }, { status: 401, statusText: 'Unauthorized' });
      await settle();

      const retry = requests.expectOne('/api/accounts');
      expect(retry.request.headers.get('Authorization')).toBe('Bearer new-token');
      retry.flush([]);
      expect(result).toEqual([]);
      expect(auth.refreshedToken).toHaveBeenCalledTimes(1);
    });

    it('gives up when there is no fresh token (signed out)', async () => {
      auth.refreshedToken = vi.fn(async () => null);
      let status = 0;
      http.get('/api/accounts').subscribe({ error: (error) => (status = error.status) });
      await settle();

      requests.expectOne('/api/accounts').flush({}, { status: 401, statusText: 'Unauthorized' });
      await settle();

      requests.expectNone('/api/accounts');
      expect(status).toBe(401);
    });

    it('never sends the visitor key - even one a request set itself - and keeps none an answer brings', async () => {
      visitor.held = KEY_A;
      http.post('/api/imports/file', {}, { headers: { 'X-Visitor-Key': KEY_B } }).subscribe();
      await settle();

      const request = requests.expectOne('/api/imports/file');
      expect(request.request.headers.get('Authorization')).toBe('Bearer old-token');
      expect(request.request.headers.has('X-Visitor-Key')).toBe(false);
      request.flush({}, { status: 201, statusText: 'Created', headers: { 'X-Visitor-Key': KEY_B } });
      expect(visitor.current).not.toHaveBeenCalled();
      expect(visitor.remember).not.toHaveBeenCalled();
    });

    it('is never sent without its token: it fails here as 401 sign_in_required - never as a visitor\'s, never making one', async () => {
      tokenLost();
      visitor.held = null;
      let failure: unknown;
      http.post('/api/imports/showcase', { game: 'genshin', uid: '700000001' }).subscribe({ error: (error) => (failure = error) });
      await settle();

      requests.expectNone('/api/imports/showcase');
      expect(failure).toBeInstanceOf(HttpErrorResponse);
      expect((failure as HttpErrorResponse).status).toBe(401);
      expect((failure as HttpErrorResponse).error).toEqual(expect.objectContaining({ code: 'sign_in_required' }));
      expect(visitor.current).not.toHaveBeenCalled();
      expect(visitor.remember).not.toHaveBeenCalled();
      expect(visitor.forget).not.toHaveBeenCalled();
    });

    it('is the person\'s as it was issued: signed out before its token came back, it is still never sent as a visitor\'s', async () => {
      visitor.held = KEY_A;
      const api = TestBed.inject(Api);
      // Issued while the page is a signed-in person's...
      const rename = api.renameAccount(3, 'Main');
      // ...and the session ends in another tab before it leaves.
      signedOut();
      let status = 0;
      rename.subscribe({ error: (error) => (status = error.status) });
      await settle();

      requests.expectNone('/api/accounts/3');
      expect(status).toBe(401);
      expect(visitor.current).not.toHaveBeenCalled();
    });

    it('a request Api did not issue is decided as it is handed over: the page\'s state, not the token', async () => {
      tokenLost();
      let status = 0;
      http.get('/api/accounts').subscribe({ error: (error) => (status = error.status) });
      await settle();

      requests.expectNone('/api/accounts');
      expect(status).toBe(401);
    });

    it('the admin panel\'s requests are a person\'s alone: nobody signed in, they are never sent', async () => {
      signedOut();
      visitor.held = KEY_A;
      const api = TestBed.inject(Api);
      let status = 0;
      api.translationGrid().subscribe({ error: (error) => (status = error.status) });
      api.people().subscribe({ error: () => undefined });
      api.exportData().subscribe({ error: () => undefined });
      await settle();

      requests.expectNone('/api/admin/translations');
      requests.expectNone('/api/admin/people');
      requests.expectNone('/api/me/export');
      expect(status).toBe(401);
      expect(visitor.current).not.toHaveBeenCalled();
    });

    it('the move alone carries the token and the key together - and with no token it is never sent', async () => {
      visitor.held = KEY_B;
      const move = () =>
        http.post('/api/visitor/move', {}, {
          headers: { 'X-Visitor-Key': KEY_A },
          context: issuedAs('person').set(CARRIES_VISITOR_KEY, true),
        });
      move().subscribe();
      await settle();

      const request = requests.expectOne('/api/visitor/move').request;
      expect(request.headers.get('Authorization')).toBe('Bearer old-token');
      expect(request.headers.get('X-Visitor-Key')).toBe(KEY_A);

      tokenLost();
      let status = 0;
      move().subscribe({ error: (error) => (status = error.status) });
      await settle();
      requests.expectNone('/api/visitor/move');
      expect(status).toBe(401);
    });
  });

  describe('a visitor\'s request, while the site does not require a sign-in', () => {
    beforeEach(() => signedOut());

    it('sends the key held at the moment each request leaves - never a token, never waiting for one', async () => {
      visitor.held = KEY_A;
      http.get('/api/accounts').subscribe();
      const first = requests.expectOne('/api/accounts').request;
      expect(first.headers.get('X-Visitor-Key')).toBe(KEY_A);
      expect(first.headers.has('Authorization')).toBe(false);
      expect(auth.accessToken).not.toHaveBeenCalled();

      // Another tab changed what the browser holds: the next request sends that.
      visitor.held = KEY_B;
      http.get('/api/accounts/3').subscribe();
      expect(requests.expectOne('/api/accounts/3').request.headers.get('X-Visitor-Key')).toBe(KEY_B);
    });

    it('is the visitor\'s as it was issued: a token the browser has by the time it leaves is not sent', async () => {
      visitor.held = KEY_A;
      auth.accessToken = vi.fn(async () => 'somebody-elses-token');
      http.get('/api/accounts', { context: issuedAs('visitor') }).subscribe();

      const request = requests.expectOne('/api/accounts').request;
      expect(request.headers.has('Authorization')).toBe(false);
      expect(request.headers.get('X-Visitor-Key')).toBe(KEY_A);
    });

    it('with no key: sends neither, and keeps the key the answer that made a visitor brings', () => {
      http.post('/api/imports/showcase', { game: 'genshin', uid: '700000001' }).subscribe();
      const request = requests.expectOne('/api/imports/showcase');
      expect(request.request.headers.has('X-Visitor-Key')).toBe(false);
      expect(request.request.headers.has('Authorization')).toBe(false);
      request.flush({ id: 1 }, { status: 201, statusText: 'Created', headers: { 'X-Visitor-Key': KEY_A } });

      expect(visitor.remember).toHaveBeenCalledWith(KEY_A);
    });

    it('keeps no key from an answer to a request that sent one, or from one with no such header', () => {
      visitor.held = KEY_A;
      http.post('/api/imports/file', {}).subscribe();
      requests.expectOne('/api/imports/file').flush({}, { status: 201, statusText: 'Created', headers: { 'X-Visitor-Key': KEY_B } });

      visitor.held = null;
      http.get('/api/builds/genshin').subscribe();
      requests.expectOne('/api/builds/genshin').flush([]);

      expect(visitor.remember).not.toHaveBeenCalled();
    });

    it('forgets the key the server no longer knows (401 visitor_unknown) - and only that answer does', () => {
      visitor.held = KEY_A;
      http.get('/api/accounts').subscribe({ error: () => undefined });
      requests.expectOne('/api/accounts').flush({ message: 'gone', code: 'visitor_unknown' }, { status: 401, statusText: 'Unauthorized' });
      expect(visitor.forget).toHaveBeenCalledWith(KEY_A, true);

      vi.mocked(visitor.forget).mockClear();
      http.get('/api/accounts/3').subscribe({ error: () => undefined });
      requests.expectOne('/api/accounts/3').flush({ message: 'Sign in.', code: 'sign_in_required' }, { status: 401, statusText: 'Unauthorized' });
      http.get('/api/accounts/4').subscribe({ error: () => undefined });
      requests.expectOne('/api/accounts/4').flush({ message: 'No.', code: 'not_found' }, { status: 404, statusText: 'Not Found' });
      expect(visitor.forget).not.toHaveBeenCalled();
      // A visitor's request is never refreshed.
      expect(auth.refreshedToken).not.toHaveBeenCalled();
    });
  });

  describe('a visitor\'s request, while the site requires a sign-in', () => {
    beforeEach(() => {
      signedOut();
      auth.loginRequired.set(true);
    });

    it('sends neither key nor token, takes no new key, and keeps the key held - a 401 sign_in_required never forgets it', () => {
      visitor.held = KEY_A;
      http.post('/api/imports/showcase', {}).subscribe({ error: () => undefined });
      const request = requests.expectOne('/api/imports/showcase');
      expect(request.request.headers.has('X-Visitor-Key')).toBe(false);
      expect(request.request.headers.has('Authorization')).toBe(false);
      request.flush({ message: 'Sign in to continue.', code: 'sign_in_required' }, { status: 401, statusText: 'Unauthorized' });

      visitor.held = null;
      http.post('/api/imports/file', {}).subscribe();
      requests.expectOne('/api/imports/file').flush({}, { status: 201, statusText: 'Created', headers: { 'X-Visitor-Key': KEY_B } });

      expect(visitor.current).not.toHaveBeenCalled();
      expect(visitor.remember).not.toHaveBeenCalled();
      expect(visitor.forget).not.toHaveBeenCalled();
    });

    it('still lets a signed-in person\'s requests carry the token', async () => {
      auth.signedIn.set(true);
      auth.accessToken = vi.fn(async () => 'old-token');
      visitor.held = KEY_A;
      http.get('/api/accounts').subscribe();
      await settle();
      const request = requests.expectOne('/api/accounts').request;
      expect(request.headers.get('Authorization')).toBe('Bearer old-token');
      expect(request.headers.has('X-Visitor-Key')).toBe(false);
    });
  });

  describe('the saves on their way', () => {
    it('counts each write until it has its answer (or fails) - never a read', async () => {
      const saves = TestBed.inject(SavesInFlight);
      http.get('/api/accounts').subscribe();
      http.post('/api/imports/file', {}).subscribe({ error: () => undefined });
      http.patch('/api/accounts/3', {}).subscribe({ error: () => undefined });
      expect(saves.count()).toBe(2);
      await settle();

      requests.expectOne('/api/accounts').flush([]);
      requests.expectOne('/api/imports/file').flush({ id: 1 }, { status: 201, statusText: 'Created' });
      expect(saves.count()).toBe(1);
      requests.expectOne('/api/accounts/3').flush({ code: 'not_found' }, { status: 404, statusText: 'Not Found' });
      expect(saves.count()).toBe(0);
    });

    it('a person\'s write that is never sent is not counted for long', async () => {
      tokenLost();
      const saves = TestBed.inject(SavesInFlight);
      http.post('/api/imports/file', {}).subscribe({ error: () => undefined });
      await settle();
      requests.expectNone('/api/imports/file');
      expect(saves.count()).toBe(0);
    });
  });

  it('leaves the site\'s own words alone: no token, no key, no waiting for one, no second try', async () => {
    visitor.held = KEY_A;
    let status = 0;
    http.get('/api/translations/sk').subscribe({ error: (error) => (status = error.status) });

    // Sent at once - the token is not even asked for.
    const request = requests.expectOne('/api/translations/sk');
    expect(request.request.headers.has('Authorization')).toBe(false);
    expect(request.request.headers.has('X-Visitor-Key')).toBe(false);
    expect(auth.accessToken).not.toHaveBeenCalled();

    request.flush({ message: 'Sign in to continue.' }, { status: 401, statusText: 'Unauthorized' });
    await settle();
    requests.expectNone('/api/translations/sk');
    expect(auth.refreshedToken).not.toHaveBeenCalled();
    expect(status).toBe(401);
  });

  it('leaves the site\'s status alone: no token, no key - a person whose token is gone still learns where the site stands', async () => {
    visitor.held = KEY_A;
    auth.accessToken = vi.fn(async () => null);
    let state: unknown = null;
    http.get<{ state: string }>('/api/site-status').subscribe((answer) => (state = answer.state));

    // Sent at once, as it is: a person's request with no token would never be sent at all.
    const request = requests.expectOne('/api/site-status');
    expect(request.request.headers.has('Authorization')).toBe(false);
    expect(request.request.headers.has('X-Visitor-Key')).toBe(false);
    expect(auth.accessToken).not.toHaveBeenCalled();
    request.flush({ state: 'update_pending', installed: false });
    expect(state).toBe('update_pending');
  });

  it('sends the token to the Migrations page\'s API, as to every admin route', async () => {
    http.get('/api/admin/migrations').subscribe();
    http.post('/api/admin/migrations/apply', {}).subscribe();
    // Not the status's address, only one that begins like it.
    http.get('/api/site-status/more').subscribe();
    await settle();

    expect(requests.expectOne('/api/admin/migrations').request.headers.get('Authorization')).toBe('Bearer old-token');
    expect(requests.expectOne('/api/admin/migrations/apply').request.headers.get('Authorization')).toBe('Bearer old-token');
    expect(requests.expectOne('/api/site-status/more').request.headers.get('Authorization')).toBe('Bearer old-token');
  });

  it('still sends the token to the admin panel\'s translation routes', async () => {
    http.get('/api/admin/translations', { context: new HttpContext() }).subscribe();
    http.put('/api/admin/translations/sk/import', {}).subscribe();
    // Not the bundle's address, only one that begins like it.
    http.get('/api/translations').subscribe();
    await settle();

    expect(requests.expectOne('/api/admin/translations').request.headers.get('Authorization')).toBe('Bearer old-token');
    expect(requests.expectOne('/api/admin/translations/sk/import').request.headers.get('Authorization')).toBe('Bearer old-token');
    expect(requests.expectOne('/api/translations').request.headers.get('Authorization')).toBe('Bearer old-token');
  });
});
