import { Component, computed, input } from '@angular/core';
import { scoreColor } from '../core/format';
import { TranslatePipe } from '../core/i18n/translate';

/**
 * Labelled 0-100 bar; "n/a" when the part could not be evaluated. The label comes worded from the page.
 */
@Component({
  selector: 'app-score-bar',
  imports: [TranslatePipe],
  templateUrl: './score-bar.html',
  styleUrl: './score-bar.scss',
})
export class ScoreBar {
  readonly label = input('');
  readonly value = input<number | null | undefined>(null);

  protected readonly color = computed(() => scoreColor(this.value()));
}
