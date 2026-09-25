import { Injectable, inject, signal } from '@angular/core';
import { Api } from './api';
import { GameKey, GameMeta, Meta } from './models';

/**
 * Game metadata (slot names, stat labels, talent names) loaded once at startup.
 */
@Injectable({ providedIn: 'root' })
export class MetaStore {
  private readonly api = inject(Api);
  private readonly meta = signal<Meta | null>(null);

  readonly loaded = signal(false);

  load(): Promise<void> {
    return new Promise((resolve) => {
      this.api.meta().subscribe({
        next: (meta) => {
          this.meta.set(meta);
          this.loaded.set(true);
          resolve();
        },
        // The app still renders (with raw keys) when the backend is down; pages show the error.
        error: () => resolve(),
      });
    });
  }

  game(key: GameKey): GameMeta | null {
    return this.meta()?.games.find((game) => game.key === key) ?? null;
  }

  statLabel(game: GameKey, stat: string, short = false): string {
    const meta = this.game(game)?.stats[stat];
    if (!meta) {
      return stat;
    }
    return short ? meta.short : meta.label;
  }

  isPercent(game: GameKey, stat: string): boolean {
    return this.game(game)?.stats[stat]?.percent ?? stat.endsWith('_');
  }

  slotLabel(game: GameKey, slot: string): string {
    return this.game(game)?.slots.find((s) => s.key === slot)?.label ?? slot;
  }

  talentLabel(game: GameKey, talent: string): string {
    return this.game(game)?.talents[talent] ?? talent;
  }

  /**
   * "46.6%" for percentage stats, "4,780" for flat stats. HSR SPD keeps one decimal.
   */
  formatStat(game: GameKey, stat: string, value: number | null | undefined): string {
    if (value === null || value === undefined) {
      return '-';
    }
    if (this.isPercent(game, stat)) {
      return `${value.toFixed(1)}%`;
    }
    if (stat === 'spd') {
      return Number.isInteger(value) ? String(value) : value.toFixed(1);
    }
    return Math.round(value).toLocaleString('en-US');
  }
}
