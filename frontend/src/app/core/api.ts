import { HttpClient, HttpContext, HttpContextToken, HttpErrorResponse, HttpParams, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { AuthService } from './auth';
import {
  AccountDetail,
  AccountSummary,
  AdminLanguage,
  BuildCharacter,
  BuildImportResult,
  CharacterAnalysis,
  CharacterBuilds,
  DataImportResult,
  GameKey,
  ImportRun,
  LanguageChanges,
  Meta,
  MigrationResult,
  MigrationStatus,
  NewLanguage,
  PeopleList,
  PersonDeleted,
  SourceStatus,
  SyncRunSummary,
  SyncStartResult,
  SyncStatus,
  SyncStepResult,
  TranslationBundle,
  TranslationChanges,
  TranslationGrid,
  VisitorMove,
} from './models';

/** The API's path on this site; the app and the API share one origin (proxied during development). */
export const API_BASE = '/api';

/** The header a visitor's key travels in, both ways (core/visitor-key.ts). */
export const VISITOR_KEY_HEADER = 'X-Visitor-Key';

/**
 * Marks the one request that carries the person's token and the visitor's key together - the move after a
 * sign-in: authInterceptor then adds the token and leaves the key the request set itself.
 */
export const CARRIES_VISITOR_KEY = new HttpContextToken<boolean>(() => false);

/** Who a request is for: the signed-in person, or the visitor this browser is (or will be, after a first import). */
export type Caller = 'person' | 'visitor';

/**
 * Who a request of the site's own API is for, decided when it is ISSUED - from the app's state then, never
 * from whether a token happens to be there when it leaves (authInterceptor reads it):
 *
 *   'person'   the token, or the request is never sent (it fails at once as a 401 sign_in_required): a
 *              person's import, rename or delete never goes out as a visitor's, and never makes one
 *   'visitor'  the visitor key, while the site does not require a sign-in; never a token
 *   null       a request Api did not issue: decided by the interceptor as it is handed the request
 */
export const ISSUED_AS = new HttpContextToken<Caller | null>(() => null);

/** A request's context saying who it is for. */
export function issuedAs(caller: Caller): HttpContext {
  return new HttpContext().set(ISSUED_AS, caller);
}

/**
 * Typed client for the backend API (backend/api/routes). Each request says who it is for (ISSUED_AS) as it
 * is made: the admin panel's, the syncs', the own-data file's and the move's are always the person's; the
 * rest are the person's while somebody is signed in and the visitor's otherwise. authInterceptor adds the
 * bearer token or the visitor key accordingly.
 */
@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);
  private readonly auth = inject(AuthService);
  private readonly base = API_BASE;

  /** For whoever uses the site now: the person signed in, or else the visitor. */
  private asCaller(): HttpContext {
    return issuedAs(this.auth.signedIn() ? 'person' : 'visitor');
  }

  /** For a signed-in person only, whatever the state: what a visitor may never do. */
  private asPerson(): HttpContext {
    return issuedAs('person');
  }

  meta(): Observable<Meta> {
    return this.http.get<Meta>(`${this.base}/meta`, { context: this.asCaller() });
  }

  /**
   * The site's own words in one language (core/i18n). Public: authInterceptor sends it without a token.
   * Only ever asked with a code that has the shape of one.
   */
  translations(code: string): Observable<TranslationBundle> {
    return this.http.get<TranslationBundle>(`${this.base}/translations/${encodeURIComponent(code)}`);
  }

  // The owner's two pages, Languages and Translations (admins only; 503 translations_unavailable while
  // the database update that brings the tables waits on the Migrations page).

  languages(): Observable<{ languages: AdminLanguage[] }> {
    return this.http.get<{ languages: AdminLanguage[] }>(`${this.base}/admin/languages`, { context: this.asPerson() });
  }

  createLanguage(language: NewLanguage): Observable<{ language: AdminLanguage }> {
    return this.http.post<{ language: AdminLanguage }>(`${this.base}/admin/languages`, language, { context: this.asPerson() });
  }

  updateLanguage(code: string, changes: LanguageChanges): Observable<{ language: AdminLanguage }> {
    return this.http.put<{ language: AdminLanguage }>(`${this.base}/admin/languages/${encodeURIComponent(code)}`, changes, { context: this.asPerson() });
  }

  /** Answers how many strings went with the language. */
  deleteLanguage(code: string): Observable<{ strings: number }> {
    return this.http.delete<{ strings: number }>(`${this.base}/admin/languages/${encodeURIComponent(code)}`, { context: this.asPerson() });
  }

  translationGrid(): Observable<TranslationGrid> {
    return this.http.get<TranslationGrid>(`${this.base}/admin/translations`, { context: this.asPerson() });
  }

  /** Only the changed cells; checked whole by the server, all or nothing. Answers the grid as it is now. */
  saveTranslations(values: TranslationChanges): Observable<TranslationGrid> {
    return this.http.put<TranslationGrid>(`${this.base}/admin/translations`, { values }, { context: this.asPerson() });
  }

  /** A language's own strings as a file to save, its name in Content-Disposition (fetched: the API wants the token). */
  exportTranslations(code: string): Observable<HttpResponse<Blob>> {
    return this.http.get(`${this.base}/admin/translations/${encodeURIComponent(code)}/export`, { observe: 'response', responseType: 'blob', context: this.asPerson() });
  }

  /** A flat {key: text} into one language, checked like a save. Answers the grid as it is now. */
  importTranslations(code: string, values: Record<string, string>): Observable<TranslationGrid> {
    return this.http.put<TranslationGrid>(`${this.base}/admin/translations/${encodeURIComponent(code)}/import`, values, { context: this.asPerson() });
  }

  accounts(): Observable<AccountSummary[]> {
    return this.http.get<AccountSummary[]>(`${this.base}/accounts`, { context: this.asCaller() });
  }

  account(id: number): Observable<AccountDetail> {
    return this.http.get<AccountDetail>(`${this.base}/accounts/${id}`, { context: this.asCaller() });
  }

  renameAccount(id: number, nickname: string): Observable<AccountSummary> {
    return this.http.patch<AccountSummary>(`${this.base}/accounts/${id}`, { nickname }, { context: this.asCaller() });
  }

  deleteAccount(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/accounts/${id}`, { context: this.asCaller() });
  }

  accountImports(id: number): Observable<ImportRun[]> {
    return this.http.get<ImportRun[]>(`${this.base}/accounts/${id}/imports`, { context: this.asCaller() });
  }

  character(accountId: number, key: string, buildId?: number | null): Observable<CharacterAnalysis> {
    let params = new HttpParams();
    if (buildId) {
      params = params.set('build', buildId);
    }
    return this.http.get<CharacterAnalysis>(`${this.base}/accounts/${accountId}/characters/${key}`, { params, context: this.asCaller() });
  }

  /** The characters of a public in-game Character Showcase, by UID. */
  importShowcase(game: GameKey, uid: string, accountId: number | null = null): Observable<ImportRun> {
    return this.http.post<ImportRun>(`${this.base}/imports/showcase`, { game, uid, account_id: accountId }, { context: this.asCaller() });
  }

  importFile(payload: { game?: GameKey; account_id?: number | null; content: string; file_name?: string | null }): Observable<ImportRun> {
    return this.http.post<ImportRun>(`${this.base}/imports/file`, payload, { context: this.asCaller() });
  }

  buildCharacters(game: GameKey): Observable<BuildCharacter[]> {
    return this.http.get<BuildCharacter[]>(`${this.base}/builds/${game}`, { context: this.asCaller() });
  }

  characterBuilds(game: GameKey, key: string): Observable<CharacterBuilds> {
    return this.http.get<CharacterBuilds>(`${this.base}/builds/${game}/${key}`, { context: this.asCaller() });
  }

  sources(): Observable<SourceStatus[]> {
    return this.http.get<SourceStatus[]>(`${this.base}/sources`, { context: this.asCaller() });
  }

  // ---------------------------------------------------------------- admin: syncs and the database

  setSourceEnabled(key: string, enabled: boolean): Observable<SourceStatus> {
    return this.http.patch<SourceStatus>(`${this.base}/sources/${key}`, { enabled }, { context: this.asPerson() });
  }

  /** "Sync now" on one source (always, however recently it was synced). */
  syncSource(key: string): Observable<SyncStartResult> {
    return this.http.post<SyncStartResult>(`${this.base}/sources/${key}/sync`, {}, { context: this.asPerson() });
  }

  /**
   * A source's builds as a file to save, its name in Content-Disposition. Fetched here rather than opened
   * as a link, because the API wants the bearer token.
   */
  exportSourceBuilds(key: string): Observable<HttpResponse<Blob>> {
    return this.http.get(`${this.base}/sources/${key}/export`, { observe: 'response', responseType: 'blob', context: this.asPerson() });
  }

  /** Makes a source's builds exactly those of a builds file, whose text is sent as it is. */
  importSourceBuilds(key: string, file: string): Observable<BuildImportResult> {
    return this.http.post<BuildImportResult>(`${this.base}/sources/${key}/import`, file, {
      context: this.asPerson(),
      headers: { 'Content-Type': 'application/json' },
    });
  }

  /** Every enabled source; without force, sources synced within the last 20 hours are skipped. */
  syncAllSources(force = false): Observable<SyncStartResult> {
    return this.http.post<SyncStartResult>(`${this.base}/sync/builds`, { force }, { context: this.asPerson() });
  }

  syncCatalog(games?: GameKey[]): Observable<SyncStartResult> {
    return this.http.post<SyncStartResult>(`${this.base}/sync/catalog`, games ? { games } : {}, { context: this.asPerson() });
  }

  syncStatus(): Observable<SyncStatus> {
    return this.http.get<SyncStatus>(`${this.base}/sync`, { context: this.asPerson() });
  }

  /** One bounded piece of sync work (a page or two); call again after wait_ms while anything is active. */
  syncStep(): Observable<SyncStepResult> {
    return this.http.post<SyncStepResult>(`${this.base}/sync/step`, {}, { context: this.asPerson() });
  }

  cancelSyncRun(id: number): Observable<SyncRunSummary> {
    return this.http.post<SyncRunSummary>(`${this.base}/sync-runs/${id}/cancel`, {}, { context: this.asPerson() });
  }

  syncRuns(source?: string, limit = 20): Observable<SyncRunSummary[]> {
    let params = new HttpParams().set('limit', limit);
    if (source) {
      params = params.set('source', source);
    }
    return this.http.get<SyncRunSummary[]>(`${this.base}/sync-runs`, { params, context: this.asPerson() });
  }

  syncRun(id: number): Observable<SyncRunSummary> {
    return this.http.get<SyncRunSummary>(`${this.base}/sync-runs/${id}`, { context: this.asPerson() });
  }

  // ---------------------------------------------------------------- admin: people (ADMIN)

  people(): Observable<PeopleList> {
    return this.http.get<PeopleList>(`${this.base}/admin/people`, { context: this.asPerson() });
  }

  /** Deletes what a person has here (their game accounts and imports); their anotoki account stays. */
  deletePerson(id: number): Observable<PersonDeleted> {
    return this.http.delete<PersonDeleted>(`${this.base}/admin/people/${id}`, { context: this.asPerson() });
  }

  // ---------------------------------------------------------------- a visitor's data, and a person's own

  /**
   * After a sign-in: what this browser imported as a visitor becomes the person's. The only request with the
   * token and the key together - the key is set here, and the interceptor adds the token. A person's
   * request: with no token it is never sent.
   */
  moveVisitor(key: string): Observable<VisitorMove> {
    return this.http.post<VisitorMove>(`${this.base}/visitor/move`, {}, {
      headers: { [VISITOR_KEY_HEADER]: key },
      context: this.asPerson().set(CARRIES_VISITOR_KEY, true),
    });
  }

  /** The signed-in person's game accounts as one file, its name in Content-Disposition. */
  exportData(): Observable<HttpResponse<Blob>> {
    return this.http.get(`${this.base}/me/export`, { observe: 'response', responseType: 'blob', context: this.asPerson() });
  }

  /**
   * The own-data file, sent as it was saved (its text unchanged: the size limit is the body's). A dry run
   * says what the import would do and changes nothing.
   */
  importData(file: string, dryRun: boolean): Observable<DataImportResult> {
    return this.http.post<DataImportResult>(`${this.base}/me/import${dryRun ? '?dry_run=1' : ''}`, file, {
      context: this.asPerson(),
      headers: { 'Content-Type': 'application/json' },
    });
  }

  migrations(): Observable<MigrationStatus> {
    return this.http.get<MigrationStatus>(`${this.base}/admin/migrations`, { context: this.asPerson() });
  }

  applyMigrations(): Observable<MigrationResult> {
    return this.http.post<MigrationResult>(`${this.base}/admin/migrations/apply`, {}, { context: this.asPerson() });
  }
}

/**
 * A readable message for any API error (validation, import problems, sign-in, server down).
 */
export function errorMessage(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    if (error.status === 0) {
      return 'Cannot reach the server. Check your connection and try again.';
    }
    const body = error.error as { message?: string; errors?: Record<string, string[]> } | null;
    if (error.status === 401) {
      return 'Your sign-in has ended. Sign in again to continue.';
    }
    const firstValidation = body?.errors ? Object.values(body.errors)[0]?.[0] : null;
    return firstValidation ?? body?.message ?? `Request failed (HTTP ${error.status}).`;
  }
  return error instanceof Error ? error.message : 'Something went wrong.';
}

/**
 * errorMessage() for a request whose answer is a file (a Blob): the API's {message} then arrives as a Blob
 * too, and has to be read first.
 */
export async function fileErrorMessage(error: unknown): Promise<string> {
  if (error instanceof HttpErrorResponse && error.error instanceof Blob) {
    try {
      const body: unknown = JSON.parse(await error.error.text());
      return errorMessage(new HttpErrorResponse({ error: body, status: error.status, statusText: error.statusText }));
    } catch {
      // Not JSON: the message for the status alone.
    }
  }
  return errorMessage(error);
}
