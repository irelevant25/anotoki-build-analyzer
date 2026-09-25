import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatExpansionModule } from '@angular/material/expansion';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { Api, errorMessage } from '../../core/api';
import { GAME_LABELS } from '../../core/format';
import { GameKey, SourceStatus, SyncRunSummary } from '../../core/models';
import { TimeAgoPipe } from '../../shared/stat-pipes';

@Component({
  selector: 'app-sources-page',
  imports: [MatButtonModule, MatExpansionModule, MatIconModule, MatProgressBarModule, MatSlideToggleModule, MatTooltipModule, TimeAgoPipe],
  templateUrl: './sources-page.html',
  styleUrl: './sources-page.scss',
})
export class SourcesPage {
  private readonly api = inject(Api);
  private readonly snackBar = inject(MatSnackBar);

  protected readonly sources = signal<SourceStatus[]>([]);
  protected readonly runs = signal<SyncRunSummary[]>([]);
  protected readonly logs = signal<Record<number, string>>({});
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly queued = signal<Set<string>>(new Set());

  protected readonly gameLabels = GAME_LABELS;
  protected readonly games: GameKey[] = ['genshin', 'hsr'];
  protected readonly byGame = computed(() => {
    const groups: Record<GameKey, SourceStatus[]> = { genshin: [], hsr: [] };
    for (const source of this.sources()) {
      groups[source.game].push(source);
    }
    return groups;
  });

  constructor() {
    this.refresh();
    // Poll while something is running or queued so progress shows up without a reload.
    const timer = setInterval(() => {
      if (this.sources().some((s) => s.running) || this.queued().size > 0) {
        this.refresh(false);
      }
    }, 10_000);
    inject(DestroyRef).onDestroy(() => clearInterval(timer));
  }

  protected refresh(showSpinner = true): void {
    if (showSpinner) {
      this.loading.set(true);
    }
    this.api.sources().subscribe({
      next: (sources) => {
        this.sources.set(sources);
        // A queued source that has started (or finished) is no longer "queued".
        const queued = new Set(this.queued());
        for (const source of sources) {
          if (source.running || (source.latest_run && queued.has(source.key) && source.latest_run.status !== 'running')) {
            queued.delete(source.key);
          }
        }
        this.queued.set(queued);
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

  protected sync(source: SourceStatus): void {
    this.api.syncSource(source.key).subscribe({
      next: () => {
        this.queued.set(new Set([...this.queued(), source.key]));
        this.snackBar.open(`${source.name} sync queued. It runs in the queue worker (php artisan queue:work).`, 'OK', { duration: 6000 });
      },
      error: (err) => this.snackBar.open(errorMessage(err), 'Close'),
    });
  }

  protected toggle(source: SourceStatus, enabled: boolean): void {
    this.api.setSourceEnabled(source.key, enabled).subscribe({
      next: () => this.sources.update((list) => list.map((s) => (s.key === source.key ? { ...s, enabled } : s))),
      error: (err) => this.snackBar.open(errorMessage(err), 'Close'),
    });
  }

  protected loadLog(run: SyncRunSummary): void {
    if (this.logs()[run.id] !== undefined) {
      return;
    }
    this.api.syncRun(run.id).subscribe({
      next: (detail) => this.logs.update((logs) => ({ ...logs, [run.id]: detail.log ?? '(no log)' })),
    });
  }

  protected stat(run: SyncRunSummary | null, key: string): number {
    return Number(run?.stats?.[key] ?? 0);
  }

  protected duration(run: SyncRunSummary): string {
    if (!run.finished_at) {
      return 'running';
    }
    const seconds = Math.round((new Date(run.finished_at).getTime() - new Date(run.started_at).getTime()) / 1000);
    return seconds >= 60 ? `${Math.floor(seconds / 60)} min ${seconds % 60} s` : `${seconds} s`;
  }
}
