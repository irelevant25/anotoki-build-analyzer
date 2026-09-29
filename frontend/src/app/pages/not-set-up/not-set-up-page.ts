import { Component, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';

/**
 * What the app shows instead of its pages while the server has not been set up (the API answers every
 * request 503 "not_set_up"): where to set it up, and what to do when that page is gone.
 */
@Component({
  selector: 'app-not-set-up-page',
  imports: [MatButtonModule, MatIconModule],
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
