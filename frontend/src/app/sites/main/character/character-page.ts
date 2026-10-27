import { HttpErrorResponse } from '@angular/common/http';
import { Component, DestroyRef, computed, effect, inject, input, signal, untracked } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { TabItem, TabsComponent } from '@anotoki/lib/ui/tabs';
import { ToastService } from '@anotoki/lib/ui/toast';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { AnotokiAgoPipe } from '@anotoki/lib/ui/dates';
import { Api } from '../../../api/api';
import { AuthService } from '../../../shared/auth';
import { titleCase } from '../../../shared/format';
import { GAME_TERMS, level } from '../common/game-terms';
import { TranslationKey } from '../../../shared/i18n/en';
import { ErrorTextPipe, errorText, isSignInRefusal } from '../../../shared/i18n/error-text';
import { recommendationText } from '../../../shared/i18n/recommendation-words';
import { TranslatePipe } from '../../../shared/i18n/translate';
import { TranslatePluralPipe } from '../../../shared/i18n/translate-plural';
import { TranslationService } from '../../../shared/i18n/translation';
import { variationLabel } from '../../../shared/i18n/variation-words';
import { MetaStore } from '../../../shared/meta-store';
import { CharacterAnalysis, Evaluation, GameKey, Recommendation, Suggestion } from '../../../api/models';
import { BuildDetail } from '../common/build-detail';
import { BUILD_TERMS } from '../common/build-terms';
import { Avatar } from '../../../shared/char-avatar';
import { FavouriteToggle } from '../common/favourite-toggle/favourite-toggle';
import { GradeBadge } from '../common/grade-badge';
import { ItemCard, ItemView } from '../common/item-card';
import { ScoreBar } from '../common/score-bar';
import { SlotLabelPipe, StatLabelPipe, StatValuePipe } from '../../../shared/stat-pipes';
import { VisitorHero } from '../common/visitor-hero/visitor-hero';
import { VisitorNotice } from '../common/visitor-notice/visitor-notice';
import { Subscription } from 'rxjs';

/**
 * What a recommendation is filed under, where that is the site's own word. The other categories are the
 * game's words and come from the terms: the weapon's label, "Talents" / "Traces", "Main stat", "Substats".
 * A category a newer server adds is "Other".
 */
const CATEGORY_KEYS = new Map<string, TranslationKey>([
  ['sets', 'character.category.sets'],
  ['targets', 'character.category.targets'],
  ['levels', 'character.category.levels'],
]);
const CATEGORY_FALLBACK: TranslationKey = 'character.category.unknown';

/**
 * Why a piece from the inventory is suggested. "better_substats" is not here: it carries a number and is
 * the plural family `character.reason.betterSubstats`. A reason a newer server adds reads "Better for this
 * build".
 */
const REASON_KEYS = new Map<string, TranslationKey>([
  ['completes_set', 'character.reason.completesSet'],
  ['fixes_main_stat', 'character.reason.fixesMainStat'],
]);
const REASON_FALLBACK: TranslationKey = 'character.reason.unknown';

/** A recommendation's mark by how much it matters: the kit's alert for what matters, its info for the rest. */
const SEVERITY_ICONS = new Map<string, string>([
  ['high', 'alert'],
  ['medium', 'alert'],
  ['low', 'info'],
]);

/**
 * One character of an account, against the recommended builds: its head (the game's facts, the grade against the
 * build in hand), a tab a build (named by its variation, its score the tab's badge, the build the player chose and
 * the closest build marked, a build the character's constellation does not reach locked and said so), the choice of
 * the build the account page grades the character by ("Use this build", "Choose automatically"), what to improve,
 * the gear it wears and the better pieces the account already owns, the build itself (the site's own build sheet)
 * and the stats. Drawn with the anotoki family's kit - cards, tabs, badges, buttons, progress bars (the score bars),
 * alerts, toasts, tooltips, icons - around the site's own pieces: the grade badge and the item cards.
 */
@Component({
  selector: 'app-character-page',
  imports: [
    RouterLink,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    IconComponent,
    SpinnerComponent,
    TabsComponent,
    TooltipDirective,
    Avatar,
    BuildDetail,
    FavouriteToggle,
    GradeBadge,
    ItemCard,
    ScoreBar,
    SlotLabelPipe,
    StatLabelPipe,
    StatValuePipe,
    ErrorTextPipe,
    AnotokiAgoPipe,
    TranslatePipe,
    TranslatePluralPipe,
    VisitorHero,
    VisitorNotice,
  ],
  templateUrl: './character-page.html',
  styleUrl: './character-page.scss',
})
export class CharacterPage {
  private readonly api = inject(Api);
  private readonly meta = inject(MetaStore);
  private readonly i18n = inject(TranslationService);
  private readonly toasts = inject(ToastService);
  protected readonly auth = inject(AuthService);

  readonly id = input.required<string>();
  readonly key = input.required<string>();

  protected readonly data = signal<CharacterAnalysis | null>(null);
  /** The build in hand: its tab is the open one. */
  protected readonly selectedBuildId = signal<number | null>(null);
  /** A choice of build on its way to the server: its buttons wait. */
  protected readonly choosing = signal(false);
  /** How many choices were saved: an analysis asked for before the latest one keeps that choice, newer than its own word. */
  private choicesSaved = 0;
  /** The character's star on its way to the server: it waits. */
  protected readonly starring = signal(false);
  /** How many stars were saved: an analysis asked for before the latest one keeps that star, as choicesSaved its choice. */
  private starsSaved = 0;
  protected readonly loading = signal(true);
  /** The analysis on its way: the newest asked wins (load()). */
  private request: Subscription | null = null;
  /** The failed request itself: the template words it, in the language on the page. */
  protected readonly error = signal<unknown>(null);
  /** Opened by somebody this account is not theirs to open - not signed in, no key that opens it: the ways in instead. */
  protected readonly signedOut = computed(() => isSignInRefusal(this.error()) && !this.auth.signedIn());

  protected readonly titleCase = titleCase;
  /** "Lv. 90" - the game's notation. */
  protected readonly level = level;
  protected readonly buildTerms = BUILD_TERMS;

  protected readonly game = computed<GameKey>(() => this.data()?.account.game ?? 'genshin');
  protected readonly gameMeta = computed(() => this.meta.game(this.game()));
  /** The game's own words for this game: "Artifacts", "Constellations", "Talents". */
  protected readonly terms = computed(() => GAME_TERMS[this.game()]);
  /** "Weapon", as the metadata says it. */
  protected readonly weaponTerm = computed(() => this.gameMeta()?.weapon_label ?? this.terms().weapon);
  protected readonly rankPrefix = computed(() => this.gameMeta()?.rank_prefix ?? this.terms().rankPrefix);
  protected readonly refinementPrefix = computed(() => this.gameMeta()?.refinement_prefix ?? this.terms().refinementPrefix);

  protected readonly evaluation = computed<Evaluation | null>(() => {
    const data = this.data();
    if (!data) {
      return null;
    }
    return data.evaluations.find((e) => e.build.id === this.selectedBuildId()) ?? data.evaluations[0] ?? null;
  });

  /**
   * A tab a build (the kit's tabs, anotoki-lib 0.6.0's icon and badge): its variation ("Main DPS · Melt · C2+"), its
   * score beside it as the kit's badge - heard after the name, "Main DPS, score 87" -; the build the player chose
   * marked by a person and a green badge, the build closest to the character's setup by a check and a filled badge,
   * and heard so ("..., score 87, Selected, Closest match to your current setup"); a build whose constellation the
   * character does not reach locked, and heard so ("..., needs C2"). One icon a tab: the choice's before the closest
   * match's, either before the lock.
   */
  protected readonly buildTabs = computed<TabItem[]>(() => {
    const data = this.data();
    return (data?.evaluations ?? []).map((evaluation): TabItem => {
      const chosen = evaluation.build.id === data?.chosen_build_id;
      const closest = evaluation.build.id === data?.best_build_id;
      const overall = evaluation.score.overall;
      // A build that could not be judged has no score: no badge (as the bare number was none before).
      const score = overall === null ? null : this.i18n.t('character.tabScore', { score: this.i18n.number(overall) });
      const heard = [
        score,
        chosen ? this.i18n.t('character.chosen') : null,
        closest ? this.i18n.t('character.closestMatch') : null,
        evaluation.build.applies ? null : this.i18n.t('character.needsRank', { rank: `${this.rankPrefix()}${evaluation.build.min_rank}` }),
      ].filter((part): part is string => !!part);
      return {
        id: String(evaluation.build.id),
        label: variationLabel(this.i18n, evaluation.build, this.rankPrefix()),
        icon: chosen ? 'user' : closest ? 'checkCircle' : evaluation.build.applies ? null : 'lock',
        badge: overall,
        badgeTone: chosen ? 'success' : closest ? 'primary' : 'neutral',
        badgeLabel: heard.length > 0 ? heard.join(', ') : null,
      };
    });
  });

  /** The build in hand, as its variation reads ("Main DPS · Melt · C2+"). */
  protected readonly variation = computed(() => {
    const evaluation = this.evaluation();
    return evaluation ? variationLabel(this.i18n, evaluation.build, this.rankPrefix()) : '';
  });

  /** "This build needs C2; Hu Tao is C0." - above a build whose constellation the character does not reach. */
  protected readonly notReached = computed(() => {
    const evaluation = this.evaluation();
    const character = this.data()?.character;
    if (!evaluation || !character || evaluation.build.applies) {
      return null;
    }
    const prefix = this.rankPrefix();
    return this.i18n.t('character.rankNotReached', { rank: `${prefix}${evaluation.build.min_rank}`, name: character.name, current: `${prefix}${character.rank}` });
  });

  /** The tab of the build in hand. */
  protected readonly activeTab = computed(() => String(this.evaluation()?.build.id ?? ''));

  protected readonly build = computed(() => this.data()?.builds.find((b) => b.id === this.evaluation()?.build.id) ?? null);

  protected readonly slots = computed(() => this.gameMeta()?.slots ?? []);

  protected readonly items = computed(() => {
    const evaluation = this.evaluation();
    return this.slots().map((slot) => ({ slot, item: (evaluation?.items[slot.key] ?? null) as ItemView | null }));
  });

  /**
   * What the build recommends, worded here: the sentence from the recommendation's kind and parameters
   * (a kind nobody knows shows its stored English, marked as such), and what it is filed under.
   */
  protected readonly recommendations = computed(() =>
    (this.evaluation()?.recommendations ?? []).map((recommendation) => ({
      recommendation,
      category: this.categoryLabel(recommendation),
      text: recommendationText(this.i18n, this.meta, this.game(), recommendation),
    })),
  );

  protected readonly suggestions = computed(() =>
    (this.data()?.suggestions ?? []).map((suggestion) => ({
      suggestion,
      reason: this.suggestionReason(suggestion),
      view: { ...suggestion.item, slot: suggestion.slot } satisfies ItemView,
    })),
  );

  protected readonly talents = computed(() => {
    const talents = this.data()?.character.talents;
    if (!talents) {
      return [];
    }
    const bonus = (talents['bonus'] ?? {}) as Record<string, number>;
    return Object.entries(talents)
      .filter(([key]) => key !== 'bonus')
      .map(([key, level]) => ({
        label: this.meta.talentLabel(this.game(), key),
        level: level as number,
        bonus: bonus[key] ?? 0,
      }));
  });

  protected readonly finalStats = computed(() => {
    const stats = this.data()?.character.final_stats;
    return stats ? Object.entries(stats).filter(([, value]) => value !== 0) : [];
  });

  /** The sheet's totals worked out from the gear, for a character without the game's own. */
  protected readonly computedStats = computed(() => {
    const stats = this.data()?.character.computed_stats;
    return stats ? Object.entries(stats).filter(([, value]) => value !== 0) : [];
  });

  protected readonly totals = computed(() => Object.entries(this.data()?.equipment.totals ?? {}).sort(([, a], [, b]) => b - a));

  /** "No builds for {name} yet. They appear once an editor imports them (on the [Builds] page).", cut where the link stands. */
  protected readonly noBuilds = computed(() => this.i18n.around('character.noBuilds', 'link', { name: this.data()?.character.name }));

  /** "Substats in [color] matter most for this build; ...", cut where the coloured word stands. */
  protected readonly legend = computed(() => this.i18n.around('character.legend', 'color'));

  constructor() {
    // The address alone asks for the analysis: whatever load() reads - the page's state, when an answer comes at
    // once - is no reason to ask again.
    effect(() => {
      const id = Number(this.id());
      const key = this.key();
      untracked(() => this.load(id, key, null));
    });
    inject(DestroyRef).onDestroy(() => this.request?.unsubscribe());
  }

  /** A build's tab chosen (by its build's id). */
  protected selectBuild(id: string): void {
    const evaluation = this.data()?.evaluations.find((e) => String(e.build.id) === id);
    if (!evaluation) {
      return;
    }
    this.selectedBuildId.set(evaluation.build.id);
    // Suggestions are computed per build on the server; refresh them when the inventory is known.
    if (this.data()?.has_full_inventory && this.data()?.focus_build_id !== evaluation.build.id) {
      this.load(Number(this.id()), this.key(), evaluation.build.id);
    }
  }

  /**
   * The build the account page grades the character by: one of its builds ("Use this build"), or null - the closest
   * match again ("Choose automatically"). Said in a toast; a refusal too, and a build that is gone by now (422) has the
   * analysis asked again, so the tabs are what the server has.
   */
  protected choose(buildId: number | null): void {
    const data = this.data();
    if (!data || this.choosing()) {
      return;
    }
    this.choosing.set(true);
    this.api.chooseBuild(data.account.id, data.character.key, buildId).subscribe({
      next: (answer) => {
        this.choosing.set(false);
        this.choicesSaved++;
        const current = this.data();
        if (current?.character.key === data.character.key && current.account.id === data.account.id) {
          this.data.set({ ...current, chosen_build_id: answer.chosen_build_id });
        }
        this.toasts.success(this.i18n.t(answer.chosen_build_id === null ? 'character.automaticSaved' : 'character.chosenSaved', { name: data.character.name }));
      },
      // A message takes a finished sentence: worded now, in the language the page is in.
      error: (err: unknown) => {
        this.choosing.set(false);
        this.toasts.error(errorText(this.i18n, err));
        if (err instanceof HttpErrorResponse && err.status === 422 && this.data() === data) {
          this.load(data.account.id, data.character.key, this.selectedBuildId());
        }
      },
    });
  }

  /**
   * The character starred - one the player is building, listed first on the account page - or its star taken away:
   * saved first, then shown. A refusal is said in a toast.
   */
  protected star(favourite: boolean): void {
    const data = this.data();
    if (!data || this.starring()) {
      return;
    }
    this.starring.set(true);
    this.api.markFavourite(data.account.id, data.character.key, favourite).subscribe({
      next: (answer) => {
        this.starring.set(false);
        this.starsSaved++;
        const current = this.data();
        if (current?.character.key === data.character.key && current.account.id === data.account.id) {
          this.data.set({ ...current, character: { ...current.character, favourite: answer.favourite } });
        }
      },
      // A message takes a finished sentence: worded now, in the language the page is in.
      error: (err: unknown) => {
        this.starring.set(false);
        this.toasts.error(errorText(this.i18n, err));
      },
    });
  }

  protected severityIcon(recommendation: Recommendation): string {
    return SEVERITY_ICONS.get(recommendation.severity) ?? 'info';
  }

  /** Read inside a computed(): a category's word follows the language, a game term stays what it is. */
  private categoryLabel(recommendation: Recommendation): string {
    const category: string = recommendation.category;
    switch (category) {
      case 'weapon':
        return this.weaponTerm();
      case 'talents':
        return this.terms().talents;
      case 'main_stats':
        return BUILD_TERMS.mainStat;
      case 'substats':
        return BUILD_TERMS.substats;
      default:
        return this.i18n.t(CATEGORY_KEYS.get(category) ?? CATEGORY_FALLBACK);
    }
  }

  /** Read inside a computed(), like categoryLabel(). */
  private suggestionReason(suggestion: Suggestion): string {
    const reason: string = suggestion.reason;
    if (reason === 'better_substats') {
      return this.i18n.plural('character.reason.betterSubstats', suggestion.gain);
    }
    return this.i18n.t(REASON_KEYS.get(reason) ?? REASON_FALLBACK);
  }

  /**
   * The analysis asked for. One still on its way - another character's, or another build's - is dropped (its request
   * cancelled): a late answer never stands for the page's address, now that a teammate's link leads from one character
   * to the next on this same page.
   */
  private load(accountId: number, key: string, buildId: number | null): void {
    this.request?.unsubscribe();
    this.loading.set(true);
    const choicesSaved = this.choicesSaved;
    const starsSaved = this.starsSaved;
    this.request = this.api.character(accountId, key, buildId).subscribe({
      next: (answer) => {
        // A choice saved while this was on its way (a tab opened, then "Use this build" at once) is newer than the
        // choice the answer read: the page keeps it - and a star likewise.
        const current = this.data();
        const same = current?.account.id === answer.account.id && current.character.key === answer.character.key;
        let data = answer;
        if (same && choicesSaved !== this.choicesSaved) {
          data = { ...data, chosen_build_id: current.chosen_build_id };
        }
        if (same && starsSaved !== this.starsSaved) {
          data = { ...data, character: { ...data.character, favourite: current.character.favourite } };
        }
        this.data.set(data);
        this.selectedBuildId.set(data.focus_build_id ?? data.best_build_id);
        this.error.set(null);
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err);
        this.loading.set(false);
      },
    });
  }
}
