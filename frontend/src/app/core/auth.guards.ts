import { inject } from '@angular/core';
import { CanActivateFn, Router, UrlTree } from '@angular/router';
import { AuthService } from './auth';

/**
 * Pages for signed-in members: accounts, imports, analysis, builds. With sign-in off (or before the
 * server could be asked) every page opens and the API answers for itself.
 */
export const memberGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthService);
  return auth.mode() !== 'iam' || auth.isMember() ? true : signIn(inject(Router), state.url);
};

/**
 * Pages for admins: the sources and their syncs. A member is sent to the dashboard instead.
 */
export const adminGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  if (auth.mode() !== 'iam' || auth.isAdmin()) {
    return true;
  }
  return auth.isMember() ? router.createUrlTree(['/']) : signIn(router, state.url);
};

function signIn(router: Router, returnTo: string): UrlTree {
  return router.createUrlTree(['/sign-in'], { queryParams: { returnTo } });
}
