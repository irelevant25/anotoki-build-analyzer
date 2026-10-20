import { MetaStore } from '../meta-store';
import { GameKey, KindParams } from '../models';
import { TranslationKey } from './en';
import { TranslationParams, TranslationService, Worded } from './translation';

/**
 * What a build recommends, in the reader's language - for the character page's list and the account
 * page's top issue.
 *
 * The server says each recommendation twice: as a kind with its parameters (BuildEvaluator::KINDS), and as
 * the English sentence in `message`. Here is the one table from a kind to its key and to the parameters
 * its sentence needs. Every parameter is the game's own word or value - a name, a label, a level, a rank -
 * and goes into the sentence as it came; nothing here translates or declines one.
 *
 * Three things the page adds:
 *   - the sets kinds' `{best}` is the option with its "(any N)" when `best_choose` is a number: a whole
 *     phrase from the plural family `buildDetail.optionAny`, the one the build detail shows beside a set;
 *   - `target_below`'s two numbers are written by MetaStore.formatStat() with `stat_key` - the notation
 *     the page's own targets have ("62.0%", "4,780");
 *   - a kind this table does not know (a newer server's), a row without one (a top issue from before kinds
 *     were sent) or one that lacks a parameter is shown as its stored English `message`, marked `lang="en"`
 *     - never worded with a key made from the kind, never a sentence with a hole in it.
 *
 * The keys are also listed in dynamic-keys.ts.
 */

interface KindWording {
  key: TranslationKey;
  /** The parameters the sentence is filled with: each must be there, a string or a number. */
  needs: readonly string[];
}

const KINDS = new Map<string, KindWording>([
  ['weapon_none', { key: 'recommendation.weapon_none', needs: ['weapon_label', 'options'] }],
  ['weapon_level', { key: 'recommendation.weapon_level', needs: ['name', 'level', 'max_level'] }],
  ['weapon_unlisted', { key: 'recommendation.weapon_unlisted', needs: ['name', 'weapon_label', 'options'] }],
  ['weapon_unlisted_owned', { key: 'recommendation.weapon_unlisted_owned', needs: ['name', 'weapon_label', 'options', 'owned', 'refinement'] }],
  ['weapon_unlisted_owned_on', { key: 'recommendation.weapon_unlisted_owned_on', needs: ['name', 'weapon_label', 'options', 'owned', 'refinement', 'character'] }],
  ['weapon_better_owned', { key: 'recommendation.weapon_better_owned', needs: ['owned', 'refinement', 'owned_rank', 'name', 'rank'] }],
  ['weapon_better_owned_on', { key: 'recommendation.weapon_better_owned_on', needs: ['owned', 'refinement', 'owned_rank', 'name', 'rank', 'character'] }],
  ['weapon_low_rank', { key: 'recommendation.weapon_low_rank', needs: ['name', 'rank', 'options'] }],
  ['sets_missing', { key: 'recommendation.sets_missing', needs: ['set_label', 'best', 'current'] }],
  ['sets_missing_empty', { key: 'recommendation.sets_missing_empty', needs: ['set_label', 'best'] }],
  ['sets_lower_option', { key: 'recommendation.sets_lower_option', needs: ['set_label', 'current', 'rank', 'best'] }],
  ['main_stat_missing', { key: 'recommendation.main_stat_missing', needs: ['slot_label', 'wanted'] }],
  ['main_stat_wrong', { key: 'recommendation.main_stat_wrong', needs: ['slot_label', 'stat', 'wanted'] }],
  ['item_level', { key: 'recommendation.item_level', needs: ['slot_label', 'level', 'max_level'] }],
  ['substats_weak', { key: 'recommendation.substats_weak', needs: ['slot_label', 'percent', 'stats'] }],
  ['target_below', { key: 'recommendation.target_below', needs: ['stat', 'stat_key', 'current', 'min'] }],
  ['talent_level', { key: 'recommendation.talent_level', needs: ['talent', 'level'] }],
  ['character_level', { key: 'recommendation.character_level', needs: ['level'] }],
]);

/** The kinds this file words: the tails of `recommendation.` (dynamic-keys.ts), the keys of the server's KINDS. */
export const RECOMMENDATION_KINDS: readonly string[] = [...KINDS.keys()];

/** What wording needs of the translation service, and of the metadata. */
export type RecommendationWords = Pick<TranslationService, 't' | 'plural' | 'storedEnglishLang'>;
export type StatNotation = Pick<MetaStore, 'formatStat'>;

/** A recommendation as the API sends it - or the account page's top issue: `top_issue_kind`, `top_issue_params`, `top_issue`. */
export interface SaidRecommendation {
  kind?: string | null;
  params?: KindParams | null;
  /** The same in English, as the server worded it. */
  message: string | null;
}

/**
 * The sentence for a recommendation, in the language on the page - or its stored English, saying so
 * (`lang: 'en'`), where there is nothing to word it with.
 */
export function recommendationText(i18n: RecommendationWords, meta: StatNotation, game: GameKey, recommendation: SaidRecommendation): Worded {
  const kind = recommendation.kind;
  const wording = typeof kind === 'string' ? KINDS.get(kind) : undefined;
  const given = recommendation.params;
  if (!wording || typeof given !== 'object' || given === null || !wording.needs.every((name) => said(given, name))) {
    return stored(i18n, recommendation.message);
  }

  const params: TranslationParams = {};
  for (const name of wording.needs) {
    params[name] = given[name];
  }

  if (kind === 'target_below') {
    const { stat_key, current, min } = given;
    if (typeof stat_key !== 'string' || typeof current !== 'number' || typeof min !== 'number') {
      return stored(i18n, recommendation.message);
    }
    params['current'] = meta.formatStat(game, stat_key, current);
    params['min'] = meta.formatStat(game, stat_key, min);
  }
  if (typeof given['best'] === 'string' && Object.hasOwn(params, 'best')) {
    params['best'] = setOptionText(i18n, given['best'], given['best_choose']);
  }

  return { text: i18n.t(wording.key, params), lang: null };
}

/**
 * A set option as a phrase: its pieces ("2pc A + 2pc B") and, when only some of them are needed, the
 * "(any N)" after a space - `best` and `best_choose` of a sets recommendation.
 */
export function setOptionText(i18n: Pick<TranslationService, 'plural'>, pieces: string, choose: unknown): string {
  return typeof choose === 'number' && Number.isFinite(choose) ? `${pieces} ${i18n.plural('buildDetail.optionAny', choose)}` : pieces;
}

/** Whether a parameter is there to be said: the object's own, a string that is not empty or a number. */
function said(params: KindParams, name: string): boolean {
  if (!Object.hasOwn(params, name)) {
    return false;
  }
  const value = params[name];
  return typeof value === 'number' ? Number.isFinite(value) : typeof value === 'string' && value !== '';
}

/** The server's own English sentence - data, shown as stored. */
function stored(i18n: RecommendationWords, message: string | null): Worded {
  return { text: message ?? '', lang: i18n.storedEnglishLang() };
}
