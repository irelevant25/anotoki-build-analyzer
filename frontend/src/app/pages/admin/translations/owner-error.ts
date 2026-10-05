import { HttpErrorResponse } from '@angular/common/http';
import { errorMessage } from '../../../core/api';

/** What the two owner pages say while the database update that brings the strings' tables waits. */
export const TRANSLATIONS_UNAVAILABLE = 'An admin applies the waiting database update on the Migrations page first.';

/**
 * A request of the Translations or the Languages page that did not go through, as those pages show it:
 * the server's own English sentence (errorMessage()), and what its body says the refusal is about - the
 * code, and for a string the key and the language. The pages are English, so the sentence is shown as
 * it comes; only the 503 of a database update not applied yet is said the pages' way.
 */
export interface OwnerError {
  message: string;
  code: string | null;
  key: string | null;
  language: string | null;
}

export function ownerError(error: unknown): OwnerError {
  const body = error instanceof HttpErrorResponse && isRecord(error.error) ? error.error : {};
  const code = text(body['code']);
  return {
    message: code === 'translations_unavailable' ? TRANSLATIONS_UNAVAILABLE : errorMessage(error),
    code,
    key: text(body['key']),
    language: text(body['language']),
  };
}

/** ownerError() for a request whose answer is a file: its error body then arrives as a Blob, read first. */
export async function ownerFileError(error: unknown): Promise<OwnerError> {
  if (error instanceof HttpErrorResponse && error.error instanceof Blob) {
    try {
      const body: unknown = JSON.parse(await error.error.text());
      return ownerError(new HttpErrorResponse({ error: body, status: error.status, statusText: error.statusText }));
    } catch {
      // Not JSON: the sentence for the status alone.
    }
  }
  return ownerError(error);
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function text(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}
