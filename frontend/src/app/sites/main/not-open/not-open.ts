import { ChangeDetectionStrategy, Component } from '@angular/core';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { TranslatePipe } from '../../../shared/i18n/translate';

/**
 * Drawn in place of a page the site's administrators closed to this reader (the site settings' Page access - a page
 * switched off, or kept for people signed in, for editors or for administrators) when the dashboard is closed to them
 * too. A closed page falls through to the dashboard as an unknown address does (the family's pageAccessGuard, a
 * canMatch - never a redirect that says the page exists); the dashboard is where everything falls through to, so where
 * it is closed as well the router has nowhere else to go, and this says so - with the bar's open sections above it.
 * Its words are migration 025's (notOpen.*). Nothing else of the site runs on it.
 */
@Component({
  selector: 'app-not-open',
  imports: [EmptyStateComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './not-open.html',
  styleUrl: './not-open.scss',
})
export class NotOpen {}
