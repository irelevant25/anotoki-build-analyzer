import { Component, Injector, effect, inject, untracked } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { SiteGateComponent, SitePagesDirective, SiteStatus } from '@anotoki/lib/migrations';
import { ThemeService } from '@anotoki/lib/shell';
import { ConfirmHostComponent } from '@anotoki/lib/ui/dialog';
import { ToastHostComponent, ToastService } from '@anotoki/lib/ui/toast';
import { AuthService } from './shared/auth';
import { Changelog } from './shared/changelog';
import { TranslationService } from './shared/i18n/translation';
import { localizationDrafts } from './shared/localization-drafts';
import { VisitorKey } from './shared/visitor-key';

/**
 * The shell: the router, whose two layouts draw the family's frame - its top bar and the footer (sites/main/app-layout
 * for everybody's pages, sites/admin/admin-layout for /admin) - inside the anotoki family's site gate
 * (@anotoki/lib/migrations, shared/site-status.ts). While the site is not ready the gate shows its page instead:
 * "being updated" (with a quiet Sign in while nobody is signed in) to everybody but the site's ADMIN, who reads
 * that a database update waits and reaches the Migrations page - the one page that shows then; "not set up yet"
 * with the setup page only while the site was never installed; "not available right now" otherwise. The
 * router outlet is the gate's template, made only while the gate shows it: a page behind the gate's page is
 * not made at all, so it sends no request (each would be a 503) and leaves nothing behind - no error, no
 * message, nothing half done in a service the pages share.
 *
 * And what belongs to no page: the theme (the family's ThemeService - theme-boot.js has put the stored one on the
 * page before anything was drawn), What's new once per version, the kit's "Are you sure?" and its messages
 * (<anotoki-confirm-host>, <anotoki-toast-host>: outside the gate, so they serve its page too), and the message
 * that says what moved from a visitor into an account after a sign-in.
 */
@Component({
  selector: 'app-root',
  imports: [RouterOutlet, SiteGateComponent, SitePagesDirective, ConfirmHostComponent, ToastHostComponent],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  private readonly siteStatus = inject(SiteStatus);
  private readonly auth = inject(AuthService);
  private readonly changelog = inject(Changelog);
  private readonly i18n = inject(TranslationService);
  private readonly toasts = inject(ToastService);
  private readonly visitor = inject(VisitorKey);
  /** The theme - the account's while somebody is signed in, else this device's - followed from the start. */
  private readonly theme = inject(ThemeService);
  constructor() {
    // What an editor typed on the Translations and Languages pages and did not save, brought back by a page
    // load: made now - after the sign-in has settled, so whose they are is known - and so asked about before the
    // tab closes, even before either page is opened again. The library keeps them with those pages, in an
    // entry point of their own: it is loaded only when this tab holds some (shared/localization-drafts.ts).
    void localizationDrafts(inject(Injector));

    // What is new since this browser last looked - for somebody who has used the site before (signed in, or
    // holding a visitor key), while the site is there to use; a first visit is never interrupted. The dialog is
    // the shell's, outside the gate, so the gate does not keep it away: it would stand over the gate's page.
    effect(() => {
      if (!this.siteStatus.blocked() && (this.auth.signedIn() || this.visitor.key() !== null) && this.changelog.current()) {
        untracked(() => this.changelog.showIfNew());
      }
    });

    // After a sign-in: what the visitor had imported is now the person's - said once, in the language on the page,
    // and kept on the screen a little longer than a passing message (it is news about the person's own data).
    effect(() => {
      const move = this.visitor.moved();
      if (!move) {
        return;
      }
      untracked(() => {
        const said = [move.moved > 0 ? this.i18n.plural('visitor.moved', move.moved) : '', move.kept > 0 ? this.i18n.plural('visitor.kept', move.kept) : ''];
        // A finished sentence when raised; a message is not worded again.
        this.toasts.show(said.filter(Boolean).join(' '), 'success', 10000);
        this.visitor.moved.set(null);
      });
    });
  }
}
