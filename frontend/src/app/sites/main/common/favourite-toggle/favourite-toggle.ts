import { Component, input, output } from '@angular/core';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { TranslatePipe } from '../../../../shared/i18n/translate';

/**
 * A character's star: whether the player is building it - one of their favourites, listed first on the account page
 * (the owner's request of 2026-10-27). The kit's quiet button, pressed or not (aria-pressed), named after the
 * character ("Favourite: Furina") and saying in a tooltip what a press does; the star is the site's own drawing (the
 * family's icons have none), filled in the favourite's gold while pressed (`--favourite`, styles.scss), its outline
 * in the muted ink otherwise.
 *
 * It only asks: `toggled` gives the state wanted, and the page that owns the character saves it and sets
 * `favourite` from the answer. While `busy` (a save on its way, a moment) a press asks nothing; the button is never
 * disabled, so the focus does not leave it - and the kit's `loading` would, besides drawing a spinner beside the star.
 */
@Component({
  selector: 'app-favourite-toggle',
  imports: [ButtonComponent, TooltipDirective, TranslatePipe],
  templateUrl: './favourite-toggle.html',
  styleUrl: './favourite-toggle.scss',
})
export class FavouriteToggle {
  readonly favourite = input.required<boolean>();
  /** The character's name - the game's: the button's name says whose star it is. */
  readonly name = input.required<string>();
  readonly busy = input(false);
  readonly toggled = output<boolean>();

  protected press(): void {
    if (!this.busy()) {
      this.toggled.emit(!this.favourite());
    }
  }
}
