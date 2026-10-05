import { Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatMenuModule } from '@angular/material/menu';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { forkJoin, of } from 'rxjs';
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
import { VisitorKey } from '../../core/visitor-key';
import { askConfirm } from '../../shared/confirm-dialog';
import { DataCard } from '../../shared/data-card/data-card';
import { VisitorHero } from '../../shared/visitor-hero/visitor-hero';
import { VisitorNotice } from '../../shared/visitor-notice/visitor-notice';

/**
 * Who is looking: somebody signed in (`person`), somebody who imported without signing in and whose
 * browser holds the visitor key (`visitor`), or neither (`nobody`).
 */
export type DashboardState = 'person' | 'visitor' | 'nobody';

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
    DataCard,
    VisitorHero,
    VisitorNotice,
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
  protected readonly visitor = inject(VisitorKey);

  /**
   * The page has three states. Nobody: what the site does and the ways on (import, browse the builds, sign
   * in) and the sources' freshness - no request for anybody's own data. A visitor: the accounts as a member
   * has them, under the notice that says how long they are kept. Somebody signed in: the accounts, and the
   * "Your data" card. The accounts are loaded again when the state changes (a sign-in, a key forgotten) and
   * after the move of a visitor's accounts into the person's.
   */
  protected readonly state = computed<DashboardState>(() => (this.auth.signedIn() ? 'person' : this.visitor.key() !== null ? 'visitor' : 'nobody'));

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

  /** Counts the loads, so that only the last one's answer is shown. */
  private loads = 0;

  constructor() {
    effect(() => {
      this.state();
      this.visitor.moves();
      untracked(() => this.load());
    });
  }

  protected load(): void {
    const load = ++this.loads;
    const state = untracked(this.state);
    this.loading.set(true);
    // Nobody has no accounts to ask for: the server would only answer 401.
    const accounts = state === 'nobody' ? of<AccountSummary[]>([]) : this.api.accounts();
    forkJoin({ accounts, sources: this.api.sources() }).subscribe({
      next: ({ accounts, sources }) => {
        if (load !== this.loads) {
          return;
        }
        this.accounts.set(accounts);
        this.sources.set(sources);
        this.error.set(null);
        this.loading.set(false);
      },
      error: (err: unknown) => {
        // A visitor key the server no longer knows is forgotten on the way (authInterceptor): the state has
        // changed, and the load that follows is the one to show.
        if (load !== this.loads || untracked(this.state) !== state) {
          return;
        }
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
