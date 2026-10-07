import { Component, effect, inject, untracked } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterOutlet } from '@angular/router';
import { SiteGateComponent, SiteStatus } from '@anotoki/lib/migrations';
import { AuthService } from './core/auth';
import { Changelog } from './core/changelog';
import { TranslationService } from './core/i18n/translation';
import { LocalizationDrafts } from './core/localization-drafts';
import { ThemeService } from './core/theme';
import { VisitorKey } from './core/visitor-key';

/**
 * The shell: the router, whose two layouts draw the family's top bar and the footer (layouts/app-layout for
 * everybody's pages, layouts/admin-layout for /admin) - inside the anotoki family's site gate
 * (@anotoki/lib/migrations, core/site-status.ts). While the site is not ready the gate shows its page instead:
 * "being updated" (with a quiet Sign in while nobody is signed in) to everybody but the site's ADMIN, who reads
 * that a database update waits and reaches the Migrations page - the one page that shows then; "not set up yet"
 * with the setup page only while the site was never installed; "not available right now" otherwise. The
 * router outlet is the gate's template, made only while the gate shows it: a page behind the gate's page is
 * not made at all, so it sends no request (each would be a 503) and leaves nothing behind - no error, no
 * snack bar, no sync "stopped" in a service the pages share.
 *
 * And what belongs to no page: the theme (applied before the first page draws), What's new once per version,
 * and the snack bar that says what moved from a visitor into an account after a sign-in.
 */
@Component({
  selector: 'app-root',
  imports: [RouterOutlet, SiteGateComponent],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  private readonly siteStatus = inject(SiteStatus);
  private readonly auth = inject(AuthService);
  private readonly changelog = inject(Changelog);
  private readonly i18n = inject(TranslationService);
  private readonly snackBar = inject(MatSnackBar);
  private readonly visitor = inject(VisitorKey);
  /** The theme - the account's while somebody is signed in, else this device's - on <html> before anything is drawn. */
  private readonly theme = inject(ThemeService);
  /**
   * What the owner typed on the Translations and Languages pages and did not save: made here, after the
   * sign-in has settled, so drafts a page load brought back are asked about before the tab closes even
   * before either page is opened again.
   */
  private readonly drafts = inject(LocalizationDrafts);

  constructor() {
    // What is new since this browser last looked - for somebody who has used the site before (signed in, or
    // holding a visitor key), while the site is there to use; a first visit is never interrupted. The dialog is
    // the shell's, outside the gate, so the gate does not keep it away: it would stand over the gate's page.
    effect(() => {
      if (
        !this.siteStatus.blocked() &&
        (this.auth.signedIn() || this.visitor.key() !== null) &&
        this.changelog.current()
      ) {
        untracked(() => this.changelog.showIfNew());
      }
    });

    // After a sign-in: what the visitor had imported is now the person's - said once, in the language on the page.
    effect(() => {
      const move = this.visitor.moved();
      if (!move) {
        return;
      }
      untracked(() => {
        const said = [
          move.moved > 0 ? this.i18n.plural('visitor.moved', move.moved) : '',
          move.kept > 0 ? this.i18n.plural('visitor.kept', move.kept) : '',
        ];
        // A finished sentence when raised; a snack bar is not worded again.
        this.snackBar.open(said.filter(Boolean).join(' '), this.i18n.t('common.close'), {
          duration: 10000,
        });
        this.visitor.moved.set(null);
      });
    });
  }
}
