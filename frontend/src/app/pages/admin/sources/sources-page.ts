import { Component, DestroyRef, computed, effect, inject, signal, untracked } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatExpansionModule } from '@angular/material/expansion';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { Observable } from 'rxjs';
import { Api, errorMessage, fileErrorMessage } from '../../../core/api';
import { AuthService } from '../../../core/auth';
import { GAME_LABELS } from '../../../core/format';
import { GameKey, SourceStatus, SyncRunSummary, SyncStartResult } from '../../../core/models';
import { SyncDriver } from '../../../core/sync-driver';
import { TimeAgoPipe } from '../../../shared/stat-pipes';
import { downloadName, saveFile } from '../../../core/download';
import { BUILD_FILE_MAX_BYTES, checkBuildFile, importQuestion, importSummary } from './build-file';

/** A run nobody has stepped for this long counts as paused. */
const PAUSED_AFTER_MS = 60_000;

/**
 * Admin page: the build sites and their syncs. Syncs run in steps driven by this page (SyncDriver); closing
 * it pauses them. A source's builds can also be exported to a file and imported from one - how a source the
 * host cannot reach gets its builds, synced on another computer.
 *
 * Editors and admins sync, read the history and carry builds files; switching a source on or off is an
 * admin's (the page hides it from an editor; the server refuses it anyway). The database's updates are
 * applied on the Migrations page (an admin's); while one waits the site gate shows its own page instead of
 * this one, so nothing here needs to say so.
 */
@Component({
  selector: 'app-sources-page',
  imports: [MatButtonModule, MatExpansionModule, MatIconModule, MatProgressBarModule, MatSlideToggleModule, MatTooltipModule, TimeAgoPipe],
  templateUrl: './sources-page.html',
  styleUrl: './sources-page.scss',
})
export class SourcesPage {
  private readonly api = inject(Api);
  private readonly snackBar = inject(MatSnackBar);
  protected readonly sync = inject(SyncDriver);
  protected readonly auth = inject(AuthService);

  protected readonly sources = signal<SourceStatus[]>([]);
  protected readonly runs = signal<SyncRunSummary[]>([]);
  protected readonly logs = signal<Record<number, string>>({});
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly starting = signal(false);
  /** The source whose builds file is on its way, and which way. */
  protected readonly transfer = signal<{ key: string; action: 'export' | 'import' } | null>(null);
  /** Ticks every second for the countdowns. */
  protected readonly now = signal(Date.now());

  protected readonly gameLabels = GAME_LABELS;
  protected readonly games: GameKey[] = ['genshin', 'hsr'];
  protected readonly byGame = computed(() => {
    const groups: Record<GameKey, SourceStatus[]> = { genshin: [], hsr: [] };
    for (const source of this.sources()) {
      groups[source.game].push(source);
    }
    return groups;
  });

  protected readonly activeRuns = computed(() => this.sync.status()?.runs ?? []);
  protected readonly syncingKeys = computed(() => new Set(this.activeRuns().map((run) => run.source?.key)));
  protected readonly logTail = computed(() => this.sync.lines().slice(-8).join('\n'));

  constructor() {
    this.refresh();
    void this.sync.refresh();

    // A run finished: its source's counts and the history changed.
    effect(() => {
      if (this.sync.finished()) {
        untracked(() => this.refresh(false));
      }
    });

    // The countdowns only move while something runs; otherwise the page has nothing to redraw.
    const timer = setInterval(() => {
      if (this.activeRuns().length > 0) {
        this.now.set(Date.now());
      }
    }, 1000);
    inject(DestroyRef).onDestroy(() => clearInterval(timer));
  }

  protected refresh(showSpinner = true): void {
    if (showSpinner) {
      this.loading.set(true);
    }
    this.api.sources().subscribe({
      next: (sources) => {
        this.sources.set(sources);
        this.error.set(null);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(errorMessage(err));
        this.loading.set(false);
      },
    });
    this.api.syncRuns(undefined, 20).subscribe({ next: (runs) => this.runs.set(runs) });
  }

  protected syncAll(): void {
    this.start(this.api.syncAllSources());
  }

  protected syncOne(source: SourceStatus): void {
    this.start(this.api.syncSource(source.key));
  }

  protected refreshCatalog(): void {
    this.start(this.api.syncCatalog());
  }

  protected cancel(run: SyncRunSummary): void {
    if (!confirm(`Cancel the sync of ${this.runLabel(run)}? What it has stored so far is kept.`)) {
      return;
    }
    this.api.cancelSyncRun(run.id).subscribe({
      next: () => {
        void this.sync.refresh();
        this.refresh(false);
      },
      error: (err) => this.snackBar.open(errorMessage(err), 'Close'),
    });
  }

  /**
   * Export and Import wait while the source syncs (an export would be half old, half new; the server refuses
   * an import) or while a file of it is on its way.
   */
  protected transferBlocked(source: SourceStatus): boolean {
    return this.syncingKeys().has(source.key) || this.transfer()?.key === source.key;
  }

  protected exportBuilds(source: SourceStatus): void {
    this.transfer.set({ key: source.key, action: 'export' });
    this.api.exportSourceBuilds(source.key).subscribe({
      next: (response) => {
        this.transfer.set(null);
        const today = new Date().toISOString().slice(0, 10);
        saveFile(response.body ?? new Blob([]), downloadName(response.headers.get('Content-Disposition'), `builds-${source.key}-${today}.json`));
      },
      error: async (err: unknown) => {
        this.transfer.set(null);
        this.snackBar.open(await fileErrorMessage(err), 'Close');
      },
    });
  }

  /** A file chosen for a source: checked here, confirmed, then sent; the list and the runs show what it did. */
  protected async importBuilds(source: SourceStatus, input: HTMLInputElement): Promise<void> {
    const file = input.files?.[0];
    // Cleared, so choosing the same file again (after fixing it) counts as a change too.
    input.value = '';
    if (!file) {
      return;
    }
    if (file.size > BUILD_FILE_MAX_BYTES) {
      this.snackBar.open(
        `This file is larger than ${BUILD_FILE_MAX_BYTES / 1048576} MB: more than the server may accept, and far more than the builds of a source take. Is it the right file?`,
        'Close',
      );
      return;
    }

    let text: string;
    try {
      text = await file.text();
    } catch {
      this.snackBar.open('This file could not be read.', 'Close');
      return;
    }
    const check = checkBuildFile(text, source);
    if (!check.ok) {
      this.snackBar.open(check.message, 'Close');
      return;
    }
    if (!confirm(importQuestion(source, check, this.auth.preferences()))) {
      return;
    }

    this.transfer.set({ key: source.key, action: 'import' });
    this.api.importSourceBuilds(source.key, text).subscribe({
      next: (result) => {
        this.transfer.set(null);
        // Skipped characters ask for something to be done: that message stays until it is closed.
        this.snackBar.open(importSummary(source, result), 'OK', { duration: result.skipped.length > 0 ? undefined : 8000 });
        this.refresh(false);
      },
      error: (err) => {
        this.transfer.set(null);
        this.snackBar.open(errorMessage(err), 'Close');
      },
    });
  }

  protected toggle(source: SourceStatus, enabled: boolean): void {
    this.api.setSourceEnabled(source.key, enabled).subscribe({
      next: () => this.sources.update((list) => list.map((s) => (s.key === source.key ? { ...s, enabled } : s))),
      error: (err) => this.snackBar.open(errorMessage(err), 'Close'),
    });
  }


  protected loadLog(run: SyncRunSummary): void {
    if (this.logs()[run.id] !== undefined && run.status !== 'running') {
      return;
    }
    this.api.syncRun(run.id).subscribe({
      next: (detail) => this.logs.update((logs) => ({ ...logs, [run.id]: detail.log ?? '(no log)' })),
    });
  }

  protected runLabel(run: SyncRunSummary): string {
    const game = run.game ? GAME_LABELS[run.game] : '';
    return run.kind === 'catalog' ? `Game catalog · ${game}` : `${run.source?.name ?? 'Unknown source'} · ${game}`;
  }

  /** Percent done, or null while the total is not known yet (the character list is being read). */
  protected progress(run: SyncRunSummary): number | null {
    const total = run.progress?.total;
    return total ? Math.round((100 * (run.progress?.done ?? 0)) / total) : total === 0 ? 100 : null;
  }

  protected progressText(run: SyncRunSummary): string {
    const progress = run.progress;
    if (!progress || progress.total === null) {
      return run.kind === 'catalog' ? 'starting' : 'reading the character list';
    }
    return `${progress.done} / ${progress.total} ${run.kind === 'catalog' ? 'lists' : 'characters'}`;
  }

  /** What a running run is doing right now, from this tab's point of view. */
  protected runState(run: SyncRunSummary): string {
    const now = this.now();
    const next = run.next_step_at ? new Date(run.next_step_at).getTime() : 0;
    if (this.sync.driving()) {
      return next > now ? `next page in ${Math.ceil((next - now) / 1000)} s` : 'working';
    }
    const last = run.last_step_at ? new Date(run.last_step_at).getTime() : 0;
    return now - last < PAUSED_AFTER_MS ? 'running in another tab' : 'paused';
  }

  protected stat(run: SyncRunSummary | null, key: string): number {
    return Number(run?.stats?.[key] ?? 0);
  }

  protected duration(run: SyncRunSummary): string {
    if (!run.finished_at) {
      return run.status === 'running' ? 'running' : '-';
    }
    const seconds = Math.round((new Date(run.finished_at).getTime() - new Date(run.started_at).getTime()) / 1000);
    return seconds >= 60 ? `${Math.floor(seconds / 60)} min ${seconds % 60} s` : `${seconds} s`;
  }

  protected summary(run: SyncRunSummary): string {
    if (run.kind === 'catalog') {
      return `${this.stat(run, 'characters')} characters, ${this.stat(run, 'weapons')} weapons, ${this.stat(run, 'sets')} sets, ${this.stat(run, 'errors')} errors`;
    }
    return `${this.stat(run, 'builds_created')} new, ${this.stat(run, 'builds_updated')} updated, ${this.stat(run, 'errors')} errors`;
  }

  private start(request: Observable<SyncStartResult>): void {
    this.starting.set(true);
    request.subscribe({
      next: (result) => {
        this.starting.set(false);
        this.sync.adopt(result);
        const skipped = result.skipped.map((skip) => `${skip.name} (${skip.reason})`).join(', ');
        const message = result.started.length > 0
          ? `Syncing ${result.started.length} run(s). Keep this tab open; closing it pauses the sync.${skipped ? ` Skipped: ${skipped}.` : ''}`
          : `Nothing to sync. Skipped: ${skipped || 'everything is up to date'}.`;
        this.snackBar.open(message, 'OK', { duration: 8000 });
      },
      error: (err) => {
        this.starting.set(false);
        this.snackBar.open(errorMessage(err), 'Close');
      },
    });
  }
}
