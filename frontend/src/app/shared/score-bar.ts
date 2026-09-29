import { Component, computed, input } from '@angular/core';
import { scoreColor } from '../core/format';

/**
 * Labelled 0-100 bar; "n/a" when the part could not be evaluated.
 */
@Component({
  selector: 'app-score-bar',
  templateUrl: './score-bar.html',
  styleUrl: './score-bar.scss',
})
export class ScoreBar {
  readonly label = input('');
  readonly value = input<number | null | undefined>(null);

  protected readonly color = computed(() => scoreColor(this.value()));
}
