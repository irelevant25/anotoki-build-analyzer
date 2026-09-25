import { Component, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatToolbarModule } from '@angular/material/toolbar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

type ThemeMode = 'system' | 'light' | 'dark';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, MatToolbarModule, MatButtonModule, MatIconModule, MatMenuModule, MatTooltipModule],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  protected readonly links = [
    { path: '/', label: 'Dashboard', icon: 'dashboard', exact: true },
    { path: '/import', label: 'Import', icon: 'upload', exact: false },
    { path: '/builds', label: 'Builds', icon: 'menu_book', exact: false },
    { path: '/sources', label: 'Sources', icon: 'sync', exact: false },
  ];

  protected readonly theme = signal<ThemeMode>(this.storedTheme());

  constructor() {
    this.applyTheme(this.theme());
  }

  protected setTheme(mode: ThemeMode): void {
    this.theme.set(mode);
    this.applyTheme(mode);
    try {
      localStorage.setItem('hoyo-theme', mode);
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
      const value = localStorage.getItem('hoyo-theme');
      return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
      return 'system';
    }
  }
}
