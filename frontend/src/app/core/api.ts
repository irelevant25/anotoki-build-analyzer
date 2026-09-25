import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AccountDetail,
  AccountSummary,
  BuildCharacter,
  CharacterAnalysis,
  CharacterBuilds,
  GameKey,
  ImportRun,
  Meta,
  SourceStatus,
  SyncRunSummary,
} from './models';

/**
 * Typed client for the Laravel API (/api, proxied to the backend during development).
 */
@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);
  private readonly base = 'api';

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

  importEnka(game: GameKey, uid: string, accountId: number | null = null): Observable<ImportRun> {
    return this.http.post<ImportRun>(`${this.base}/imports/enka`, { game, uid, account_id: accountId });
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

  setSourceEnabled(key: string, enabled: boolean): Observable<SourceStatus> {
    return this.http.patch<SourceStatus>(`${this.base}/sources/${key}`, { enabled });
  }

  syncSource(key: string): Observable<{ queued: boolean }> {
    return this.http.post<{ queued: boolean }>(`${this.base}/sources/${key}/sync`, {});
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
}

/**
 * A readable message for any API error (validation, import problems, server down).
 */
export function errorMessage(error: unknown): string {
  if (error instanceof HttpErrorResponse) {
    if (error.status === 0) {
      return 'Cannot reach the backend. Is "php artisan serve" running?';
    }
    const body = error.error as { message?: string; errors?: Record<string, string[]> } | null;
    const firstValidation = body?.errors ? Object.values(body.errors)[0]?.[0] : null;
    return firstValidation ?? body?.message ?? `Request failed (HTTP ${error.status}).`;
  }
  return error instanceof Error ? error.message : 'Something went wrong.';
}
