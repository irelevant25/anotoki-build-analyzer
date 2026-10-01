import { Component, computed, effect, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslationService } from '../../core/i18n/translation';

/**
 * Where the guards send someone who is not signed in (or has no access here). Signing in happens at the
 * anotoki IAM; the person comes back to returnTo.
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

  /** The page to come back to (query parameter). */
  readonly returnTo = input<string | undefined>();

  protected readonly target = computed(() => safePath(this.returnTo()));
  protected readonly noAccess = computed(() => this.auth.mode() === 'iam' && this.auth.user() !== null && !this.auth.isMember());

  /**
   * "Your anotoki account NAME has no access ...", cut where the name stands so the name can be drawn
   * strong: [before, after, whether the sentence names the account at all].
   */
  protected readonly noAccessSentence = computed(() => this.i18n.around('signIn.noAccess', 'name'));

  /** The account's own name - or, for one with neither a user name nor an email address, its number, worded. */
  protected readonly accountName = computed(() => this.auth.displayName() ?? this.i18n.t('common.userFallback', { sub: this.auth.user()?.sub ?? '' }));

  constructor() {
    // Signed in with access (or sign-in is off): nothing to do here.
    effect(() => {
      if (this.auth.mode() === 'none' || (this.auth.mode() === 'iam' && this.auth.isMember())) {
        void this.router.navigateByUrl(this.target(), { replaceUrl: true });
      }
    });
  }

  /** To the IAM's sign-in page - in the person's language, when it is known (a bundle is in memory). */
  protected signIn(): void {
    void this.auth.login(this.target(), { uiLocales: this.i18n.signInLanguage() });
  }

  /** The same, asking the IAM for its sign-in form even though somebody is signed in there. */
  protected useAnotherAccount(): void {
    void this.auth.login(this.target(), { anotherAccount: true, uiLocales: this.i18n.signInLanguage() });
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
