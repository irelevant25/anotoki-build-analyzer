import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { TabItem, TabsComponent } from '@anotoki/lib/ui/tabs';
import { Api } from '../../core/api';
import { GAMES, titleCase } from '../../core/format';
import { GAME_TERMS } from '../../core/game-terms';
import { ErrorTextPipe } from '../../core/i18n/error-text';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslatePluralPipe } from '../../core/i18n/translate-plural';
import { TranslationService } from '../../core/i18n/translation';
import { variationLabel } from '../../core/i18n/variation-words';
import { MetaStore } from '../../core/meta-store';
import { CharacterBuilds, GameKey } from '../../core/models';
import { BuildDetail } from '../../shared/build-detail';
import { Avatar } from '../../shared/char-avatar';

/**
 * One character's recommended builds, for everybody (no account needed): a tab a build, named by its variation -
 * its role, its qualifier, the constellation it needs - in the character's order, the main build first (the anotoki
 * family's tabs), each the site's own build sheet (shared/build-detail).
 */
@Component({
  selector: 'app-build-character-page',
  imports: [RouterLink, AlertComponent, ButtonComponent, CardComponent, IconComponent, SpinnerComponent, TabsComponent, Avatar, BuildDetail, ErrorTextPipe, TranslatePipe, TranslatePluralPipe],
  templateUrl: './build-character-page.html',
  styleUrl: './build-character-page.scss',
})
export class BuildCharacterPage {
  private readonly api = inject(Api);
  private readonly i18n = inject(TranslationService);
  private readonly meta = inject(MetaStore);

  readonly game = input.required<string>();
  readonly key = input.required<string>();

  protected readonly data = signal<CharacterBuilds | null>(null);
  protected readonly loading = signal(true);
  /** The failed request itself: the template words it, in the language on the page. */
  protected readonly error = signal<unknown>(null);
  /** The build's tab chosen (its id); the first build's until one is. */
  protected readonly chosen = signal<string | null>(null);

  protected readonly titleCase = titleCase;
  protected readonly gameKey = computed<GameKey>(() => GAMES.find((offered) => offered === this.game()) ?? GAMES[0]);

  /** "C", as the metadata says it. */
  private readonly rankPrefix = computed(() => this.meta.game(this.gameKey())?.rank_prefix ?? GAME_TERMS[this.gameKey()].rankPrefix);

  /** A tab a build: its variation ("Main DPS · Melt · C2+"). */
  protected readonly buildTabs = computed<TabItem[]>(() =>
    (this.data()?.builds ?? []).map((build) => ({
      id: String(build.id),
      label: variationLabel(this.i18n, build, this.rankPrefix()),
    })),
  );
  protected readonly activeTab = computed(() => {
    const tabs = this.buildTabs();
    const chosen = this.chosen();
    return tabs.some((tab) => tab.id === chosen) ? chosen! : (tabs[0]?.id ?? '');
  });
  protected readonly build = computed(() => this.data()?.builds.find((build) => String(build.id) === this.activeTab()) ?? null);

  constructor() {
    effect(() => {
      const game = this.gameKey();
      const key = this.key();
      this.loading.set(true);
      this.api.characterBuilds(game, key).subscribe({
        next: (data) => {
          this.data.set(data);
          this.chosen.set(null);
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
