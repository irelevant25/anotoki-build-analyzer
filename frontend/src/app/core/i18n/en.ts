import builds from './en/builds.json';
import core from './en/core.json';
import imports from './en/import.json';
import shell from './en/shell.json';

/**
 * The list of keys, and the English of last resort.
 *
 * The words the pages show live in the database - edited on the Translations page - and arrive as a bundle
 * (TranslationService). This dictionary is compiled into the app for two things:
 *
 * 1. The keys. `TranslationKey` is derived from it, so a key that does not exist is a compile error in
 *    every template and class.
 *
 * 2. The English shown while no bundle is in memory - and only then. A new build runs on the old database
 *    until an admin presses Apply on the Sources page; the bundle route answers 503 meanwhile, and the
 *    pages must still be readable. Once a bundle has arrived, the bundle is what is shown: a key it has
 *    no string for appears on the page as the key itself. These strings never paper over a single missing
 *    one.
 *
 * So the two are kept in step, and a backend test holds them to it: the keys here are exactly the seeded
 * keys, and each seeded English string equals the one here. A new string is its key and English here plus
 * a migration with the key, its description, its English and its Slovak. There is no Slovak in the build.
 *
 * The dictionary is written in parts, one flat {"key": "English"} object each; a key lives in exactly one
 * part (en.spec.ts).
 *
 * A key is namespace.thing[.detail]. Game terms are never keys: they reach a sentence as a {placeholder}
 * or stand before a colon (core/game-terms.ts). A plural family is three keys - name.one, name.few,
 * name.other - asked for by its name, and inside it the number is {count}; English .few reads as .other.
 */
export const EN_PARTS = { core, shell, import: imports, builds } as const;

export const en = { ...core, ...shell, ...imports, ...builds };

/** A key of the site's own words. */
export type TranslationKey = keyof typeof en;

/** The name of a plural family: what stands before `.one` / `.few` / `.other` (typed from its `.other` key). */
export type PluralKey = {
  [Key in TranslationKey]: Key extends `${infer Family}.other` ? Family : never;
}[TranslationKey];

/** Whether a string is a key - for what arrives as a plain string (a route's title). */
export function isTranslationKey(value: string): value is TranslationKey {
  return Object.hasOwn(en, value);
}
