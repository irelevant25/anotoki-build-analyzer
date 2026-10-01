import { Injectable, effect, inject, untracked } from '@angular/core';
import { MatStepperIntl } from '@angular/material/stepper';
import { TranslationService } from '../../core/i18n/translation';

/**
 * The stepper's own words - what it tells a screen reader about a step ("Completed", "Editable",
 * "Optional") - in the language on the page. The stepper is the only Material piece here that has strings
 * of its own; the steps' labels are the wizard's.
 *
 * Material reads these three fields when a step header is drawn, and draws the headers again when
 * `changes` fires: so they are worded once at the start and again whenever the strings on the page change
 * (another language is chosen, or the bundle arrives after the page started on the compiled English).
 *
 * Provided by the import wizard, the only page with a stepper.
 */
@Injectable()
export class StepperWords extends MatStepperIntl {
  private readonly i18n = inject(TranslationService);

  constructor() {
    super();
    this.word();
    effect(() => {
      this.word();
      untracked(() => this.changes.next());
    });
  }

  private word(): void {
    this.optionalLabel = this.i18n.t('stepper.optional');
    this.completedLabel = this.i18n.t('stepper.completed');
    this.editableLabel = this.i18n.t('stepper.editable');
  }
}
