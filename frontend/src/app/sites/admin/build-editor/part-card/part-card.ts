import { Component, inject, input, output } from '@angular/core';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';

/**
 * A part of a build in the builds editor - its weapons, sets, main stats... -: the kit's card, its heading the part's
 * name, and beside it what the part's state is - "Edited" where it differs from what the builds file says of the build,
 * "Unsaved" where it differs from what was loaded - and "Use the file's", which asks first and then puts the part back
 * as the file has it (the page does: `useFile`). The part's view or form is the content. English, as the admin panel.
 */
@Component({
  selector: 'app-part-card',
  imports: [BadgeComponent, ButtonComponent, CardComponent, IconComponent, TooltipDirective],
  templateUrl: './part-card.html',
  styleUrl: './part-card.scss',
})
export class PartCard {
  private readonly confirm = inject(ConfirmService);

  /** The part's name as a heading: "Weapons". */
  readonly heading = input.required<string>();
  /** The part's name in a sentence: "weapons". */
  readonly part = input.required<string>();
  /** It differs from what the builds file says of the build. */
  readonly edited = input(false);
  /** It differs from what was loaded: a change not saved yet. */
  readonly unsaved = input(false);
  /** The build has the file's version of the part to go back to, unlike its own. */
  readonly canUseFile = input(false);

  readonly useFile = output<void>();

  protected async askUseFile(): Promise<void> {
    const sure = await this.confirm.ask({
      title: `Use the file's ${this.part()}?`,
      message: `What the builds file says replaces this build's ${this.part()}. Until you save, Discard puts back what was there.`,
      confirmLabel: "Use the file's",
    });
    if (sure) {
      this.useFile.emit();
    }
  }
}
