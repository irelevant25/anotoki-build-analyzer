import { Component, computed, input } from '@angular/core';
import { scoreColor } from '../core/format';

/**
 * Labelled 0-100 bar; "n/a" when the part could not be evaluated.
 */
@Component({
  selector: 'app-score-bar',
  template: `
    <div class="head">
      <span>{{ label() }}</span>
      <span class="value">{{ value() === null || value() === undefined ? 'n/a' : value() }}</span>
    </div>
    <div class="track" role="progressbar" [attr.aria-valuenow]="value()" aria-valuemin="0" aria-valuemax="100" [attr.aria-label]="label()">
      <div class="fill" [style.width.%]="value() ?? 0" [style.background]="color()"></div>
    </div>
  `,
  styles: `
    :host { display: block; }
    .head { display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 4px; }
    .value { font-weight: 500; }
    .track { height: 8px; border-radius: 999px; background: var(--app-surface-2); overflow: hidden; }
    .fill { height: 100%; border-radius: 999px; transition: width 300ms ease; }
  `,
})
export class ScoreBar {
  readonly label = input('');
  readonly value = input<number | null | undefined>(null);

  protected readonly color = computed(() => scoreColor(this.value()));
}
