import { Component, inject, signal } from '@angular/core';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { Changelog } from '../../core/changelog';
import { TranslatePipe } from '../../core/i18n/translate';
import { FeedbackDialog } from '../../shared/feedback-dialog/feedback-dialog';

/**
 * The foot of every page (the family's: "version · What's new"): the version this copy runs - the newest entry
 * of public/changelog.json - and the button that opens every release's notes (nothing of them while there is no
 * changelog: a broken upload); and Feedback, which opens the anotoki family's feedback form in the kit's dialog
 * (@anotoki/lib/feedback/form, shared/feedback-dialog - in a @defer block, so the dialog and the form come with the
 * first press and no first load carries them). In the admin panel it reads English, as the rest of the panel.
 */
@Component({
  selector: 'app-footer',
  imports: [ButtonComponent, TranslatePipe, FeedbackDialog],
  templateUrl: './footer.html',
  styleUrl: './footer.scss',
})
export class Footer {
  protected readonly changelog = inject(Changelog);
  /** The feedback dialog is open: drawn (and, the first time, loaded) while this holds. */
  protected readonly feedbackOpen = signal(false);
}
