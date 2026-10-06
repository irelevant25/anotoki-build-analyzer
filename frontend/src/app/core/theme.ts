import { DOCUMENT, Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { AuthService, preferencesProblem } from './auth';
import { TranslationService } from './i18n/translation';
import { AnotokiTheme } from './iam/anotoki-auth';
import { readStorage, writeStorage } from './storage';

/** Light, dark, or as the device is set. */
export type ThemeMode = 'system' | 'light' | 'dark';

/** Where the choice is kept on this device (the key the toolbar used before the shared bar). */
export const THEME_KEY = 'build-analyzer-theme';

/** The account's name for each mode: what this site calls "system" the IAM calls "auto". */
const ACCOUNT_THEME: Record<ThemeMode, AnotokiTheme> = {
  system: 'auto',
  light: 'light',
  dark: 'dark',
};
const SITE_MODE: Record<AnotokiTheme, ThemeMode> = { auto: 'system', light: 'light', dark: 'dark' };

/**
 * The theme the pages are drawn in. "system" follows the device (`color-scheme: light dark` on <html>); light
 * and dark are a class on <html> (`theme-light` / `theme-dark`, styles.scss), which Material's tokens follow.
 *
 * Signed in, the theme is the account's (the IAM's migration 015, the owner's decision of 2026-10-06): one
 * choice every anotoki site follows, on every page here, the admin panel included. It is shown as soon as the
 * account is known - the sign-in settles before the first page is drawn - and again whenever it changes
 * (another anotoki site or another tab saved one, a refreshed token brought it), and the toggle saves to it.
 * Signed out, and while the IAM keeps no preferences (`AuthService.preferences()` null), it is this device's,
 * as it always was. Either way the theme on the page is kept in localStorage, so the next page load starts in
 * it before anything else is known; where storage refuses, it lasts for the visit.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly document = inject(DOCUMENT);
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(TranslationService);
  private readonly snackBar = inject(MatSnackBar);
  private readonly _mode = signal<ThemeMode>(stored(readStorage(THEME_KEY)));
  readonly mode = this._mode.asReadonly();

  /** The account's theme, in this site's words, while somebody is signed in and the IAM keeps it; null otherwise. */
  private readonly accountMode = computed<ThemeMode | null>(() => {
    const theme = this.auth.preferences()?.theme;
    return theme ? SITE_MODE[theme] : null;
  });

  /** A theme save is on its way. */
  private saving = false;
  /** The latest theme chosen while it is: saved once it settles. */
  private next: ThemeMode | null = null;

  constructor() {
    // The account's theme from the first frame, not a moment after the device's.
    const account = this.accountMode();
    if (account) {
      this._mode.set(account);
      writeStorage(THEME_KEY, account);
    }
    this.apply(this._mode());

    // And whenever the account's theme changes. Not while a choice made here is on its way to it: the account
    // still says the earlier one then, and the page would flick back.
    effect(() => {
      const mode = this.accountMode();
      if (mode && !this.saving) {
        untracked(() => this.show(mode));
      }
    });
  }

  /**
   * The person's choice: shown at once and, while somebody is signed in and the IAM keeps the preferences,
   * saved to the account, so every anotoki site follows it.
   *
   * One save at a time, and the last choice is the one saved: two saves in flight together could be handled
   * in either order and leave the account on a theme the page no longer shows. A choice made while a save is
   * on its way waits for it to settle - only the latest such choice, and only if the account does not have it
   * by then. A save that is refused puts the account's theme back on the page, and a snack bar says why.
   */
  set(mode: ThemeMode): void {
    this.show(mode);
    if (this.saving) {
      this.next = mode;
      return;
    }
    this.save(mode);
  }

  private save(mode: ThemeMode): void {
    const account = this.accountMode();
    if (account === null || account === mode) {
      return;
    }
    this.saving = true;
    /** The save is over: the next choice, if one waits, is saved now - and then this one's refusal is not news. */
    const settle = (): boolean => {
      const next = this.next;
      this.saving = false;
      this.next = null;
      if (next !== null) {
        this.save(next);
      }
      return next !== null;
    };
    this.auth.savePreferences({ theme: ACCOUNT_THEME[mode] }).then(
      () => {
        settle();
      },
      (error: unknown) => {
        if (settle()) {
          return;
        }
        const current = this.accountMode();
        if (current) {
          this.show(current);
        }
        const problem = preferencesProblem(error);
        // A finished sentence when raised, in the language on the page; a snack bar is not worded again.
        this.snackBar.open(this.i18n.t(problem.key, problem.params), undefined, { duration: 6000 });
      },
    );
  }

  private show(mode: ThemeMode): void {
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
