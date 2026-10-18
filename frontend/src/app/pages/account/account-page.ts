import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent, BadgeTone } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { DisclosureComponent, DisclosurePanelDirective } from '@anotoki/lib/ui/disclosure';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { SelectComponent, SelectOption, SwitchComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { LabelledGroupComponent } from '@anotoki/lib/ui/labelled-group';
import { LinkCardComponent } from '@anotoki/lib/ui/link-card';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { SegmentedComponent, SegmentedOption } from '@anotoki/lib/ui/segmented';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { ToastService } from '@anotoki/lib/ui/toast';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { GAME_LABELS, titleCase } from '../../core/format';
import { GAME_TERMS, UID_TERM, level } from '../../core/game-terms';
import { TranslationKey } from '../../core/i18n/en';
import { ErrorTextPipe, errorText, isSignInRefusal } from '../../core/i18n/error-text';
import { importRunError, importStatusText } from '../../core/i18n/import-words';
import { LocalDatePipe } from '../../core/i18n/local-date';
import { recommendationText } from '../../core/i18n/recommendation-words';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslatePluralPipe } from '../../core/i18n/translate-plural';
import { TranslationService, Worded } from '../../core/i18n/translation';
import { MetaStore } from '../../core/meta-store';
import { AccountDetail, CharacterOverview, ImportRun } from '../../core/models';
import { BUILD_TERMS } from '../../shared/build-terms';
import { Avatar } from '../../shared/char-avatar';
import { GradeBadge } from '../../shared/grade-badge';
import { ScoreBar } from '../../shared/score-bar';
import { VisitorHero } from '../../shared/visitor-hero/visitor-hero';
import { VisitorNotice } from '../../shared/visitor-notice/visitor-notice';

type SortKey = 'score-asc' | 'score-desc' | 'name' | 'level';

/** The orders the characters can be put in; a label is a key, worded where it is drawn. */
const SORTS: readonly { value: SortKey; label: TranslationKey }[] = [
  { value: 'score-asc', label: 'account.sortScoreAsc' },
  { value: 'score-desc', label: 'account.sortScoreDesc' },
  { value: 'level', label: 'account.sortLevel' },
  { value: 'name', label: 'account.sortName' },
];

/** Where an import's data came from, as a key. Anything that is not the showcase is a file, as it always was. */
const IMPORT_SOURCES = new Map<string, TranslationKey>([
  ['showcase', 'account.sourceShowcase'],
  ['file', 'account.sourceFile'],
]);
const IMPORT_SOURCE_FALLBACK: TranslationKey = 'account.sourceFile';

/** One line of the import history, worded: the run, where it came from, how it ended, and what came in - or why not. */
interface HistoryRow {
  run: ImportRun;
  /** A key: the template words it. */
  source: TranslationKey;
  status: string;
  /** The counts of what was imported, or a failed run's reason - `lang: 'en'` where that is the stored English sentence. */
  outcome: Worded;
}

/** How an import ended, as the kit's badge says it: done, done in part, failed. */
const STATUS_TONES: Record<string, BadgeTone> = { success: 'success', partial: 'warning', failed: 'danger' };

/**
 * One game account: its head (name, UID, level, last import; renaming it; updating it by UID or from a file), its
 * numbers, its characters - each the kit's link card (anotoki-lib 0.6.0: one link as a whole, its focus ring round
 * the card) with its grade, gear and first thing to improve - filtered (the element filter the kit's segmented
 * control, each element's colour its mark, the label beside it the kit's labelled group) and sorted, and its import
 * history (the kit's disclosure, its table made only while open). Drawn with the anotoki family's kit: the page
 * header, cards, fields, a switch, badges, alerts.
 */
@Component({
  selector: 'app-account-page',
  imports: [
    RouterLink,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    DisclosureComponent,
    DisclosurePanelDirective,
    EmptyStateComponent,
    IconComponent,
    LabelledGroupComponent,
    LinkCardComponent,
    PageHeaderComponent,
    SegmentedComponent,
    SelectComponent,
    SpinnerComponent,
    SwitchComponent,
    TextFieldComponent,
    Avatar,
    GradeBadge,
    ScoreBar,
    TranslatePipe,
    TranslatePluralPipe,
    LocalDatePipe,
    ErrorTextPipe,
    VisitorHero,
    VisitorNotice,
  ],
  templateUrl: './account-page.html',
  styleUrl: './account-page.scss',
})
export class AccountPage {
  private readonly api = inject(Api);
  private readonly meta = inject(MetaStore);
  private readonly toasts = inject(ToastService);
  private readonly i18n = inject(TranslationService);
  protected readonly auth = inject(AuthService);

  /** Route parameter (component input binding). */
  readonly id = input.required<string>();

  protected readonly account = signal<AccountDetail | null>(null);
  protected readonly imports = signal<ImportRun[]>([]);
  protected readonly loading = signal(true);
  /** Why the account could not be loaded: the failed request itself, worded where it is shown. */
  protected readonly error = signal<unknown>(null);
  /** Opened by somebody this account is not theirs to open - not signed in, no key that opens it: the ways in instead. */
  protected readonly signedOut = computed(() => isSignInRefusal(this.error()) && !this.auth.signedIn());

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
  protected readonly sorts = SORTS;
  /**
   * The games' own words, the same in every language and never keys: Artifacts / Relics, Adventure Rank /
   * Trailblaze Level, UID, "Lv. 90" - and the two parts of a build's score that the games name themselves
   * ("Main stats", "Substats").
   */
  protected readonly gameTerms = GAME_TERMS;
  protected readonly uidTerm = UID_TERM;
  protected readonly level = level;
  protected readonly buildTerms = BUILD_TERMS;

  protected readonly gameMeta = computed(() => {
    const account = this.account();
    return account ? this.meta.game(account.game) : null;
  });

  protected readonly elements = computed(() => {
    const present = new Set((this.account()?.characters ?? []).map((c) => c.element).filter((e): e is string => !!e));
    return (this.gameMeta()?.elements ?? []).filter((element) => present.has(element));
  });

  /**
   * The element filter's choices: all, then each element a character of the account has - by the game's name, its
   * colour the mark before it (`--el-mark-*`, styles.scss: 3:1 on the control in both themes), which the name says
   * in words.
   */
  protected readonly elementOptions = computed<SegmentedOption[]>(() => [
    { value: 'all', label: this.i18n.t('account.allElements') },
    ...this.elements().map((element) => ({
      value: element,
      label: titleCase(element),
      mark: `var(--el-mark-${element}, var(--anotoki-muted))`,
    })),
  ]);

  /** The orders, worded in the language on the page. */
  protected readonly sortOptions = computed<SelectOption[]>(() =>
    SORTS.map((option) => ({ value: option.value, label: this.i18n.t(option.label) })),
  );

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

  /**
   * The first thing each character's best build recommends, by character id - worded from its kind and
   * parameters in the language on the page. Where there is no kind to word it with (a newer server's, or
   * none at all) it is the server's English sentence, and `lang` says so.
   */
  protected readonly issues = computed(() => {
    const account = this.account();
    const issues = new Map<number, Worded>();
    for (const character of account?.characters ?? []) {
      const best = character.best;
      if (!account || !best || (!best.top_issue && !best.top_issue_kind)) {
        continue;
      }
      const issue = recommendationText(this.i18n, this.meta, account.game, { kind: best.top_issue_kind, params: best.top_issue_params, message: best.top_issue });
      if (issue.text !== '') {
        issues.set(character.id, issue);
      }
    }
    return issues;
  });

  /**
   * The import history, worded. A run that failed says why - from its code, or as the sentence stored with
   * it; whether it failed is its status, never its summary. Any other run says what came in: a count of
   * characters, and the game's own noun with its number after a colon ("Artifacts: 187").
   */
  protected readonly history = computed<HistoryRow[]>(() => {
    const account = this.account();
    if (!account) {
      return [];
    }
    const items = GAME_TERMS[account.game].items;
    return this.imports().map((run) => {
      const summary = run.summary;
      let outcome: Worded = { text: '', lang: null };
      if (run.status === 'failed') {
        outcome = importRunError(this.i18n, run);
      } else if (summary) {
        outcome = { text: this.i18n.plural('account.runSummary', summary.characters, { items, itemCount: this.i18n.number(summary.items) }), lang: null };
      }
      return {
        run,
        source: IMPORT_SOURCES.get(run.source) ?? IMPORT_SOURCE_FALLBACK,
        status: importStatusText(this.i18n, run.status),
        outcome,
      };
    });
  });

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
      error: (err: unknown) => {
        this.error.set(err);
        this.loading.set(false);
      },
    });
    this.api.accountImports(id).subscribe({ next: (runs) => this.imports.set(runs) });
  }

  protected saveName(event?: Event): void {
    event?.preventDefault();
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
      // A message takes a finished sentence: worded now, in the language the page is in.
      error: (err: unknown) => this.toasts.error(errorText(this.i18n, err)),
    });
  }

  /** One of the orders, as the sort select gives it. */
  protected setSort(value: string): void {
    if (SORTS.some((option) => option.value === value)) {
      this.sort.set(value as SortKey);
    }
  }

  /** An import's ending, as a badge's tone. */
  protected statusTone(status: string): BadgeTone {
    return STATUS_TONES[status] ?? 'neutral';
  }

  /** "C2" / "E2": the game's notation, with the game's own prefix where the metadata is missing. */
  protected rankLabel(c: CharacterOverview): string {
    return `${this.gameMeta()?.rank_prefix ?? this.fallbackTerms().rankPrefix}${c.rank}`;
  }

  /** "R1" / "S1". */
  protected refinement(rank: number): string {
    return `${this.gameMeta()?.refinement_prefix ?? this.fallbackTerms().refinementPrefix}${rank}`;
  }

  private fallbackTerms() {
    return GAME_TERMS[this.account()?.game ?? 'genshin'];
  }
}
