import { Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import type { ChangelogEntry } from '../core/changelog';
import { LocalDatePipe } from '../core/i18n/local-date';
import { TranslatePipe } from '../core/i18n/translate';
import { TranslationService } from '../core/i18n/translation';

/**
 * "What's new": the releases it is handed (all of them from the version button, or the ones this browser
 * has not seen yet), newest first, each with what was added, fixed and changed.
 *
 * Only the frame is the site's words - the title, "Version", the three headings, the date, Close. The
 * entries come from changelog.json as they were written, in English, and their lists say so (`lang="en"`)
 * while the page is in another language.
 */
@Component({
  selector: 'app-changelog-dialog',
  imports: [MatDialogModule, MatButtonModule, MatIconModule, TranslatePipe, LocalDatePipe],
  templateUrl: './changelog-dialog.html',
  styleUrl: './changelog-dialog.scss',
})
export class ChangelogDialog {
  protected readonly entries = inject<ChangelogEntry[]>(MAT_DIALOG_DATA);
  protected readonly i18n = inject(TranslationService);
}
