import { DOCUMENT, Injectable, inject, signal } from '@angular/core';
import { readStorage, writeStorage } from './storage';

/** Light, dark, or as the device is set. */
export type ThemeMode = 'system' | 'light' | 'dark';

/** Where the choice is kept on this device (the key the toolbar used before the shared bar). */
export const THEME_KEY = 'build-analyzer-theme';

/**
 * The theme the pages are drawn in. "system" follows the device (`color-scheme: light dark` on <html>); light
 * and dark are a class on <html> (`theme-light` / `theme-dark`, styles.scss), which Material's tokens follow.
 * The choice is remembered on this device; where storage refuses, it lasts for the visit.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly document = inject(DOCUMENT);
  private readonly _mode = signal<ThemeMode>(stored(readStorage(THEME_KEY)));
  readonly mode = this._mode.asReadonly();

  constructor() {
    this.apply(this._mode());
  }

  set(mode: ThemeMode): void {
    this._mode.set(mode);
    this.apply(mode);
    writeStorage(THEME_KEY, mode);
  }

  private apply(mode: ThemeMode): void {
    const root = this.document.documentElement;
    root.classList.remove('theme-light', 'theme-dark');
    if (mode !== 'system') {
      root.classList.add(`theme-${mode}`);
    }
  }
}

function stored(value: string | null): ThemeMode {
  return value === 'light' || value === 'dark' ? value : 'system';
}
