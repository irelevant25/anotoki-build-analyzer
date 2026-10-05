import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { GAME_LABELS, titleCase } from '../../core/format';
import { ErrorTextPipe } from '../../core/i18n/error-text';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslatePluralPipe } from '../../core/i18n/translate-plural';
import { TranslationService } from '../../core/i18n/translation';
import { BuildCharacter, GameKey } from '../../core/models';
import { Avatar } from '../../shared/char-avatar';

@Component({
  selector: 'app-builds-page',
  imports: [
    FormsModule,
    RouterLink,
    MatButtonToggleModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    Avatar,
    ErrorTextPipe,
    TranslatePipe,
    TranslatePluralPipe,
  ],
  templateUrl: './builds-page.html',
  styleUrl: './builds-page.scss',
})
export class BuildsPage {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslationService);
  protected readonly auth = inject(AuthService);

  readonly game = input.required<string>();

  protected readonly characters = signal<BuildCharacter[]>([]);
  protected readonly loading = signal(true);
  /** The failed request itself: the template words it, in the language on the page. */
  protected readonly error = signal<unknown>(null);
  protected readonly search = signal('');

  protected readonly gameLabels = GAME_LABELS;
  protected readonly titleCase = titleCase;
  protected readonly gameKey = computed<GameKey>(() => (this.game() === 'hsr' ? 'hsr' : 'genshin'));

  protected readonly filtered = computed(() => {
    const query = this.search().trim().toLowerCase();
    return this.characters().filter((c) => !query || c.name.toLowerCase().includes(query));
  });

  /** "No builds yet. Start a sync on the [Sources] page.", cut where the link stands. */
  protected readonly empty = computed(() => this.i18n.around('builds.empty', 'link'));

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
          this.error.set(err);
          this.loading.set(false);
        },
      });
    });
  }

  protected switchGame(game: GameKey): void {
    void this.router.navigate(['/builds', game]);
  }
}
