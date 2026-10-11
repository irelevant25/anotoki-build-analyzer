import { Component, computed, inject } from '@angular/core';
import { MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { feedbackWords } from '@anotoki/lib/feedback';
import { FeedbackFormComponent } from '@anotoki/lib/feedback/form';
import { TranslationService } from '../../core/i18n/translation';

/**
 * The visitors' feedback form - the anotoki family's (@anotoki/lib/feedback/form, set up in app.config.ts:
 * the language on the page, the site's four places, the person's address) - in a dialog, opened from the foot
 * of every page (layouts/footer). Anybody may send one: nobody, a visitor, a person - who may send it without
 * their name. No mail goes anywhere: the admin panel counts what is new.
 *
 * The dialog's title is the form's own words (`anotoki.feedback.title`: an owner's rewording in the database,
 * else the library's built-in English and Slovak), in the dialog's heading so that it names the dialog; the
 * form draws no heading of its own. Cancel, and Close after the thanks, close the dialog.
 *
 * This file is reached only by a dynamic import: the form and its fields come with the first press.
 */
@Component({
  selector: 'app-feedback-dialog',
  imports: [MatDialogModule, FeedbackFormComponent],
  templateUrl: './feedback-dialog.html',
  styleUrl: './feedback-dialog.scss',
})
export class FeedbackDialog {
  private readonly ref = inject<MatDialogRef<FeedbackDialog>>(MatDialogRef);
  private readonly i18n = inject(TranslationService);

  /** The form's words in the language on the page, and the language they are in. */
  protected readonly said = computed(() =>
    feedbackWords(this.i18n.effectiveLanguage(), undefined, (key) => this.i18n.libraryWord(key)),
  );

  protected close(): void {
    this.ref.close();
  }
}
