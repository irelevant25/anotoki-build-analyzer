import { LowerCasePipe } from '@angular/common';
import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatTabsModule } from '@angular/material/tabs';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { titleCase } from '../../core/format';
import { MetaStore } from '../../core/meta-store';
import { CharacterAnalysis, Evaluation, GameKey, Recommendation, Suggestion } from '../../core/models';
import { BuildDetail } from '../../shared/build-detail';
import { Avatar } from '../../shared/char-avatar';
import { GradeBadge } from '../../shared/grade-badge';
import { ItemCard, ItemView } from '../../shared/item-card';
import { ScoreBar } from '../../shared/score-bar';
import { SlotLabelPipe, StatLabelPipe, StatValuePipe, TimeAgoPipe } from '../../shared/stat-pipes';

@Component({
  selector: 'app-character-page',
  imports: [
    LowerCasePipe,
    RouterLink,
    MatButtonModule,
    MatIconModule,
    MatProgressBarModule,
    MatTabsModule,
    MatTooltipModule,
    Avatar,
    BuildDetail,
    GradeBadge,
    ItemCard,
    ScoreBar,
    SlotLabelPipe,
    StatLabelPipe,
    StatValuePipe,
    TimeAgoPipe,
  ],
  templateUrl: './character-page.html',
  styleUrl: './character-page.scss',
})
export class CharacterPage {
  private readonly api = inject(Api);
  private readonly meta = inject(MetaStore);

  readonly id = input.required<string>();
  readonly key = input.required<string>();

  protected readonly data = signal<CharacterAnalysis | null>(null);
  protected readonly selectedBuildId = signal<number | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly titleCase = titleCase;

  protected readonly game = computed<GameKey>(() => this.data()?.account.game ?? 'genshin');
  protected readonly gameMeta = computed(() => this.meta.game(this.game()));

  protected readonly evaluation = computed<Evaluation | null>(() => {
    const data = this.data();
    if (!data) {
      return null;
    }
    return data.evaluations.find((e) => e.build.id === this.selectedBuildId()) ?? data.evaluations[0] ?? null;
  });

  protected readonly selectedIndex = computed(() =>
    Math.max(0, (this.data()?.evaluations ?? []).findIndex((e) => e.build.id === this.evaluation()?.build.id)),
  );

  protected readonly build = computed(() => this.data()?.builds.find((b) => b.id === this.evaluation()?.build.id) ?? null);

  protected readonly slots = computed(() => this.gameMeta()?.slots ?? []);

  protected readonly items = computed(() => {
    const evaluation = this.evaluation();
    return this.slots().map((slot) => ({ slot, item: (evaluation?.items[slot.key] ?? null) as ItemView | null }));
  });

  protected readonly recommendations = computed(() => this.evaluation()?.recommendations ?? []);

  protected readonly suggestions = computed(() =>
    (this.data()?.suggestions ?? []).map((suggestion) => ({
      suggestion,
      view: { ...suggestion.item, slot: suggestion.slot } satisfies ItemView,
    })),
  );

  protected readonly talents = computed(() => {
    const talents = this.data()?.character.talents;
    if (!talents) {
      return [];
    }
    const bonus = (talents['bonus'] ?? {}) as Record<string, number>;
    return Object.entries(talents)
      .filter(([key]) => key !== 'bonus')
      .map(([key, level]) => ({
        label: this.meta.talentLabel(this.game(), key),
        level: level as number,
        bonus: bonus[key] ?? 0,
      }));
  });

  protected readonly finalStats = computed(() => {
    const stats = this.data()?.character.final_stats;
    return stats ? Object.entries(stats).filter(([, value]) => value !== 0) : [];
  });

  protected readonly totals = computed(() => Object.entries(this.data()?.equipment.totals ?? {}).sort(([, a], [, b]) => b - a));

  protected readonly consensusSlots = computed(() => {
    const consensus = this.data()?.consensus;
    return this.slots()
      .filter((slot) => consensus?.main_stats[slot.key]?.length)
      .map((slot) => ({ label: slot.label, stats: consensus!.main_stats[slot.key] }));
  });

  protected readonly consensusSets = computed(() => {
    const sets = this.data()?.consensus.sets ?? {};
    const titles: Record<string, string> = { artifact: 'Artifact set', relic: 'Cavern relics', planar: 'Planar ornaments' };
    return Object.entries(sets).map(([kind, list]) => ({ title: titles[kind] ?? kind, list }));
  });

  constructor() {
    effect(() => {
      this.load(Number(this.id()), this.key(), null);
    });
  }

  protected selectTab(index: number): void {
    const evaluation = this.data()?.evaluations[index];
    if (!evaluation) {
      return;
    }
    this.selectedBuildId.set(evaluation.build.id);
    // Suggestions are computed per build on the server; refresh them when the inventory is known.
    if (this.data()?.has_full_inventory && this.data()?.focus_build_id !== evaluation.build.id) {
      this.load(Number(this.id()), this.key(), evaluation.build.id);
    }
  }

  protected severityIcon(recommendation: Recommendation): string {
    return { high: 'priority_high', medium: 'warning', low: 'info' }[recommendation.severity];
  }

  protected categoryLabel(recommendation: Recommendation): string {
    return {
      weapon: this.gameMeta()?.weapon_label ?? 'Weapon',
      sets: 'Sets',
      main_stats: 'Main stat',
      substats: 'Substats',
      targets: 'Stat target',
      talents: this.game() === 'hsr' ? 'Traces' : 'Talents',
      levels: 'Levels',
    }[recommendation.category];
  }

  protected suggestionReason(suggestion: Suggestion): string {
    return {
      better_substats: `+${suggestion.gain} points of substat quality`,
      completes_set: 'Completes the recommended set bonus',
      fixes_main_stat: 'Has the recommended main stat',
    }[suggestion.reason];
  }

  protected rankPrefix(): string {
    return this.gameMeta()?.rank_prefix ?? 'C';
  }

  protected refinementPrefix(): string {
    return this.gameMeta()?.refinement_prefix ?? 'R';
  }

  private load(accountId: number, key: string, buildId: number | null): void {
    this.loading.set(true);
    this.api.character(accountId, key, buildId).subscribe({
      next: (data) => {
        this.data.set(data);
        this.selectedBuildId.set(data.focus_build_id ?? data.best_build_id);
        this.error.set(null);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(errorMessage(err));
        this.loading.set(false);
      },
    });
  }
}
