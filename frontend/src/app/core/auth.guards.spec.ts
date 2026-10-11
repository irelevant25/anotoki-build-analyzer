import { provideHttpClient } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  Router,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';
import {
  ANOTOKI_AUTH_CLIENT_FACTORY,
  AnotokiAuth,
  AnotokiAuthEnvironment,
  AnotokiAuthOptions,
  adminGuard,
} from '@anotoki/lib/auth';
import {
  FakeAuthClient,
  TestAnotokiAuth,
  TestUser,
  provideTestAnotokiAuth,
  testAnotokiAuth,
} from '@anotoki/lib/auth/testing';
import { CALLBACK_PATH, SIGN_IN_FAILED_PATH, sitePagesGuard } from './auth.guards';

interface Who {
  user?: TestUser | null;
  roles?: string[];
  /** The IAM's switch, as the server relayed it (default: visitors welcome, as this site is). */
  loginRequired?: boolean;
  /** This page load came back from the IAM without a sign-in: its code. */
  refused?: string;
}

/**
 * The auth module as this site sets it up - the failure's screen on /sign-in, an editor sent to the Sources page -
 * with the IAM's client in memory and a browser that only records where it was sent.
 */
async function setup(who: Who = {}): Promise<{ handle: TestAnotokiAuth; auth: AnotokiAuth }> {
  TestBed.resetTestingModule();
  const handle = testAnotokiAuth();
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      provideHttpClient(),
      ...provideTestAnotokiAuth(
        {
          user: who.user ?? null,
          roles: who.roles,
          loginRequired: who.loginRequired ?? false,
          visitors: { current: () => null, remember: () => undefined, forget: () => undefined },
          config: { failureRoute: `/${SIGN_IN_FAILED_PATH}`, forbiddenRoute: '/admin/sources' },
        },
        handle,
      ),
      {
        provide: ANOTOKI_AUTH_CLIENT_FACTORY,
        useValue: (options: AnotokiAuthOptions, environment: AnotokiAuthEnvironment) => {
          const user = who.user ? { roles: who.roles ?? ['MEMBER'], ...who.user } : null;
          const client = (handle.client = new FakeAuthClient(options, environment, user));
          if (who.refused) {
            client.initResult = {
              returnTo: '/builds/genshin',
              error: Object.assign(new Error('The IAM said no'), { code: who.refused }),
              silent: false,
            };
          }
          return client;
        },
      },
    ],
  });
  const auth = TestBed.inject(AnotokiAuth);
  await auth.init();
  return { handle, auth };
}

/** What the guard answers for a page of the AppLayout: true, or where it sends the browser instead. */
async function page(path: string, url: string): Promise<boolean | string> {
  const result = await TestBed.runInInjectionContext(() =>
    sitePagesGuard(
      { routeConfig: { path } } as ActivatedRouteSnapshot,
      { url } as RouterStateSnapshot,
    ),
  );
  return result instanceof UrlTree
    ? TestBed.inject(Router).serializeUrl(result)
    : (result as boolean);
}

describe("everybody's pages: sitePagesGuard (the family's personOrVisitorGuard, and a failed sign-in said once)", () => {
  it('lets a visitor in while the IAM lets visitors in, and a person signed in', async () => {
    await setup();
    expect(await page('builds/:game', '/builds/genshin')).toBe(true);
    expect(await page('import', '/import')).toBe(true);

    await setup({ user: { sub: '7' } });
    expect(await page('', '/')).toBe(true);
  });

  it('sends somebody not signed in to the IAM first while the IAM requires a sign-in here - back to the page they asked for', async () => {
    const { handle } = await setup({ loginRequired: true });
    expect(await page('accounts/:id', '/accounts/3')).toBe(false);
    expect(handle.client?.logins).toEqual([expect.objectContaining({ returnTo: '/accounts/3' })]);
  });

  it('says a sign-in this page load came back without on /sign-in - to a visitor too, who would otherwise be let through', async () => {
    const { auth } = await setup({ refused: 'access_denied' });
    expect(auth.state()).toBe('visitor');
    expect(auth.signInFailure()).toEqual({ code: 'access_denied', returnTo: '/builds/genshin' });
    expect(await page('builds/:game', '/builds/genshin')).toBe('/sign-in');
    expect(await page('', '/')).toBe('/sign-in');

    // Back on the screen puts it away: the pages go through again.
    auth.dismissFailure();
    expect(await page('builds/:game', '/builds/genshin')).toBe(true);
  });

  it('never stands in the way of the callback or of the failure screen itself', async () => {
    await setup({ refused: 'state_mismatch', loginRequired: true });
    expect(await page(CALLBACK_PATH, '/auth/callback')).toBe(true);
    expect(await page(SIGN_IN_FAILED_PATH, '/sign-in')).toBe(true);
  });
});

describe("the admin panel's ADMIN pages: the family's adminGuard, as this site sets it up", () => {
  async function admin(url = '/admin/people'): Promise<boolean | string> {
    const result = await TestBed.runInInjectionContext(() =>
      adminGuard({} as ActivatedRouteSnapshot, { url } as RouterStateSnapshot),
    );
    return result instanceof UrlTree
      ? TestBed.inject(Router).serializeUrl(result)
      : (result as boolean);
  }

  it('lets the ADMIN in, sends an editor (and anybody else signed in) to the Sources page', async () => {
    await setup({ user: { sub: '1' }, roles: ['MEMBER', 'ADMIN'] });
    expect(await admin()).toBe(true);
    expect(await admin('/admin/backups')).toBe(true);

    await setup({ user: { sub: '4' }, roles: ['MEMBER', 'EDITOR'] });
    expect(await admin()).toBe('/admin/sources');

    await setup({ user: { sub: '7' } });
    expect(await admin('/admin/feedback')).toBe('/admin/sources');
  });

  it('sends somebody not signed in to the IAM, back to the page', async () => {
    const { handle } = await setup();
    expect(await admin('/admin/errors')).toBe(false);
    expect(handle.client?.logins).toEqual([expect.objectContaining({ returnTo: '/admin/errors' })]);
  });
});
