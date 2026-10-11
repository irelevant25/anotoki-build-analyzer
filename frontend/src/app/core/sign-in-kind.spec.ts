import { TestBed } from '@angular/core/testing';
import type { AnotokiAuthClient, AnotokiInitResult } from '@anotoki/lib/auth';
import { SIGN_IN_KIND_STORAGE_KEY, SignInKind, clientNotingSignInKind } from './sign-in-kind';

const NO_CALLBACK: AnotokiInitResult = { returnTo: null, error: null, silent: false };
const SILENT: AnotokiInitResult = { returnTo: '/builds/genshin', error: null, silent: true };
const CLICKED: AnotokiInitResult = { returnTo: '/import', error: null, silent: false };

describe('SignInKind', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  function kind(): SignInKind {
    return TestBed.inject(SignInKind);
  }

  it('is a click when nothing was noted (a session from before the hint)', () => {
    expect(kind().silent()).toBe(false);
  });

  it('notes how a callback signed somebody in - and keeps it for the page loads that restore that session', () => {
    kind().note(SILENT);
    expect(kind().silent()).toBe(true);
    expect(localStorage.getItem(SIGN_IN_KIND_STORAGE_KEY)).toBe('silent');

    kind().note(CLICKED);
    expect(kind().silent()).toBe(false);
    expect(localStorage.getItem(SIGN_IN_KIND_STORAGE_KEY)).toBe('clicked');
  });

  it('notes nothing for a page load that was no callback, or a callback that signed nobody in', () => {
    kind().note(SILENT);
    kind().note(NO_CALLBACK);
    kind().note({
      ...CLICKED,
      error: Object.assign(new Error('refused'), { code: 'access_denied' }),
    });
    expect(kind().silent()).toBe(true);
  });

  it("notes the auth module's client's own answer (the factory provided as ANOTOKI_AUTH_CLIENT_FACTORY)", async () => {
    const answers = [SILENT, CLICKED];
    const make = vi.fn(
      () => ({ init: async () => answers.shift() }) as unknown as AnotokiAuthClient,
    );
    const factory = clientNotingSignInKind(kind(), make);

    const client = factory(
      {
        issuer: 'https://iam.example.test',
        clientId: 'build-analyzer',
        redirectUri: '/auth/callback',
      },
      {},
    );
    expect(await client.init()).toEqual(SILENT);
    expect(kind().silent()).toBe(true);
    expect(await client.init()).toEqual(CLICKED);
    expect(kind().silent()).toBe(false);
    expect(make).toHaveBeenCalledTimes(1);
  });
});
