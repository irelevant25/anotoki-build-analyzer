import { provideHttpClient } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AnotokiAuth } from '@anotoki/lib/auth';
import { TestAnotokiAuth, TestAnotokiAuthOptions, provideTestAnotokiAuth, testAnotokiAuth } from '@anotoki/lib/auth/testing';
import { AuthService } from './auth';

/** The auth module with the IAM's client in memory, started as the app starts it. */
async function start(options: TestAnotokiAuthOptions): Promise<{ handle: TestAnotokiAuth; auth: AuthService }> {
  TestBed.resetTestingModule();
  const handle = testAnotokiAuth();
  TestBed.configureTestingModule({
    providers: [provideRouter([]), provideHttpClient(), ...provideTestAnotokiAuth(options, handle)],
  });
  await TestBed.inject(AnotokiAuth).init();
  return { handle, auth: TestBed.inject(AuthService) };
}

describe("AuthService: the auth module (@anotoki/lib/auth) under the names the site's pages use", () => {
  it('says who is signed in, with their roles here, their name and their account page', async () => {
    const { auth } = await start({
      user: { sub: '7', preferred_username: 'mona', email: 'mona@example.test' },
      roles: ['MEMBER', 'EDITOR'],
      loginRequired: false,
    });
    expect([auth.signedIn(), auth.displayName(), auth.user()?.sub]).toEqual([true, 'mona', '7']);
    expect([auth.isStaff(), auth.isAdmin(), auth.roles()]).toEqual([true, false, ['MEMBER', 'EDITOR']]);
    expect([auth.available(), auth.loginRequired()]).toEqual([true, false]);
    expect(auth.accountUrl()).toBe('https://iam.test/account');
    expect(auth.preferences()).toBeNull();
    expect(auth.failure()).toBeNull();
  });

  it('names a person by their address where the account has no name - and nobody by nothing', async () => {
    const named = await start({
      user: { sub: '8', preferred_username: null, email: 'albedo@example.test' },
    });
    expect(named.auth.displayName()).toBe('albedo@example.test');

    const nobody = await start({ loginRequired: false });
    expect([nobody.auth.signedIn(), nobody.auth.user(), nobody.auth.displayName()]).toEqual([false, null, null]);
    expect(nobody.auth.isStaff()).toBe(false);
  });

  it('says when the page is shown to a visitor: nobody signed in, while the IAM lets visitors in', async () => {
    const visitor = await start({ loginRequired: false });
    expect([visitor.auth.visiting(), visitor.auth.signedIn()]).toEqual([true, false]);
    // The IAM requires signing in here: nobody visits - somebody not signed in is signed out.
    const signedOut = await start({ loginRequired: true });
    expect([signedOut.auth.visiting(), signedOut.auth.signedIn()]).toEqual([false, false]);
    const person = await start({ user: { sub: '7' }, loginRequired: false });
    expect([person.auth.visiting(), person.auth.signedIn()]).toEqual([false, true]);
  });

  it('signs in at the IAM, back to the page asked for - or to this one - and out everywhere', async () => {
    const { handle, auth } = await start({ loginRequired: false });
    expect(await auth.login('/builds/hsr')).toBe(true);
    expect(await auth.login()).toBe(true);
    expect(handle.client?.logins.map((login) => login.returnTo)).toEqual(['/builds/hsr', '/']);

    const signedIn = await start({ user: { sub: '7' } });
    await signedIn.auth.logout();
    expect(signedIn.handle.client?.logouts).toEqual([{ local: false }]);
  });

  it("follows the account's theme, dates and clock, and saves a choice to it", async () => {
    const { handle, auth } = await start({
      user: {
        sub: '7',
        preferences: { theme: 'dark', date_format: 'ymd_dash', time_format: '24' },
      },
    });
    expect(auth.preferences()).toEqual({
      theme: 'dark',
      date_format: 'ymd_dash',
      time_format: '24',
    });
    await auth.savePreferences({ theme: 'light' });
    expect(handle.client?.saved).toEqual([{ theme: 'light' }]);
    expect(auth.preferences()?.theme).toBe('light');
  });

  it('is fail-closed about requiring a sign-in, and offers none where the IAM does not know the site', async () => {
    const { auth } = await start({ signInAvailable: false });
    expect([auth.loginRequired(), auth.available()]).toEqual([true, false]);
  });
});
