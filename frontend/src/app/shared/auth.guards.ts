import { inject } from '@angular/core';
import { CanActivateChildFn, Router } from '@angular/router';
import { AnotokiAuth, personOrVisitorGuard } from '@anotoki/lib/auth';

/** The route of the sign-in failure's screen (the library's <anotoki-sign-in-failure>, in the site's frame). */
export const SIGN_IN_FAILED_PATH = 'sign-in';
/** Where the IAM sends the browser back to (the library's <anotoki-auth-callback>). */
export const CALLBACK_PATH = 'auth/callback';

/**
 * Everybody's pages (the AppLayout's children): the anotoki family's personOrVisitorGuard (@anotoki/lib/auth) -
 * a visitor passes while the IAM lets visitors in; while it requires a sign-in, somebody not signed in is sent to
 * the IAM before anything of a page is drawn, and comes back to the address they asked for - with this site's
 * one addition: a sign-in this page load came back without (or that could not start) is said once, on the
 * failure's screen (/sign-in), also to a visitor, whom the family's guard would let through to a page that says
 * nothing of it. The screen's Back puts it away and returns to the page the sign-in started on; Try again signs
 * in once more.
 *
 * The callback and the failure's screen themselves always go through. The sign-in hint's automatic sign-in of a
 * visitor is no guard's: AnotokiAuth.init() makes its one silent hop before the first page is drawn, and a hop
 * that comes back without a sign-in says nothing.
 */
export const sitePagesGuard: CanActivateChildFn = (child, state) => {
  const path = child.routeConfig?.path;
  if (path === CALLBACK_PATH || path === SIGN_IN_FAILED_PATH) {
    return true;
  }
  if (inject(AnotokiAuth).signInFailure() !== null) {
    return inject(Router).parseUrl(`/${SIGN_IN_FAILED_PATH}`);
  }
  return personOrVisitorGuard(child, state);
};
