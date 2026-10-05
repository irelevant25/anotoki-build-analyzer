import { Component, computed, effect, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslationService } from '../../core/i18n/translation';
import { signInOrSayWhy } from '../../core/sign-in';

/**
 * Signing in, on a page of its own: it says why the last sign-in did not complete (`lastError`), and is
 * what the sign-in callback falls back to - and where siteSignInGuard shows a sign-in this page load came
 * back from refused, visiting or not. Signing in happens at the anotoki IAM; the person comes back to
 * returnTo. While the site is open to visitors nobody else is sent here; while the IAM says it requires a
 * sign-in, this is where somebody lands whom the guard could not send to the IAM (no IAM to go to, a
 * sign-in that came back without one, or one that could not start).
 *
 * The page is also what shows while the server cannot be reached: then there may be no strings from it
 * at all, and the words are the compiled English - through the same keys.
 */
@Component({
  selector: 'app-sign-in-page',
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
  templateUrl: './sign-in-page.html',
  styleUrl: './sign-in-page.scss',
})
export class SignInPage {
  protected readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslationService);
  private readonly snackBar = inject(MatSnackBar);

  /** The page to come back to (query parameter). */
  readonly returnTo = input<string | undefined>();

  protected readonly target = computed(() => safePath(this.returnTo()));

  constructor() {
    // Signed in: nothing to do here.
    effect(() => {
      if (this.auth.signedIn()) {
        void this.router.navigateByUrl(this.target(), { replaceUrl: true });
      }
    });
  }

  /** To the IAM's sign-in page - in the person's language, when it is known; a snack bar says why when it cannot start. */
  protected signIn(): void {
    void signInOrSayWhy(this.auth, this.i18n, this.snackBar, this.target());
  }
}

/**
 * Only a path on this site; anything else would make the sign-in an open redirect. Control characters are
 * refused too: URL parsing drops tabs and line breaks, which would turn "/\t/elsewhere" into "//elsewhere".
 */
export function safePath(value: string | undefined): string {
  if (!value || /[\u0000-\u001f\u007f]/.test(value)) {
    return '/';
  }
  return value.startsWith('/') && !value.startsWith('//') && !value.startsWith('/\\') ? value : '/';
}
