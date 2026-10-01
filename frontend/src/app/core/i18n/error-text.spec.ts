import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { DYNAMIC_KEYS } from './dynamic-keys';
import { en } from './en';
import { ErrorTextPipe, errorCode, errorKey, errorParams, errorText } from './error-text';
import { provideTestTranslations } from './testing';
import { TranslationService } from './translation';

/** An answer of the API: a status, and the body's code beside the server's English sentence. */
function answered(status: number, code?: string): HttpErrorResponse {
  return new HttpErrorResponse({ status, error: { message: 'The server said this in English.', ...(code === undefined ? {} : { code }) } });
}

describe('errorKey(): a failed request in the reader’s words', () => {
  it('is "offline" when there was no answer at all', () => {
    expect(errorKey(new HttpErrorResponse({ status: 0, error: new ProgressEvent('error') }))).toBe('error.offline');
    // Whatever a body might claim.
    expect(errorKey(answered(0, 'server_error'))).toBe('error.offline');
  });

  it('is "your sign-in has ended" for any 401, whatever it says', () => {
    expect(errorKey(answered(401, 'sign_in_required'))).toBe('error.sign_in_required');
    expect(errorKey(answered(401))).toBe('error.sign_in_required');
    expect(errorKey(answered(401, 'something_new'))).toBe('error.sign_in_required');
    expect(errorKey(new HttpErrorResponse({ status: 401, error: null }))).toBe('error.sign_in_required');
  });

  it('words the server’s codes that mean the same on every page', () => {
    expect(errorKey(answered(403, 'local_only'))).toBe('error.local_only');
    expect(errorKey(answered(403, 'no_access'))).toBe('error.no_access');
    expect(errorKey(answered(403, 'admin_only'))).toBe('error.admin_only');
    expect(errorKey(answered(429, 'too_many_imports'))).toBe('error.too_many_imports');
    expect(errorKey(answered(404, 'not_found'))).toBe('error.not_found');
    expect(errorKey(answered(500, 'server_error'))).toBe('error.server_error');
    expect(errorKey(answered(503, 'not_set_up'))).toBe('error.not_set_up');
    expect(errorKey(answered(422, 'invalid'))).toBe('error.invalid');
    // A code that reaches a page with another status is still that code.
    expect(errorKey(answered(400, 'sign_in_required'))).toBe('error.sign_in_required');
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
    expect(errorCode(answered(403, 'no_access'))).toBe('no_access');
    expect(errorCode(answered(403))).toBeNull();
    expect(errorCode(new HttpErrorResponse({ status: 403, error: 'no_access' }))).toBeNull();
    expect(errorCode(new HttpErrorResponse({ status: 403, error: Object.create({ code: 'no_access' }) }))).toBeNull();
    expect(errorCode({ error: { code: 'no_access' } })).toBeNull();
  });

  it('only ever answers a key the key scan is told about - and every one of them has its English', () => {
    const declared = DYNAMIC_KEYS['error.'].map((tail) => `error.${tail}`);
    const answers = [
      new HttpErrorResponse({ status: 0 }),
      answered(401),
      ...['local_only', 'no_access', 'admin_only', 'too_many_imports', 'not_found', 'server_error', 'not_set_up', 'invalid', 'nothing'].map((code) => answered(400, code)),
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
    'error.no_access': 'Your account has no access to the build analyzer. Ask the owner to add you.',
    'error.unknown': 'Something went wrong.',
    'import.error.file_game_mismatch': 'This file contains {file_game} data, but you chose {chosen_game}.',
  };
  const SLOVAK = {
    'error.offline': 'Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.',
    'error.http': 'Požiadavka zlyhala (HTTP {status}).',
    'error.no_access': 'Tvoj účet nemá prístup do aplikácie Build Analyzer. Požiadaj vlastníka o prístup.',
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
    expect(errorText(i18n, answered(403, 'no_access'))).toBe('Tvoj účet nemá prístup do aplikácie Build Analyzer. Požiadaj vlastníka o prístup.');
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
    const error = answered(403, 'no_access');
    expect(pipe.transform(error)).toBe('Tvoj účet nemá prístup do aplikácie Build Analyzer. Požiadaj vlastníka o prístup.');
    expect(pipe.transform(null)).toBe('');
    expect(pipe.transform(undefined)).toBe('');

    expect(await i18n.setLanguage('en', false)).toBe(true);
    expect(pipe.transform(error)).toBe('Your account has no access to the build analyzer. Ask the owner to add you.');
    expect(pipe.transform({ key: 'error.http', params: { status: 418 } })).toBe('Request failed (HTTP 418).');
  });
});
