import { Injectable, computed, inject, signal } from '@angular/core';
import { Api } from '../api/api';
import { GameKey, GameMeta, Meta } from '../api/models';

/**
 * Game metadata (slot names, stat labels, talent names) and the sign-in settings, loaded once at startup - the
 * auth module reads its boot configuration from the same answer (app.config.ts), so it is asked for once.
 *
 * /api/meta answers while a database update waits (the site gate keeps it open: it says where people sign in).
 * An installation nobody has set up yet answers it 503 not_set_up like every other path: the load gives null,
 * and the site gate - which heard it too - shows its own page instead of the site's (shared/site-status.ts).
 */
@Injectable({ providedIn: 'root' })
export class MetaStore {
  private readonly api = inject(Api);
  private readonly meta = signal<Meta | null>(null);
  /** The one request of this page load, answered or on its way. */
  private loading: Promise<Meta | null> | null = null;

  readonly loaded = signal(false);
  /** How many days a visitor's data is kept after its last visit (the visitor notice): the server's word, else 30. */
  readonly visitorDays = computed(() => this.meta()?.visitors?.keep_days ?? 30);

  /** The metadata, or null when the backend could not be reached (or is not set up) - asked for once a page load. */
  load(): Promise<Meta | null> {
    this.loading ??= new Promise((resolve) => {
      this.api.meta().subscribe({
        next: (meta) => {
          this.meta.set(meta);
          this.loaded.set(true);
          resolve(meta);
        },
        // The app still renders (with raw keys) when the backend is down; pages show the error.
        error: () => resolve(null),
      });
    });
    return this.loading;
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
   * "46.6%" for percentage stats, "4,780" for flat stats.
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
