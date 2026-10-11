import { Router } from '@angular/router';
import { AuthService } from './auth';
import { SIGN_IN_FAILED_PATH } from './auth.guards';

/**
 * A Sign in button's work (the bar's, the visitor notice's, the dashboard's, the admin panel's, the site gate's
 * page's): to the IAM and back to `returnTo` (this page, by default) - the IAM's pages in the language read here,
 * once the saves on their way have their answers (the auth module waits for them: a visitor's first import
 * brings the key the move after the sign-in needs). When the sign-in cannot even start - the browser refuses the
 * site's storage, where the sign-in in progress is kept - the failure's screen (/sign-in) says why, where nothing
 * would have happened at all.
 *
 * Answers true when the browser is on its way to the IAM.
 */
export async function signInOrSayWhy(
  auth: AuthService,
  router: Router,
  returnTo?: string,
): Promise<boolean> {
  if (await auth.login(returnTo)) {
    return true;
  }
  if (auth.failure() !== null) {
    await router.navigateByUrl(`/${SIGN_IN_FAILED_PATH}`);
  }
  return false;
}
