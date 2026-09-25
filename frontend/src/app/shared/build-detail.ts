import { Component, computed, inject, input } from '@angular/core';
import { MatExpansionModule } from '@angular/material/expansion';
import { MatIconModule } from '@angular/material/icon';
import { MatTooltipModule } from '@angular/material/tooltip';
import { MetaStore } from '../core/meta-store';
import { Build, Evaluation, GameKey, SetOption, StatPriority, TalentPriority } from '../core/models';
import { Avatar } from './char-avatar';
import { StatValuePipe, TimeAgoPipe } from './stat-pipes';

interface SetGroup {
  kind: SetOption['kind'];
  title: string;
  options: SetOption[];
  matchedRank: number | null;
}

/**
 * Renders a recommended build. With an evaluation it also marks what the player already has.
 */
@Component({
  selector: 'app-build-detail',
  imports: [Avatar, StatValuePipe, TimeAgoPipe, MatIconModule, MatTooltipModule, MatExpansionModule],
  templateUrl: './build-detail.html',
  styleUrl: './build-detail.scss',
})
export class BuildDetail {
  private readonly meta = inject(MetaStore);

  readonly build = input.required<Build>();
  readonly game = input.required<GameKey>();
  readonly evaluation = input<Evaluation | null>(null);
  /** Character element, used to show "Elemental DMG" as e.g. "Pyro DMG". */
  readonly element = input<string | null>(null);

  protected readonly gameMeta = computed(() => this.meta.game(this.game()));

  protected readonly equippedWeaponKey = computed(() => this.evaluation()?.weapon?.equipped?.key ?? null);

  protected readonly ownedWeaponKeys = computed(
    () => new Set((this.evaluation()?.weapon?.owned_better ?? []).map((weapon) => weapon.key)),
  );

  protected readonly setGroups = computed<SetGroup[]>(() => {
    const titles: Record<SetOption['kind'], string> = {
      artifact: 'Artifact sets',
      relic: 'Cavern Relics',
      planar: 'Planar Ornaments',
    };
    const groups = this.evaluation()?.sets?.groups ?? [];
    return (['artifact', 'relic', 'planar'] as const)
      .map((kind) => ({
        kind,
        title: titles[kind],
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

  protected pieceText(option: SetOption): string {
    return option.pieces.map((piece) => `${piece.count}pc ${piece.label}`).join(' + ');
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
