import { HttpClient, HttpErrorResponse, HttpParams, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AccountDetail,
  AccountSummary,
  BuildCharacter,
  BuildImportResult,
  CharacterAnalysis,
  CharacterBuilds,
  GameKey,
  ImportRun,
  Meta,
  MigrationResult,
  MigrationStatus,
  SourceStatus,
  SyncRunSummary,
  SyncStartResult,
  SyncStatus,
  SyncStepResult,
} from './models';

/** The API's path on this site; the app and the API share one origin (proxied during development). */
export const API_BASE = '/api';

/**
 * Typed client for the backend API (backend/api/routes). The bearer token is added by authInterceptor.
 */
@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);
  private readonly base = API_BASE;

  meta(): Observable<Meta> {
    return this.http.get<Meta>(`${this.base}/meta`);
  }

  accounts(): Observable<AccountSummary[]> {
    return this.http.get<AccountSummary[]>(`${this.base}/accounts`);
  }

  account(id: number): Observable<AccountDetail> {
    return this.http.get<AccountDetail>(`${this.base}/accounts/${id}`);
  }

  renameAccount(id: number, nickname: string): Observable<AccountSummary> {
    return this.http.patch<AccountSummary>(`${this.base}/accounts/${id}`, { nickname });
  }

  deleteAccount(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/accounts/${id}`);
  }

  accountImports(id: number): Observable<ImportRun[]> {
    return this.http.get<ImportRun[]>(`${this.base}/accounts/${id}/imports`);
  }

  character(accountId: number, key: string, buildId?: number | null): Observable<CharacterAnalysis> {
    let params = new HttpParams();
    if (buildId) {
      params = params.set('build', buildId);
    }
    return this.http.get<CharacterAnalysis>(`${this.base}/accounts/${accountId}/characters/${key}`, { params });
  }

  /** The characters of a public in-game Character Showcase, by UID. */
  importShowcase(game: GameKey, uid: string, accountId: number | null = null): Observable<ImportRun> {
    return this.http.post<ImportRun>(`${this.base}/imports/showcase`, { game, uid, account_id: accountId });
  }

  importFile(payload: { game?: GameKey; account_id?: number | null; content: string; file_name?: string | null }): Observable<ImportRun> {
    return this.http.post<ImportRun>(`${this.base}/imports/file`, payload);
  }

  buildCharacters(game: GameKey): Observable<BuildCharacter[]> {
    return this.http.get<BuildCharacter[]>(`${this.base}/builds/${game}`);
  }

  characterBuilds(game: GameKey, key: string): Observable<CharacterBuilds> {
    return this.http.get<CharacterBuilds>(`${this.base}/builds/${game}/${key}`);
  }

  sources(): Observable<SourceStatus[]> {
    return this.http.get<SourceStatus[]>(`${this.base}/sources`);
  }

  // ---------------------------------------------------------------- admin: syncs and the database

  setSourceEnabled(key: string, enabled: boolean): Observable<SourceStatus> {
    return this.http.patch<SourceStatus>(`${this.base}/sources/${key}`, { enabled });
  }

  /** "Sync now" on one source (always, however recently it was synced). */
  syncSource(key: string): Observable<SyncStartResult> {
    return this.http.post<SyncStartResult>(`${this.base}/sources/${key}/sync`, {});
  }

  /**
   * A source's builds as a file to save, its name in Content-Disposition. Fetched here rather than opened
   * as a link, because the API wants the bearer token.
   */
  exportSourceBuilds(key: string): Observable<HttpResponse<Blob>> {
    return this.http.get(`${this.base}/sources/${key}/export`, { observe: 'response', responseType: 'blob' });
  }

  /** Makes a source's builds exactly those of a builds file, whose text is sent as it is. */
  importSourceBuilds(key: string, file: string): Observable<BuildImportResult> {
    return this.http.post<BuildImportResult>(`${this.base}/sources/${key}/import`, file, {
      headers: { 'Content-Type': 'application/json' },
    });
  }

  /** Every enabled source; without force, sources synced within the last 20 hours are skipped. */
  syncAllSources(force = false): Observable<SyncStartResult> {
    return this.http.post<SyncStartResult>(`${this.base}/sync/builds`, { force });
  }

  syncCatalog(games?: GameKey[]): Observable<SyncStartResult> {
    return this.http.post<SyncStartResult>(`${this.base}/sync/catalog`, games ? { games } : {});
  }

  syncStatus(): Observable<SyncStatus> {
    return this.http.get<SyncStatus>(`${this.base}/sync`);
  }

  /** One bounded piece of sync work (a page or two); call again after wait_ms while anything is active. */
  syncStep(): Observable<SyncStepResult> {
    return this.http.post<SyncStepResult>(`${this.base}/sync/step`, {});
  }

  cancelSyncRun(id: number): Observable<SyncRunSummary> {
    return this.http.post<SyncRunSummary>(`${this.base}/sync-runs/${id}/cancel`, {});
  }

  syncRuns(source?: string, limit = 20): Observable<SyncRunSummary[]> {
    let params = new HttpParams().set('limit', limit);
    if (source) {
      params = params.set('source', source);
    }
    return this.http.get<SyncRunSummary[]>(`${this.base}/sync-runs`, { params });
  }

  syncRun(id: number): Observable<SyncRunSummary> {
    return this.http.get<SyncRunSummary>(`${this.base}/sync-runs/${id}`);
  }

  migrations(): Observable<MigrationStatus> {
    return this.http.get<MigrationStatus>(`${this.base}/admin/migrations`);
  }

  applyMigrations(): Observable<MigrationResult> {
    return this.http.post<MigrationResult>(`${this.base}/admin/migrations/apply`, {});
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
