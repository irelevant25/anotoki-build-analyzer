import { Component, effect, inject, untracked } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { Router, RouterLink, RouterOutlet } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { AuthService } from '../../core/auth';
import { signInOrSayWhy } from '../../core/sign-in';
import { Footer } from '../footer/footer';
import { TopBar } from '../top-bar/top-bar';

/**
 * The frame of the admin panel (`/admin/...`, English): for the people who run the site - EDITOR (the
 * syncs, their history, a source's builds file, the site's strings) and ADMIN (also sources on and off,
 * database updates on the Migrations page, languages, people, and the anotoki family's admin tools: the error
 * log, the audit log, feedback and backups). The family's top bar on top ("Build Analyzer - admin panel" beside
 * the mark), with the panel's sections as its links (an editor sees only theirs: ADMIN_LINKS in layouts/top-bar;
 * the count of new feedback beside Feedback, for the ADMIN) and "Back to Build Analyzer" in the person's menu;
 * the footer below. Between them, one of three:
 *
 * - staff: the page;
 * - nobody signed in, with a Sign in button: a session that ended with an admin page open ("You are signed
 *   out" - what was typed on the Translations and Languages pages is kept for the tab);
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
  private readonly router = inject(Router);
  private readonly unread = inject(FeedbackUnread);

  constructor() {
    // The new feedback, for the badge beside Feedback: asked once the ADMIN is in the panel (no mail is sent for
    // a message - this is how the site's ADMIN hears of one); the Feedback page keeps it in step from then on.
    effect(() => {
      if (this.auth.isAdmin()) {
        untracked(() => void this.unread.refresh());
      }
    });
  }

  /** To the IAM and back to this page - its pages in the language the person reads the site in. */
  protected signIn(): void {
    void signInOrSayWhy(this.auth, this.router);
  }
}
