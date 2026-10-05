import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Api } from './api';
import { GameKey, GameMeta, Meta } from './models';

/**
 * Game metadata (slot names, stat labels, talent names) and the sign-in settings, loaded once at startup.
 *
 * An installation nobody has set up yet answers every request with 503 {code: "not_set_up", setup: "/setup.php"};
 * notSetUp then holds where to set it up, and the app shows that instead of its pages.
 */
@Injectable({ providedIn: 'root' })
export class MetaStore {
  private readonly api = inject(Api);
  private readonly meta = signal<Meta | null>(null);

  readonly loaded = signal(false);
  /** The setup page to open ("/setup.php") when the server has not been set up yet; null otherwise. */
  readonly notSetUp = signal<string | null>(null);
  /** How many days a visitor's data is kept after its last visit (the visitor notice): the server's word, else 30. */
  readonly visitorDays = computed(() => this.meta()?.visitors?.keep_days ?? 30);

  /** The metadata, or null when the backend could not be reached. */
  load(): Promise<Meta | null> {
    return new Promise((resolve) => {
      this.api.meta().subscribe({
        next: (meta) => {
          this.meta.set(meta);
          this.loaded.set(true);
          resolve(meta);
        },
        // The app still renders (with raw keys) when the backend is down; pages show the error.
        error: (error: unknown) => {
          this.notSetUp.set(setupPage(error));
          resolve(null);
        },
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

/** Where to set the server up, when this error says it has not been; null for any other error. */
function setupPage(error: unknown): string | null {
  if (!(error instanceof HttpErrorResponse) || error.status !== 503) {
    return null;
  }
  const body = error.error as { code?: string; setup?: string } | null;
  if (body?.code !== 'not_set_up') {
    return null;
  }
  // Only a page of this site, whatever the answer says.
  return typeof body.setup === 'string' && /^\/[\w.-]+$/.test(body.setup) ? body.setup : '/setup.php';
}
