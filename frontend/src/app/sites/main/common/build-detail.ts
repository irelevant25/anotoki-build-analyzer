import { Component, computed, inject, input } from '@angular/core';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { DisclosureComponent } from '@anotoki/lib/ui/disclosure';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { GAME_TERMS, SET_KIND_TERMS, pieces } from './game-terms';
import { LocalDatePipe } from '../../../shared/i18n/local-date';
import { siteNoteText } from '../../../shared/i18n/site-note';
import { TranslatePipe } from '../../../shared/i18n/translate';
import { TranslatePluralPipe } from '../../../shared/i18n/translate-plural';
import { TranslationService, Worded } from '../../../shared/i18n/translation';
import { rankMark } from '../../../shared/i18n/variation-words';
import { MetaStore } from '../../../shared/meta-store';
import { Build, Evaluation, GameKey, SetOption, SetPiece, SiteNote, StatPriority, TalentPriority } from '../../../api/models';
import { BUILD_TERMS } from './build-terms';
import { BuildTeams } from './build-teams';
import { Avatar } from '../../../shared/char-avatar';
import { StatValuePipe } from '../../../shared/stat-pipes';

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
 * talent names, a target's constellation ("C1+") - are terms and stay as they are. The build's note is the site's
 * own, written in the builds editor: in the reader's language where an editor wrote it, else in English - marked
 * `lang="en"` while the page is in another language (shared/i18n/site-note.ts). The texts the builds file carries but
 * the site no longer shows (a weapon's or a set option's note, a target's text) never reach the page.
 *
 * The build sheet is the site's own; its parts are the kit's: badges for the stats and what the player has, its
 * icons, tooltips, and the kit's disclosure (`@anotoki/lib/ui/disclosure`) for the build's note. The teams it is
 * played in are BuildTeams'.
 */
@Component({
  selector: 'app-build-detail',
  imports: [Avatar, BuildTeams, StatValuePipe, LocalDatePipe, TranslatePipe, TranslatePluralPipe, BadgeComponent, DisclosureComponent, IconComponent, TooltipDirective],
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
  /** On a player's own character page: the teams' teammates the account has, with each one's constellation (BuildTeams). */
  readonly ownedTeammates = input<Record<string, number | null> | null>(null);
  readonly accountId = input<number | null>(null);

  protected readonly gameMeta = computed(() => this.meta.game(this.game()));
  /** The game's own words for this game: "Weapons", "Talents". */
  protected readonly terms = computed(() => GAME_TERMS[this.game()]);
  protected readonly buildTerms = BUILD_TERMS;
  protected readonly equippedWeaponKey = computed(() => this.evaluation()?.weapon?.equipped?.key ?? null);

  protected readonly ownedWeaponKeys = computed(() => new Set((this.evaluation()?.weapon?.owned_better ?? []).map((weapon) => weapon.key)));

  protected readonly setGroups = computed<SetGroup[]>(() => {
    const groups = this.evaluation()?.sets?.groups ?? [];
    return (Object.keys(SET_KIND_TERMS) as SetOption['kind'][])
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
    return [...byPriority.entries()].sort(([a], [b]) => a - b).map(([, list]) => list.map((t) => this.meta.talentLabel(this.game(), t.talent)).join(' = '));
  });

  /**
   * The stat targets: with an evaluation those of the character's constellation (the server keeps, per stat, the one
   * from the highest constellation reached), else every one the build has - a stat's from C0 and from C1 both.
   */
  protected readonly targets = computed(() => {
    const evaluated = this.evaluation()?.targets;
    return evaluated && evaluated.length > 0 ? evaluated : (this.build().stat_targets ?? []);
  });

  /** The build's note in the language on the page where it is written, else in English; null for none. */
  protected note(note: SiteNote | null): Worded | null {
    return siteNoteText(note, this.i18n.effectiveLanguage());
  }

  /** "C1+" on a target that holds from a constellation; none on one from the start. */
  protected targetRank(fromRank: number | undefined): string | null {
    return rankMark(this.gameMeta()?.rank_prefix ?? this.terms().rankPrefix, fromRank ?? 0);
  }

  /** The pieces that are one set each - the ones with a picture -, the first two. */
  protected setPictures(option: SetOption): SetPiece[] {
    return option.pieces.filter((piece) => piece.keys.length === 1).slice(0, 2);
  }

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
