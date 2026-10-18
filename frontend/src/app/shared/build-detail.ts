import { Component, computed, inject, input } from '@angular/core';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { DisclosureComponent } from '@anotoki/lib/ui/disclosure';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { GAME_TERMS, SET_KIND_TERMS, pieces } from '../core/game-terms';
import { LocalDatePipe } from '../core/i18n/local-date';
import { TranslatePipe } from '../core/i18n/translate';
import { TranslatePluralPipe } from '../core/i18n/translate-plural';
import { TranslationService } from '../core/i18n/translation';
import { MetaStore } from '../core/meta-store';
import { Build, Evaluation, GameKey, SetOption, StatPriority, TalentPriority } from '../core/models';
import { BUILD_TERMS } from './build-terms';
import { Avatar } from './char-avatar';
import { StatValuePipe } from './stat-pipes';

interface SetGroup {
  kind: SetOption['kind'];
  title: string;
  options: SetOption[];
  matchedRank: number | null;
}

/**
 * Renders a recommended build. With an evaluation it also marks what the player already has.
 *
 * The site's own words are keys; the game's words - the headings of weapons and sets, "4pc", stat and
 * talent names - are terms and stay as they are. What a source wrote (a weapon's or a set's note, a
 * target's text, the build's notes) is stored English: shown as it is, marked `lang="en"` while the page
 * is in another language.
 *
 * The build sheet is the site's own; its parts are the kit's: badges for the stats and what the player has, its
 * icons, tooltips for the notes cut to a line, and the kit's disclosure (`@anotoki/lib/ui/disclosure`) for the
 * build's notes.
 */
@Component({
  selector: 'app-build-detail',
  imports: [
    Avatar,
    StatValuePipe,
    LocalDatePipe,
    TranslatePipe,
    TranslatePluralPipe,
    BadgeComponent,
    DisclosureComponent,
    IconComponent,
    TooltipDirective,
  ],
  templateUrl: './build-detail.html',
  styleUrl: './build-detail.scss',
})
export class BuildDetail {
  private readonly meta = inject(MetaStore);
  private readonly i18n = inject(TranslationService);

  readonly build = input.required<Build>();
  readonly game = input.required<GameKey>();
  readonly evaluation = input<Evaluation | null>(null);
  /** Character element, used to show "Elemental DMG" as e.g. "Pyro DMG". */
  readonly element = input<string | null>(null);

  protected readonly gameMeta = computed(() => this.meta.game(this.game()));
  /** The game's own words for this game: "Weapons" / "Light Cones", "Talents" / "Traces". */
  protected readonly terms = computed(() => GAME_TERMS[this.game()]);
  protected readonly buildTerms = BUILD_TERMS;
  /** `en` on what a source wrote while the page is in another language; nothing while it is English. */
  protected readonly storedLang = this.i18n.storedEnglishLang;

  protected readonly equippedWeaponKey = computed(() => this.evaluation()?.weapon?.equipped?.key ?? null);

  protected readonly ownedWeaponKeys = computed(
    () => new Set((this.evaluation()?.weapon?.owned_better ?? []).map((weapon) => weapon.key)),
  );

  protected readonly setGroups = computed<SetGroup[]>(() => {
    const groups = this.evaluation()?.sets?.groups ?? [];
    return (['artifact', 'relic', 'planar'] as const)
      .map((kind) => ({
        kind,
        title: SET_KIND_TERMS[kind],
        options: this.build().sets.filter((option) => option.kind === kind),
        matchedRank: groups.find((group) => group.kind === kind)?.matched_rank ?? null,
      }))
      .filter((group) => group.options.length > 0);
  });

  protected readonly mainStatSlots = computed(() =>
    (this.gameMeta()?.slots ?? [])
      .filter((slot) => slot.variable && (this.build().main_stats?.[slot.key]?.length ?? 0) > 0)
      .map((slot) => ({
        key: slot.key,
        label: slot.label,
        groups: this.priorityGroups(this.build().main_stats[slot.key]),
        check: this.evaluation()?.main_stats?.slots?.[slot.key] ?? null,
      })),
  );

  protected readonly substatGroups = computed(() => this.priorityGroups(this.build().substats ?? []));

  protected readonly talentGroups = computed(() => {
    const talents = this.build().talent_priority ?? [];
    const byPriority = new Map<number, TalentPriority[]>();
    for (const talent of talents) {
      byPriority.set(talent.priority, [...(byPriority.get(talent.priority) ?? []), talent]);
    }
    return [...byPriority.entries()]
      .sort(([a], [b]) => a - b)
      .map(([, list]) => list.map((t) => this.meta.talentLabel(this.game(), t.talent)).join(' = '));
  });

  protected readonly targets = computed(() => {
    const evaluated = this.evaluation()?.targets;
    return evaluated && evaluated.length > 0 ? evaluated : (this.build().stat_targets ?? []);
  });

  /** "4pc A + 2pc B": the game's notation, the same in every language. */
  protected pieceText(option: SetOption): string {
    return option.pieces.map((piece) => `${pieces(piece.count)} ${piece.label}`).join(' + ');
  }

  protected stat(stat: string): string {
    const resolved = stat === 'elemental_dmg_' && this.element() ? `${this.element()}_dmg_` : stat;
    return this.meta.statLabel(this.game(), resolved);
  }

  protected isCurrentMain(slot: string, stat: string): boolean {
    const current = this.evaluation()?.main_stats?.slots?.[slot]?.current;
    const resolved = stat === 'elemental_dmg_' && this.element() ? `${this.element()}_dmg_` : stat;
    return current === resolved;
  }

  private priorityGroups(list: StatPriority[]): StatPriority[][] {
    const byPriority = new Map<number, StatPriority[]>();
    for (const entry of list) {
      byPriority.set(entry.priority, [...(byPriority.get(entry.priority) ?? []), entry]);
    }
    return [...byPriority.entries()].sort(([a], [b]) => a - b).map(([, entries]) => entries);
  }
}
