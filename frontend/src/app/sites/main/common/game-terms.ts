import { GameKey, SetOption } from '../../../api/models';

/**
 * The game's own words that the frontend writes itself. They are the games' terms, the same in every
 * language: never translated, never declined, never a translation key and never a row of the database.
 *
 * A term reaches a sentence as a {placeholder} - `'dashboard.items' | translate: { items: terms.items }` -
 * or stands by itself as a label, before a colon ("Artifacts: 187"). So every term here is written the
 * way it can open a line: with its capital. Nothing lower-cases one or glues an "s" onto it; the plural
 * is its own entry.
 *
 * What /api/meta sends is the same kind of word and needs nothing from here: stat, slot and talent names,
 * `weapon_label` ("Weapon"), the rank and refinement prefixes ("C", "R").
 * Game names are GAME_LABELS (shared/format.ts). Game values keep the game's notation -
 * MetaStore.formatStat(), the statValue pipe.
 *
 * Terms that stand inside a sentence as they are - main stat, substats, set bonus, Character Showcase, GOOD - are
 * written in the strings themselves, in every language.
 */
export interface GameTerms {
  /** What a character wears, as a group: "Artifacts". */
  items: string;
  /** One of them: "Artifact". */
  item: string;
  /** "Weapons". */
  weapons: string;
  /** "Weapon" - what /api/meta sends as `weapon_label`, for where the metadata is missing. */
  weapon: string;
  /** What raises a character's rank: "Constellations". */
  ranks: string;
  /** "Talents". */
  talents: string;
  /** The account's level in full: "Adventure Rank". */
  accountLevel: string;
  /** The same, short: "AR". */
  accountLevelShort: string;
  /** The prefix of a character's rank where the metadata is missing: "C". */
  rankPrefix: string;
  /** The prefix of a weapon's refinement where the metadata is missing: "R". */
  refinementPrefix: string;
}

export const GAME_TERMS: Record<GameKey, GameTerms> = {
  genshin: {
    items: 'Artifacts',
    item: 'Artifact',
    weapons: 'Weapons',
    weapon: 'Weapon',
    ranks: 'Constellations',
    talents: 'Talents',
    accountLevel: 'Adventure Rank',
    accountLevelShort: 'AR',
    rankPrefix: 'C',
    refinementPrefix: 'R',
  },
};

/** The kinds of set a build recommends, as headings: what `SetOption.kind` and a set group's `kind` name. */
export const SET_KIND_TERMS: Record<SetOption['kind'], string> = {
  artifact: 'Artifact sets',
};

/** A level, the way the game abbreviates it: "Lv. 90". */
export const LEVEL_TERM = 'Lv.';

/** Pieces of a set, the way players write a set bonus: "4pc", "2pc". */
export const PIECES_TERM = 'pc';

/** A player's number in either game. */
export const UID_TERM = 'UID';

/** "4pc" - a number of pieces of one set. */
export function pieces(count: number): string {
  return `${count}${PIECES_TERM}`;
}

/** "Lv. 90". */
export function level(value: number): string {
  return `${LEVEL_TERM} ${value}`;
}
