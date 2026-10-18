import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { ImportWarningKind, KindParams } from '../models';
import { DYNAMIC_KEYS } from './dynamic-keys';
import { en } from './en';
import {
  IMPORT_ERROR_CODES,
  IMPORT_STATUSES,
  IMPORT_WARNING_CODES,
  importErrorText,
  importFailure,
  importRunError,
  importStatusKey,
  importStatusText,
  importWarningText,
  importWarnings,
} from './import-words';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { TranslationService } from './translation';

/** The informal Slovak of the codes that carry something: every game term and name stays as it is. */
const SLOVAK: Record<string, string> = {
  'error.invalid': 'Niektoré zadané údaje nie sú platné. Skontroluj ich a skús to znova.',
  'error.offline': 'Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.',
  'error.unknown': 'Niečo sa pokazilo.',
  'import.error.account_game_mismatch': 'Vybraný účet je z hry {account_game}, ale údaje sú z hry {data_game}.',
  'import.error.account_uid_mismatch': 'Údaje majú UID {data_uid}, ale vybraný účet má UID {account_uid}.',
  'import.error.showcase_http': 'Služba, ktorá číta Character Showcase, odpovedala kódom HTTP {status}.',
  'import.error.showcase_busy': 'Služba, ktorá číta Character Showcase, má teraz príliš veľa požiadaviek. Počkaj minútu a skús to znova.',
  'import.error.file_game_mismatch': 'Súbor obsahuje údaje z hry {file_game}, ale zvolená hra je {chosen_game}.',
  'import.error.file_not_json': 'Súbor nie je platný JSON.',
  'import.warning.unknown_character': 'Neznáma postava, import ju preskočil: {ref}',
  'import.warning.unknown_weapon': '{weapon_label}: neznámy názov, import ho preskočil: {ref}',
  'import.warning.unknown_set': 'Neznámy set, import ho preskočil: {ref}',
  'import.warning.catalog_behind': 'Čo katalóg hry ešte nepozná, to import preskočil. Keď správca obnoví katalóg (stránka Zdroje), importuj znova.',
  'import.warning.good_artifacts_skipped': 'Artifacts, ktoré import preskočil (chýba set, slot alebo main stat): {count}',
  'import.warning.hsr_relics_skipped': 'Relics, ktoré import preskočil (neznámy slot, set alebo main stat): {count}',
  'import.status.success': 'úspešný',
  'import.status.partial': 'čiastočný',
  'import.status.failed': 'neúspešný',
};

/** What each code that carries something is sent with (the server's ImportKinds). */
const ERROR_PARAMS: Record<string, KindParams> = {
  account_game_mismatch: { account_game: 'Genshin Impact', data_game: 'Honkai: Star Rail' },
  account_uid_mismatch: { data_uid: '700000002', account_uid: '700000001' },
  showcase_http: { status: 418 },
  file_game_mismatch: { file_game: 'Honkai: Star Rail', chosen_game: 'Genshin Impact' },
};
const WARNING_PARAMS: Record<string, KindParams> = {
  unknown_character: { ref: 'Newcomer' },
  unknown_weapon: { weapon_label: 'Light Cone', ref: '23099' },
  unknown_set: { ref: 'NewSet' },
  good_artifacts_skipped: { count: 3 },
  hsr_relics_skipped: { count: 12 },
};

/** The English of the four errors and five warnings that carry something. */
const ENGLISH_WITH_PARAMS: Record<string, string> = {
  account_game_mismatch: 'The selected account is a Genshin Impact account, but the data is from Honkai: Star Rail.',
  account_uid_mismatch: 'The data belongs to UID 700000002, but the selected account is UID 700000001.',
  showcase_http: 'The showcase service answered with HTTP 418.',
  file_game_mismatch: 'This file contains Honkai: Star Rail data, but you chose Genshin Impact.',
  unknown_character: 'Unknown character Newcomer was skipped.',
  unknown_weapon: 'Light Cone: 23099 is unknown and was skipped.',
  unknown_set: 'Unknown set NewSet was skipped.',
  good_artifacts_skipped: 'Artifacts skipped for a missing set, slot or main stat: 3',
  hsr_relics_skipped: 'Relics skipped for an unknown slot, set or main stat: 12',
};

describe('the words of an import', () => {
  function words(language: 'en' | 'sk'): TranslationService {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [language === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: en }) : provideTestTranslations(en)] });
    return TestBed.inject(TranslationService);
  }

  let warn: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
  });

  afterEach(() => vi.restoreAllMocks());

  describe('importErrorText(): why an import was refused or failed', () => {
    it('words every one of the 23 codes from its key, its parameters filled in', () => {
      const i18n = words('en');
      expect(IMPORT_ERROR_CODES).toHaveLength(23);
      for (const code of IMPORT_ERROR_CODES) {
        const worded = importErrorText(i18n, { code, params: ERROR_PARAMS[code] ?? {}, message: 'The stored sentence.' });
        const english = en[`import.error.${code}` as keyof typeof en];
        expect(worded, code).toEqual({ text: ENGLISH_WITH_PARAMS[code] ?? english, lang: null });
        expect(worded.text, code).not.toMatch(/[{}]/);
      }
      // A code without parameters needs none to be sent - an older row's null, the 422's empty object.
      expect(importErrorText(i18n, { code: 'good_empty', params: null, message: 'x' }).text).toBe('The GOOD file contains no characters or artifacts. Check the exporter settings.');
      expect(importErrorText(i18n, { code: 'uid_invalid', message: 'x' }).text).toBe('A UID is 9 or 10 digits long.');
      expect(warn).not.toHaveBeenCalled();
    });

    it('words them in Slovak around the games’ names', () => {
      const i18n = words('sk');
      expect(importErrorText(i18n, { code: 'file_game_mismatch', params: ERROR_PARAMS['file_game_mismatch'], message: 'x' })).toEqual({
        text: 'Súbor obsahuje údaje z hry Honkai: Star Rail, ale zvolená hra je Genshin Impact.',
        lang: null,
      });
      expect(importErrorText(i18n, { code: 'account_game_mismatch', params: ERROR_PARAMS['account_game_mismatch'], message: 'x' }).text).toBe(
        'Vybraný účet je z hry Genshin Impact, ale údaje sú z hry Honkai: Star Rail.',
      );
      expect(importErrorText(i18n, { code: 'account_uid_mismatch', params: ERROR_PARAMS['account_uid_mismatch'], message: 'x' }).text).toBe(
        'Údaje majú UID 700000002, ale vybraný účet má UID 700000001.',
      );
      // A status is a number of the protocol's, written as it is.
      expect(importErrorText(i18n, { code: 'showcase_http', params: { status: 1200 }, message: 'x' }).text).toBe('Služba, ktorá číta Character Showcase, odpovedala kódom HTTP 1200.');
    });

    it('shows the stored English sentence, marked as English, for a code it does not know, a row without one, or one that lacks a parameter', () => {
      const i18n = words('sk');
      const message = 'The showcase service cannot be reached right now. Try again in a few minutes.';
      const stored = { text: message, lang: 'en' };
      for (const code of ['showcase_gone', 'constructor', 'toString', 'import.error.good_empty', 'unknown_character', '']) {
        expect(importErrorText(i18n, { code, params: {}, message }), code).toEqual(stored);
      }
      expect(importErrorText(i18n, { code: null, params: null, message })).toEqual(stored);
      expect(importErrorText(i18n, { message })).toEqual(stored);
      expect(importErrorText(i18n, { code: 'file_game_mismatch', params: { file_game: 'Genshin Impact' }, message })).toEqual(stored);
      expect(importErrorText(i18n, { code: 'file_game_mismatch', params: { file_game: 'Genshin Impact', chosen_game: '' }, message })).toEqual(stored);
      expect(importErrorText(i18n, { code: 'file_game_mismatch', params: null, message })).toEqual(stored);
      expect(importErrorText(i18n, { code: 'showcase_http', params: Object.create({ status: 500 }), message })).toEqual(stored);
      expect(warn).not.toHaveBeenCalled();

      // While the page is English the sentence needs no mark.
      expect(importErrorText(words('en'), { code: 'showcase_gone', params: {}, message })).toEqual({ text: message, lang: null });
    });
  });

  describe('importRunError(): a failed run in the import history', () => {
    it('words a run stored with its code, and shows an older run’s sentence as it was stored', () => {
      const i18n = words('sk');
      expect(importRunError(i18n, { error: 'The file is not valid JSON.', error_code: 'file_not_json', error_params: {} })).toEqual({ text: 'Súbor nie je platný JSON.', lang: null });
      expect(importRunError(i18n, { error: 'The file is not valid JSON.', error_code: null, error_params: null })).toEqual({ text: 'The file is not valid JSON.', lang: 'en' });
      // "unexpected" is stored with the failure's own text after it; the page says only that it stopped.
      const unexpected = importRunError(words('en'), { error: 'The import stopped with an unexpected error: SQLSTATE[08006]', error_code: 'unexpected', error_params: {} });
      expect(unexpected).toEqual({ text: 'The import stopped with an unexpected error.', lang: null });
    });
  });

  describe('importWarningText() and importWarnings(): what an import that went through warns about', () => {
    it('words every one of the 9 codes from its key, its parameters filled in', () => {
      const i18n = words('en');
      expect(IMPORT_WARNING_CODES).toHaveLength(9);
      for (const code of IMPORT_WARNING_CODES) {
        const worded = importWarningText(i18n, { code, params: WARNING_PARAMS[code] ?? {}, message: 'The stored sentence.' });
        const english = en[`import.warning.${code}` as keyof typeof en];
        expect(worded, code).toEqual({ text: ENGLISH_WITH_PARAMS[code] ?? english, lang: null });
        expect(worded.text, code).not.toMatch(/[{}]/);
      }
      expect(warn).not.toHaveBeenCalled();
    });

    it('words them in Slovak: the game’s term first, a count after the colon - written as the language writes numbers', () => {
      const i18n = words('sk');
      expect(importWarningText(i18n, { code: 'unknown_weapon', params: WARNING_PARAMS['unknown_weapon'], message: 'x' }).text).toBe('Light Cone: neznámy názov, import ho preskočil: 23099');
      expect(importWarningText(i18n, { code: 'unknown_character', params: WARNING_PARAMS['unknown_character'], message: 'x' }).text).toBe('Neznáma postava, import ju preskočil: Newcomer');
      expect(importWarningText(i18n, { code: 'good_artifacts_skipped', params: { count: 3 }, message: 'x' }).text).toBe('Artifacts, ktoré import preskočil (chýba set, slot alebo main stat): 3');
      expect(importWarningText(i18n, { code: 'hsr_relics_skipped', params: { count: 12345 }, message: 'x' }).text).toBe('Relics, ktoré import preskočil (neznámy slot, set alebo main stat): 12 345');
      expect(importWarningText(words('en'), { code: 'hsr_relics_skipped', params: { count: 12345 }, message: 'x' }).text).toBe('Relics skipped for an unknown slot, set or main stat: 12,345');
    });

    it('shows the stored English sentence for a code it does not know, a warning without one, or one that lacks a parameter', () => {
      const i18n = words('sk');
      const message = 'Unknown character {"name":"Newcomer"} was skipped.';
      const stored = { text: message, lang: 'en' };
      expect(importWarningText(i18n, { code: 'unknown_pet', params: { ref: 'x' }, message })).toEqual(stored);
      expect(importWarningText(i18n, { code: 'file_not_json', params: {}, message })).toEqual(stored);
      expect(importWarningText(i18n, { code: undefined, params: undefined, message })).toEqual(stored);
      // A reference the data did not name at all arrives empty.
      expect(importWarningText(i18n, { code: 'unknown_character', params: { ref: '' }, message })).toEqual(stored);
      expect(importWarningText(i18n, { code: 'good_artifacts_skipped', params: { count: '3' }, message })).toEqual(stored);
    });

    it('pairs a run’s sentences with their codes index for index', () => {
      const i18n = words('sk');
      const summary: { warnings: string[]; warning_kinds: ImportWarningKind[] } = {
        warnings: [
          'Unknown character {"name":"Newcomer"} was skipped.',
          'Something a newer server warns about.',
          'What the game catalog does not know yet was skipped. After an admin refreshes the catalog (Sources page), import again.',
        ],
        warning_kinds: [
          { code: 'unknown_character', params: { ref: 'Newcomer' } },
          { code: 'brand_new', params: {} },
          { code: 'catalog_behind', params: {} },
        ],
      };
      expect(importWarnings(i18n, summary)).toEqual([
        { text: 'Neznáma postava, import ju preskočil: Newcomer', lang: null },
        { text: 'Something a newer server warns about.', lang: 'en' },
        { text: 'Čo katalóg hry ešte nepozná, to import preskočil. Keď správca obnoví katalóg (stránka Zdroje), importuj znova.', lang: null },
      ]);
    });

    it('shows every sentence as stored for a run from before codes were kept, or whose codes do not match its sentences', () => {
      const i18n = words('sk');
      const warnings = ['Unknown character {"name":"Newcomer"} was skipped.', 'Skipped an artifact with unknown data.'];
      const stored = warnings.map((text) => ({ text, lang: 'en' }));
      expect(importWarnings(i18n, { warnings })).toEqual(stored);
      expect(importWarnings(i18n, { warnings, warning_kinds: null })).toEqual(stored);
      expect(importWarnings(i18n, { warnings, warning_kinds: [{ code: 'unknown_character', params: { ref: 'Newcomer' } }] })).toEqual(stored);
      // No warnings, no summary (a failed run): nothing.
      expect(importWarnings(i18n, { warnings: [] })).toEqual([]);
      expect(importWarnings(i18n, null)).toEqual([]);
      expect(importWarnings(i18n, undefined)).toEqual([]);
    });
  });

  describe('importStatusText(): how a run ended', () => {
    it('words the three statuses, and shows one nobody listed as it came', () => {
      const i18n = words('sk');
      expect(IMPORT_STATUSES.map((status) => importStatusText(i18n, status))).toEqual(['úspešný', 'čiastočný', 'neúspešný']);
      expect(IMPORT_STATUSES.map((status) => importStatusText(words('en'), status))).toEqual(['success', 'partial', 'failed']);
      expect(importStatusKey('failed')).toBe('import.status.failed');
      expect(importStatusKey('running')).toBeNull();
      expect(importStatusKey('constructor')).toBeNull();
      expect(importStatusText(i18n, 'running')).toBe('running');
      expect(warn).not.toHaveBeenCalled();
    });
  });

  describe('importFailure(): an import request that failed, as a page’s error holds it', () => {
    const refused = (body: unknown, status = 422): HttpErrorResponse => new HttpErrorResponse({ status, error: body });

    it('is the key of the answer’s import code, with its parameters', () => {
      expect(importFailure(refused({ message: 'Too many showcase requests right now. Wait a minute and try again.', code: 'showcase_busy', params: {} }))).toEqual({
        key: 'import.error.showcase_busy',
        params: {},
      });
      expect(importFailure(refused({ message: 'x', code: 'file_game_mismatch', params: { file_game: 'Honkai: Star Rail', chosen_game: 'Genshin Impact' } }))).toEqual({
        key: 'import.error.file_game_mismatch',
        params: { file_game: 'Honkai: Star Rail', chosen_game: 'Genshin Impact' },
      });
      expect(importFailure(refused({ message: 'x', code: 'showcase_http', params: { status: 418 } }))).toEqual({ key: 'import.error.showcase_http', params: { status: 418 } });
    });

    it('is any failed request otherwise - never the server’s sentence', () => {
      // A UID of the wrong length is refused by the input check, before the import begins.
      expect(importFailure(refused({ message: 'The uid must be 9 or 10 digits.', code: 'invalid', errors: { uid: ['The uid must be 9 or 10 digits.'] } }))).toEqual({
        key: 'error.invalid',
        params: { status: 422 },
      });
      expect(importFailure(refused({ message: 'A newer server says this.', code: 'showcase_gone', params: {} }))).toEqual({ key: 'error.unknown', params: { status: 422 } });
      // A known code that came without what its sentence needs.
      expect(importFailure(refused({ message: 'x', code: 'file_game_mismatch', params: {} }))).toEqual({ key: 'error.unknown', params: { status: 422 } });
      expect(importFailure(refused({ message: 'x', code: 'file_game_mismatch' }))).toEqual({ key: 'error.unknown', params: { status: 422 } });
      expect(importFailure(refused({ message: 'That is a lot of imports in one minute.', code: 'too_many_imports' }, 429))).toEqual({ key: 'error.too_many_imports', params: { status: 429 } });
      expect(importFailure(refused('<html>', 502))).toEqual({ key: 'error.http', params: { status: 502 } });
      expect(importFailure(new HttpErrorResponse({ status: 0 }))).toEqual({ key: 'error.offline', params: { status: 0 } });
      expect(importFailure(new Error('boom'))).toEqual({ key: 'error.unknown', params: { status: 0 } });
    });
  });

  it('words exactly the codes the key scan is told about - the server’s ImportKinds - and each has its English', () => {
    expect([...IMPORT_ERROR_CODES]).toEqual([...DYNAMIC_KEYS['import.error.']]);
    expect([...IMPORT_WARNING_CODES]).toEqual([...DYNAMIC_KEYS['import.warning.']]);
    expect([...IMPORT_STATUSES]).toEqual([...DYNAMIC_KEYS['import.status.']]);
    const declared = [
      ...IMPORT_ERROR_CODES.map((code) => `import.error.${code}`),
      ...IMPORT_WARNING_CODES.map((code) => `import.warning.${code}`),
      ...IMPORT_STATUSES.map((status) => `import.status.${status}`),
    ];
    expect(
      Object.keys(en)
        .filter((key) => key.startsWith('import.error.') || key.startsWith('import.warning.') || key.startsWith('import.status.'))
        .sort(),
    ).toEqual([...declared].sort());
  });
});
