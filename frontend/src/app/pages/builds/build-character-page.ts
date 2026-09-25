import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTabsModule } from '@angular/material/tabs';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { titleCase } from '../../core/format';
import { CharacterBuilds, GameKey } from '../../core/models';
import { BuildDetail } from '../../shared/build-detail';
import { Avatar } from '../../shared/char-avatar';

@Component({
  selector: 'app-build-character-page',
  imports: [RouterLink, MatButtonModule, MatIconModule, MatProgressBarModule, MatTabsModule, Avatar, BuildDetail],
  template: `
    <div class="page">
      <a mat-button [routerLink]="['/builds', gameKey()]"><mat-icon>arrow_back</mat-icon>All characters</a>
      @if (loading()) {
        <mat-progress-bar mode="indeterminate" />
      }
      @if (error(); as message) {
        <div class="panel">{{ message }}</div>
      }
      @if (data(); as d) {
        <header class="panel hero">
          <app-avatar [src]="d.character.icon_url" [alt]="d.character.name" [rarity]="d.character.rarity" [element]="d.character.element" size="lg" />
          <div>
            <h1>{{ d.character.name }}</h1>
            <div class="muted">{{ titleCase(d.character.element) }}{{ d.character.weapon_type ? ' · ' + titleCase(d.character.weapon_type) : '' }} · {{ d.builds.length }} builds</div>
          </div>
        </header>

        <mat-tab-group mat-stretch-tabs="false" animationDuration="0ms">
          @for (b of d.builds; track b.id) {
            <mat-tab [label]="b.source.name + ' · ' + b.variant">
              <div class="tab-body">
                @if (b.role && b.role !== b.variant) {
                  <span class="chip">{{ b.role }}</span>
                }
                <app-build-detail [build]="b" [game]="gameKey()" [element]="d.character.element" />
              </div>
            </mat-tab>
          }
        </mat-tab-group>
      }
    </div>
  `,
  styles: `
    .hero { display: flex; gap: 20px; align-items: center; margin: 8px 0 16px; }
    .hero h1 { margin: 0 0 4px; font: var(--mat-sys-headline-medium); }
    .tab-body { padding: 16px 4px; display: flex; flex-direction: column; gap: 12px; align-items: flex-start; }
    .tab-body app-build-detail { align-self: stretch; }
  `,
})
export class BuildCharacterPage {
  private readonly api = inject(Api);

  readonly game = input.required<string>();
  readonly key = input.required<string>();

  protected readonly data = signal<CharacterBuilds | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly titleCase = titleCase;
  protected readonly gameKey = computed<GameKey>(() => (this.game() === 'hsr' ? 'hsr' : 'genshin'));

  constructor() {
    effect(() => {
      const game = this.gameKey();
      const key = this.key();
      this.loading.set(true);
      this.api.characterBuilds(game, key).subscribe({
        next: (data) => {
          this.data.set(data);
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
}
