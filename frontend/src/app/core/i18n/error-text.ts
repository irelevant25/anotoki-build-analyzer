import { HttpErrorResponse } from '@angular/common/http';
import { Pipe, PipeTransform, inject } from '@angular/core';
import { TranslationKey, isTranslationKey } from './en';
import { TranslationParams, TranslationService, Wording } from './translation';

/**
 * What a member page says when a request fails, in the reader's language.
 *
 * A member is never shown the server's English sentence: the page words the failure from the answer's
 * `code`. The codes whose meaning does not depend on the page are worded here; a page words its own
 * refusals first (an import's 422 - import-words.ts) and falls back to this. The English pages (Sources,
 * Translations, Languages) do not come here: they go on showing the server's sentences through
 * errorMessage() (core/api.ts).
 *
 * The key comes from a table and never from the code itself: a code nobody listed is `error.unknown`.
 * The tails are also in dynamic-keys.ts.
 */
const BY_CODE = new Map<string, TranslationKey>([
  ['sign_in_required', 'error.sign_in_required'],
  ['local_only', 'error.local_only'],
  ['no_access', 'error.no_access'],
  ['admin_only', 'error.admin_only'],
  ['too_many_imports', 'error.too_many_imports'],
  ['not_found', 'error.not_found'],
  ['server_error', 'error.server_error'],
  ['not_set_up', 'error.not_set_up'],
  ['invalid', 'error.invalid'],
]);

/**
 * The key for a failed request:
 *
 *   no answer at all (status 0)       error.offline
 *   401, whatever it says             error.sign_in_required - the sign-in has ended
 *   one of the server's listed codes  its own key
 *   an answer without a code          error.http, which has a {status} - fill it with errorParams()
 *   anything else                     error.unknown
 */
export function errorKey(error: unknown): TranslationKey {
  if (!(error instanceof HttpErrorResponse)) {
    return 'error.unknown';
  }
  if (error.status === 0) {
    return 'error.offline';
  }
  if (error.status === 401) {
    return 'error.sign_in_required';
  }
  const code = errorCode(error);
  return code === null ? 'error.http' : (BY_CODE.get(code) ?? 'error.unknown');
}

/** What errorKey()'s strings are filled with: `i18n.t(errorKey(error), errorParams(error))`. */
export function errorParams(error: unknown): TranslationParams {
  return { status: error instanceof HttpErrorResponse ? error.status : 0 };
}

/** The `code` of the API's own error answer - or null: the answer has none, or is not the API's at all. */
export function errorCode(error: unknown): string | null {
  if (!(error instanceof HttpErrorResponse)) {
    return null;
  }
  const body: unknown = error.error;
  if (typeof body !== 'object' || body === null || !Object.hasOwn(body, 'code')) {
    return null;
  }
  const code = (body as { code: unknown }).code;
  return typeof code === 'string' && code !== '' ? code : null;
}

/**
 * The sentence for whatever a page's `error` holds: a key with its params (what the page decided itself),
 * or the failed request - worded now, in the language on the page. For a snack bar or a dialog, which
 * take a finished string when they are raised and are not worded again.
 */
export function errorText(i18n: Pick<TranslationService, 't'>, error: unknown): string {
  if (isWording(error)) {
    return i18n.t(error.key, error.params);
  }
  return i18n.t(errorKey(error), errorParams(error));
}

function isWording(value: unknown): value is Wording {
  if (typeof value !== 'object' || value === null || value instanceof HttpErrorResponse || !Object.hasOwn(value, 'key')) {
    return false;
  }
  const key = (value as { key: unknown }).key;
  return typeof key === 'string' && isTranslationKey(key);
}

/**
 * `{{ error() | errorText }}`: what a page's `error` signal holds, worded in the language on the page -
 * a Wording ({key, params}) as it is, anything else as a failed request (errorKey()). Nothing for nothing.
 *
 * Impure, like the translate pipe: the language changes under the same error.
 */
@Pipe({ name: 'errorText', pure: false })
export class ErrorTextPipe implements PipeTransform {
  private readonly _i18n = inject(TranslationService);

  transform(error: unknown): string {
    return error === null || error === undefined ? '' : errorText(this._i18n, error);
  }
}
