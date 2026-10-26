import { Component, computed, inject, input } from '@angular/core';
import { ProgressBarComponent } from '@anotoki/lib/ui/progress';
import { GameKey, StatTarget, TARGET_TIERS } from '../../../api/models';
import { MetaStore } from '../../../shared/meta-store';

/**
 * How far a character's total has come towards a stat target, as a bar (the owner's round of 2026-10-26, step 4):
 * the family's progress bar (`@anotoki/lib/ui/progress`) from where the sheet starts (`floor`: the fixed sources
 * alone, not one useful substat roll yet; nought where the catalog could not say) to the advanced tier - the basic
 * and the intermediate tiers marked on it - or, for a stated requirement, to its value. The fill is the total; its
 * colour one of the bars' three (the owner's rule of 2026-10-26; styles.scss, `--bar-*`): red below the basic tier
 * or an unmet requirement, gold at the basic tier, green from the intermediate tier or a requirement met, the
 * kit's own where nothing is known. The bar is heard with the stat's name and the total as the page writes it; the
 * marks are drawn only, the tier values standing beside the bar.
 */
@Component({
  selector: 'app-target-bar',
  imports: [ProgressBarComponent],
  templateUrl: './target-bar.html',
  styleUrl: './target-bar.scss',
})
export class TargetBar {
  private readonly meta = inject(MetaStore);

  readonly target = input.required<StatTarget>();
  readonly game = input.required<GameKey>();
  /** The stat's name, as the page words it: the bar's name for screen readers. */
  readonly label = input('');

  /** Where the bar starts: the sheet's value from the fixed sources alone. */
  protected readonly min = computed(() => this.target().floor ?? 0);

  /** Where it ends: the advanced tier, or the requirement; a point past the start where the two would meet. */
  protected readonly max = computed(() => {
    const target = this.target();
    const end = target.tiers ? target.tiers.advanced : (target.min ?? 0);
    return end > this.min() ? end : this.min() + 1;
  });

  protected readonly value = computed(() => this.target().current ?? 0);

  protected readonly valueText = computed(() => this.meta.formatStat(this.game(), this.target().stat, this.target().current ?? null));

  /** The basic and the intermediate tiers' places along the bar, in percent of its length; none on a requirement. */
  protected readonly marks = computed(() => {
    const tiers = this.target().tiers;
    if (!tiers) {
      return [];
    }
    const [min, max] = [this.min(), this.max()];
    return TARGET_TIERS.filter((tier) => tier !== 'advanced').map((tier) => Math.max(0, Math.min(100, ((tiers[tier] - min) / (max - min)) * 100)));
  });

  /** The fill's colour: red, gold or green by how far the total has come (above), else the kit's own. */
  protected readonly color = computed(() => {
    const { ok, tier, tiers } = this.target();
    if (ok === false) {
      return 'var(--bar-low)';
    }
    if (ok === true) {
      return tiers && tier === 'basic' ? 'var(--bar-mid)' : 'var(--bar-high)';
    }
    return null;
  });
}
