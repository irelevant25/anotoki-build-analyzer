import { Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { GAME_LABELS } from '../../core/format';
import { GAME_TERMS, UID_TERM } from '../../core/game-terms';
import { ErrorTextPipe, errorText } from '../../core/i18n/error-text';
import { LocalDatePipe } from '../../core/i18n/local-date';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslatePluralPipe } from '../../core/i18n/translate-plural';
import { TranslationService } from '../../core/i18n/translation';
import { AccountSummary, GameKey, SourceStatus } from '../../core/models';
import { askConfirm } from '../../shared/confirm-dialog';

@Component({
  selector: 'app-dashboard-page',
  imports: [
    RouterLink,
    MatButtonModule,
    MatIconModule,
    MatMenuModule,
    MatProgressBarModule,
    MatTooltipModule,
    TranslatePipe,
    TranslatePluralPipe,
    LocalDatePipe,
    ErrorTextPipe,
  ],
  templateUrl: './dashboard-page.html',
  styleUrl: './dashboard-page.scss',
})
export class DashboardPage {
  private readonly api = inject(Api);
  private readonly snackBar = inject(MatSnackBar);
  private readonly dialog = inject(MatDialog);
  private readonly i18n = inject(TranslationService);
  protected readonly auth = inject(AuthService);

  protected readonly accounts = signal<AccountSummary[]>([]);
  protected readonly sources = signal<SourceStatus[]>([]);
  protected readonly loading = signal(true);
  /** Why the page could not be loaded: the failed request itself, worded where it is shown. */
  protected readonly error = signal<unknown>(null);

  protected readonly games: GameKey[] = ['genshin', 'hsr'];
  protected readonly gameLabels = GAME_LABELS;
  /** The games' own words - Artifacts / Relics, AR / TL, UID: shown as they are in every language. */
  protected readonly terms = GAME_TERMS;
  protected readonly uidTerm = UID_TERM;

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

  /** "No builds yet ... on the Sources page", cut where the link to that page stands: [before, after, whether it stands at all]. */
  protected readonly noBuildsAdmin = computed(() => this.i18n.around('dashboard.noBuildsAdmin', 'sources'));

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
      error: (err: unknown) => {
        this.error.set(err);
        this.loading.set(false);
      },
    });
  }

  /**
   * Asks first, in the app's own dialog. The question, and what is said afterwards, are finished
   * sentences: worded now, in the language the page is in.
   */
  protected async deleteAccount(account: AccountSummary): Promise<void> {
    const name = account.nickname ?? `${UID_TERM} ${account.uid}`;
    const sure = await askConfirm(this.dialog, {
      title: this.i18n.t('confirm.deleteAccount', { name }),
      message: this.i18n.t('confirm.deleteAccountKept'),
      confirmLabel: this.i18n.t('confirm.delete'),
      cancelLabel: this.i18n.t('common.cancel'),
      danger: true,
    });
    if (!sure) {
      return;
    }
    this.api.deleteAccount(account.id).subscribe({
      next: () => {
        this.accounts.update((list) => list.filter((a) => a.id !== account.id));
        this.snackBar.open(this.i18n.t('dashboard.deleted', { name }), undefined, { duration: 3000 });
      },
      error: (err: unknown) => this.snackBar.open(errorText(this.i18n, err), this.i18n.t('common.close')),
    });
  }
}
