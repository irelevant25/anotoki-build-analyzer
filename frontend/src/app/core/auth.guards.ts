import { inject } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { CanActivateChildFn, CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth';
import { TranslationService } from './i18n/translation';
import { signInOrSayWhy } from './sign-in';

/**
 * The admin panel (`/admin`, as it is entered) is for somebody signed in: anybody else is sent to the IAM
 * before anything of the page is drawn, and comes back to the address they asked for - the IAM's pages in
 * the language the person reads here. A visitor (a key held, the site open to visitors) is somebody signed
 * out here like anybody else: the admin panel is never a visitor's (and its requests are a person's alone -
 * core/api.ts). Every other page is open to everybody, unless the IAM says this site requires a sign-in
 * (siteSignInGuard). Who of the signed-in may use the area - EDITOR or ADMIN - the admin layout says, and
 * the API decides.
 *
 * It lets the page through with nobody signed in when sending them away would go round for ever or show
 * nothing: this page load came back from the IAM without a sign-in (`lastError`), the sign-in cannot even
 * start (the browser refuses the site's storage - a snack bar says why), or there is no IAM to go to (the
 * server could not be asked). The admin layout then draws its "not signed in" card with a Sign in button.
 */
export const signInGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthService);
  if (auth.signedIn() || auth.lastError() || !auth.available()) {
    return true;
  }
  return signInOrSayWhy(auth, inject(TranslationService), inject(MatSnackBar), state.url).then((started) => !started);
};

/**
 * Everybody's pages (the AppLayout's children) while the IAM says this site requires a sign-in
 * (`auth.loginRequired()`, also when the server could not be asked): somebody not signed in is sent to the
 * IAM before anything of a page is drawn, and comes back to the address they asked for. While the site is
 * open to visitors, every page goes through.
 *
 * The sign-in page and the callback always go through. Where sending somebody to the IAM would go round for
 * ever or cannot happen - this page load came back from the IAM without a sign-in (`lastError`), there is no
 * IAM to go to (the server could not be asked, or has none), or the sign-in cannot even start - they go to
 * the sign-in page instead, which says why (`returnTo`: the page they asked for).
 *
 * And, visiting or not: a sign-in this page load came back from refused or failed (an `access_denied` is
 * also how the IAM turns away somebody whose MEMBER role was taken) is shown once, on the sign-in page -
 * never swallowed by a page a visitor may use anyway.
 */
export const siteSignInGuard: CanActivateChildFn = (child, state) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const path = child.routeConfig?.path;
  if (path === 'auth/callback') {
    return true;
  }
  const toSignIn = router.createUrlTree(['/sign-in'], { queryParams: { returnTo: state.url } });
  if (path === 'sign-in') {
    // The page says why itself (lastError).
    auth.takeRefusalOnReturn();
    return true;
  }
  if (auth.takeRefusalOnReturn()) {
    return toSignIn;
  }
  if (!auth.loginRequired() || auth.signedIn()) {
    return true;
  }
  if (auth.lastError() || !auth.available()) {
    return toSignIn;
  }
  return signInOrSayWhy(auth, inject(TranslationService), inject(MatSnackBar), state.url).then((started) =>
    started ? false : toSignIn,
  );
};

/**
 * The admin panel's ADMIN pages (Languages, People, Migrations): an editor is sent to the Sources page. The
 * server refuses them all the same (403 admin_only); this only keeps the page from opening empty. Nobody
 * signed in goes through to the admin layout's card.
 */
export const adminOnlyGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  return auth.isAdmin() || !auth.signedIn() ? true : inject(Router).createUrlTree(['/admin/sources']);
};
