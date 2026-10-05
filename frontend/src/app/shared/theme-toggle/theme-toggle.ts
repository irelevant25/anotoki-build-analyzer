import { Component, ElementRef, inject, viewChildren } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { TranslationKey } from '../../core/i18n/en';
import { TranslatePipe } from '../../core/i18n/translate';
import { ThemeMode, ThemeService } from '../../core/theme';

/** Each choice with the key of its name - worded where it is drawn, so it follows the language. */
const OPTIONS: readonly { mode: ThemeMode; icon: string; label: TranslationKey }[] = [
  { mode: 'light', icon: 'light_mode', label: 'theme.light' },
  { mode: 'dark', icon: 'dark_mode', label: 'theme.dark' },
  { mode: 'system', icon: 'contrast', label: 'theme.system' },
];

/**
 * Light, dark or as the device is set - the family's compact three-way switch in the top bar (102 x 40):
 * the icons alone, each named for a screen reader and in a tooltip. A radio group underneath
 * (`role="radiogroup"`; the arrow keys move the choice, Tab reaches only the chosen one), because it is one
 * choice out of three.
 */
@Component({
  selector: 'app-theme-toggle',
  imports: [MatIconModule, TranslatePipe],
  templateUrl: './theme-toggle.html',
  styleUrl: './theme-toggle.scss',
})
export class ThemeToggle {
  protected readonly theme = inject(ThemeService);
  protected readonly options = OPTIONS;

  private readonly buttons = viewChildren<ElementRef<HTMLButtonElement>>('option');

  protected onKeydown(event: KeyboardEvent): void {
    const step =
      event.key === 'ArrowRight' || event.key === 'ArrowDown'
        ? 1
        : event.key === 'ArrowLeft' || event.key === 'ArrowUp'
          ? -1
          : 0;
    if (!step) {
      return;
    }
    event.preventDefault();
    const index = OPTIONS.findIndex((option) => option.mode === this.theme.mode());
    const next = (index + step + OPTIONS.length) % OPTIONS.length;
    this.theme.set(OPTIONS[next].mode);
    this.buttons()[next]?.nativeElement.focus();
  }
}
