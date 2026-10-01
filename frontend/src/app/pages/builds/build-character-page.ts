import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTabsModule } from '@angular/material/tabs';
import { RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { titleCase } from '../../core/format';
import { ErrorTextPipe } from '../../core/i18n/error-text';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslatePluralPipe } from '../../core/i18n/translate-plural';
import { CharacterBuilds, GameKey } from '../../core/models';
import { BuildDetail } from '../../shared/build-detail';
import { Avatar } from '../../shared/char-avatar';

@Component({
  selector: 'app-build-character-page',
  imports: [RouterLink, MatButtonModule, MatIconModule, MatProgressBarModule, MatTabsModule, Avatar, BuildDetail, ErrorTextPipe, TranslatePipe, TranslatePluralPipe],
  templateUrl: './build-character-page.html',
  styleUrl: './build-character-page.scss',
})
export class BuildCharacterPage {
  private readonly api = inject(Api);

  readonly game = input.required<string>();
  readonly key = input.required<string>();

  protected readonly data = signal<CharacterBuilds | null>(null);
  protected readonly loading = signal(true);
  /** The failed request itself: the template words it, in the language on the page. */
  protected readonly error = signal<unknown>(null);

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
          this.error.set(err);
          this.loading.set(false);
        },
      });
    });
  }
}
