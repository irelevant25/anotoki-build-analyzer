import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { DisclosureComponent } from '@anotoki/lib/ui/disclosure';
import { GAME_TERMS } from '../core/game-terms';
import { TranslatePipe } from '../core/i18n/translate';
import { TranslationService } from '../core/i18n/translation';
import { rankMark, roleLabel } from '../core/i18n/variation-words';
import { MetaStore } from '../core/meta-store';
import { BuildTeam, GameKey, Teammate, TeamMember } from '../core/models';
import { Avatar } from './char-avatar';

/**
 * The teams a build is played in (the builds file's format version 3). A team's name and note are as the file has
 * them - stored English, `lang="en"` while the page is in another language; its members come in the team's order,
 * the build's own character among them (never a link: the page is its own), each with the role it plays, the
 * constellation the team asks of it ("C6+") and who can take its place ("or Yelan / Mona"). A teammate links to its
 * builds where it has some.
 *
 * On a player's own character page (`owned`, `accountId`) a teammate the account has wears "Owned" - and "needs C6"
 * when its constellation is below what the team asks - and links to the player's own analysis of it instead.
 */
@Component({
  selector: 'app-build-teams',
  imports: [RouterLink, Avatar, TranslatePipe, BadgeComponent, DisclosureComponent],
  templateUrl: './build-teams.html',
  styleUrl: './build-teams.scss',
})
export class BuildTeams {
  private readonly meta = inject(MetaStore);
  private readonly i18n = inject(TranslationService);

  readonly teams = input.required<BuildTeam[]>();
  readonly game = input.required<GameKey>();
  /** The build's id: each team's heading gets an id of its own, which its members' list is labelled by. */
  readonly buildId = input.required<number>();
  /** The constellation the build needs: the own member's mark shows only above it. */
  readonly buildMinRank = input(0);
  /** On a player's own character page: the teammates the account has, by key, with each one's constellation. */
  readonly owned = input<Record<string, number> | null>(null);
  readonly accountId = input<number | null>(null);

  /** `en` on what a team's text says while the page is in another language; nothing while it is English. */
  protected readonly storedLang = this.i18n.storedEnglishLang;
  private readonly rankPrefix = computed(() => this.meta.game(this.game())?.rank_prefix ?? GAME_TERMS[this.game()].rankPrefix);
  /** What stands before and after the stand-ins' names ("or ", ""), in the reader's language. */
  protected readonly alternatives = computed(() => this.i18n.around('buildDetail.teamAlternatives', 'names'));

  protected teamId(index: number): string {
    return `team-${this.buildId()}-${index}`;
  }

  protected role(role: string): string {
    return roleLabel(this.i18n, role);
  }

  /** "C6+" on a member the team asks a constellation of; on the build's own member only above what the build needs. */
  protected rank(member: TeamMember): string | null {
    return member.own && member.min_rank <= this.buildMinRank() ? null : rankMark(this.rankPrefix(), member.min_rank);
  }

  /** A constellation in the game's notation: "C6". */
  protected rankText(rank: number): string {
    return `${this.rankPrefix()}${rank}`;
  }

  /** The account's constellation of a teammate it has; null for one it has not, and off a player's own page. */
  protected ownedRank(teammate: Teammate): number | null {
    return this.owned()?.[teammate.key] ?? null;
  }

  /** Whether the account has the teammate below the constellation the team asks of it. */
  protected belowRank(member: TeamMember): boolean {
    const rank = this.ownedRank(member);
    return rank !== null && rank < member.min_rank;
  }

  /** Where a teammate leads: the player's own analysis of one the account has, else its builds, else nowhere. */
  protected link(teammate: Teammate): string[] | null {
    const account = this.accountId();
    if (account !== null && this.ownedRank(teammate) !== null) {
      return ['/accounts', String(account), 'characters', teammate.key];
    }
    return teammate.has_builds ? ['/builds', this.game(), teammate.key] : null;
  }
}
