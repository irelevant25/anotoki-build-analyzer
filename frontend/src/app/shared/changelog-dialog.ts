import { Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import type { ChangelogEntry } from '../core/changelog';

/**
 * "What's new": the releases it is handed (all of them from the version button, or the ones this browser
 * has not seen yet), newest first, each with what was added, fixed and changed.
 */
@Component({
  selector: 'app-changelog-dialog',
  imports: [MatDialogModule, MatButtonModule, MatIconModule],
  templateUrl: './changelog-dialog.html',
  styleUrl: './changelog-dialog.scss',
})
export class ChangelogDialog {
  protected readonly entries = inject<ChangelogEntry[]>(MAT_DIALOG_DATA);

  /** "29 September 2026" from "2026-09-29", whatever the time zone. */
  protected date(value: string): string {
    const [year, month, day] = value.split('-').map(Number);
    if (!year || !month || !day) {
      return value;
    }
    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
  }
}
