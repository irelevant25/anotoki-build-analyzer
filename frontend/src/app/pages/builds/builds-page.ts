import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { GAME_LABELS, titleCase } from '../../core/format';
import { BuildCharacter, GameKey } from '../../core/models';
import { Avatar } from '../../shared/char-avatar';


@Component({
  selector: 'app-builds-page',
  imports: [FormsModule, RouterLink, MatButtonToggleModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressBarModule, Avatar],
  template: `
    <div class="page">
      <header class="page-header">
        <div>
          <h1>Recommended builds</h1>
          <p class="subtitle">Everything the daily sync collected, per character and source.</p>
        </div>
        <mat-button-toggle-group [value]="gameKey()" (change)="switchGame($event.value)" hideSingleSelectionIndicator aria-label="Game">
          <mat-button-toggle value="genshin">Genshin Impact</mat-button-toggle>
          <mat-button-toggle value="hsr">Honkai: Star Rail</mat-button-toggle>
        </mat-button-toggle-group>
      </header>

      <mat-form-field appearance="outline" subscriptSizing="dynamic" class="search">
        <mat-icon matPrefix>search</mat-icon>
        <mat-label>Search {{ gameLabels[gameKey()] }} characters</mat-label>
        <input matInput [ngModel]="search()" (ngModelChange)="search.set($event)" />
      </mat-form-field>

      @if (loading()) {
        <mat-progress-bar mode="indeterminate" />
      }
      @if (error(); as message) {
        <div class="panel">{{ message }}</div>
      }

      <div class="grid-list">
        @for (c of filtered(); track c.key) {
          <a class="panel card" [routerLink]="['/builds', gameKey(), c.key]">
            <app-avatar [src]="c.icon_url" [alt]="c.name" [rarity]="c.rarity" [element]="c.element" />
            <div class="text">
              <div class="name">{{ c.name }}</div>
              <div class="muted small">{{ titleCase(c.element) }}{{ c.weapon_type ? ' · ' + titleCase(c.weapon_type) : '' }}</div>
              <div class="muted small">{{ c.builds }} build{{ c.builds === 1 ? '' : 's' }} · {{ c.sources.join(', ') }}</div>
            </div>
          </a>
        } @empty {
          @if (!loading()) {
            <div class="empty-state">
              <mat-icon>menu_book</mat-icon>
              <p>No builds yet. Start a sync on the <a routerLink="/sources">Sources</a> page.</p>
            </div>
          }
        }
      </div>
    </div>
  `,
  styles: `
    .search { width: 320px; max-width: 100%; margin-bottom: 16px; }
    .grid-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 12px; }
    .card { display: flex; gap: 12px; align-items: center; color: inherit; text-decoration: none; }
    .card:hover { border-color: var(--mat-sys-primary); }
    .text { min-width: 0; }
    .name { font-weight: 500; }
  `,
})
export class BuildsPage {
  private readonly api = inject(Api);
  private readonly router = inject(Router);

  readonly game = input.required<string>();

  protected readonly characters = signal<BuildCharacter[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly search = signal('');

  protected readonly gameLabels = GAME_LABELS;
  protected readonly titleCase = titleCase;
  protected readonly gameKey = computed<GameKey>(() => (this.game() === 'hsr' ? 'hsr' : 'genshin'));

  protected readonly filtered = computed(() => {
    const query = this.search().trim().toLowerCase();
    return this.characters().filter((c) => !query || c.name.toLowerCase().includes(query));
  });

  constructor() {
    effect(() => {
      const game = this.gameKey();
      this.loading.set(true);
      this.api.buildCharacters(game).subscribe({
        next: (list) => {
          this.characters.set(list);
          this.error.set(null);
          this.loading.set(false);
        },
        error: (err) => {
          this.error.set(errorMessage(err));
          this.loading.set(false);
        },
      });
    });
  }

  protected switchGame(game: GameKey): void {
    void this.router.navigate(['/builds', game]);
  }
}
