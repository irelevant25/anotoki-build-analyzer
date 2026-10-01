import { HttpErrorResponse, HttpInterceptorFn, HttpRequest } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, from, switchMap, throwError } from 'rxjs';
import { API_BASE } from './api';
import { AuthService } from './auth';

/**
 * Adds the IAM access token to this app's own API requests (never to another origin). When the API
 * answers 401 to a token that looked valid (the session was ended, the IAM rotated its key), the request
 * is sent once more with a fresh token; no fresh token means the person is signed out.
 */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  const auth = inject(AuthService);
  if (!auth.usesSignIn() || !isApiRequest(request.url) || isTranslationsRequest(request.url)) {
    return next(request);
  }

  return from(auth.accessToken()).pipe(
    switchMap((token) =>
      next(withBearer(request, token)).pipe(
        catchError((error: unknown) => {
          if (!(error instanceof HttpErrorResponse) || error.status !== 401 || !token) {
            return throwError(() => error);
          }
          return from(auth.refreshedToken()).pipe(
            switchMap((fresh) => (fresh && fresh !== token ? next(withBearer(request, fresh)) : throwError(() => error))),
          );
        }),
      ),
    ),
  );
};

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
