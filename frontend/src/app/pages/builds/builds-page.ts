import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { CardComponent } from '@anotoki/lib/ui/card';
import { TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { LinkCardComponent } from '@anotoki/lib/ui/link-card';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { GAME_LABELS, GAMES, titleCase } from '../../core/format';
import { ErrorTextPipe } from '../../core/i18n/error-text';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslatePluralPipe } from '../../core/i18n/translate-plural';
import { TranslationService } from '../../core/i18n/translation';
import { roleLabel } from '../../core/i18n/variation-words';
import { BuildCharacter, GameKey } from '../../core/models';
import { Avatar } from '../../shared/char-avatar';

/**
 * The recommended builds, for everybody (no account needed): a game's characters (the game in the address, one of
 * GAMES), each the kit's link card (anotoki-lib 0.6.0: one link as a whole) saying how many builds it has and what
 * they are for, a search - drawn with the anotoki family's kit.
 */
@Component({
  selector: 'app-builds-page',
  imports: [
    RouterLink,
    AlertComponent,
    CardComponent,
    IconComponent,
    LinkCardComponent,
    PageHeaderComponent,
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
  /** The game the address names, when the site offers it; else the first it offers. */
  protected readonly gameKey = computed<GameKey>(() => GAMES.find((offered) => offered === this.game()) ?? GAMES[0]);

  /** The characters the search finds, each with its builds' roles worded ("Main DPS, Support"). */
  protected readonly filtered = computed(() => {
    const query = this.search().trim().toLowerCase();
    return this.characters()
      .filter((c) => !query || c.name.toLowerCase().includes(query))
      .map((character) => ({ character, roles: character.roles.map((role) => roleLabel(this.i18n, role)).join(', ') }));
  });

  /** "No builds yet. An editor imports them on the [Builds] page.", cut where the link stands. */
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
}
