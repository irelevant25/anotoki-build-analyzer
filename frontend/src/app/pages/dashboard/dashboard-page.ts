import { Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { Api, errorMessage } from '../../core/api';
import { GAME_LABELS } from '../../core/format';
import { AccountSummary, GameKey, SourceStatus } from '../../core/models';
import { TimeAgoPipe } from '../../shared/stat-pipes';

@Component({
  selector: 'app-dashboard-page',
  imports: [RouterLink, MatButtonModule, MatIconModule, MatMenuModule, MatProgressBarModule, MatTooltipModule, TimeAgoPipe],
  templateUrl: './dashboard-page.html',
  styleUrl: './dashboard-page.scss',
})
export class DashboardPage {
  private readonly api = inject(Api);
  private readonly snackBar = inject(MatSnackBar);

  protected readonly accounts = signal<AccountSummary[]>([]);
  protected readonly sources = signal<SourceStatus[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly games: GameKey[] = ['genshin', 'hsr'];
  protected readonly gameLabels = GAME_LABELS;

  protected readonly accountsByGame = computed(() => {
    const groups: Record<GameKey, AccountSummary[]> = { genshin: [], hsr: [] };
    for (const account of this.accounts()) {
      groups[account.game].push(account);
    }
    return groups;
  });

  protected readonly sourcesByGame = computed(() => {
    const groups: Record<GameKey, SourceStatus[]> = { genshin: [], hsr: [] };
    for (const source of this.sources()) {
      groups[source.game].push(source);
    }
    return groups;
  });

  protected readonly buildsTotal = computed(() => this.sources().reduce((sum, source) => sum + source.builds, 0));

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    forkJoin({ accounts: this.api.accounts(), sources: this.api.sources() }).subscribe({
      next: ({ accounts, sources }) => {
        this.accounts.set(accounts);
        this.sources.set(sources);
        this.error.set(null);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(errorMessage(err));
        this.loading.set(false);
      },
    });
  }

  protected deleteAccount(account: AccountSummary): void {
    const name = account.nickname ?? `UID ${account.uid}`;
    if (!confirm(`Delete "${name}" and everything imported for it? Builds are kept.`)) {
      return;
    }
    this.api.deleteAccount(account.id).subscribe({
      next: () => {
        this.accounts.update((list) => list.filter((a) => a.id !== account.id));
        this.snackBar.open(`Deleted ${name}.`, undefined, { duration: 3000 });
      },
      error: (err) => this.snackBar.open(errorMessage(err), 'Close'),
    });
  }
}
