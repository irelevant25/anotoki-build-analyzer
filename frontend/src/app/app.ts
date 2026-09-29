import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from './core/auth';
import { Changelog } from './core/changelog';
import { MetaStore } from './core/meta-store';
import { SyncDriver } from './core/sync-driver';
import { NotSetUpPage } from './pages/not-set-up/not-set-up-page';

type ThemeMode = 'system' | 'light' | 'dark';

const LINKS = [
  { path: '/', label: 'Dashboard', icon: 'dashboard', exact: true, admin: false },
  { path: '/import', label: 'Import', icon: 'upload', exact: false, admin: false },
  { path: '/builds', label: 'Builds', icon: 'menu_book', exact: false, admin: false },
  { path: '/sources', label: 'Sources', icon: 'sync', exact: false, admin: true },
];

@Component({
  selector: 'app-root',
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    MatToolbarModule,
    MatButtonModule,
    MatIconModule,
    MatMenuModule,
    MatTooltipModule,
    NotSetUpPage,
  ],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  protected readonly auth = inject(AuthService);
  protected readonly sync = inject(SyncDriver);
  protected readonly meta = inject(MetaStore);
  protected readonly changelog = inject(Changelog);

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
