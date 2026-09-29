import { Component, computed, input } from '@angular/core';
import { gradeColor } from '../core/format';
import { Grade } from '../core/models';

/**
 * Letter grade (S/A/B/C/D) in a colored ring, optionally with the numeric score.
 */
@Component({
  selector: 'app-grade-badge',
  templateUrl: './grade-badge.html',
  styleUrl: './grade-badge.scss',
})
export class GradeBadge {
  readonly grade = input<Grade | string>('-');
  readonly score = input<number | null | undefined>(null);
  readonly size = input<'sm' | 'md' | 'lg'>('md');

  protected readonly color = computed(() => gradeColor(this.grade()));
}
