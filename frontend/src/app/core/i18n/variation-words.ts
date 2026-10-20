import { TranslationKey } from './en';
import { TranslationService } from './translation';

/**
 * What a build is for, as a key: its role (the server's Game::roles()). A role a newer server adds reads "Build".
 */
const ROLE_KEYS = new Map<string, TranslationKey>([
  ['main_dps', 'builds.role.main_dps'],
  ['sub_dps', 'builds.role.sub_dps'],
  ['support', 'builds.role.support'],
]);
const ROLE_FALLBACK: TranslationKey = 'builds.role.unknown';

/** A build's variation, as the API sends it with every build and evaluation. */
export interface Variation {
  role: string;
  qualifier: string;
  min_rank: number;
}

/** A build's role in the reader's language. Read inside a computed(): it follows the language. */
export function roleLabel(i18n: TranslationService, role: string): string {
  return i18n.t(ROLE_KEYS.get(role) ?? ROLE_FALLBACK);
}

/**
 * The constellation a build needs, or a stat target holds from, in the game's notation - "C2+" -, none from the
 * start (0). The game's rank prefix comes from the metadata (MetaStore: rank_prefix).
 */
export function rankMark(rankPrefix: string, rank: number): string | null {
  return rank > 0 ? `${rankPrefix}${rank}+` : null;
}

/**
 * A build's variation as a tab, a heading or a line says it: its role in the reader's language, its qualifier as the
 * build has it (the build's own word, often a game term: "Melt"), the constellation it needs - "Main DPS · Melt ·
 * C2+". Read inside a computed().
 */
export function variationLabel(i18n: TranslationService, variation: Variation, rankPrefix: string): string {
  return [roleLabel(i18n, variation.role), variation.qualifier, rankMark(rankPrefix, variation.min_rank)].filter((part): part is string => !!part).join(' · ');
}
