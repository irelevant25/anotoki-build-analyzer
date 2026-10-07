import { HttpErrorResponse, HttpEvent, HttpHandlerFn, HttpInterceptorFn, HttpRequest, HttpResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Observable, catchError, defer, finalize, from, switchMap, tap, throwError } from 'rxjs';
import { API_BASE, CARRIES_VISITOR_KEY, Caller, ISSUED_AS, VISITOR_KEY_HEADER } from './api';
import { AuthService } from './auth';
import { errorCode } from './i18n/error-text';
import { SavesInFlight } from './saves-in-flight';
import { SITE_STATUS_URL } from './site-status';
import { VisitorKey } from './visitor-key';

/**
 * Who a request of this app's own API is (never another origin's, never the site's words, never the site's
 * status - both are anybody's, asked with neither token nor key) - decided when the request was ISSUED (`ISSUED_AS`, which Api sets from the app's state as it makes the request; a request
 * without it is decided here, as it is handed over), never from whether a token happens to be there when it
 * leaves:
 *
 * - **A person's**: the IAM access token, never the visitor key. When the token comes back null (the refresh
 *   failed, signed out in another tab) the request is **never sent**: it fails here as a 401
 *   `sign_in_required`, and the page's signed-out handling takes over - it never goes out as a visitor's,
 *   and never makes one. When the API answers 401 to a token that looked valid (the session was ended, the
 *   IAM rotated its key), the request is sent once more with a fresh token; no fresh token: the 401 stands.
 *   A key in its answer is ignored.
 * - **A visitor's**, while the site does not require a sign-in (`auth.loginRequired()`): the visitor key this
 *   browser holds now (core/visitor-key.ts, read as the request leaves), if any. The answer that made a
 *   visitor - a request sent with no key - brings the new key in its `X-Visitor-Key` header, and it is kept
 *   (the first key stays). A 401 `visitor_unknown` forgets the key that was sent (only while it is still
 *   the one held). Never a token.
 * - **A visitor's while the site requires a sign-in**: neither key nor token - the key is KEPT (not sent, no
 *   new one taken, a 401 `sign_in_required` never forgets it), so the next sign-in still moves what it holds.
 * - **The move** after a sign-in (`CARRIES_VISITOR_KEY`, a person's): the token, beside the key the request
 *   set itself - the one request that carries both.
 *
 * Every write (anything but GET and HEAD) is counted while it is on its way (SavesInFlight): a sign-in waits
 * for them before it leaves the page.
 */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  if (!isApiRequest(request.url) || isTranslationsRequest(request.url) || isSiteStatusRequest(request.url)) {
    return next(request);
  }
  const auth = inject(AuthService);
  const visitor = inject(VisitorKey);
  const saves = inject(SavesInFlight);
  const caller: Caller = request.context.get(ISSUED_AS) ?? (auth.signedIn() ? 'person' : 'visitor');
  const sent =
    caller === 'person' ? asPerson(request, next, auth) : asVisitor(request, next, visitor, auth.loginRequired());
  return counted(request, sent, saves);
};

/** A person's request: with the token, or not at all. */
function asPerson(request: HttpRequest<unknown>, next: HttpHandlerFn, auth: AuthService): Observable<HttpEvent<unknown>> {
  // A key is never sent for a person - but by the move, which set it itself.
  if (!request.context.get(CARRIES_VISITOR_KEY)) {
    request = request.clone({ headers: request.headers.delete(VISITOR_KEY_HEADER) });
  }
  return from(auth.accessToken()).pipe(
    switchMap((token) => {
      if (!token) {
        return throwError(() => notSignedIn(request));
      }
      return next(withBearer(request, token)).pipe(
        catchError((error: unknown) => {
          if (!(error instanceof HttpErrorResponse) || error.status !== 401) {
            return throwError(() => error);
          }
          return from(auth.refreshedToken()).pipe(
            switchMap((fresh) => (fresh && fresh !== token ? next(withBearer(request, fresh)) : throwError(() => error))),
          );
        }),
      );
    }),
  );
}

/**
 * A visitor's request: with the key held now, if any - and the key a new visitor gets - while the site does
 * not require a sign-in; with neither while it does (the key stays where it is).
 */
function asVisitor(
  request: HttpRequest<unknown>,
  next: HttpHandlerFn,
  visitor: VisitorKey,
  loginRequired: boolean,
): Observable<HttpEvent<unknown>> {
  const bare = request.clone({ headers: request.headers.delete(VISITOR_KEY_HEADER).delete('Authorization') });
  if (loginRequired) {
    return next(bare);
  }
  const key = visitor.current();
  const sent = key ? bare.clone({ setHeaders: { [VISITOR_KEY_HEADER]: key } }) : bare;
  return next(sent).pipe(
    tap((event) => {
      const made = !key && event instanceof HttpResponse ? event.headers.get(VISITOR_KEY_HEADER) : null;
      if (made) {
        visitor.remember(made);
      }
    }),
    catchError((error: unknown) => {
      if (key && error instanceof HttpErrorResponse && error.status === 401 && errorCode(error) === 'visitor_unknown') {
        visitor.forget(key, true);
      }
      return throwError(() => error);
    }),
  );
}

/** What a person's request with no token to send answers, without being sent: what the API would say. */
function notSignedIn(request: HttpRequest<unknown>): HttpErrorResponse {
  return new HttpErrorResponse({
    status: 401,
    statusText: 'Unauthorized',
    url: request.urlWithParams,
    error: { message: 'Sign in to continue.', code: 'sign_in_required' },
  });
}

/** A write, counted from the moment it is handed over until it has its answer (or fails, or is dropped). */
function counted(request: HttpRequest<unknown>, sent: Observable<HttpEvent<unknown>>, saves: SavesInFlight): Observable<HttpEvent<unknown>> {
  if (request.method === 'GET' || request.method === 'HEAD') {
    return sent;
  }
  return defer(() => {
    const end = saves.begin();
    return sent.pipe(finalize(end));
  });
}

function withBearer<T>(request: HttpRequest<T>, token: string | null): HttpRequest<T> {
  return token ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } }) : request;
}

/** "/api/…" on this site, written relative or absolute. */
export function isApiRequest(url: string): boolean {
  try {
    const target = new URL(url, window.location.href);
    return target.origin === window.location.origin && (target.pathname === API_BASE || target.pathname.startsWith(`${API_BASE}/`));
  } catch {
    return false;
  }
}

/**
 * "/api/translations/…": the site's own words, which the server gives to anybody. They are asked for before
 * the first page is drawn, so they go without a token and never wait for one to be refreshed. (The owner's
 * routes are under /api/admin/ and get their token like every other request.)
 */
export function isTranslationsRequest(url: string): boolean {
  try {
    return new URL(url, window.location.href).pathname.startsWith(`${API_BASE}/translations/`);
  } catch {
    return false;
  }
}

/**
 * "/api/site-status": where the site stands, which the server tells anybody (core/site-status.ts). It is asked
 * at the start and again by the waiting page, so it goes without a token - never waiting for one to be
 * refreshed, never refused for want of one (a person's request with no token is never sent, and an unsent
 * status reads as "not available") - and without a visitor's key.
 */
export function isSiteStatusRequest(url: string): boolean {
  try {
    return new URL(url, window.location.href).pathname === SITE_STATUS_URL;
  } catch {
    return false;
  }
}
