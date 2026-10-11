import { Component, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { AuthService } from '../../core/auth';
import { TranslatePipe } from '../../core/i18n/translate';
import { MetaStore } from '../../core/meta-store';
import { signInOrSayWhy } from '../../core/sign-in';

/**
 * What somebody who is not signed in is told where they import and look at their accounts (the dashboard,
 * the import page - before the first import too, where it says what will happen - and the account and
 * character pages): what they import is kept for this browser, on the server, for `visitors.keep_days`
 * after their last visit, and signing in keeps it in their anotoki account. With a Sign in button - the kit's
 * note (an <anotoki-alert> that is part of the page, never announced).
 *
 * The page decides when to show it (nobody signed in); the notice draws nothing for a person signed in.
 */
@Component({
  selector: 'app-visitor-notice',
  imports: [AlertComponent, ButtonComponent, IconComponent, TranslatePipe],
  templateUrl: './visitor-notice.html',
  styleUrl: './visitor-notice.scss',
})
export class VisitorNotice {
  protected readonly auth = inject(AuthService);
  protected readonly meta = inject(MetaStore);
  private readonly router = inject(Router);

  /** To the IAM and back to this page; the failure's screen says why when the sign-in cannot start. */
  protected signIn(): void {
    void signInOrSayWhy(this.auth, this.router);
  }
}
