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
 * errorMessage() (api/api.ts).
 *
 * The key comes from a table and never from the code itself: a code nobody listed is `error.unknown`.
 * The tails are also in dynamic-keys.ts.
 */
const BY_CODE = new Map<string, TranslationKey>([
  ['sign_in_required', 'error.sign_in_required'],
  ['editor_only', 'error.editor_only'],
  ['admin_only', 'error.admin_only'],
  ['visitor_unknown', 'error.visitor_unknown'],
  ['too_many_visitors', 'error.too_many_visitors'],
  ['visitors_unavailable', 'error.visitors_unavailable'],
  ['too_many_imports', 'error.too_many_imports'],
  ['not_found', 'error.not_found'],
  ['server_error', 'error.server_error'],
  ['not_set_up', 'error.not_set_up'],
  ['invalid', 'error.invalid'],
]);

/** A visitor's own limits (422 visitor_limit {limit, max}): one sentence a limit, each with its {max}. */
const VISITOR_LIMITS = new Map<string, TranslationKey>([
  ['accounts', 'error.visitor_limit_accounts'],
  ['file_size', 'error.visitor_limit_file_size'],
]);

/**
 * The key for a failed request:
 *
 *   no answer at all (status 0)       error.offline
 *   401 visitor_unknown               error.visitor_unknown - the visitor key opens nothing any more
 *   any other 401                     error.sign_in_required - the sign-in has ended
 *   422 visitor_limit {limit, max}    error.visitor_limit_<limit>, with {max} (errorParams())
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
  const code = errorCode(error);
  if (error.status === 401) {
    return code === 'visitor_unknown' ? 'error.visitor_unknown' : 'error.sign_in_required';
  }
  if (code === 'visitor_limit') {
    return VISITOR_LIMITS.get(String(answerParams(error)['limit'] ?? '')) ?? 'error.unknown';
  }
  return code === null ? 'error.http' : (BY_CODE.get(code) ?? 'error.unknown');
}

/**
 * What errorKey()'s strings are filled with: `i18n.t(errorKey(error), errorParams(error))` - the status,
 * and a visitor limit's {max} (a number, or nothing).
 */
export function errorParams(error: unknown): TranslationParams {
  const status = error instanceof HttpErrorResponse ? error.status : 0;
  const max = answerParams(error)['max'];
  return typeof max === 'number' ? { status, max } : { status };
}

/** The `params` of the API's own error answer ({} for none, or for one that is not an object). */
export function answerParams(error: unknown): Record<string, unknown> {
  if (!(error instanceof HttpErrorResponse)) {
    return {};
  }
  const body: unknown = error.error;
  if (typeof body !== 'object' || body === null || !Object.hasOwn(body, 'params')) {
    return {};
  }
  const params = (body as { params: unknown }).params;
  return typeof params === 'object' && params !== null && !Array.isArray(params) ? (params as Record<string, unknown>) : {};
}

/**
 * Whether a request was refused for want of a sign-in (any 401): on a page anybody may open, somebody who is
 * not signed in then sees the ways in (sites/main/common/visitor-hero) rather than an error.
 */
export function isSignInRefusal(error: unknown): boolean {
  return error instanceof HttpErrorResponse && error.status === 401;
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
 * or the failed request - worded now, in the language on the page. For a toast or a dialog, which
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
