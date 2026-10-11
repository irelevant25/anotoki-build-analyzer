import { Component, inject, signal } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { Changelog } from '../../core/changelog';
import { TranslatePipe } from '../../core/i18n/translate';

/**
 * The foot of every page (the family's: "version · What's new"): the version this copy runs - the newest entry
 * of public/changelog.json - and the button that opens every release's notes (nothing of them while there is no
 * changelog: a broken upload); and Feedback, which opens the anotoki family's feedback form in a dialog
 * (@anotoki/lib/feedback/form, shared/feedback-dialog - loaded when it is first opened, so no first load carries
 * the form). In the admin panel it reads English, as the rest of the panel.
 */
@Component({
  selector: 'app-footer',
  imports: [TranslatePipe],
  templateUrl: './footer.html',
  styleUrl: './footer.scss',
})
export class Footer {
  protected readonly changelog = inject(Changelog);
  private readonly dialog = inject(MatDialog);
  /** The form is on its way (its chunk loading): one dialog for one press. */
  protected readonly opening = signal(false);

  protected async openFeedback(): Promise<void> {
    if (this.opening()) {
      return;
    }
    this.opening.set(true);
    try {
      const { FeedbackDialog } = await import('../../shared/feedback-dialog/feedback-dialog');
      this.dialog.open(FeedbackDialog, {
        width: '36rem',
        maxWidth: 'calc(100vw - 2rem)',
        autoFocus: 'first-tabbable',
        restoreFocus: true,
      });
    } finally {
      this.opening.set(false);
    }
  }
}
