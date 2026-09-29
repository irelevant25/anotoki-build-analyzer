import { HttpErrorResponse } from '@angular/common/http';
import { errorMessage, fileErrorMessage } from './api';

describe('error messages', () => {
  it('reads the API\'s message out of a file request\'s error, which arrives as a Blob', async () => {
    const answer = new Blob([JSON.stringify({ message: 'Only an admin can do this.' })], { type: 'application/json' });
    expect(await fileErrorMessage(new HttpErrorResponse({ error: answer, status: 403 }))).toBe('Only an admin can do this.');
  });

  it('falls back to the status when the Blob holds no message', async () => {
    expect(await fileErrorMessage(new HttpErrorResponse({ error: new Blob(['<html>'], { type: 'text/html' }), status: 502 }))).toBe(
      'Request failed (HTTP 502).',
    );
  });

  it('reads other errors as errorMessage() does', async () => {
    const error = new HttpErrorResponse({ error: { message: 'Not found.' }, status: 404 });
    expect(await fileErrorMessage(error)).toBe(errorMessage(error));
    expect(await fileErrorMessage(new HttpErrorResponse({ error: null, status: 0 }))).toBe('Cannot reach the server. Check your connection and try again.');
  });
});
