import { Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialog, MatDialogModule } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';

/**
 * A question to answer yes or no, worded by whoever asks it: the dialog has no words of its own. Every
 * text is a finished string - worded when the question is raised, in the language the page is in then
 * (a member page words them with TranslationService.t(); the English pages write English).
 */
export interface ConfirmQuestion {
  /** The question itself, as the dialog's title. */
  title: string;
  /** What else there is to know before answering: what goes, what is kept. */
  message?: string;
  /** The button that says yes: "Delete", "Leave". */
  confirmLabel: string;
  /** The button that says no: "Cancel", "Stay here". */
  cancelLabel: string;
  /** For what ends, removes or cannot be undone: the yes button is drawn as a warning. */
  danger?: boolean;
}

/**
 * "Are you sure?" as a dialog of the app's own - in place of the browser's confirm(), whose buttons are
 * in the browser's language and cannot be worded. Open it with askConfirm().
 *
 * No is the answer that is always at hand: the button that says it stands first and has the focus, and
 * Escape or a click outside answers it too.
 */
@Component({
  selector: 'app-confirm-dialog',
  imports: [MatDialogModule, MatButtonModule],
  templateUrl: './confirm-dialog.html',
  styleUrl: './confirm-dialog.scss',
})
export class ConfirmDialog {
  protected readonly question = inject<ConfirmQuestion>(MAT_DIALOG_DATA);
}

/**
 * Asks, and answers true only for the button that says yes - false for the other one, for Escape and for
 * a click outside. Never rejects.
 *
 * `if (await askConfirm(this.dialog, { title, message, confirmLabel, cancelLabel, danger: true })) { … }`
 */
export function askConfirm(dialog: MatDialog, question: ConfirmQuestion): Promise<boolean> {
  const opened = dialog.open<ConfirmDialog, ConfirmQuestion, boolean>(ConfirmDialog, {
    data: question,
    role: 'alertdialog',
    width: '440px',
    maxWidth: 'calc(100vw - 32px)',
  });
  return firstValueFrom(opened.afterClosed(), { defaultValue: false }).then((answer) => answer === true);
}
