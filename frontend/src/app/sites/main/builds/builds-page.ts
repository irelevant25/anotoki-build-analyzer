import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { SelectComponent, SelectOption, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { LabelledGroupComponent } from '@anotoki/lib/ui/labelled-group';
import { LinkCardComponent } from '@anotoki/lib/ui/link-card';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { matchesSearch } from '@anotoki/lib/ui/search';
import { SegmentedComponent, SegmentedOption } from '@anotoki/lib/ui/segmented';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { Api } from '../../../api/api';
import { AuthService } from '../../../shared/auth';
import { GAME_LABELS, GAMES, titleCase } from '../../../shared/format';
import { GAME_TERMS } from '../common/game-terms';
import { ErrorTextPipe } from '../../../shared/i18n/error-text';
import { TranslatePipe } from '../../../shared/i18n/translate';
import { TranslatePluralPipe } from '../../../shared/i18n/translate-plural';
import { TranslationService } from '../../../shared/i18n/translation';
import { roleLabel } from '../../../shared/i18n/variation-words';
import { MetaStore } from '../../../shared/meta-store';
import { BuildCharacter, GameKey } from '../../../api/models';
import { bindQueryFilters } from '../common/query-filters';
import { Avatar } from '../../../shared/char-avatar';

/** A segmented filter's first choice, "All": the filter's absence, never in the address. */
const ALL = 'all';

/** The values the characters have, once each, in the game's order where it gives one - then by name. */
function present(values: readonly (string | null)[], order: readonly string[]): string[] {
  const rank = (value: string) => (order.includes(value) ? order.indexOf(value) : order.length);
  return [...new Set(values.filter((value): value is string => !!value))].sort((a, b) => rank(a) - rank(b) || a.localeCompare(b));
}

/** "6.1" before "6.0" before "5.8" before "1.0": by the game's numbers, the newest first. */
function newestFirst(a: string, b: string): number {
  const [aMajor = 0, aMinor = 0] = a.split('.').map(Number);
  const [bMajor = 0, bMinor = 0] = b.split('.').map(Number);
  return bMajor - aMajor || bMinor - aMinor;
}

/**
 * The recommended builds, for everybody (no account needed): a game's characters (the game in the address, one of
 * GAMES), each the kit's link card (anotoki-lib 0.6.0: one link as a whole) saying how many builds it has and what
 * they are for - drawn with the anotoki family's kit.
 *
 * Above them the filters, as genshin.anotoki.eu's lists have them: the name; the element, the weapon type and the
 * rarity, each the kit's segmented control (All, then what the characters here have, in the game's order - an
 * element's colour the mark before its name); the region and the version the character came with, each the kit's
 * select (any, then the regions by name, the versions newest first with the version's name: "6.1 (Luna II)"); Reset;
 * and how many of them show, said as it changes. The filters live in the address (sites/main/common/query-filters.ts): a link
 * opens the list filtered, and coming back from a character finds it as it was left. Region, version and weapon type
 * names are the game's, never translated.
 */
@Component({
  selector: 'app-builds-page',
  imports: [
    RouterLink,
    AlertComponent,
    ButtonComponent,
    CardComponent,
    EmptyStateComponent,
    IconComponent,
    LabelledGroupComponent,
    LinkCardComponent,
    PageHeaderComponent,
    SegmentedComponent,
    SelectComponent,
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
  private readonly meta = inject(MetaStore);
  protected readonly auth = inject(AuthService);

  readonly game = input.required<string>();

  protected readonly characters = signal<BuildCharacter[]>([]);
  protected readonly loading = signal(true);
  /** The failed request itself: the template words it, in the language on the page. */
  protected readonly error = signal<unknown>(null);

  // The filters: what the address says (bindQueryFilters, in the constructor).
  protected readonly search = signal('');
  protected readonly element = signal(ALL);
  protected readonly weapon = signal(ALL);
  protected readonly rarity = signal(ALL);
  protected readonly region = signal('');
  protected readonly version = signal('');

  protected readonly gameLabels = GAME_LABELS;
  protected readonly titleCase = titleCase;
  /** The game the address names, when the site offers it; else the first it offers. */
  protected readonly gameKey = computed<GameKey>(() => GAMES.find((offered) => offered === this.game()) ?? GAMES[0]);
  private readonly gameMeta = computed(() => this.meta.game(this.gameKey()));
  /** The game's word for a weapon - a game term, never translated: "Weapon". */
  protected readonly weaponLabel = computed(() => this.gameMeta()?.weapon_label || GAME_TERMS[this.gameKey()].weapon);

  protected readonly elementOptions = computed<SegmentedOption[]>(() => [
    { value: ALL, label: this.i18n.t('builds.filter.all') },
    ...present(
      this.characters().map((c) => c.element),
      this.gameMeta()?.elements ?? [],
    ).map((element) => ({ value: element, label: titleCase(element), mark: `var(--el-mark-${element}, var(--anotoki-muted))` })),
  ]);

  protected readonly weaponOptions = computed<SegmentedOption[]>(() => [
    { value: ALL, label: this.i18n.t('builds.filter.all') },
    ...present(
      this.characters().map((c) => c.weapon_type),
      this.gameMeta()?.weapon_types ?? [],
    ).map((weapon) => ({ value: weapon, label: titleCase(weapon) })),
  ]);

  /** The rarities, the highest first: "5 stars", in the form the number asks for. */
  protected readonly rarityOptions = computed<SegmentedOption[]>(() => [
    { value: ALL, label: this.i18n.t('builds.filter.all') },
    ...[...new Set(this.characters().map((c) => c.rarity))]
      .filter((rarity): rarity is number => rarity !== null)
      .sort((a, b) => b - a)
      .map((rarity) => ({ value: String(rarity), label: this.i18n.plural('builds.filter.stars', rarity) })),
  ]);

  protected readonly regionOptions = computed<SelectOption[]>(() =>
    present(
      this.characters().map((c) => c.region),
      [],
    ).map((region) => ({ value: region, label: region })),
  );

  /** The versions, the newest first, each with its name where the game gave it one: "6.1 (Luna II)". */
  protected readonly versionOptions = computed<SelectOption[]>(() => {
    const names = new Map<string, string | null>();
    for (const c of this.characters()) {
      if (c.version && !names.get(c.version)) {
        names.set(c.version, c.version_name);
      }
    }
    return [...names.keys()].sort(newestFirst).map((version) => ({ value: version, label: names.get(version) ? `${version} (${names.get(version)})` : version }));
  });

  /**
   * The characters the filters find, each with its builds' roles worded ("Main DPS, Support"). The name is found the
   * family's way (@anotoki/lib/ui/search): each word typed anywhere in it, accents and case aside.
   */
  protected readonly filtered = computed(() => {
    const query = this.search();
    const [element, weapon, rarity, region, version] = [this.element(), this.weapon(), this.rarity(), this.region(), this.version()];
    return this.characters()
      .filter(
        (c) =>
          matchesSearch(c.name, query) &&
          (element === ALL || c.element === element) &&
          (weapon === ALL || c.weapon_type === weapon) &&
          (rarity === ALL || String(c.rarity) === rarity) &&
          (!region || c.region === region) &&
          (!version || c.version === version),
      )
      .map((character) => ({ character, roles: character.roles.map((role) => roleLabel(this.i18n, role)).join(', ') }));
  });

  /** Whether a filter is on: Reset has something to do. */
  protected readonly filtering = computed(() => !!this.search().trim() || this.element() !== ALL || this.weapon() !== ALL || this.rarity() !== ALL || !!this.region() || !!this.version());

  /** "No builds yet. An editor imports them on the [Builds] page.", cut where the link stands. */
  protected readonly empty = computed(() => this.i18n.around('builds.empty', 'link'));

  constructor() {
    bindQueryFilters({
      name: [this.search, ''],
      element: [this.element, ALL],
      weapon: [this.weapon, ALL],
      rarity: [this.rarity, ALL],
      region: [this.region, ''],
      version: [this.version, ''],
    });

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

  protected reset(): void {
    this.search.set('');
    this.element.set(ALL);
    this.weapon.set(ALL);
    this.rarity.set(ALL);
    this.region.set('');
    this.version.set('');
  }
}
