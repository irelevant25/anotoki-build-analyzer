import { HttpErrorResponse } from '@angular/common/http';
import { ImportRun, ImportWarningKind, KindParams } from '../../api/models';
import { TranslationKey } from './en';
import { errorCode, errorKey, errorParams } from './error-text';
import { TranslationParams, TranslationService, Worded, Wording } from './translation';

/**
 * What an import says - why it was refused or failed, what it warns about, how it ended - in the reader's
 * language: for the import wizard and the account page's import history.
 *
 * The server says each error and each warning twice: as a code with its parameters (ImportKinds::ERRORS,
 * ::WARNINGS) and as the English sentence. Here are the tables from a code to its key and to the
 * parameters its sentence needs. The parameters are names from the games or from the person's own file
 * (a game, a UID, a character the catalog lacks) and go in as they came.
 *
 * A code these tables do not know (a newer server's), a row without one (a run stored before codes were
 * kept) or one that lacks a parameter is shown as the stored English sentence, marked `lang="en"` - the
 * one place the server's sentence may be shown, because those rows are data. A request that fails now is
 * never shown that way: importFailure() falls back to errorKey().
 *
 * The keys are also listed in dynamic-keys.ts.
 */

interface CodeWording {
  key: TranslationKey;
  /** The parameters the sentence is filled with: each must be there, a string or a number. */
  needs: readonly string[];
  /** The one of them that is a count of the site's own: written the way the language writes numbers. */
  counted?: string;
}

const NONE: readonly string[] = [];

const ERRORS = new Map<string, CodeWording>([
  ['account_missing', { key: 'import.error.account_missing', needs: NONE }],
  ['account_uid_mismatch', { key: 'import.error.account_uid_mismatch', needs: ['data_uid', 'account_uid'] }],
  ['unexpected', { key: 'import.error.unexpected', needs: NONE }],
  ['uid_invalid', { key: 'import.error.uid_invalid', needs: NONE }],
  ['showcase_unreachable', { key: 'import.error.showcase_unreachable', needs: NONE }],
  ['showcase_uid_refused', { key: 'import.error.showcase_uid_refused', needs: NONE }],
  ['showcase_player_not_found', { key: 'import.error.showcase_player_not_found', needs: NONE }],
  ['showcase_maintenance', { key: 'import.error.showcase_maintenance', needs: NONE }],
  ['showcase_busy', { key: 'import.error.showcase_busy', needs: NONE }],
  ['showcase_down', { key: 'import.error.showcase_down', needs: NONE }],
  ['showcase_http', { key: 'import.error.showcase_http', needs: ['status'] }],
  ['showcase_bad_answer', { key: 'import.error.showcase_bad_answer', needs: NONE }],
  ['showcase_details_off', { key: 'import.error.showcase_details_off', needs: NONE }],
  ['showcase_empty', { key: 'import.error.showcase_empty', needs: NONE }],
  ['good_not_good', { key: 'import.error.good_not_good', needs: NONE }],
  ['good_empty', { key: 'import.error.good_empty', needs: NONE }],
  ['file_not_json', { key: 'import.error.file_not_json', needs: NONE }],
  ['file_unknown_format', { key: 'import.error.file_unknown_format', needs: NONE }],
]);

const WARNINGS = new Map<string, CodeWording>([
  ['unknown_character', { key: 'import.warning.unknown_character', needs: ['ref'] }],
  ['unknown_weapon', { key: 'import.warning.unknown_weapon', needs: ['weapon_label', 'ref'] }],
  ['unknown_set', { key: 'import.warning.unknown_set', needs: ['ref'] }],
  ['catalog_behind', { key: 'import.warning.catalog_behind', needs: NONE }],
  ['showcase_artifact_skipped', { key: 'import.warning.showcase_artifact_skipped', needs: NONE }],
  ['good_traveler_no_element', { key: 'import.warning.good_traveler_no_element', needs: NONE }],
  ['good_artifacts_skipped', { key: 'import.warning.good_artifacts_skipped', needs: ['count'], counted: 'count' }],
]);

const STATUSES = new Map<string, TranslationKey>([
  ['success', 'import.status.success'],
  ['partial', 'import.status.partial'],
  ['failed', 'import.status.failed'],
]);

/** The codes this file words: the tails of `import.error.`, `import.warning.` and `import.status.` (dynamic-keys.ts). */
export const IMPORT_ERROR_CODES: readonly string[] = [...ERRORS.keys()];
export const IMPORT_WARNING_CODES: readonly string[] = [...WARNINGS.keys()];
export const IMPORT_STATUSES: readonly string[] = [...STATUSES.keys()];

/** What wording needs of the translation service. */
export type ImportWords = Pick<TranslationService, 't' | 'number' | 'storedEnglishLang'>;

/** An error or a warning as it was stored or sent: its code, that code's parameters, and the English sentence. */
export interface SaidImport {
  code?: string | null;
  params?: KindParams | null;
  message: string | null;
}

/** Why an import was refused or failed: worded from its code - or the stored English sentence, saying so. */
export function importErrorText(i18n: ImportWords, error: SaidImport): Worded {
  return worded(i18n, ERRORS, error);
}

/** One warning of an import that went through: worded from its code - or the stored English sentence, saying so. */
export function importWarningText(i18n: ImportWords, warning: SaidImport): Worded {
  return worded(i18n, WARNINGS, warning);
}

/**
 * A failed run's reason, for the import history. Whether a run failed is `run.status === 'failed'` - never
 * its `summary`, which is null for every failed run.
 */
export function importRunError(i18n: ImportWords, run: Pick<ImportRun, 'error' | 'error_code' | 'error_params'>): Worded {
  return importErrorText(i18n, { code: run.error_code, params: run.error_params, message: run.error });
}

/**
 * A run's warnings, each worded. The codes stand index for index beside the sentences; a run stored
 * before codes were kept has none (and the server leaves them out when the two lists differ in length) -
 * then every warning is its stored English sentence.
 */
export function importWarnings(i18n: ImportWords, summary: { warnings?: string[] | null; warning_kinds?: ImportWarningKind[] | null } | null | undefined): Worded[] {
  const messages = Array.isArray(summary?.warnings) ? summary.warnings : [];
  const kinds = Array.isArray(summary?.warning_kinds) && summary.warning_kinds.length === messages.length ? summary.warning_kinds : null;
  return messages.map((message, index) => importWarningText(i18n, { code: kinds?.[index]?.code, params: kinds?.[index]?.params, message }));
}

/** The key of a run's status - or null for a status nobody listed, which the page then shows as it came. */
export function importStatusKey(status: string): TranslationKey | null {
  return STATUSES.get(status) ?? null;
}

/** A run's status in the reader's language; one nobody listed as it came. */
export function importStatusText(i18n: Pick<TranslationService, 't'>, status: string): string {
  const key = importStatusKey(status);
  return key ? i18n.t(key) : status;
}

/**
 * What to say of an import request that failed, as a page's `error` holds it: the key of the answer's
 * import code with its parameters when the code is one of these and whole - anything else as any failed
 * request (errorKey()). The server's sentence is never what is shown.
 *
 * A UID of the wrong length is refused before the import begins: that answer's code is `invalid`, not
 * `uid_invalid`, and is worded as `error.invalid`.
 */
export function importFailure(error: unknown): Wording {
  const code = errorCode(error);
  const wording = code === null ? undefined : ERRORS.get(code);
  const body: unknown = error instanceof HttpErrorResponse ? error.error : null;
  const given = typeof body === 'object' && body !== null ? (body as { params?: unknown }).params : null;
  // No error code counts anything, so no number needs the language here: state stays free of wording.
  const params = wording ? filled(wording, given, String) : null;
  return wording && params ? { key: wording.key, params } : { key: errorKey(error), params: errorParams(error) };
}

function worded(i18n: ImportWords, table: ReadonlyMap<string, CodeWording>, said: SaidImport): Worded {
  const wording = typeof said.code === 'string' ? table.get(said.code) : undefined;
  const params = wording ? filled(wording, said.params, (value) => i18n.number(value)) : null;
  return wording && params ? { text: i18n.t(wording.key, params), lang: null } : { text: said.message ?? '', lang: i18n.storedEnglishLang() };
}

/**
 * The parameters a sentence needs, each the object's own and a string or a number (a count: a number) - or
 * null when one is not there.
 */
function filled(wording: CodeWording, given: unknown, count: (value: number) => string): TranslationParams | null {
  const params: TranslationParams = {};
  if (!wording.needs.length) {
    return params;
  }
  if (typeof given !== 'object' || given === null) {
    return null;
  }
  for (const name of wording.needs) {
    const value: unknown = Object.hasOwn(given, name) ? (given as Record<string, unknown>)[name] : undefined;
    if (typeof value === 'number' && Number.isFinite(value)) {
      params[name] = name === wording.counted ? count(value) : value;
    } else if (typeof value === 'string' && value !== '' && name !== wording.counted) {
      params[name] = value;
    } else {
      return null;
    }
  }
  return params;
}
