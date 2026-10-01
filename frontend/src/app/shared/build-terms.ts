/**
 * The game's words the build pages write as a label of their own - a score part, a heading, what a
 * recommendation is about. They are the games' terms, the same in every language: never translated, never
 * a translation key, never a row of the database (core/game-terms.ts says how terms are used, and holds
 * the others). Inside a sentence the same words are written in the string itself.
 */
export const BUILD_TERMS = {
  /** The score part for the sets a character wears. */
  setBonuses: 'Set bonuses',
  /** The score part and the build's heading. */
  mainStats: 'Main stats',
  /** What a recommendation about one piece's main stat is filed under. */
  mainStat: 'Main stat',
  /** The score part, and what a recommendation about substats is filed under. */
  substats: 'Substats',
} as const;
