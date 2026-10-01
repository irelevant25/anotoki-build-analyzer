import { Component, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '../../core/i18n/translate';

/**
 * What the app shows instead of its pages while the server has not been set up (the API answers every
 * request 503 "not_set_up"): where to set it up, and what to do when that page is gone.
 *
 * The server cannot be asked for the site's words either, so the page reads the compiled English - or
 * the strings this browser kept from an earlier visit - through the same keys as any other page.
 */
@Component({
  selector: 'app-not-set-up-page',
  imports: [MatButtonModule, MatIconModule, TranslatePipe],
  templateUrl: './not-set-up-page.html',
  styleUrl: './not-set-up-page.scss',
})
export class NotSetUpPage {
  /** The setup page, "/setup.php". */
  readonly setupUrl = input.required<string>();

  protected reload(): void {
    window.location.reload();
  }
}
