import { DYNAMIC_KEYS } from './dynamic-keys';
import { EN_PARTS, en, isTranslationKey } from './en';

/**
 * The namespaces a key may begin with. Never "changelog" or "pasted": the app has file names of that shape.
 * And never "anotoki": the library's own words (the language button's name among them) are its own keys.
 */
const NAMESPACES = [
  'common',
  'nav',
  'toolbar',
  'theme',
  'title',
  'error',
  'feedback',
  'dashboard',
  'importWizard',
  'importGuide',
  'import',
  'account',
  'builds',
  'character',
  'recommendation',
  'buildDetail',
  'item',
  'whatsNew',
  'time',
  'stepper',
  'confirm',
  'topbar',
  'home',
  'visitor',
  'data',
  'brand',
  'footer',
  'legal',
];

/** A key: namespace.thing[.detail] - the same shape the backend's key scan reads. */
const KEY = /^[a-z][A-Za-z0-9]*(\.[A-Za-z0-9][A-Za-z0-9_-]*)+$/;
/** A placeholder - the same expression as the server's check. */
const PLACEHOLDER = /\{([A-Za-z][A-Za-z0-9_]*)\}/g;
const FORMS = ['one', 'few', 'other'];

const keys = Object.keys(en);
const placeholders = (text: string): string[] => [...text.matchAll(PLACEHOLDER)].map((match) => match[1]).sort();
const family = (key: string): string | null => {
  const form = FORMS.find((one) => key.endsWith(`.${one}`));
  return form ? key.slice(0, -form.length - 1) : null;
};

describe('the compiled English (en.ts and its parts)', () => {
  it('has every key in exactly one part', () => {
    const where = new Map<string, string[]>();
    for (const [part, strings] of Object.entries(EN_PARTS)) {
      for (const key of Object.keys(strings)) {
        where.set(key, [...(where.get(key) ?? []), part]);
      }
    }
    expect([...where].filter(([, parts]) => parts.length > 1)).toEqual([]);
    expect([...where.keys()].sort()).toEqual([...keys].sort());
  });

  it('has keys of one shape, in the namespaces the site has - none that looks like a file of the app', () => {
    expect(keys.filter((key) => !KEY.test(key))).toEqual([]);
    expect(keys.filter((key) => !NAMESPACES.includes(key.split('.')[0]))).toEqual([]);
    expect(NAMESPACES).not.toContain('changelog');
    expect(NAMESPACES).not.toContain('pasted');
    expect(NAMESPACES).not.toContain('anotoki');
  });

  it('has a string for every key: text, with no space around it and no brace that is not a placeholder', () => {
    const strings = en as Record<string, unknown>;
    expect(keys.filter((key) => typeof strings[key] !== 'string' || (strings[key] as string).trim() === '')).toEqual([]);
    expect(keys.filter((key) => (strings[key] as string) !== (strings[key] as string).trim())).toEqual([]);
    expect(keys.filter((key) => /[{}]/.test((strings[key] as string).replace(PLACEHOLDER, '')))).toEqual([]);
    expect(keys.filter((key) => (strings[key] as string).length > 2000)).toEqual([]);
  });

  it('has whole plural families: .one, .few and .other, the number as {count}, English .few the same as .other', () => {
    const strings = en as Record<string, string>;
    const families = [...new Set(keys.map(family).filter((name): name is string => name !== null))];
    for (const name of families) {
      expect(
        FORMS.filter((form) => !Object.hasOwn(strings, `${name}.${form}`)),
        name,
      ).toEqual([]);
      expect(strings[`${name}.few`], name).toBe(strings[`${name}.other`]);
      expect(placeholders(strings[`${name}.other`]), name).toContain('count');
      // The forms differ only in how they say the number: no form has a placeholder another lacks.
      expect(
        placeholders(strings[`${name}.one`]).filter((placeholder) => !placeholders(strings[`${name}.other`]).includes(placeholder)),
        name,
      ).toEqual([]);
      // A family's name is not itself a key, or asking for it would be ambiguous.
      expect(Object.hasOwn(strings, name), name).toBe(false);
    }
  });

  it('has every key the run-time families declare, and no key under their prefixes that is not declared', () => {
    const strings = en as Record<string, string>;
    const prefixes = Object.keys(DYNAMIC_KEYS);
    expect(prefixes).toEqual(['recommendation.', 'import.error.', 'import.warning.', 'import.status.', 'error.', 'data.error.', 'data.outcome.']);
    for (const prefix of prefixes) {
      const declared = DYNAMIC_KEYS[prefix].map((tail) => prefix + tail);
      expect(new Set(declared).size, prefix).toBe(declared.length);
      expect(
        declared.filter((key) => !Object.hasOwn(strings, key)),
        prefix,
      ).toEqual([]);
      expect(
        keys.filter((key) => key.startsWith(prefix) && !declared.includes(key)),
        prefix,
      ).toEqual([]);
    }
    expect(DYNAMIC_KEYS['recommendation.']).toHaveLength(18);
    expect(DYNAMIC_KEYS['import.error.']).toHaveLength(23);
    expect(DYNAMIC_KEYS['import.warning.']).toHaveLength(9);
  });

  it('tells a key from any other string', () => {
    expect(isTranslationKey('title.builds')).toBe(true);
    expect(isTranslationKey('Build sources')).toBe(false);
    expect(isTranslationKey('title')).toBe(false);
    expect(isTranslationKey('buildDetail.optionAny')).toBe(false);
    expect(isTranslationKey('constructor')).toBe(false);
    expect(isTranslationKey('__proto__')).toBe(false);
  });
});
