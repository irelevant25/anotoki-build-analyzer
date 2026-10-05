import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { ActivatedRouteSnapshot, CanActivateFn, Router, RouterStateSnapshot, UrlTree, provideRouter } from '@angular/router';
import { AuthService } from './auth';
import { adminOnlyGuard, signInGuard, siteSignInGuard } from './auth.guards';
import { TranslationService, Wording } from './i18n/translation';

interface Who {
  signedIn?: boolean;
  roles?: string[];
  available?: boolean;
  lastError?: Wording | null;
  /** The IAM's switch, as the server relayed it (default: required). */
  loginRequired?: boolean;
  /** This page load came back from a refused sign-in. */
  refusedOnReturn?: boolean;
  /** What login() does: starts (true), or cannot start and leaves this in lastError. */
  login?: true | Wording;
}

function setup(who: Who) {
  TestBed.resetTestingModule();
  const roles = who.roles ?? [];
  const lastError = signal<Wording | null>(who.lastError ?? null);
  let refusedOnReturn = who.refusedOnReturn ?? false;
  const auth = {
    signedIn: signal(who.signedIn ?? false),
    isAdmin: signal(roles.includes('ADMIN')),
    isStaff: signal(roles.includes('ADMIN') || roles.includes('EDITOR')),
    available: signal(who.available ?? true),
    lastError,
    loginRequired: signal(who.loginRequired ?? true),
    takeRefusalOnReturn: vi.fn(() => {
      const refused = refusedOnReturn;
      refusedOnReturn = false;
      return refused;
    }),
    login: vi.fn(async () => {
      if (who.login === undefined || who.login === true) {
        return true;
      }
      lastError.set(who.login);
      return false;
    }),
  };
  const snackBar = { open: vi.fn() };
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      { provide: AuthService, useValue: auth },
      { provide: MatSnackBar, useValue: snackBar },
      { provide: TranslationService, useValue: { signInLanguage: () => 'sk', t: (key: string) => `«${key}»` } },
    ],
  });
  return { auth, snackBar };
}

async function check(guard: CanActivateFn, url = '/admin/sources'): Promise<boolean | string> {
  const result = await TestBed.runInInjectionContext(() => guard({} as ActivatedRouteSnapshot, { url } as RouterStateSnapshot));
  return result instanceof UrlTree ? TestBed.inject(Router).serializeUrl(result) : (result as boolean);
}

describe('signInGuard: the admin panel, for somebody signed in', () => {
  it('lets a signed-in person in - whoever they are: the admin layout tells editors and admins from the rest', async () => {
    setup({ signedIn: true, roles: [] });
    expect(await check(signInGuard)).toBe(true);
  });

  it('sends anybody else to the IAM, in the language they read the site in, and back to the address they asked for', async () => {
    const { auth } = setup({});
    expect(await check(signInGuard, '/admin/translations?missing=sk')).toBe(false);
    expect(auth.login).toHaveBeenCalledWith('/admin/translations?missing=sk', { uiLocales: 'sk' });
  });

  it('treats a visitor as signed out: the admin panel is sign-in only, whether or not the site is open to visitors', async () => {
    const { auth } = setup({ loginRequired: false });
    expect(await check(signInGuard, '/admin/sources')).toBe(false);
    expect(auth.login).toHaveBeenCalledWith('/admin/sources', { uiLocales: 'sk' });
  });

  it('lets the page through to its card, with a snack bar that says why, when the sign-in cannot start', async () => {
    const { snackBar } = setup({ login: { key: 'signIn.storageUnavailable', params: {} } });
    expect(await check(signInGuard)).toBe(true);
    expect(snackBar.open).toHaveBeenCalledWith('«signIn.storageUnavailable»', '«common.close»', expect.anything());
  });

  it('does not go round for ever: back from the IAM without a sign-in, or with no IAM to go to, the page goes through', async () => {
    const back = setup({ lastError: { key: 'signIn.notCompleted', params: {} } });
    expect(await check(signInGuard)).toBe(true);
    expect(back.auth.login).not.toHaveBeenCalled();

    const none = setup({ available: false });
    expect(await check(signInGuard)).toBe(true);
    expect(none.auth.login).not.toHaveBeenCalled();
  });
});

describe('adminOnlyGuard: Languages, People and Migrations, for an admin', () => {
  it('opens them to an admin, sends an editor (or anybody signed in without the role) to the Sources page, and leaves nobody to the layout', async () => {
    setup({ signedIn: true, roles: ['ADMIN'] });
    expect(await check(adminOnlyGuard)).toBe(true);
    setup({ signedIn: true, roles: ['EDITOR'] });
    expect(await check(adminOnlyGuard)).toBe('/admin/sources');
    setup({ signedIn: true, roles: [] });
    expect(await check(adminOnlyGuard)).toBe('/admin/sources');
    setup({ signedIn: false });
    expect(await check(adminOnlyGuard)).toBe(true);
  });
});

/** The site guard, for the AppLayout child at `path`, as a navigation to `url` asks it. */
async function checkChild(path: string, url: string): Promise<boolean | string> {
  const child = { routeConfig: { path } } as ActivatedRouteSnapshot;
  const result = await TestBed.runInInjectionContext(() => siteSignInGuard(child, { url } as RouterStateSnapshot));
  return result instanceof UrlTree ? TestBed.inject(Router).serializeUrl(result) : (result as boolean);
}

describe('siteSignInGuard: everybody\'s pages, when the IAM says the site requires a sign-in', () => {
  it('lets everybody through while the site is open to visitors', async () => {
    const { auth } = setup({ loginRequired: false });
    expect(await checkChild('builds/:game', '/builds/genshin')).toBe(true);
    expect(await checkChild('', '/')).toBe(true);
    expect(auth.login).not.toHaveBeenCalled();
  });

  it('sends somebody not signed in to the IAM, in their language, and back to the page they asked for', async () => {
    const { auth } = setup({ loginRequired: true });
    expect(await checkChild('builds/:game', '/builds/hsr?q=x')).toBe(false);
    expect(auth.login).toHaveBeenCalledWith('/builds/hsr?q=x', { uiLocales: 'sk' });
  });

  it('lets a signed-in person through - MEMBER alone is an ordinary person', async () => {
    const { auth } = setup({ loginRequired: true, signedIn: true, roles: ['MEMBER'] });
    expect(await checkChild('import', '/import')).toBe(true);
    expect(auth.login).not.toHaveBeenCalled();
  });

  it('always lets the sign-in page and the callback through - no loop', async () => {
    const { auth } = setup({ loginRequired: true });
    expect(await checkChild('sign-in', '/sign-in?returnTo=%2Fimport')).toBe(true);
    expect(await checkChild('auth/callback', '/auth/callback?code=x')).toBe(true);
    expect(auth.login).not.toHaveBeenCalled();
  });

  it('shows the sign-in page, which says why, where the IAM cannot be gone to: back without a sign-in, no IAM, or a sign-in that cannot start', async () => {
    const back = setup({ loginRequired: true, lastError: { key: 'signIn.stateMismatch', params: {} } });
    expect(await checkChild('import', '/import')).toBe('/sign-in?returnTo=%2Fimport');
    expect(back.auth.login).not.toHaveBeenCalled();

    const none = setup({ loginRequired: true, available: false });
    expect(await checkChild('', '/')).toBe('/sign-in?returnTo=%2F');
    expect(none.auth.login).not.toHaveBeenCalled();

    const blocked = setup({ loginRequired: true, login: { key: 'signIn.storageUnavailable', params: {} } });
    expect(await checkChild('builds/:game', '/builds/genshin')).toBe('/sign-in?returnTo=%2Fbuilds%2Fgenshin');
    expect(blocked.snackBar.open).toHaveBeenCalledWith('«signIn.storageUnavailable»', '«common.close»', expect.anything());
  });

  it('shows a sign-in the IAM refused while visiting (MEMBER taken away) on the sign-in page, once - never swallowed', async () => {
    const { auth } = setup({ loginRequired: false, refusedOnReturn: true, lastError: { key: 'signIn.notCompleted', params: {} } });
    expect(await checkChild('builds/:game', '/builds/genshin')).toBe('/sign-in?returnTo=%2Fbuilds%2Fgenshin');
    // Said once: the visitor goes on visiting.
    expect(await checkChild('builds/:game', '/builds/genshin')).toBe(true);
    expect(auth.login).not.toHaveBeenCalled();
  });

  it('does not show it twice when the page asked for is the sign-in page itself', async () => {
    setup({ loginRequired: false, refusedOnReturn: true, lastError: { key: 'signIn.notCompleted', params: {} } });
    expect(await checkChild('sign-in', '/sign-in')).toBe(true);
    expect(await checkChild('', '/')).toBe(true);
  });
});
