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
  templateUrl: './builds-page.html',
  styleUrl: './builds-page.scss',
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
