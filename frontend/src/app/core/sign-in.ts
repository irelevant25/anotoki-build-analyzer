import { MatSnackBar } from '@angular/material/snack-bar';
import { AuthService } from './auth';
import { TranslationService } from './i18n/translation';

/**
 * A Sign in button's work (the bar's, the visitor notice's, the dashboard's, the admin panel's): to the IAM
 * and back to `returnTo` (this page, by default), the IAM's pages in the language the person reads here when
 * it is known (a bundle is in memory) - or, when the sign-in cannot start (the browser refuses the site's
 * storage, where the SDK keeps the sign-in in progress), a snack bar that says why, where nothing would have
 * happened at all. Worded from the key AuthService.login() left in `lastError`, never the SDK's sentence.
 * The page is left only once the saves on their way have their answers (AuthService.login(): a visitor's
 * first import brings the key the move needs).
 *
 * Answers true when the browser is on its way to the IAM.
 */
export async function signInOrSayWhy(
  auth: AuthService,
  i18n: TranslationService,
  snackBar: MatSnackBar,
  returnTo?: string,
): Promise<boolean> {
  if (await auth.login(returnTo, { uiLocales: i18n.signInLanguage() })) {
    return true;
  }
  const problem = auth.lastError();
  if (problem) {
    // A finished sentence when raised; a snack bar is not worded again.
    snackBar.open(i18n.t(problem.key, problem.params), i18n.t('common.close'), { duration: 10000 });
  }
  return false;
}
