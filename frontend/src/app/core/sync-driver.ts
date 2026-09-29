import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { Api, errorMessage } from './api';
import { SyncRunSummary, SyncStatus } from './models';

/**
 * Drives the build and catalog syncs from this tab. The server does a bounded piece of work per
 * POST /api/sync/step (a page or two, respecting each site's crawl delay) and says how long to wait before
 * the next one; this loop simply keeps asking while anything is active. Nothing else advances a sync, so
 * closing the tab (or pause()) pauses it, and start() - here or in another tab - continues where it stopped.
 *
 * Provided in root, so the sync keeps going while the admin moves between pages of the app.
 */
@Injectable({ providedIn: 'root' })
export class SyncDriver {
  private readonly api = inject(Api);

  readonly status = signal<SyncStatus | null>(null);
  /** This tab is stepping the syncs. */
  readonly driving = signal(false);
  readonly error = signal<string | null>(null);
  /** When this tab sends its next step (epoch ms), while driving. */
  readonly nextStepAt = signal<number | null>(null);
  /** The latest log lines written by this tab's steps. */
  readonly lines = signal<string[]>([]);
  /** The last run a step finished; pages reload their lists when it changes. */
  readonly finished = signal<SyncRunSummary | null>(null);

  readonly active = computed(() => this.status()?.active ?? false);

  private generation = 0;
  private timer: ReturnType<typeof setTimeout> | null = null;
  private wake: (() => void) | null = null;

  /** Loads the current state without doing any work. */
  async refresh(): Promise<void> {
    try {
      this.status.set(await firstValueFrom(this.api.syncStatus()));
    } catch (error) {
      this.error.set(errorMessage(error));
    }
  }

  /** Takes the state a start request answered with, then drives it. */
  adopt(status: SyncStatus): void {
    this.status.set(status);
    if (status.active) {
      this.start();
    }
  }

  start(): void {
    if (this.driving()) {
      return;
    }
    this.error.set(null);
    this.driving.set(true);
    void this.loop(++this.generation);
  }

  pause(): void {
    this.generation++;
    this.driving.set(false);
    this.nextStepAt.set(null);
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
    // Let a loop that is waiting notice it should stop.
    const wake = this.wake;
    this.wake = null;
    wake?.();
  }

  private async loop(generation: number): Promise<void> {
    let failures = 0;

    while (generation === this.generation) {
      let result;
      try {
        result = await firstValueFrom(this.api.syncStep());
        failures = 0;
      } catch (error) {
        failures++;
        const signedOut = error instanceof HttpErrorResponse && (error.status === 401 || error.status === 403);
        if (signedOut || failures >= 3) {
          this.error.set(errorMessage(error));
          this.pause();
          return;
        }
        // A hiccup (the host was busy, the connection dropped): try again a little later.
        await this.sleep(5000 * failures, generation);
        continue;
      }

      if (generation !== this.generation) {
        return;
      }
      this.status.set(result);
      if (result.lines.length > 0) {
        this.lines.update((lines) => [...lines, ...result.lines].slice(-200));
      }
      if (result.did && result.did.status !== 'running') {
        this.finished.set(result.did);
      }
      if (!result.active) {
        this.driving.set(false);
        this.nextStepAt.set(null);
        return;
      }

      // wait_ms is when the next step has work (a site's crawl delay); never ask in a tight loop.
      const wait = result.did ? (result.wait_ms ?? 0) : Math.max(result.wait_ms ?? 1000, result.busy ? 1000 : 500);
      await this.sleep(wait, generation);
    }
  }

  private sleep(ms: number, generation: number): Promise<void> {
    if (generation !== this.generation) {
      return Promise.resolve();
    }
    this.nextStepAt.set(Date.now() + ms);
    return new Promise<void>((resolve) => {
      this.wake = resolve;
      this.timer = setTimeout(() => {
        this.timer = null;
        this.wake = null;
        resolve();
      }, ms);
    });
  }
}
