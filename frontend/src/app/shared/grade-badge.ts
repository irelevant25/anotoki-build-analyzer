import { Component, computed, input } from '@angular/core';
import { gradeColor } from '../core/format';
import { Grade } from '../core/models';

/**
 * Letter grade (S/A/B/C/D) in a colored ring, optionally with the numeric score.
 */
@Component({
  selector: 'app-grade-badge',
  template: `
    <span class="badge" [class.lg]="size() === 'lg'" [class.sm]="size() === 'sm'" [style.--grade-color]="color()"
      [attr.aria-label]="'Grade ' + grade()">{{ grade() }}</span>
    @if (score() !== null && score() !== undefined) {
      <span class="score">{{ score() }}</span>
    }
  `,
  styles: `
    :host { display: inline-flex; align-items: center; gap: 6px; }
    .badge {
      --size: 32px;
      width: var(--size); height: var(--size);
      display: inline-grid; place-items: center;
      border-radius: 50%;
      font-weight: 700;
      color: var(--grade-color);
      border: 2px solid var(--grade-color);
      background: color-mix(in srgb, var(--grade-color) 14%, transparent);
    }
    .badge.lg { --size: 56px; font-size: 1.6rem; border-width: 3px; }
    .badge.sm { --size: 24px; font-size: 0.8rem; }
    .score { font-weight: 500; color: var(--app-muted); }
  `,
})
export class GradeBadge {
  readonly grade = input<Grade | string>('-');
  readonly score = input<number | null | undefined>(null);
  readonly size = input<'sm' | 'md' | 'lg'>('md');

  protected readonly color = computed(() => gradeColor(this.grade()));
}
