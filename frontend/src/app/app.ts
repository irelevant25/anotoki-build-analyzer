import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from './core/auth';
import { Changelog } from './core/changelog';
import { TranslationKey } from './core/i18n/en';
import { TranslatePipe } from './core/i18n/translate';
import { TranslationService } from './core/i18n/translation';
import { LocalizationDrafts } from './core/localization-drafts';
import { MetaStore } from './core/meta-store';
import { SyncDriver } from './core/sync-driver';
import { NotSetUpPage } from './pages/not-set-up/not-set-up-page';

type ThemeMode = 'system' | 'light' | 'dark';

/** The bar's links. A label is a key, worded where it is drawn - so it follows the language on the page. */
const LINKS: readonly { path: string; label: TranslationKey; icon: string; exact: boolean; admin: boolean }[] = [
  { path: '/', label: 'nav.dashboard', icon: 'dashboard', exact: true, admin: false },
  { path: '/import', label: 'nav.import', icon: 'upload', exact: false, admin: false },
  { path: '/builds', label: 'nav.builds', icon: 'menu_book', exact: false, admin: false },
  { path: '/sources', label: 'nav.sources', icon: 'sync', exact: false, admin: true },
];

@Component({
  selector: 'app-root',
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    MatToolbarModule,
    MatButtonModule,
    MatDividerModule,
    MatIconModule,
    MatMenuModule,
    MatTooltipModule,
    NotSetUpPage,
    TranslatePipe,
  ],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  protected readonly auth = inject(AuthService);
  protected readonly sync = inject(SyncDriver);
  protected readonly meta = inject(MetaStore);
  protected readonly changelog = inject(Changelog);
  protected readonly i18n = inject(TranslationService);
  private readonly snackBar = inject(MatSnackBar);
  /**
   * What the owner typed on the Translations and Languages pages and did not save: made here, after the
   * sign-in has settled, so drafts a page load brought back are asked about before the tab closes even
   * before either page is opened again.
   */
  private readonly drafts = inject(LocalizationDrafts);

  /**
   * The bar's menu offers the languages when there is a choice to make - and not on the English pages
   * (Sources, Translations, Languages), where choosing one would change nothing the page shows.
   */
  protected readonly offersLanguages = computed(() => this.i18n.languages().length > 1 && !this.i18n.englishOnly());

  /** The owner's two pages, Translations and Languages: an admin's (with sign-in off, everyone's). The server decides for itself. */
  protected readonly ownsWords = computed(() => !this.meta.notSetUp() && this.auth.isAdmin());

  /** Counts the choices made in the menu, so that only the last one may say it failed. */
  private languageChoices = 0;

  /** Only the pages this person may open (all of them with sign-in off; none before the server is set up). */
  protected readonly links = computed(() => {
    if (this.meta.notSetUp()) {
      return [];
    }
    if (this.auth.mode() !== 'iam') {
      return LINKS;
    }
    return LINKS.filter((link) => (link.admin ? this.auth.isAdmin() : this.auth.isMember()));
  });

  protected readonly theme = signal<ThemeMode>(this.storedTheme());

  constructor() {
    this.applyTheme(this.theme());

    // What is new since this browser last looked, once someone can use the app (with sign-in off, everyone).
    effect(() => {
      if (!this.meta.notSetUp() && this.auth.isMember() && this.changelog.current()) {
        untracked(() => this.changelog.showIfNew());
      }
    });
  }

  protected setTheme(mode: ThemeMode): void {
    this.theme.set(mode);
    this.applyTheme(mode);
    try {
      localStorage.setItem('build-analyzer-theme', mode);
    } catch {
      // Storage can be unavailable (private mode); the choice then lasts for this visit only.
    }
  }

  protected themeIcon(): string {
    return { system: 'contrast', light: 'light_mode', dark: 'dark_mode' }[this.theme()];
  }

  /**
   * A language chosen in the bar's menu: the page switches once that language's strings are here, and the
   * choice is remembered on this device. When they cannot be had the page stays as it is and says so -
   * unless another choice was made meanwhile: the last one wins, and the one it overtook did not fail.
   */
  protected async setLanguage(code: string): Promise<void> {
    const choice = ++this.languageChoices;
    const switched = await this.i18n.setLanguage(code);
    if (!switched && choice === this.languageChoices) {
      // A finished sentence when raised; a snack bar is not worded again.
      this.snackBar.open(this.i18n.t('language.notLoaded'), undefined, { duration: 6000 });
    }
  }

  /** To the IAM's sign-in page - in the person's language, when it is known (a bundle is in memory). */
  protected signIn(): void {
    void this.auth.login(undefined, { uiLocales: this.i18n.signInLanguage() });
  }

  private applyTheme(mode: ThemeMode): void {
    const root = document.documentElement;
    root.classList.remove('theme-light', 'theme-dark');
    if (mode !== 'system') {
      root.classList.add(`theme-${mode}`);
    }
  }

  private storedTheme(): ThemeMode {
    try {
      const value = localStorage.getItem('build-analyzer-theme');
      return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
      return 'system';
    }
  }
}
