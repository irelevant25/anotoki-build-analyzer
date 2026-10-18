import { Component, effect, inject, untracked } from '@angular/core';
import { Router, RouterLink, RouterOutlet } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { SiteFooterComponent } from '@anotoki/lib/footer';
import { ShellComponent } from '@anotoki/lib/shell';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { AuthService } from '../../core/auth';
import { Changelog } from '../../core/changelog';
import { feedbackOffered } from '../../core/feedback';
import { signInOrSayWhy } from '../../core/sign-in';
import { TopBar } from '../top-bar/top-bar';

/**
 * The frame of the admin panel (`/admin/...`, English): for the people who run the site - EDITOR (the
 * syncs, their history, a source's builds file, the site's strings) and ADMIN (also sources on and off,
 * database updates on the Migrations page, languages, people, and the anotoki family's admin tools: the error
 * log, the audit log, feedback and backups). The anotoki family's frame and top bar ("anotoki Build Analyzer ·
 * admin panel", short there: "Analyzer" with "admin panel" under it), with the panel's sections as its links (an
 * editor sees only theirs: ADMIN_LINKS in layouts/top-bar; the count of new feedback beside Feedback, for the
 * ADMIN) and "Back to Build Analyzer" in the person's menu; the family's footer below, as under everybody's pages
 * (layouts/app-layout), in English as the panel is. Between them, one of three:
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
  imports: [
    RouterOutlet,
    RouterLink,
    ShellComponent,
    ButtonComponent,
    CardComponent,
    IconComponent,
    SiteFooterComponent,
    TopBar,
  ],
  templateUrl: './admin-layout.html',
  styleUrl: './admin-layout.scss',
})
export class AdminLayout {
  protected readonly auth = inject(AuthService);
  protected readonly changelog = inject(Changelog);
  /** Feedback in the footer: while a message can be sent - never on "You are signed out", nor while an update waits. */
  protected readonly feedback = feedbackOffered();
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
