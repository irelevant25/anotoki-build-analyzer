import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AuthService } from './auth';
import { authInterceptor } from './auth.interceptor';

/** Lets the interceptor's token promise settle before looking at the requests. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('authInterceptor', () => {
  let http: HttpClient;
  let requests: HttpTestingController;
  let auth: { usesSignIn: ReturnType<typeof signal<boolean>>; accessToken: () => Promise<string | null>; refreshedToken: () => Promise<string | null> };

  beforeEach(() => {
    auth = {
      usesSignIn: signal(true),
      accessToken: vi.fn(async () => 'old-token'),
      refreshedToken: vi.fn(async () => 'new-token'),
    };
    TestBed.configureTestingModule({
      providers: [provideHttpClient(withInterceptors([authInterceptor])), provideHttpClientTesting(), { provide: AuthService, useValue: auth }],
    });
    http = TestBed.inject(HttpClient);
    requests = TestBed.inject(HttpTestingController);
  });

  afterEach(() => requests.verify());

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

  it('adds nothing with sign-in off', () => {
    auth.usesSignIn.set(false);

    http.get('/api/accounts').subscribe();

    expect(requests.expectOne('/api/accounts').request.headers.has('Authorization')).toBe(false);
  });
});
