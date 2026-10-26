import { HttpClient, HttpContext, HttpErrorResponse, HttpParams, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { CARRIES_VISITOR_KEY, VISITOR_KEY_HEADER, issuedAs } from '@anotoki/lib/auth';
import { Observable } from 'rxjs';
import {
  AccountDetail,
  AccountSummary,
  AdminBuildsGame,
  BuildCharacter,
  CharacterEditing,
  BuildImportChoices,
  BuildImportPlan,
  BuildsOverview,
  CatalogList,
  CatalogRefreshResult,
  CharacterAnalysis,
  CharacterBuilds,
  DataImportResult,
  EditorCatalog,
  EditorSave,
  GameKey,
  ImportRun,
  Meta,
  VisitorMove,
} from './models';

/** The API's path on this site; the app and the API share one origin (proxied during development). */
export const API_BASE = '/api';

/**
 * Typed client for the backend API (backend/api/routes). Each request says whom it is for as it is made - the
 * anotoki family's ISSUED_AS (@anotoki/lib/auth), which its interceptor reads: the admin panel's, the
 * own-data file's and the move's are always the person's (the token, or the request is never sent - it
 * fails at once as a 401 sign_in_required: a person's save never goes out as a visitor's, and never makes one);
 * the rest are whoever the page is shown to as the request is issued - the person signed in, else the visitor
 * (the visitor key, while the IAM lets visitors in; never a token).
 */
@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);
  private readonly base = API_BASE;

  /** For whoever uses the site now: the person signed in, or else the visitor. */
  private asCaller(): HttpContext {
    return issuedAs('caller');
  }

  /** For a signed-in person only, whatever the state: what a visitor may never do. */
  private asPerson(): HttpContext {
    return issuedAs('person');
  }

  /** Public, and read with no credentials at all: the interceptor passes it by (app.config.ts, `api.skip`). */
  meta(): Observable<Meta> {
    return this.http.get<Meta>(`${this.base}/meta`);
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

  /** The build the account's character is judged by - one of its builds, or null for the closest match. */
  chooseBuild(accountId: number, key: string, buildId: number | null): Observable<{ chosen_build_id: number | null }> {
    return this.http.put<{ chosen_build_id: number | null }>(`${this.base}/accounts/${accountId}/characters/${key}/build`, { build_id: buildId }, { context: this.asCaller() });
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

  /** Per game: how many characters have builds and how many builds, when they were checked and changed (the dashboard). */
  buildsOverview(): Observable<BuildsOverview[]> {
    return this.http.get<BuildsOverview[]>(`${this.base}/builds`, { context: this.asCaller() });
  }

  // ---------------------------------------------------------------- admin: the builds and the catalog

  /** The Builds page: per game the counts, the latest imports and who made them, the catalog's lists. */
  adminBuilds(): Observable<AdminBuildsGame[]> {
    return this.http.get<AdminBuildsGame[]>(`${this.base}/admin/builds`, { context: this.asPerson() });
  }

  /**
   * A game's builds as a file to save, its name in Content-Disposition. Fetched here rather than opened as a link,
   * because the API wants the bearer token.
   */
  exportBuilds(game: GameKey): Observable<HttpResponse<Blob>> {
    return this.http.get(`${this.base}/admin/builds/${game}/export`, { observe: 'response', responseType: 'blob', context: this.asPerson() });
  }

  /**
   * A builds file's text, sent as it is: as a dry run, what importing it would do (nothing written); else the game's
   * builds become the file's, but for what editors did by hand - the questions named in `choices` answered with the
   * file, every other with the editors' work.
   */
  importBuilds(game: GameKey, file: string, dryRun: boolean, choices: BuildImportChoices | null = null): Observable<BuildImportPlan> {
    const query = new URLSearchParams();
    if (dryRun) {
      query.set('dry_run', '1');
    }
    if (choices && (choices.file === 'all' || choices.file.length > 0)) {
      query.set('file', choices.file === 'all' ? 'all' : choices.file.join(','));
      query.set('plan', choices.plan);
    }
    const search = query.toString();
    return this.http.post<BuildImportPlan>(`${this.base}/admin/builds/${game}/import${search ? `?${search}` : ''}`, file, {
      context: this.asPerson(),
      headers: { 'Content-Type': 'application/json' },
    });
  }

  /** What the builds editor picks from and holds a build to: the game's facts, and the catalog's characters, weapons and sets. */
  editorCatalog(game: GameKey): Observable<EditorCatalog> {
    return this.http.get<EditorCatalog>(`${this.base}/admin/builds/${game}/catalog`, { context: this.asPerson() });
  }

  /** A character's builds as the builds editor edits them, the builds an editor removed, and the version a save is checked against. */
  characterEditing(game: GameKey, key: string): Observable<CharacterEditing> {
    return this.http.get<CharacterEditing>(`${this.base}/admin/builds/${game}/characters/${encodeURIComponent(key)}`, { context: this.asPerson() });
  }

  /**
   * A character's builds as the editor leaves them, saved whole and answered as loaded: refused (nothing written) with
   * the build and the path a problem is at (422), while an import or another save is written (409 busy), or when the
   * builds changed since they were loaded (409 changed).
   */
  saveCharacterEditing(game: GameKey, key: string, save: EditorSave): Observable<CharacterEditing> {
    return this.http.put<CharacterEditing>(`${this.base}/admin/builds/${game}/characters/${encodeURIComponent(key)}`, save, { context: this.asPerson() });
  }

  /**
   * One list of the game catalog, refreshed from the game data provider - in full, not by its version tag: an
   * editor pressing the button wants every row read again (the upkeep's refresh after an answer stays conditional).
   */
  refreshCatalog(game: GameKey, list: CatalogList): Observable<CatalogRefreshResult> {
    return this.http.post<CatalogRefreshResult>(`${this.base}/admin/catalog/${game}/${list}`, {}, { context: this.asPerson(), params: { force: '1' } });
  }

  // The People page, the error log, the audit log, feedback and backups (/api/admin/people, /errors, /audit,
  // /feedback, /backups) are the anotoki family's pages, which make their own requests (@anotoki/lib/people/page,
  // @anotoki/lib/admin/*); the interceptor makes them the person's as it makes every request of this site's.

  // ---------------------------------------------------------------- a visitor's data, and a person's own

  /**
   * After a sign-in: what this browser imported as a visitor becomes the person's. The only request with the
   * token and the key together (CARRIES_VISITOR_KEY) - the key is set here, and the interceptor adds the token.
   * A person's request: with no token it is never sent. `silent`: the sign-in was the IAM's hint's silent hop -
   * then the server moves nothing into an account that already has data here (held: true).
   */
  moveVisitor(key: string, silent = false): Observable<VisitorMove> {
    return this.http.post<VisitorMove>(
      `${this.base}/visitor/move`,
      { silent },
      {
        headers: { [VISITOR_KEY_HEADER]: key },
        context: this.asPerson().set(CARRIES_VISITOR_KEY, true),
      },
    );
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

  // The site's status and the Migrations page's API (/api/site-status, /api/admin/migrations) are the anotoki
  // family's module's own requests (@anotoki/lib/migrations, shared/site-status.ts), not this service's.
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
