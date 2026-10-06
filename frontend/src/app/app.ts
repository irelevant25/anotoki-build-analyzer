import { Component, effect, inject, untracked } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterOutlet } from '@angular/router';
import { AuthService } from './core/auth';
import { Changelog } from './core/changelog';
import { TranslatePipe } from './core/i18n/translate';
import { TranslationService } from './core/i18n/translation';
import { LocalizationDrafts } from './core/localization-drafts';
import { MetaStore } from './core/meta-store';
import { ThemeService } from './core/theme';
import { VisitorKey } from './core/visitor-key';
import { NotSetUpPage } from './pages/not-set-up/not-set-up-page';
import { Brand } from './shared/brand/brand';

/**
 * The shell: only the boot decision. Not set up -> the "not set up yet" page under the brand alone (there is
 * nothing to sign in to, nothing to link to); else the router, whose two layouts draw the family's top bar
 * and the footer (layouts/app-layout for everybody's pages, layouts/admin-layout for /admin).
 *
 * And what belongs to no page: the theme (applied before the first page draws), What's new once per version,
 * and the snack bar that says what moved from a visitor into an account after a sign-in.
 */
@Component({
  selector: 'app-root',
  imports: [RouterOutlet, NotSetUpPage, Brand, TranslatePipe],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  protected readonly meta = inject(MetaStore);
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
    // holding a visitor key); a first visit is never interrupted.
    effect(() => {
      if (
        !this.meta.notSetUp() &&
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
