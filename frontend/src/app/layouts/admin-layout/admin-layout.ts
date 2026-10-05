import { Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink, RouterOutlet } from '@angular/router';
import { AuthService } from '../../core/auth';
import { TranslationService } from '../../core/i18n/translation';
import { signInOrSayWhy } from '../../core/sign-in';
import { Footer } from '../footer/footer';
import { TopBar } from '../top-bar/top-bar';

/**
 * The frame of the admin panel (`/admin/...`, English): for the people who run the site - EDITOR (the
 * syncs, their history, a source's builds file, the site's strings) and ADMIN (also sources on and off,
 * database updates on the Migrations page, languages, people). The family's top bar on top ("Build
 * Analyzer - admin panel" beside the mark), with the panel's sections as its links
 * (an editor sees only theirs: ADMIN_LINKS in layouts/top-bar) and "Back to Build Analyzer" in the person's
 * menu; the footer below. Between them, one of three:
 *
 * - staff: the page;
 * - nobody signed in, with a Sign in button: a session that ended with an admin page open ("You are signed
 *   out" - what was typed on the Translations and Languages pages is kept for the tab), or a sign-in that
 *   came back without one or could not start (signInGuard lets that page load through): "You are not
 *   signed in";
 * - somebody signed in with no role here: whose the area is, and the way back to the dashboard.
 *
 * It only hides: every admin route checks the role on the server (403 editor_only / admin_only).
 */
@Component({
  selector: 'app-admin-layout',
  imports: [RouterOutlet, RouterLink, MatButtonModule, MatIconModule, TopBar, Footer],
  templateUrl: './admin-layout.html',
  styleUrl: './admin-layout.scss',
})
export class AdminLayout {
  protected readonly auth = inject(AuthService);
  private readonly i18n = inject(TranslationService);
  private readonly snackBar = inject(MatSnackBar);

  /** To the IAM and back to this page - its pages in the language the person reads the site in; a snack bar if it cannot start. */
  protected signIn(): void {
    void signInOrSayWhy(this.auth, this.i18n, this.snackBar);
  }
}
