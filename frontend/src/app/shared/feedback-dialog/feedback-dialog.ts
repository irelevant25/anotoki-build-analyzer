import { Component, computed, inject, output } from '@angular/core';
import { feedbackWords } from '@anotoki/lib/feedback';
import { FeedbackFormComponent } from '@anotoki/lib/feedback/form';
import { DialogComponent } from '@anotoki/lib/ui/dialog';
import { TranslationService } from '../../core/i18n/translation';

/**
 * The visitors' feedback form - the anotoki family's (@anotoki/lib/feedback/form, set up in app.config.ts:
 * the language on the page, the site's four places, the person's address) - in the family's dialog
 * (@anotoki/lib/ui/dialog: the focus kept inside, Escape and the backdrop close it, the focus back on Feedback
 * after), opened from the foot of every page (layouts/footer). Anybody may send one: nobody, a visitor, a person -
 * who may send it without their name. No mail goes anywhere: the admin panel counts what is new.
 *
 * The dialog's heading is the form's own title (`anotoki.feedback.title`: an owner's rewording in the database,
 * else the library's built-in English and Slovak), so that it names the dialog; the form draws no heading of its
 * own. The dialog says which language those words are in (English in a language that has none of them). Cancel,
 * and Close after the thanks, close it - `closed`, and the footer takes it off the page.
 *
 * The footer draws this in a @defer block: it and the form come with the first press.
 */
@Component({
  selector: 'app-feedback-dialog',
  imports: [DialogComponent, FeedbackFormComponent],
  templateUrl: './feedback-dialog.html',
  styleUrl: './feedback-dialog.scss',
})
export class FeedbackDialog {
  private readonly i18n = inject(TranslationService);

  /** The dialog is done with: Escape, the backdrop, its close button, the form's Cancel or Close. */
  readonly closed = output<void>();

  /** The form's words in the language on the page, and the language they are in. */
  protected readonly said = computed(() =>
    feedbackWords(this.i18n.effectiveLanguage(), undefined, (key) => this.i18n.libraryWord(key)),
  );
}
