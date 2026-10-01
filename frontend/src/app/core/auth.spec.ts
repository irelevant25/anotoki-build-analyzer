import { TestBed } from '@angular/core/testing';
import { AUTH_CLIENT_FACTORY, AuthService, signInProblem } from './auth';
import { AnotokiAuth, AnotokiInitResult, AnotokiUser } from './iam/anotoki-auth';

const MONA: AnotokiUser = {
  sub: '7',
  preferred_username: 'mona',
  email: 'mona@example.test',
  email_verified: true,
  locale: 'en',
  roles: ['MEMBER'],
};

const IAM = { mode: 'iam' as const, issuer: 'https://iam.example.test', client_id: 'build-analyzer' };

/** A stand-in for the anotoki-auth SDK; `current` is who it says is signed in. */
function fakeClient(state: { current: AnotokiUser | null; init?: AnotokiInitResult }) {
  let listener: (() => void) | null = null;
  const client = {
    version: 'test',
    init: vi.fn(async () => state.init ?? { returnTo: null, error: null }),
    login: vi.fn(async () => undefined),
    register: vi.fn(async () => undefined),
    logout: vi.fn(async () => undefined),
    getAccessToken: vi.fn(async () => 'token-1'),
    refresh: vi.fn(async () => 'token-2'),
    fetch: vi.fn(),
    onChange: vi.fn((next: () => void) => {
      listener = next;
      return () => (listener = null);
    }),
    isAuthenticated: () => state.current !== null,
    user: () => state.current,
    roles: () => state.current?.roles ?? [],
    hasRole: (role: string) => state.current?.roles.includes(role) ?? false,
    accountUrl: () => 'https://iam.example.test/account',
    issuer: () => 'https://iam.example.test',
    clientId: () => 'build-analyzer',
  } satisfies AnotokiAuth;

  return { client, changed: () => listener?.() };
}

function setup(client: AnotokiAuth) {
  const factory = vi.fn(() => client);
  TestBed.configureTestingModule({ providers: [{ provide: AUTH_CLIENT_FACTORY, useValue: factory }] });
  return { auth: TestBed.inject(AuthService), factory };
}

describe('AuthService', () => {
  it('needs no IAM client and allows everything when sign-in is off', async () => {
    const { auth, factory } = setup(fakeClient({ current: null }).client);

    await auth.init({ mode: 'none', issuer: null, client_id: null });

    expect(factory).not.toHaveBeenCalled();
    expect(auth.usesSignIn()).toBe(false);
    expect(auth.signedIn()).toBe(true);
    expect(auth.isAdmin()).toBe(true);
    expect(auth.isMember()).toBe(true);
  });

  it('signs in through the IAM client, on this site and back to it', async () => {
    const { client } = fakeClient({ current: MONA });
    const { auth, factory } = setup(client);

    await auth.init(IAM);

    expect(factory).toHaveBeenCalledWith({
      issuer: 'https://iam.example.test',
      clientId: 'build-analyzer',
      redirectUri: `${location.origin}/auth/callback`,
      postLogoutRedirectUri: `${location.origin}/`,
    });
    expect(client.init).toHaveBeenCalledTimes(1);
    expect(auth.displayName()).toBe('mona');
    expect(auth.isMember()).toBe(true);
    expect(auth.isAdmin()).toBe(false);
    expect(await auth.accessToken()).toBe('token-1');
    expect(await auth.refreshedToken()).toBe('token-2');
    expect(auth.accountUrl()).toBe('https://iam.example.test/account');
  });

  it('says a sign-in the IAM refused as a key for the page to word - never in the IAM\'s words', async () => {
    const error = Object.assign(new Error('This account cannot use Build Analyzer'), { code: 'access_denied' });
    const { auth } = setup(fakeClient({ current: null, init: { returnTo: '/', error } }).client);

    await auth.init(IAM);

    expect(auth.signedIn()).toBe(false);
    expect(auth.lastError()).toEqual({ key: 'signIn.notCompleted', params: {} });
  });

  it('says going back from the IAM\'s page with the same key: one sentence that is true either way', async () => {
    const error = Object.assign(new Error('The sign-in was cancelled'), { code: 'access_denied' });
    const { auth } = setup(fakeClient({ current: null, init: { returnTo: '/', error } }).client);

    await auth.init(IAM);

    expect(auth.signedIn()).toBe(false);
    expect(auth.lastError()).toEqual({ key: 'signIn.notCompleted', params: {} });
  });

  it('decides what to say from the error\'s code alone', async () => {
    expect(signInProblem('access_denied')).toEqual({ key: 'signIn.notCompleted', params: {} });
    expect(signInProblem('state_mismatch')).toEqual({ key: 'signIn.stateMismatch', params: {} });
    expect(signInProblem('invalid_grant')).toEqual({ key: 'signIn.failed', params: { code: 'invalid_grant' } });
    expect(signInProblem('http_503')).toEqual({ key: 'signIn.failed', params: { code: 'http_503' } });
    // What came through the address and does not look like a code is never shown.
    expect(signInProblem('Your account is locked. Call 0900 123 456')).toEqual({ key: 'signIn.failed', params: { code: 'unknown' } });
    expect(signInProblem('x'.repeat(41))).toEqual({ key: 'signIn.failed', params: { code: 'unknown' } });
    expect(signInProblem('')).toEqual({ key: 'signIn.failed', params: { code: 'unknown' } });
    expect(signInProblem(undefined)).toEqual({ key: 'signIn.failed', params: { code: 'unknown' } });

    const error = Object.assign(new Error('The ID token could not be verified'), { code: 'invalid_id_token' });
    const { auth } = setup(fakeClient({ current: null, init: { returnTo: '/', error } }).client);
    await auth.init(IAM);
    expect(auth.lastError()).toEqual({ key: 'signIn.failed', params: { code: 'invalid_id_token' } });
  });

  it('says so when the server has no issuer or client id for the sign-in', async () => {
    const { auth, factory } = setup(fakeClient({ current: null }).client);

    await auth.init({ mode: 'iam', issuer: null, client_id: 'build-analyzer' });

    expect(factory).not.toHaveBeenCalled();
    expect(auth.mode()).toBe('unavailable');
    expect(auth.lastError()).toEqual({ key: 'signIn.notConfigured', params: {} });
  });

  it('names the signed-in person by user name, else by email - and leaves anything else to the template', async () => {
    const state = { current: { ...MONA, preferred_username: null } as AnotokiUser | null };
    const { client, changed } = fakeClient(state);
    const { auth } = setup(client);
    await auth.init(IAM);
    expect(auth.displayName()).toBe('mona@example.test');

    state.current = { ...MONA, preferred_username: null, email: null };
    changed();
    expect(auth.displayName()).toBeNull();
    expect(auth.user()?.sub).toBe('7');

    state.current = null;
    changed();
    expect(auth.displayName()).toBeNull();
  });

  it('hands the IAM the language its caller says, and none when the caller has none', async () => {
    const { client } = fakeClient({ current: null });
    const { auth } = setup(client);
    await auth.init(IAM);

    await auth.login('/builds', { uiLocales: 'sk' });
    await auth.login('/builds', { anotherAccount: true, uiLocales: undefined });
    await auth.login('/builds', { uiLocales: null });

    expect(client.login.mock.calls).toEqual([
      [{ returnTo: '/builds', prompt: undefined, uiLocales: 'sk' }],
      [{ returnTo: '/builds', prompt: 'login' }],
      [{ returnTo: '/builds', prompt: undefined }],
    ]);
    // No language is said at all - not even an empty one - so the IAM chooses for itself.
    expect(client.login.mock.calls.slice(1).every(([parameters]: unknown[]) => !Object.hasOwn(parameters as object, 'uiLocales'))).toBe(true);
  });

  it('follows a sign-out in another tab', async () => {
    const state = { current: { ...MONA, roles: ['ADMIN'] } as AnotokiUser | null };
    const { client, changed } = fakeClient(state);
    const { auth } = setup(client);
    await auth.init(IAM);
    expect(auth.isAdmin()).toBe(true);

    state.current = null;
    changed();

    expect(auth.user()).toBeNull();
    expect(auth.isMember()).toBe(false);
  });

  it('stays unavailable without the settings from the server', async () => {
    const { auth, factory } = setup(fakeClient({ current: null }).client);

    await auth.init(null);

    expect(factory).not.toHaveBeenCalled();
    expect(auth.mode()).toBe('unavailable');
    expect(auth.signedIn()).toBe(false);
  });
});
