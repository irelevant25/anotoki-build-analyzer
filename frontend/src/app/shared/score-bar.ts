import { Component, computed, inject, input } from '@angular/core';
import { ProgressBarComponent } from '@anotoki/lib/ui/progress';
import { scoreColor } from '../core/format';
import { TranslationService } from '../core/i18n/translation';

/**
 * A part of a build's score, out of 100 - the family's progress bar (anotoki-lib 0.6.0, `@anotoki/lib/ui/progress`):
 * the part's name over it (worded by the page), its score beside the name and heard as the bar's value ("87"; "n/a"
 * where the part could not be judged, the bar empty), the fill in the score's grade colour (`--grade-*`, which
 * styles.scss keeps at 4.5:1 - well over the 3:1 a fill needs on its track - in both themes).
 */
@Component({
  selector: 'app-score-bar',
  imports: [ProgressBarComponent],
  templateUrl: './score-bar.html',
  styleUrl: './score-bar.scss',
})
export class ScoreBar {
  private readonly i18n = inject(TranslationService);

  readonly label = input('');
  readonly value = input<number | null | undefined>(null);

  protected readonly color = computed(() => scoreColor(this.value()));

  /** The score as the page writes a number, or "n/a" - never the kit's "45 %": a score is no share. */
  protected readonly valueText = computed(() => {
    const value = this.value();
    return value === null || value === undefined ? this.i18n.t('item.noScore') : this.i18n.number(value);
  });
}
