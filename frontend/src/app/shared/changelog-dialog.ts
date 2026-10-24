import { Component, effect, inject } from '@angular/core';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { ANOTOKI_DIALOG_DATA, AnotokiDialogRef, DialogFooterDirective } from '@anotoki/lib/ui/dialog';
import { IconComponent } from '@anotoki/lib/ui/icon';
import type { ChangelogEntry } from './changelog';
import { LocalDatePipe } from './i18n/local-date';
import { TranslatePipe } from './i18n/translate';
import { TranslationService } from './i18n/translation';

/**
 * "What's new": the releases it is handed (all of them from the footer's button, or the ones this browser has not
 * seen yet), newest first, each with what was added, fixed and changed - in the kit's dialog (Changelog.open():
 * the anotoki family's AnotokiDialog, with its focus, Escape and backdrop).
 *
 * Only the frame is the site's words - the heading (set here, so it follows the language), "Version", the three
 * headings, the date, Close. The entries come from changelog.json as they were written, in English, and their
 * lists say so (`lang="en"`) while the page is in another language.
 */
@Component({
  selector: 'app-changelog-dialog',
  imports: [BadgeComponent, ButtonComponent, DialogFooterDirective, IconComponent, TranslatePipe, LocalDatePipe],
  templateUrl: './changelog-dialog.html',
  styleUrl: './changelog-dialog.scss',
})
export class ChangelogDialog {
  protected readonly entries = inject(ANOTOKI_DIALOG_DATA) as ChangelogEntry[];
  protected readonly ref = inject(AnotokiDialogRef);
  protected readonly i18n = inject(TranslationService);

  constructor() {
    effect(() => this.ref.heading.set(this.i18n.t('whatsNew.title')));
  }
}
