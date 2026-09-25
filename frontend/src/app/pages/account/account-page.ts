import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatExpansionModule } from '@angular/material/expansion';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { GAME_LABELS, titleCase } from '../../core/format';
import { MetaStore } from '../../core/meta-store';
import { AccountDetail, CharacterOverview, ImportRun } from '../../core/models';
import { Avatar } from '../../shared/char-avatar';
import { GradeBadge } from '../../shared/grade-badge';
import { ScoreBar } from '../../shared/score-bar';
import { TimeAgoPipe } from '../../shared/stat-pipes';

type SortKey = 'score-asc' | 'score-desc' | 'name' | 'level';

@Component({
  selector: 'app-account-page',
  imports: [
    FormsModule,
    RouterLink,
    MatButtonModule,
    MatButtonToggleModule,
    MatExpansionModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatSlideToggleModule,
    MatTooltipModule,
    Avatar,
    GradeBadge,
    ScoreBar,
    TimeAgoPipe,
  ],
  templateUrl: './account-page.html',
  styleUrl: './account-page.scss',
})
export class AccountPage {
  private readonly api = inject(Api);
  private readonly meta = inject(MetaStore);
  private readonly snackBar = inject(MatSnackBar);

  /** Route parameter (component input binding). */
  readonly id = input.required<string>();

  protected readonly account = signal<AccountDetail | null>(null);
  protected readonly imports = signal<ImportRun[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly search = signal('');
  protected readonly element = signal<string>('all');
  protected readonly sort = signal<SortKey>('score-asc');
  protected readonly onlyWithBuilds = signal(false);
  /** Characters with nothing equipped are usually just unbuilt; they would otherwise top "needs work". */
  protected readonly hideUnbuilt = signal(true);
  protected readonly renaming = signal(false);
  protected readonly newName = signal('');

  protected readonly gameLabels = GAME_LABELS;
  protected readonly titleCase = titleCase;

  protected readonly gameMeta = computed(() => {
    const account = this.account();
    return account ? this.meta.game(account.game) : null;
  });

  protected readonly elements = computed(() => {
    const present = new Set((this.account()?.characters ?? []).map((c) => c.element).filter((e): e is string => !!e));
    return (this.gameMeta()?.elements ?? []).filter((element) => present.has(element));
  });

  protected readonly characters = computed(() => {
    const query = this.search().trim().toLowerCase();
    const element = this.element();
    let list = (this.account()?.characters ?? []).filter(
      (c) =>
        (!query || c.name.toLowerCase().includes(query)) &&
        (element === 'all' || c.element === element) &&
        (!this.onlyWithBuilds() || c.builds_count > 0) &&
        (!this.hideUnbuilt() || c.items_count > 0),
    );

    const score = (c: CharacterOverview) => c.best?.overall ?? -1;
    // Characters without builds or without any equipment go last; the built ones needing most work come first.
    const last = (c: CharacterOverview) => (score(c) < 0 || c.items_count === 0 ? 1 : 0);
    switch (this.sort()) {
      case 'score-asc':
        list = [...list].sort((a, b) => last(a) - last(b) || score(a) - score(b));
        break;
      case 'score-desc':
        list = [...list].sort((a, b) => score(b) - score(a));
        break;
      case 'name':
        list = [...list].sort((a, b) => a.name.localeCompare(b.name));
        break;
      case 'level':
        list = [...list].sort((a, b) => b.level - a.level || (b.rarity ?? 0) - (a.rarity ?? 0));
        break;
    }
    return list;
  });

  /** Average over characters that actually wear something. */
  protected readonly averageScore = computed(() => {
    const scores = (this.account()?.characters ?? [])
      .filter((c) => c.items_count > 0)
      .map((c) => c.best?.overall)
      .filter((s): s is number => typeof s === 'number');
    return scores.length ? Math.round(scores.reduce((a, b) => a + b, 0) / scores.length) : null;
  });

  protected readonly withoutBuilds = computed(() => (this.account()?.characters ?? []).filter((c) => c.builds_count === 0).length);

  protected readonly unbuiltCount = computed(() => (this.account()?.characters ?? []).filter((c) => c.items_count === 0).length);

  constructor() {
    effect(() => {
      const id = Number(this.id());
      this.load(id);
    });
  }

  protected load(id: number): void {
    this.loading.set(true);
    this.api.account(id).subscribe({
      next: (account) => {
        this.account.set(account);
        this.newName.set(account.nickname ?? '');
        this.error.set(null);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(errorMessage(err));
        this.loading.set(false);
      },
    });
    this.api.accountImports(id).subscribe({ next: (runs) => this.imports.set(runs) });
  }

  protected saveName(): void {
    const account = this.account();
    const name = this.newName().trim();
    if (!account || !name) {
      return;
    }
    this.api.renameAccount(account.id, name).subscribe({
      next: (updated) => {
        this.account.set({ ...account, nickname: updated.nickname });
        this.renaming.set(false);
      },
      error: (err) => this.snackBar.open(errorMessage(err), 'Close'),
    });
  }

  protected rankLabel(c: CharacterOverview): string {
    return `${this.gameMeta()?.rank_prefix ?? 'C'}${c.rank}`;
  }

  protected refinement(level: number): string {
    return `${this.gameMeta()?.refinement_prefix ?? 'R'}${level}`;
  }

  protected sourceLabel(run: ImportRun): string {
    return run.source === 'enka' ? 'Enka.Network (UID)' : (run.summary?.tool ?? run.source);
  }
}
