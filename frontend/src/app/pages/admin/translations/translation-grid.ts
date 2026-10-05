import { FALLBACK_LANGUAGE } from '../../../core/i18n/translation';
import { TranslationChanges, TranslationGrid, TranslationKeyRow } from '../../../core/models';

/**
 * What the Translations page works out from the grid the API sends
 * (GET /api/admin/translations) - pure, so it is tested without a page:
 * which keys are one plural, what a search finds, which cells changed, and
 * how a plural reads for a number in a language.
 */

/** The forms of a plural family, in the order the page shows them. */
export const PLURAL_FORMS = ['one', 'few', 'other'] as const;
export type PluralForm = (typeof PLURAL_FORMS)[number];

/** The numbers a plural family is shown filled in for: one of each of Slovak's forms. */
export const SAMPLE_COUNTS = [1, 3, 12] as const;

/** The largest file an import takes - the server's TranslationCheck::BODY_LIMIT. */
export const IMPORT_MAX_BYTES = 1024 * 1024;

/** A language code as the database keeps it - only such a code is ever given to `Intl`. */
const LANGUAGE_CODE = /^[a-z]{2}(-[a-z]{2})?$/;

/** How far the numbers are looked at when saying which ones a form is for. */
const NUMBERS_UP_TO = 100;
/** How many runs of numbers a form's label names before it says "…". */
const NUMBERS_RUNS = 3;

/** One key of a block, and which form of the plural it is (null: a key on its own). */
export interface BlockRow {
  key: TranslationKeyRow;
  form: PluralForm | null;
}

/**
 * What the page shows as one piece: a key, or a plural family - the keys
 * `name.one`, `name.few`, `name.other`, which the code asks for by `name`
 * with a number.
 */
export interface KeyBlock {
  /** The key's name; for a family, the name without the form. */
  name: string;
  plural: boolean;
  rows: BlockRow[];
}

/** Which form a key is, by its last segment - the three suffixes are kept for plural families. */
function formOf(name: string): PluralForm | null {
  const dot = name.lastIndexOf('.');
  const last = name.slice(dot + 1);
  return dot > 0 && (PLURAL_FORMS as readonly string[]).includes(last) ? (last as PluralForm) : null;
}

/**
 * The keys as blocks, in the order they came (by name): a plural family is
 * one block where its first form stood, its forms in the order one, few,
 * other - the API sends them few, one, other.
 */
export function keyBlocks(keys: readonly TranslationKeyRow[]): KeyBlock[] {
  const blocks: KeyBlock[] = [];
  const families = new Map<string, KeyBlock>();
  for (const key of keys) {
    const form = formOf(key.name);
    if (form === null) {
      blocks.push({ name: key.name, plural: false, rows: [{ key, form: null }] });
      continue;
    }
    const name = key.name.slice(0, key.name.lastIndexOf('.'));
    let family = families.get(name);
    if (!family) {
      family = { name, plural: true, rows: [] };
      families.set(name, family);
      blocks.push(family);
    }
    family.rows.push({ key, form });
  }
  for (const family of families.values()) {
    family.rows.sort((a, b) => PLURAL_FORMS.indexOf(a.form!) - PLURAL_FORMS.indexOf(b.form!));
  }
  return blocks;
}

/** Text as a search compares it: small letters, and no diacritics - "otazka" finds "Otázka". */
export function searchable(text: string): string {
  return text
    .normalize('NFD')
    .replace(/\p{M}+/gu, '')
    .toLowerCase();
}

/** What is in a cell now: the change not saved yet, or the stored string ('' for none). */
export function cellValue(edits: TranslationChanges, key: TranslationKeyRow, code: string): string {
  const mine = edits[key.name];
  return mine && Object.hasOwn(mine, code) ? mine[code] : storedValue(key, code);
}

/** The string the site has for a key in a language ('' for none). */
export function storedValue(key: TranslationKeyRow, code: string): string {
  return Object.hasOwn(key.values, code) ? key.values[code] : '';
}

/**
 * Is there nothing in a box to see - as the server takes it when the box is
 * saved? The twin of TranslationCheck::blank() (backend/src/Services/Translations),
 * after the TranslationCheck::clean() that comes first there: a box of nothing
 * but white space, separators, format characters (a zero-width space, a word
 * joiner, a soft hyphen) and the control characters clean() drops is an empty
 * string to the server - no string of its own in another language, refused for
 * English. `\s` is the browser's (trim()'s) spaces; PCRE's `\s` under /u also
 * takes U+0085, hence the extra one.
 *
 * For the site's own strings only - it tells whether a box is a change, and
 * whether a file's value is one. And never widened to trim():
 * "Hello " over a stored "Hello" is a change, as the server keeps what is
 * inside the spaces it trims.
 */
export function blankText(value: string): boolean {
  return !/[^\s\p{Z}\p{Cf}\u0085\u0000-\u0008\u000E-\u001F\u007F]/u.test(value);
}

/**
 * Does a box say what is stored? It does when the text is the same - and, over
 * no string at all, when there is nothing in it to see (blankText): saved, it
 * would be no string still. Over a stored string a blank box is a change: it
 * means "take the string away".
 */
function sameAsStored(value: string, stored: string): boolean {
  return value === stored || (stored === '' && blankText(value));
}

/**
 * Does a search find this block? By a key's name, its description, or its
 * text in any language - somebody looking for a string knows its words, not
 * its key. The text is both what the site has and what stands in the box now:
 * a row found by its words does not leave while they are being rewritten.
 * A family is found whole.
 */
export function blockMatches(block: KeyBlock, search: string, codes: readonly string[], edits: TranslationChanges): boolean {
  const needle = searchable(search.trim());
  if (needle === '') {
    return true;
  }
  return block.rows.some(
    ({ key }) =>
      searchable(key.name).includes(needle) ||
      searchable(key.description ?? '').includes(needle) ||
      codes.some((code) => searchable(storedValue(key, code)).includes(needle) || searchable(cellValue(edits, key, code)).includes(needle)),
  );
}

/**
 * Is this row one the language needs a string for? Every key on its own is;
 * of a plural family, only the forms the language's numbers ever take -
 * German never says `few`, Japanese neither `one` nor `few`, and the site
 * never reads what stands there.
 */
export function rowCounts(row: BlockRow, code: string): boolean {
  return row.form === null || formUsed(code, row.form);
}

/**
 * Has the site no string of its own for some key of this block in the
 * language? By what is saved: a row does not leave while it is being filled
 * in. A form the language never takes is not missing: nothing can be written
 * there that anybody would read.
 */
export function blockMissing(block: KeyBlock, code: string): boolean {
  return block.rows.some((row) => rowCounts(row, code) && storedValue(row.key, code) === '');
}

/**
 * The edits with one cell set: a cell typed back to what is stored is no
 * change any more, nor is a box with nothing to see where nothing is stored
 * (sameAsStored) - unless `keep` says to hold it whatever it says. That is
 * for typing while a save or an import is on its way: what is stored is about
 * to change, so "the same as stored" cannot be told yet, and what was typed
 * must not be lost to the answer. remainingEdits() lets such a cell go once
 * the fresh grid says the same.
 */
export function withEdit(edits: TranslationChanges, key: TranslationKeyRow, code: string, value: string, keep = false): TranslationChanges {
  const { [key.name]: before, ...others } = edits;
  const cells = { ...before };
  if (!keep && sameAsStored(value, storedValue(key, code))) {
    delete cells[code];
  } else {
    cells[code] = value;
  }
  return Object.keys(cells).length ? { ...others, [key.name]: cells } : others;
}

/** How many cells are changed and not saved. */
export function editCount(edits: TranslationChanges): number {
  return Object.values(edits).reduce((count, cells) => count + Object.keys(cells).length, 0);
}

/**
 * The edits that are still changes once a fresh grid has come: not the cells
 * that were `sent` and saved as they were sent (whatever the server made of
 * them - it trims), not a cell that now says what is stored (sameAsStored:
 * a blank box where nothing is stored is let go too), and nothing for
 * a key or a language that is gone. What was typed while the save was on its
 * way stays.
 */
export function remainingEdits(edits: TranslationChanges, grid: TranslationGrid, sent: TranslationChanges = {}): TranslationChanges {
  const keys = new Map(grid.keys.map((key) => [key.name, key]));
  const codes = new Set(grid.languages.map((language) => language.code));
  const remaining: TranslationChanges = {};
  for (const [name, cells] of Object.entries(edits)) {
    const key = keys.get(name);
    if (!key) {
      continue;
    }
    const kept: Record<string, string> = {};
    for (const [code, value] of Object.entries(cells)) {
      const saved = Object.hasOwn(sent, name) && Object.hasOwn(sent[name], code) && sent[name][code] === value;
      if (codes.has(code) && !saved && !sameAsStored(value, storedValue(key, code))) {
        kept[code] = value;
      }
    }
    if (Object.keys(kept).length) {
      remaining[name] = kept;
    }
  }
  return remaining;
}

// ── Plurals ─────────────────────────────────────────────────────────────────

const PLURAL_RULES = new Map<string, Intl.PluralRules>();

function pluralRules(code: string): Intl.PluralRules {
  let rules = PLURAL_RULES.get(code);
  if (!rules) {
    rules = new Intl.PluralRules(LANGUAGE_CODE.test(code) ? code : FALLBACK_LANGUAGE);
    PLURAL_RULES.set(code, rules);
  }
  return rules;
}

/**
 * The form the site takes for a number in a language - as
 * TranslationService.plural() does: the language's own category when it is
 * one of the three, `other` for every other one (many, two, zero).
 */
export function pluralForm(code: string, count: number): PluralForm {
  const category = pluralRules(code).select(count);
  return category === 'one' || category === 'few' ? category : 'other';
}

const FORMS_USED = new Map<string, boolean>();

/**
 * Does the site ever read this form in the language? English's always: they
 * are what every other language falls back to. Any other language's only
 * when some whole number takes it - `few` in Slovak (2-4), never in German.
 * (pluralForm() folds many, two and zero into `other`, so Polish keeps its
 * `other`.) A form that is not used needs no string: it counts neither as
 * missing nor towards how much of the language is written.
 */
export function formUsed(code: string, form: PluralForm): boolean {
  if (code === FALLBACK_LANGUAGE) {
    return true;
  }
  const known = `${code} ${form}`;
  let used = FORMS_USED.get(known);
  if (used === undefined) {
    used = false;
    for (let count = 0; count <= NUMBERS_UP_TO && !used; count++) {
      used = pluralForm(code, count) === form;
    }
    FORMS_USED.set(known, used);
  }
  return used;
}

/**
 * The numbers a form is for in a language, for the label over its box -
 * Slovak: "1", "2-4", "0, 5 and more". English never takes `few`: what it says
 * there is what a language with no string of its own for that form falls back to.
 */
export function pluralNumbers(code: string, form: PluralForm, languageName: string): string {
  if (code === FALLBACK_LANGUAGE) {
    return form === 'one' ? '1' : form === 'few' ? 'not used in English - the fallback for other languages' : 'everything else';
  }
  if (!formUsed(code, form)) {
    return `not used in ${languageName}`;
  }
  const runs: [number, number][] = [];
  for (let count = 0; count <= NUMBERS_UP_TO; count++) {
    if (pluralForm(code, count) !== form) {
      continue;
    }
    const last = runs[runs.length - 1];
    if (last && last[1] === count - 1) {
      last[1] = count;
    } else {
      runs.push([count, count]);
    }
  }
  if (runs.length === 1 && runs[0][0] === 0 && runs[0][1] === NUMBERS_UP_TO) {
    return 'every number';
  }
  const words = runs.slice(0, NUMBERS_RUNS).map(([from, to]) => (to === NUMBERS_UP_TO ? `${from} and more` : from === to ? String(from) : `${from}-${to}`));
  return words.join(', ') + (runs.length > NUMBERS_RUNS ? ', …' : '');
}

/**
 * A plural family as the site would say it for 1, 3 and 12 in a language:
 * `text(form)` is that language's string for a form - English's when it has
 * none, as the site falls back - and only {count} is filled in; any other
 * placeholder stays as written. A form nobody has written yet is a dash.
 */
export function pluralSamples(code: string, text: (form: PluralForm) => string): string[] {
  return SAMPLE_COUNTS.map((count) => {
    const value = text(pluralForm(code, count));
    return value === '' ? '–' : value.replace(/\{count\}/g, String(count));
  });
}

// ── A language as a file ────────────────────────────────────────────────────

/**
 * A language's strings as the text of a file - one flat object, a key a line,
 * the way `php translations.php --export` writes it, so the two can be
 * compared line by line.
 */
export function translationFileText(values: Record<string, string>): string {
  return JSON.stringify(values, null, 4) + '\n';
}

/** The strings a file holds - a flat {key: text} object - or null when it is anything else. */
export function stringsFromFile(text: string): Record<string, string> | null {
  let parsed: unknown;
  try {
    // A file saved by Notepad starts with a byte-order mark, which JSON does not know.
    parsed = JSON.parse(text.replace(/^﻿/, ''));
  } catch {
    return null;
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    return null;
  }
  const entries = Object.entries(parsed);
  // fromEntries, so a key like "__proto__" stays a key - and is refused by the server like any other it does not know.
  return entries.every((entry): entry is [string, string] => typeof entry[1] === 'string') ? Object.fromEntries(entries) : null;
}
