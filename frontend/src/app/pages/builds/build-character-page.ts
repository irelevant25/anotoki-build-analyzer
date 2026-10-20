import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { TabItem, TabsComponent } from '@anotoki/lib/ui/tabs';
import { Api } from '../../core/api';
import { GAMES, titleCase } from '../../core/format';
import { ErrorTextPipe } from '../../core/i18n/error-text';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslatePluralPipe } from '../../core/i18n/translate-plural';
import { CharacterBuilds, GameKey } from '../../core/models';
import { BuildDetail } from '../../shared/build-detail';
import { Avatar } from '../../shared/char-avatar';

/**
 * One character's recommended builds, for everybody (no account needed): a tab a build (the anotoki family's
 * tabs), each the site's own build sheet (shared/build-detail).
 */
@Component({
  selector: 'app-build-character-page',
  imports: [
    RouterLink,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    IconComponent,
    SpinnerComponent,
    TabsComponent,
    Avatar,
    BuildDetail,
    ErrorTextPipe,
    TranslatePipe,
    TranslatePluralPipe,
  ],
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
  /** The build's tab chosen (its id); the first build's until one is. */
  protected readonly chosen = signal<string | null>(null);

  protected readonly titleCase = titleCase;
  protected readonly gameKey = computed<GameKey>(() => GAMES.find((offered) => offered === this.game()) ?? GAMES[0]);

  /** A tab a build: its source and variant. */
  protected readonly buildTabs = computed<TabItem[]>(() =>
    (this.data()?.builds ?? []).map((build) => ({
      id: String(build.id),
      label: `${build.source.name} · ${build.variant}`,
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
