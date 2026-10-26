import { Component, computed, inject, input } from '@angular/core';
import { ProgressBarComponent } from '@anotoki/lib/ui/progress';
import { scoreColor } from '../../../shared/format';
import { TranslationService } from '../../../shared/i18n/translation';

/**
 * A part of a build's score, out of 100 - the family's progress bar (anotoki-lib 0.6.0, `@anotoki/lib/ui/progress`):
 * the part's name over it (worded by the page), its score beside the name and heard as the bar's value ("87"; "n/a"
 * where the part could not be judged, the bar empty), the fill in one of the bars' three colours - red below 50, gold
 * to 79, green from 80, the grades' cuts (`scoreColor()`; `--bar-*` in styles.scss, each 3:1 at least on the track in
 * both themes).
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
