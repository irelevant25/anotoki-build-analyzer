import { Component, computed, effect, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { TabItem, TabsComponent } from '@anotoki/lib/ui/tabs';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { titleCase } from '../../core/format';
import { GAME_TERMS, level } from '../../core/game-terms';
import { TranslationKey } from '../../core/i18n/en';
import { ErrorTextPipe, isSignInRefusal } from '../../core/i18n/error-text';
import { LocalDatePipe } from '../../core/i18n/local-date';
import { recommendationText } from '../../core/i18n/recommendation-words';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslatePluralPipe } from '../../core/i18n/translate-plural';
import { TranslationService } from '../../core/i18n/translation';
import { variationLabel } from '../../core/i18n/variation-words';
import { MetaStore } from '../../core/meta-store';
import { CharacterAnalysis, Evaluation, GameKey, Recommendation, Suggestion } from '../../core/models';
import { BuildDetail } from '../../shared/build-detail';
import { BUILD_TERMS } from '../../shared/build-terms';
import { Avatar } from '../../shared/char-avatar';
import { GradeBadge } from '../../shared/grade-badge';
import { ItemCard, ItemView } from '../../shared/item-card';
import { ScoreBar } from '../../shared/score-bar';
import { SlotLabelPipe, StatLabelPipe, StatValuePipe } from '../../shared/stat-pipes';
import { VisitorHero } from '../../shared/visitor-hero/visitor-hero';
import { VisitorNotice } from '../../shared/visitor-notice/visitor-notice';

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
 * build in hand), a tab a build (named by its variation, its score the tab's badge, the closest build marked, a
 * build the character's constellation does not reach locked and said so), what to improve, the gear it wears and
 * the better pieces the account already owns, the build itself (the site's own build sheet) and the stats. Drawn
 * with the anotoki family's kit - cards, tabs, badges, progress bars (the score bars), alerts, tooltips, icons -
 * around the site's own pieces: the grade badge and the item cards.
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
    GradeBadge,
    ItemCard,
    ScoreBar,
    SlotLabelPipe,
    StatLabelPipe,
    StatValuePipe,
    ErrorTextPipe,
    LocalDatePipe,
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
  protected readonly auth = inject(AuthService);

  readonly id = input.required<string>();
  readonly key = input.required<string>();

  protected readonly data = signal<CharacterAnalysis | null>(null);
  protected readonly selectedBuildId = signal<number | null>(null);
  protected readonly loading = signal(true);
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
   * score beside it as the kit's badge - heard after the name, "Main DPS, score 87" -; the build closest to the
   * character's setup marked by an icon and a filled badge, and heard so ("..., score 87, Closest match to your
   * current setup"); a build whose constellation the character does not reach locked, and heard so ("..., needs C2").
   */
  protected readonly buildTabs = computed<TabItem[]>(() => {
    const data = this.data();
    return (data?.evaluations ?? []).map((evaluation): TabItem => {
      const closest = evaluation.build.id === data?.best_build_id;
      const overall = evaluation.score.overall;
      // A build that could not be judged has no score: no badge (as the bare number was none before).
      const score = overall === null ? null : this.i18n.t('character.tabScore', { score: this.i18n.number(overall) });
      const heard = [
        score,
        closest ? this.i18n.t('character.closestMatch') : null,
        evaluation.build.applies ? null : this.i18n.t('character.needsRank', { rank: `${this.rankPrefix()}${evaluation.build.min_rank}` }),
      ].filter((part): part is string => !!part);
      return {
        id: String(evaluation.build.id),
        label: variationLabel(this.i18n, evaluation.build, this.rankPrefix()),
        icon: closest ? 'checkCircle' : evaluation.build.applies ? null : 'lock',
        badge: overall,
        badgeTone: closest ? 'primary' : 'neutral',
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

  protected readonly totals = computed(() => Object.entries(this.data()?.equipment.totals ?? {}).sort(([, a], [, b]) => b - a));

  /** "No builds for {name} yet. They appear once an editor imports them (on the [Builds] page).", cut where the link stands. */
  protected readonly noBuilds = computed(() => this.i18n.around('character.noBuilds', 'link', { name: this.data()?.character.name }));

  /** "Substats in [color] matter most for this build; ...", cut where the coloured word stands. */
  protected readonly legend = computed(() => this.i18n.around('character.legend', 'color'));

  constructor() {
    effect(() => {
      this.load(Number(this.id()), this.key(), null);
    });
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

  private load(accountId: number, key: string, buildId: number | null): void {
    this.loading.set(true);
    this.api.character(accountId, key, buildId).subscribe({
      next: (data) => {
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
