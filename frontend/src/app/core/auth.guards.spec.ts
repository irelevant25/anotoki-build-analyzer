import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, CanActivateFn, Router, RouterStateSnapshot, UrlTree, provideRouter } from '@angular/router';
import { AuthMode, AuthService } from './auth';
import { adminGuard, memberGuard } from './auth.guards';

function check(guard: CanActivateFn, mode: AuthMode, roles: string[], url = '/sources'): true | string {
  TestBed.resetTestingModule();
  const admin = roles.includes('ADMIN');
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      { provide: AuthService, useValue: { mode: signal(mode), isAdmin: signal(admin), isMember: signal(admin || roles.includes('MEMBER')) } },
    ],
  });
  const result = TestBed.runInInjectionContext(() => guard({} as ActivatedRouteSnapshot, { url } as RouterStateSnapshot));
  return result instanceof UrlTree ? TestBed.inject(Router).serializeUrl(result) : (result as true);
}

describe('route guards', () => {
  it('open the member pages to members and send everyone else to sign in', () => {
    expect(check(memberGuard, 'iam', ['MEMBER'], '/accounts/3')).toBe(true);
    expect(check(memberGuard, 'iam', ['ADMIN'], '/accounts/3')).toBe(true);
    expect(check(memberGuard, 'iam', [], '/accounts/3')).toBe('/sign-in?returnTo=%2Faccounts%2F3');
  });

  it('keep the sources for admins and send members to the dashboard', () => {
    expect(check(adminGuard, 'iam', ['ADMIN'])).toBe(true);
    expect(check(adminGuard, 'iam', ['MEMBER'])).toBe('/');
    expect(check(adminGuard, 'iam', [])).toBe('/sign-in?returnTo=%2Fsources');
  });

  it('open everything with sign-in off', () => {
    expect(check(memberGuard, 'none', [])).toBe(true);
    expect(check(adminGuard, 'none', [])).toBe(true);
  });
});
