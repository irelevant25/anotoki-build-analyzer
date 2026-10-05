import { AdminLanguage, TranslationGrid, TranslationKeyRow } from '../../../core/models';
import {
  blankText,
  blockMatches,
  blockMissing,
  cellValue,
  editCount,
  formUsed,
  keyBlocks,
  pluralForm,
  pluralNumbers,
  pluralSamples,
  remainingEdits,
  rowCounts,
  searchable,
  stringsFromFile,
  translationFileText,
  withEdit,
} from './translation-grid';

const key = (name: string, values: Record<string, string>, description = ''): TranslationKeyRow => ({ name, description, values });
const language = (code: string, name: string): AdminLanguage => ({ code, name, native_name: name, enabled: true, sort_order: 1, seeded: false, strings: 0 });

// As the API sends them: by name, so a family's forms come few, one, other.
const CLOSE = key('common.close', { en: 'Close', sk: 'Zavrieť' }, 'A button that closes what is open.');
const FEW = key('home.questions.few', { en: '{count} questions', sk: '{count} otázky' });
const ONE = key('home.questions.one', { en: '{count} question', sk: '1 otázka' });
const OTHER = key('home.questions.other', { en: '{count} questions', sk: '{count} otázok' });
const TITLE = key('home.title', { en: 'Have your say' }, 'Home page: the heading.');
const KEYS = [CLOSE, FEW, ONE, OTHER, TITLE];

describe('keyBlocks', () => {
  it('makes one block of a plural family, its forms in the order one, few, other - and leaves every other key on its own', () => {
    const blocks = keyBlocks(KEYS);

    expect(blocks.map((block) => [block.name, block.plural])).toEqual([
      ['common.close', false],
      ['home.questions', true],
      ['home.title', false],
    ]);
    expect(blocks[1].rows.map((row) => [row.key.name, row.form])).toEqual([
      ['home.questions.one', 'one'],
      ['home.questions.few', 'few'],
      ['home.questions.other', 'other'],
    ]);
    expect(blocks[0].rows).toEqual([{ key: CLOSE, form: null }]);
  });

  it('takes a family that lacks a form as the family it is', () => {
    const [block] = keyBlocks([OTHER, ONE]);

    expect(block.plural).toBe(true);
    expect(block.rows.map((row) => row.form)).toEqual(['one', 'other']);
  });
});

describe('searching', () => {
  const [close, questions, title] = keyBlocks(KEYS);
  const codes = ['en', 'sk'];

  it('compares without case and without diacritics', () => {
    expect(searchable('Otázka Čeština ŽLTÝ')).toBe('otazka cestina zlty');
  });

  it('finds a block by a key, by a description and by a text in any language', () => {
    expect(blockMatches(close, 'COMMON.cl', codes, {})).toBe(true);
    expect(blockMatches(close, 'closes what', codes, {})).toBe(true);
    expect(blockMatches(close, 'zavriet', codes, {})).toBe(true);
    expect(blockMatches(close, 'ZAVRIEŤ', codes, {})).toBe(true);
    expect(blockMatches(close, 'otazka', codes, {})).toBe(false);
    expect(blockMatches(title, '  ', codes, {})).toBe(true);
  });

  it('finds a plural family whole, by any of its forms', () => {
    expect(blockMatches(questions, 'otazok', codes, {})).toBe(true);
    expect(blockMatches(questions, 'questions.one', codes, {})).toBe(true);
  });

  it('finds a change not saved yet - and still what it replaced, so a row does not leave while it is rewritten', () => {
    const edits = withEdit({}, TITLE, 'sk', 'Povedzte nám svoj názor');

    expect(blockMatches(title, 'nazor', codes, edits)).toBe(true);
    expect(blockMatches(title, 'nazor', codes, {})).toBe(false);
    expect(blockMatches(close, 'zavriet', codes, withEdit({}, CLOSE, 'sk', 'Zatvoriť'))).toBe(true);
    expect(blockMatches(close, 'zatvorit', codes, withEdit({}, CLOSE, 'sk', 'Zatvoriť'))).toBe(true);
  });

  it('says what a language is missing by what is saved - a family when any of its forms is', () => {
    expect(blockMissing(title, 'sk')).toBe(true);
    expect(blockMissing(close, 'sk')).toBe(false);
    expect(blockMissing(questions, 'sk')).toBe(false);
    expect(blockMissing(keyBlocks([ONE, key('home.questions.few', { en: '{count} questions' }), OTHER])[0], 'sk')).toBe(true);
  });

  it('does not call a form missing in a language whose numbers never take it', () => {
    // `one` and `other` written in both, `few` in neither.
    const [family] = keyBlocks([
      key('home.questions.few', { en: '{count} questions' }),
      key('home.questions.one', { en: '{count} question', de: '{count} Frage', sk: '1 otázka' }),
      key('home.questions.other', { en: '{count} questions', de: '{count} Fragen', sk: '{count} otázok' }),
    ]);

    // German never says `few`: nothing is missing. Slovak says it for 2, 3 and 4.
    expect(blockMissing(family, 'de')).toBe(false);
    expect(blockMissing(family, 'sk')).toBe(true);
    expect(family.rows.map((row) => rowCounts(row, 'de'))).toEqual([true, false, true]);
    expect(family.rows.map((row) => rowCounts(row, 'sk'))).toEqual([true, true, true]);

    // A form the language does take is missing like any other; Japanese takes only `other`.
    const [unwritten] = keyBlocks([FEW, ONE, key('home.questions.other', { en: '{count} questions' })]);
    expect(blockMissing(unwritten, 'de')).toBe(true);
    expect(blockMissing(unwritten, 'ja')).toBe(true);
    const [onlyOther] = keyBlocks([
      key('home.questions.few', { en: '{count} questions' }),
      key('home.questions.one', { en: '{count} question' }),
      key('home.questions.other', { en: '{count} questions', ja: '{count} 問' }),
    ]);
    expect(blockMissing(onlyOther, 'ja')).toBe(false);

    // A key on its own always counts.
    expect(rowCounts(title.rows[0], 'ja')).toBe(true);
    expect(blockMissing(title, 'ja')).toBe(true);
  });
});

describe('the changed cells', () => {
  it('holds a cell only while it differs from what is stored', () => {
    let edits = withEdit({}, CLOSE, 'sk', 'Zatvoriť');
    edits = withEdit(edits, TITLE, 'sk', 'Názor');
    edits = withEdit(edits, TITLE, 'en', 'Say it');

    expect(edits).toEqual({ 'common.close': { sk: 'Zatvoriť' }, 'home.title': { sk: 'Názor', en: 'Say it' } });
    expect(editCount(edits)).toBe(3);
    expect(cellValue(edits, CLOSE, 'sk')).toBe('Zatvoriť');
    expect(cellValue(edits, CLOSE, 'en')).toBe('Close');
    expect(cellValue(edits, TITLE, 'de')).toBe('');

    // Typed back to what the site has: no change any more - and an emptied box that was empty is none either.
    edits = withEdit(edits, CLOSE, 'sk', 'Zavrieť');
    edits = withEdit(edits, TITLE, 'sk', '');
    expect(edits).toEqual({ 'home.title': { en: 'Say it' } });
    expect(editCount(edits)).toBe(1);
  });

  it('counts an emptied box as a change: it takes the string away', () => {
    expect(withEdit({}, CLOSE, 'sk', '')).toEqual({ 'common.close': { sk: '' } });
  });

  it('keeps, after a save, only what was typed meanwhile and still differs', () => {
    const sent = { 'common.close': { sk: ' Zatvoriť ' }, 'home.title': { sk: 'Názor' } };
    // While the save was on its way: the title typed on, a third cell begun.
    const edits = { 'common.close': { sk: ' Zatvoriť ' }, 'home.title': { sk: 'Názor!' }, 'home.questions.one': { en: 'One question' } };
    const fresh: TranslationGrid = {
      languages: [language('en', 'English'), language('sk', 'Slovak')],
      keys: [key('common.close', { en: 'Close', sk: 'Zatvoriť' }), ONE, key('home.title', { en: 'Have your say', sk: 'Názor' })],
    };

    expect(remainingEdits(edits, fresh, sent)).toEqual({ 'home.title': { sk: 'Názor!' }, 'home.questions.one': { en: 'One question' } });
  });

  it('holds a cell whatever it says when told to keep it: typed while a save is on its way, against a grid about to change', () => {
    const sent = withEdit({}, CLOSE, 'en', 'Shut');
    // Typed back to the old text before the answer: kept, where it would otherwise be no change.
    const edits = withEdit(sent, CLOSE, 'en', 'Close', true);
    expect(withEdit(sent, CLOSE, 'en', 'Close')).toEqual({});
    expect(edits).toEqual({ 'common.close': { en: 'Close' } });

    // The save went through: the site says "Shut", the box "Close" - a change again.
    const saved: TranslationGrid = { languages: [language('en', 'English'), language('sk', 'Slovak')], keys: [key('common.close', { en: 'Shut', sk: 'Zavrieť' })] };
    expect(remainingEdits(edits, saved, sent)).toEqual({ 'common.close': { en: 'Close' } });

    // The save failed: the site still says "Close", and the kept cell is let go.
    const asBefore: TranslationGrid = { languages: saved.languages, keys: [CLOSE] };
    expect(remainingEdits(edits, asBefore)).toEqual({});
  });

  describe('a box with nothing in it to see', () => {
    // What the server's blank() takes for empty once clean() has
    // dropped the controls: spaces of every kind, format characters, controls.
    const BLANK = [
      ['three spaces', '   '],
      ['a line break', '\n\n'],
      ['a zero-width space', '​'],
      ['a word joiner', '⁠'],
      ['a soft hyphen', '­'],
      ['a next-line character', '\u0085'],
      ['a Mongolian vowel separator', '᠎'],
      ['a byte-order mark', '﻿'],
      ['an ideographic space', '　'],
      ['a control character', '\u0001\u007F'],
      ['a mix', ' ​\t   '],
    ];

    it.each(BLANK)('is blank as the server takes it: %s', (_, value) => {
      expect(blankText(value)).toBe(true);
    });

    it.each([
      ['a letter', 'a'],
      ['a letter among spaces', ' ​ x ​ '],
      ['a dot', '.'],
      ['a Hangul filler (a letter to Unicode)', 'ㅤ'],
      ['a Braille blank (a symbol to Unicode)', '⠀'],
    ])('is not blank: %s', (_, value) => {
      expect(blankText(value)).toBe(false);
    });

    it.each(BLANK)('is no change where no string is stored - saved, it would be no string still: %s', (_, value) => {
      expect(withEdit({}, TITLE, 'sk', value)).toEqual({});
      expect(withEdit(withEdit({}, TITLE, 'sk', 'Názor'), TITLE, 'sk', value)).toEqual({});
      expect(withEdit({}, TITLE, 'de', value)).toEqual({});
    });

    it.each(BLANK)('is a change over a stored string: it takes the string away - %s', (_, value) => {
      expect(withEdit({}, CLOSE, 'sk', value)).toEqual({ 'common.close': { sk: value } });
      expect(withEdit({}, CLOSE, 'en', value)).toEqual({ 'common.close': { en: value } });
    });

    it('is not trim(): spaces around words over the words are a change, typed as they are', () => {
      // Deleting "Close w" back to "Close " must not rewrite the box to "Close" under the cursor.
      expect(withEdit({}, CLOSE, 'en', 'Close ')).toEqual({ 'common.close': { en: 'Close ' } });
      expect(withEdit({}, CLOSE, 'sk', '​Zavrieť')).toEqual({ 'common.close': { sk: '​Zavrieť' } });
    });

    it('is held while a save is on its way, and let go by the answer when nothing is stored there', () => {
      const edits = withEdit({}, TITLE, 'sk', '​', true);
      expect(edits).toEqual({ 'home.title': { sk: '​' } });

      const languages = [language('en', 'English'), language('sk', 'Slovak')];
      expect(remainingEdits(edits, { languages, keys: [TITLE] })).toEqual({});
      // Somebody else saved a Slovak title meanwhile: now it would take theirs away - a change.
      expect(remainingEdits(edits, { languages, keys: [key('home.title', { en: 'Have your say', sk: 'Názor' })] })).toEqual({ 'home.title': { sk: '​' } });
    });
  });

  it('lets go of a change the fresh grid already says, and of one for a key or a language that is gone', () => {
    const edits = { 'common.close': { sk: 'Zatvoriť', de: 'Schließen' }, 'gone.key': { en: 'x' }, 'home.title': { sk: 'Názor' } };
    const fresh: TranslationGrid = { languages: [language('en', 'English'), language('sk', 'Slovak')], keys: [key('common.close', { en: 'Close', sk: 'Zatvoriť' }), TITLE] };

    expect(remainingEdits(edits, fresh)).toEqual({ 'home.title': { sk: 'Názor' } });
  });
});

describe('plurals', () => {
  it('takes the form the site takes: Slovak 1, 2-4, and the rest', () => {
    expect([0, 1, 2, 4, 5, 12, 22].map((count) => pluralForm('sk', count))).toEqual(['other', 'one', 'few', 'few', 'other', 'other', 'other']);
    expect([0, 1, 2, 12].map((count) => pluralForm('en', count))).toEqual(['other', 'one', 'other', 'other']);
    // Not a code: English's rules, never an error.
    expect(pluralForm('not a code', 1)).toBe('one');
  });

  it('says which numbers a form is for, in the language of the column', () => {
    expect(['one', 'few', 'other'].map((form) => pluralNumbers('sk', form as 'one', 'Slovak'))).toEqual(['1', '2-4', '0, 5 and more']);
    expect(['one', 'few', 'other'].map((form) => pluralNumbers('en', form as 'one', 'English'))).toEqual(['1', 'not used in English - the fallback for other languages', 'everything else']);
  });

  it('works the numbers out for a language added later', () => {
    expect(pluralNumbers('cs', 'few', 'Czech')).toBe('2-4');
    expect(pluralNumbers('pl', 'few', 'Polish')).toBe('2-4, 22-24, 32-34, …');
    expect(pluralNumbers('ja', 'one', 'Japanese')).toBe('not used in Japanese');
    expect(pluralNumbers('ja', 'other', 'Japanese')).toBe('every number');
  });

  it('knows which forms a language ever takes - and every form of English, which the others fall back to', () => {
    expect(['one', 'few', 'other'].map((form) => formUsed('sk', form as 'one'))).toEqual([true, true, true]);
    expect(['one', 'few', 'other'].map((form) => formUsed('de', form as 'one'))).toEqual([true, false, true]);
    expect(['one', 'few', 'other'].map((form) => formUsed('ja', form as 'one'))).toEqual([false, false, true]);
    // Polish has `many` too, which the site reads as `other`.
    expect(['one', 'few', 'other'].map((form) => formUsed('pl', form as 'one'))).toEqual([true, true, true]);
    // English never says `few` itself, but its `few` is read - by a language with none of its own.
    expect(['one', 'few', 'other'].map((form) => formUsed('en', form as 'one'))).toEqual([true, true, true]);
    // Asked again: the same (it is worked out once).
    expect(formUsed('de', 'few')).toBe(false);
  });

  it('fills a family in for 1, 3 and 12', () => {
    const slovak: Record<string, string> = { one: '1 otázka', few: '{count} otázky', other: '{count} otázok' };
    const english: Record<string, string> = { one: '{count} question', few: '{count} questions (few)', other: '{count} questions' };

    expect(pluralSamples('sk', (form) => slovak[form])).toEqual(['1 otázka', '3 otázky', '12 otázok']);
    // English never takes its "few".
    expect(pluralSamples('en', (form) => english[form])).toEqual(['1 question', '3 questions', '12 questions']);
  });

  it('fills in {count} only, and shows a dash where there is no text', () => {
    expect(pluralSamples('sk', (form) => (form === 'few' ? '' : 'Choose {min} to {count}'))).toEqual(['Choose {min} to 1', '–', 'Choose {min} to 12']);
  });
});

describe('a language as a file', () => {
  it('writes one key a line, as the command line does, and reads it back', () => {
    const values = { 'common.close': 'Zavrieť', 'home.title': 'Povedzte "nám"' };
    const text = translationFileText(values);

    expect(text).toBe('{\n    "common.close": "Zavrieť",\n    "home.title": "Povedzte \\"nám\\""\n}\n');
    expect(stringsFromFile(text)).toEqual(values);
    expect(translationFileText({})).toBe('{}\n');
  });

  it('reads a file that starts with a byte-order mark', () => {
    expect(stringsFromFile('﻿{"common.close": "Zavrieť"}')).toEqual({ 'common.close': 'Zavrieť' });
  });

  it('takes nothing that is not a flat object of texts', () => {
    expect(stringsFromFile('not json')).toBeNull();
    expect(stringsFromFile('["common.close"]')).toBeNull();
    expect(stringsFromFile('null')).toBeNull();
    expect(stringsFromFile('"text"')).toBeNull();
    expect(stringsFromFile('{"common.close": {"sk": "Zavrieť"}}')).toBeNull();
    expect(stringsFromFile('{"common.close": 1}')).toBeNull();
    expect(stringsFromFile('{}')).toEqual({});
  });

  it('keeps a key named like something every object has, so the server can refuse it', () => {
    const strings = stringsFromFile('{"__proto__": "x", "constructor": "y"}')!;

    expect(Object.keys(strings).sort()).toEqual(['__proto__', 'constructor']);
  });
});
