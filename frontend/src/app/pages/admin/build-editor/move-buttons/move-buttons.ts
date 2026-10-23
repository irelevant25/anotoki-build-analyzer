import { Component, ElementRef, Injector, afterNextRender, inject, input, output } from '@angular/core';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { EditorAnnouncer } from '../editor-announcer';

/**
 * Move up, move down and remove, for one entry of an ordered list of the builds editor - a build among its character's,
 * a weapon, a set option, a team... (the anotoki-genshin-impact site's way, the owner's choice of 2026-10-22): each an
 * icon-only button of the kit's with its whole name ("Move weapon Staff of Homa up") and a tooltip. Up is off on the
 * first entry, down on the last.
 *
 * The list's owner moves the entry (`move`: -1 or 1) and removes it (`remove`: asking first where that cannot be
 * undone). Once the entry is drawn in its new place - which the page does by moving it, losing the focus on the way - the
 * focus is back on the button pressed, or on the other where this one can go no further, and the move is said to a
 * screen reader ("Weapon Staff of Homa moved up: 1 of 3.", EditorAnnouncer). English, as the whole admin panel.
 */
@Component({
  selector: 'app-move-buttons',
  imports: [ButtonComponent, IconComponent, TooltipDirective],
  templateUrl: './move-buttons.html',
  styleUrl: './move-buttons.scss',
})
export class MoveButtons {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  private readonly announcer = inject(EditorAnnouncer);

  /** The entry's place in its list, from 0. */
  readonly index = input.required<number>();
  /** How many entries the list has. */
  readonly count = input.required<number>();
  /** What the entry is, as its buttons name it: "build Main DPS · Melt", "weapon Staff of Homa". */
  readonly name = input.required<string>();
  /** Whether it can be removed here. */
  readonly removable = input(true);
  /** What removing is called on its button: "Remove", "Delete". */
  readonly removeLabel = input('Remove');

  readonly move = output<-1 | 1>();
  readonly remove = output<void>();

  protected step(delta: -1 | 1): void {
    const to = this.index() + delta;
    if (to < 0 || to >= this.count()) {
      return;
    }
    this.move.emit(delta);
    afterNextRender(
      () => {
        const index = this.index();
        const count = this.count();
        const up = this.host.nativeElement.querySelector<HTMLButtonElement>('button.move-up');
        const down = this.host.nativeElement.querySelector<HTMLButtonElement>('button.move-down');
        const pressed = delta < 0 ? up : down;
        const other = delta < 0 ? down : up;
        const further = delta < 0 ? index > 0 : index < count - 1;
        (further ? pressed : other)?.focus();
        const name = this.name();
        this.announcer.announce(`${name.charAt(0).toUpperCase()}${name.slice(1)} moved ${delta < 0 ? 'up' : 'down'}: ${index + 1} of ${count}.`);
      },
      { injector: this.injector },
    );
  }
}
