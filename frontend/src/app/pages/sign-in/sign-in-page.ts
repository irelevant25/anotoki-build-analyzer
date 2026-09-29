import { Component, computed, effect, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth';

/**
 * Where the guards send someone who is not signed in (or has no access here). Signing in happens at the
 * anotoki IAM; the person comes back to returnTo.
 */
@Component({
  selector: 'app-sign-in-page',
  imports: [MatButtonModule, MatIconModule],
  templateUrl: './sign-in-page.html',
  styleUrl: './sign-in-page.scss',
})
export class SignInPage {
  protected readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  /** The page to come back to (query parameter). */
  readonly returnTo = input<string | undefined>();

  protected readonly target = computed(() => safePath(this.returnTo()));
  protected readonly noAccess = computed(() => this.auth.mode() === 'iam' && this.auth.user() !== null && !this.auth.isMember());

  constructor() {
    // Signed in with access (or sign-in is off): nothing to do here.
    effect(() => {
      if (this.auth.mode() === 'none' || (this.auth.mode() === 'iam' && this.auth.isMember())) {
        void this.router.navigateByUrl(this.target(), { replaceUrl: true });
      }
    });
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
