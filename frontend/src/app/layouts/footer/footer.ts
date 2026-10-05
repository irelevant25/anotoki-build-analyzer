import { Component, inject } from '@angular/core';
import { Changelog } from '../../core/changelog';
import { TranslatePipe } from '../../core/i18n/translate';

/**
 * The foot of every page (the family's: "version · What's new"): the version this copy runs - the newest
 * entry of public/changelog.json - and the button that opens every release's notes. Nothing while there is
 * no changelog (a broken upload). In the admin panel it reads English, as the rest of the panel.
 */
@Component({
  selector: 'app-footer',
  imports: [TranslatePipe],
  templateUrl: './footer.html',
  styleUrl: './footer.scss',
})
export class Footer {
  protected readonly changelog = inject(Changelog);
}
