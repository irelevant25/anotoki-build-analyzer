import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { DYNAMIC_KEYS } from './dynamic-keys';
import { en } from './en';
import { ErrorTextPipe, answerParams, errorCode, errorKey, errorParams, errorText, isSignInRefusal } from './error-text';
import { TranslationService } from './translation';

/** An answer of the API: a status, and the body's code (and params) beside the server's English sentence. */
function answered(status: number, code?: string, params?: unknown): HttpErrorResponse {
  return new HttpErrorResponse({
    status,
    error: { message: 'The server said this in English.', ...(code === undefined ? {} : { code }), ...(params === undefined ? {} : { params }) },
  });
}

describe('errorKey(): a failed request in the reader’s words', () => {
  it('is "offline" when there was no answer at all', () => {
    expect(errorKey(new HttpErrorResponse({ status: 0, error: new ProgressEvent('error') }))).toBe('error.offline');
    // Whatever a body might claim.
    expect(errorKey(answered(0, 'server_error'))).toBe('error.offline');
  });

  it('is "your sign-in has ended" for any 401, whatever it says - but the one that says the visitor key opens nothing', () => {
    expect(errorKey(answered(401, 'visitor_unknown'))).toBe('error.visitor_unknown');
    expect(isSignInRefusal(answered(401, 'visitor_unknown'))).toBe(true);
    expect(isSignInRefusal(answered(403, 'editor_only'))).toBe(false);
    expect(errorKey(answered(401, 'sign_in_required'))).toBe('error.sign_in_required');
    expect(errorKey(answered(401))).toBe('error.sign_in_required');
    expect(errorKey(answered(401, 'something_new'))).toBe('error.sign_in_required');
    expect(errorKey(new HttpErrorResponse({ status: 401, error: null }))).toBe('error.sign_in_required');
  });

  it('words the server’s codes that mean the same on every page', () => {
    expect(errorKey(answered(403, 'editor_only'))).toBe('error.editor_only');
    expect(errorKey(answered(403, 'admin_only'))).toBe('error.admin_only');
    expect(errorKey(answered(429, 'too_many_visitors'))).toBe('error.too_many_visitors');
    expect(errorKey(answered(503, 'visitors_unavailable'))).toBe('error.visitors_unavailable');
    expect(errorKey(answered(429, 'too_many_imports'))).toBe('error.too_many_imports');
    expect(errorKey(answered(404, 'not_found'))).toBe('error.not_found');
    expect(errorKey(answered(500, 'server_error'))).toBe('error.server_error');
    expect(errorKey(answered(503, 'not_set_up'))).toBe('error.not_set_up');
    expect(errorKey(answered(422, 'invalid'))).toBe('error.invalid');
    // A code that reaches a page with another status is still that code.
    expect(errorKey(answered(400, 'sign_in_required'))).toBe('error.sign_in_required');
  });

  it('words a visitor\'s two limits, each with its {max} - and nothing for a limit nobody listed', () => {
    const accounts = answered(422, 'visitor_limit', { limit: 'accounts', max: 5 });
    expect(errorKey(accounts)).toBe('error.visitor_limit_accounts');
    expect(errorParams(accounts)).toEqual({ status: 422, max: 5 });
    const size = answered(422, 'visitor_limit', { limit: 'file_size', max: 5120 });
    expect(errorKey(size)).toBe('error.visitor_limit_file_size');
    expect(errorParams(size)).toEqual({ status: 422, max: 5120 });

    expect(errorKey(answered(422, 'visitor_limit', { limit: 'constructor', max: 1 }))).toBe('error.unknown');
    expect(errorKey(answered(422, 'visitor_limit'))).toBe('error.unknown');
    // A max that is not a number is not put in.
    expect(errorParams(answered(422, 'visitor_limit', { limit: 'accounts', max: '<b>' }))).toEqual({ status: 422 });
    expect(answerParams(answered(422, 'x', ['a']))).toEqual({});
    expect(answerParams(new Error('boom'))).toEqual({});
  });

  it('says the status of an answer without a code', () => {
    for (const error of [
      new HttpErrorResponse({ status: 502, error: '<html>Bad gateway</html>' }),
      new HttpErrorResponse({ status: 502, error: null }),
      // An admin-only refusal: the server's sentence and no code.
      answered(502),
      new HttpErrorResponse({ status: 502, error: { message: 'x', code: '' } }),
      new HttpErrorResponse({ status: 502, error: { message: 'x', code: 7 } }),
      new HttpErrorResponse({ status: 502, error: ['server_error'] }),
    ]) {
      expect(errorKey(error)).toBe('error.http');
      expect(errorParams(error)).toEqual({ status: 502 });
    }
  });

  it('falls back to one key for anything else - never a key made from the code', () => {
    expect(errorKey(answered(405, 'method_not_allowed'))).toBe('error.unknown');
    expect(errorKey(answered(503, 'translations_unavailable'))).toBe('error.unknown');
    expect(errorKey(answered(422, 'showcase_busy'))).toBe('error.unknown');
    expect(errorKey(answered(422, 'constructor'))).toBe('error.unknown');
    expect(errorKey(answered(422, 'http'))).toBe('error.unknown');
    expect(errorKey(answered(422, 'offline'))).toBe('error.unknown');
    expect(errorKey(answered(422, 'unknown'))).toBe('error.unknown');
    expect(errorKey(new Error('boom'))).toBe('error.unknown');
    expect(errorKey('boom')).toBe('error.unknown');
    expect(errorKey(null)).toBe('error.unknown');
    expect(errorParams(new Error('boom'))).toEqual({ status: 0 });
  });

  it('reads the code only from the API’s own answer', () => {
    expect(errorCode(answered(403, 'editor_only'))).toBe('editor_only');
    expect(errorCode(answered(403))).toBeNull();
    expect(errorCode(new HttpErrorResponse({ status: 403, error: 'editor_only' }))).toBeNull();
    expect(errorCode(new HttpErrorResponse({ status: 403, error: Object.create({ code: 'editor_only' }) }))).toBeNull();
    expect(errorCode({ error: { code: 'editor_only' } })).toBeNull();
  });

  it('only ever answers a key the key scan is told about - and every one of them has its English', () => {
    const declared = DYNAMIC_KEYS['error.'].map((tail) => `error.${tail}`);
    const answers = [
      new HttpErrorResponse({ status: 0 }),
      answered(401),
      answered(401, 'visitor_unknown'),
      answered(422, 'visitor_limit', { limit: 'accounts', max: 5 }),
      answered(422, 'visitor_limit', { limit: 'file_size', max: 5120 }),
      ...['editor_only', 'admin_only', 'too_many_visitors', 'visitors_unavailable', 'too_many_imports', 'not_found', 'server_error', 'not_set_up', 'invalid', 'nothing'].map((code) => answered(400, code)),
      answered(502),
    ].map((error) => errorKey(error));
    expect([...new Set<string>(answers)].sort()).toEqual([...declared].sort());
    expect(declared.filter((key) => !Object.hasOwn(en, key))).toEqual([]);
  });
});

describe('errorText() and the errorText pipe: what a page’s error holds, worded where it is shown', () => {
  const ENGLISH = {
    'error.offline': 'Cannot reach the server. Check your connection and try again.',
    'error.http': 'Request failed (HTTP {status}).',
    'error.editor_only': 'Only an editor or an admin can do this.',
    'error.unknown': 'Something went wrong.',
    'import.error.file_game_mismatch': 'This file contains {file_game} data, but you chose {chosen_game}.',
  };
  const SLOVAK = {
    'error.offline': 'Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.',
    'error.http': 'Požiadavka zlyhala (HTTP {status}).',
    'error.editor_only': 'Toto môže urobiť len editor alebo správca.',
    'error.unknown': 'Niečo sa pokazilo.',
    'import.error.file_game_mismatch': 'Súbor obsahuje údaje z hry {file_game}, ale zvolená hra je {chosen_game}.',
  };

  function slovak() {
    TestBed.configureTestingModule({ providers: [provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }), ErrorTextPipe] });
    return { i18n: TestBed.inject(TranslationService), pipe: TestBed.inject(ErrorTextPipe) };
  }

  it('words a failed request from its code - never with the server’s sentence', () => {
    const { i18n } = slovak();
    expect(errorText(i18n, new HttpErrorResponse({ status: 0 }))).toBe('Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.');
    expect(errorText(i18n, answered(403, 'editor_only'))).toBe('Toto môže urobiť len editor alebo správca.');
    expect(errorText(i18n, new HttpErrorResponse({ status: 502, error: '<html>' }))).toBe('Požiadavka zlyhala (HTTP 502).');
    expect(errorText(i18n, answered(409, 'something_new'))).toBe('Niečo sa pokazilo.');
    expect(errorText(i18n, new Error('boom'))).toBe('Niečo sa pokazilo.');
  });

  it('words a key with its params as it is - what the page decided itself', () => {
    const { i18n } = slovak();
    const wording = { key: 'import.error.file_game_mismatch', params: { file_game: 'Honkai: Star Rail', chosen_game: 'Genshin Impact' } };
    expect(errorText(i18n, wording)).toBe('Súbor obsahuje údaje z hry Honkai: Star Rail, ale zvolená hra je Genshin Impact.');
    expect(errorText(i18n, { key: 'error.unknown' })).toBe('Niečo sa pokazilo.');
    // Not a key: not worded as one.
    expect(errorText(i18n, { key: 'constructor' })).toBe('Niečo sa pokazilo.');
    expect(errorText(i18n, { key: 'no.such.key' })).toBe('Niečo sa pokazilo.');
  });

  it('follows the language as a pipe, and gives nothing for nothing', async () => {
    const { i18n, pipe } = slovak();
    const error = answered(403, 'editor_only');
    expect(pipe.transform(error)).toBe('Toto môže urobiť len editor alebo správca.');
    expect(pipe.transform(null)).toBe('');
    expect(pipe.transform(undefined)).toBe('');

    expect(await i18n.setLanguage('en', { remember: false })).toBe(true);
    expect(pipe.transform(error)).toBe('Only an editor or an admin can do this.');
    expect(pipe.transform({ key: 'error.http', params: { status: 418 } })).toBe('Request failed (HTTP 418).');
  });
});
