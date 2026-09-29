import { Component, OnInit, inject } from '@angular/core';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth';

/**
 * /auth/callback, where the IAM sends the browser back. The IAM client has already finished the sign-in
 * in the app initializer (and normally replaced this address before the router saw it); this page only
 * moves on - to the dashboard, or to the sign-in page when the sign-in did not complete.
 */
@Component({
  selector: 'app-auth-callback-page',
  imports: [MatProgressBarModule],
  templateUrl: './auth-callback-page.html',
  styleUrl: './auth-callback-page.scss',
})
export class AuthCallbackPage implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  ngOnInit(): void {
    const next = this.auth.lastError() && !this.auth.signedIn() ? '/sign-in' : '/';
    void this.router.navigateByUrl(next, { replaceUrl: true });
  }
}
