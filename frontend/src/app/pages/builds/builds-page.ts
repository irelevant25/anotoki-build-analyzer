import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { CardComponent } from '@anotoki/lib/ui/card';
import { TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { SegmentedComponent, SegmentedOption } from '@anotoki/lib/ui/segmented';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { GAME_LABELS, titleCase } from '../../core/format';
import { ErrorTextPipe } from '../../core/i18n/error-text';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslatePluralPipe } from '../../core/i18n/translate-plural';
import { TranslationService } from '../../core/i18n/translation';
import { BuildCharacter, GameKey } from '../../core/models';
import { Avatar } from '../../shared/char-avatar';

/**
 * The recommended builds, for everybody (no account needed): a game at a time (the kit's segmented control), its
 * characters as tiles of the site's own, a search - drawn with the anotoki family's kit.
 */
@Component({
  selector: 'app-builds-page',
  imports: [
    RouterLink,
    AlertComponent,
    CardComponent,
    IconComponent,
    PageHeaderComponent,
    SegmentedComponent,
    SpinnerComponent,
    TextFieldComponent,
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
  /** The two games, by their own names. */
  protected readonly gameOptions: SegmentedOption[] = [
    { value: 'genshin', label: GAME_LABELS.genshin },
    { value: 'hsr', label: GAME_LABELS.hsr },
  ];
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

  protected switchGame(game: string): void {
    if (game === 'genshin' || game === 'hsr') {
      void this.router.navigate(['/builds', game]);
    }
  }
}
