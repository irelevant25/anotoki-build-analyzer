import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, output, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Api } from '../../core/api';
import { downloadName, saveFile } from '../../core/download';
import { GAME_LABELS } from '../../core/format';
import { UID_TERM } from '../../core/game-terms';
import { TranslationKey } from '../../core/i18n/en';
import { ErrorTextPipe, answerParams, errorCode } from '../../core/i18n/error-text';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslatePluralPipe } from '../../core/i18n/translate-plural';
import { TranslationParams, TranslationService, Wording } from '../../core/i18n/translation';
import { DataImportResult, DataOutcome } from '../../core/models';

/** The largest own-data file the server takes (config/app.php me.file_max_mb): checked before anything is sent. */
export const DATA_FILE_MAX_MB = 8;

/**
 * The file's problems the server names (422 {code, params}), each with its key and the parameters its
 * sentence needs. `unreadable` is the browser's own: the chosen file could not be read. The tails are also
 * in dynamic-keys.ts.
 */
const PROBLEMS = new Map<string, { key: TranslationKey; needs: readonly string[] }>([
  ['unreadable', { key: 'data.error.unreadable', needs: [] }],
  ['no_file', { key: 'data.error.no_file', needs: [] }],
  ['file_too_big', { key: 'data.error.file_too_big', needs: ['max_mb'] }],
  ['not_a_data_file', { key: 'data.error.not_a_data_file', needs: [] }],
  ['newer_version', { key: 'data.error.newer_version', needs: ['version'] }],
  ['bad_entry', { key: 'data.error.bad_entry', needs: ['path'] }],
  ['too_many_accounts', { key: 'data.error.too_many_accounts', needs: ['max'] }],
  ['export_too_big', { key: 'data.error.export_too_big', needs: ['max_mb', 'max_accounts'] }],
]);

/** The codes the card words: the tails of `data.error.` (dynamic-keys.ts). */
export const DATA_PROBLEM_CODES: readonly string[] = [...PROBLEMS.keys()];

/** What an import does to each game account, as a key (dynamic-keys.ts: `data.outcome.`). */
export const OUTCOME_KEYS: Record<DataOutcome, TranslationKey> = {
  added: 'data.outcome.added',
  replaced: 'data.outcome.replaced',
  kept: 'data.outcome.kept',
};

/** The file chosen for an import, and what the server's dry run said importing it would do. */
interface Preview {
  name: string;
  text: string;
  result: DataImportResult;
}

/**
 * "Your data" (the dashboard, for somebody signed in): their game accounts as one file, out and back in.
 *
 * - **Export**: GET /api/me/export, saved under the name the server gives.
 * - **Import**: a file is chosen; past 8 MB it is refused here, before anything is sent; otherwise its text
 *   goes to the server unchanged (the limit is the body's) as a dry run, whose answer is the preview - what
 *   would be added, replaced by a newer copy, kept, and how many entries this site does not know are left
 *   out. Confirm sends it again for real; Cancel forgets it. `imported` then tells the page to load the
 *   accounts again.
 *
 * A problem is kept as a key with its parameters (the file's, from the answer's code) or as the failed
 * request itself, and worded where it is shown - never the server's English.
 */
@Component({
  selector: 'app-data-card',
  imports: [
    MatButtonModule,
    MatIconModule,
    MatProgressBarModule,
    TranslatePipe,
    TranslatePluralPipe,
    ErrorTextPipe,
  ],
  templateUrl: './data-card.html',
  styleUrl: './data-card.scss',
})
export class DataCard {
  private readonly api = inject(Api);
  private readonly i18n = inject(TranslationService);
  private readonly snackBar = inject(MatSnackBar);

  /** An import went through: the page loads the accounts again. */
  readonly imported = output<DataImportResult>();

  protected readonly busy = signal(false);
  protected readonly preview = signal<Preview | null>(null);
  protected readonly done = signal<DataImportResult | null>(null);
  /** What went wrong: a key with its parameters, or the failed request - worded where it is shown. */
  protected readonly error = signal<unknown>(null);

  protected readonly gameLabels = GAME_LABELS;
  protected readonly outcomeKeys = OUTCOME_KEYS;

  protected exportData(): void {
    this.start();
    this.api.exportData().subscribe({
      next: (response) => {
        this.busy.set(false);
        if (response.body) {
          saveFile(
            response.body,
            downloadName(
              response.headers.get('Content-Disposition'),
              'build-analyzer-accounts.json',
            ),
          );
          // A finished sentence when raised; a snack bar is not worded again.
          this.snackBar.open(this.i18n.t('data.exported'), undefined, { duration: 4000 });
        }
      },
      error: async (err: unknown) => {
        this.error.set(dataProblem(await jsonError(err)));
        this.busy.set(false);
      },
    });
  }

  protected async choose(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) {
      return;
    }
    this.start();
    if (file.size > DATA_FILE_MAX_MB * 1048576) {
      this.fail({ key: 'data.error.file_too_big', params: { max_mb: DATA_FILE_MAX_MB } });
      return;
    }
    let text: string;
    try {
      text = await file.text();
    } catch {
      this.fail({ key: 'data.error.unreadable' });
      return;
    }
    this.api.importData(text, true).subscribe({
      next: (result) => {
        this.busy.set(false);
        this.preview.set({ name: file.name, text, result });
      },
      error: (err: unknown) => this.fail(dataProblem(err)),
    });
  }

  protected confirm(): void {
    const preview = this.preview();
    if (!preview || this.busy()) {
      return;
    }
    this.busy.set(true);
    this.error.set(null);
    this.api.importData(preview.text, false).subscribe({
      next: (result) => {
        this.busy.set(false);
        this.preview.set(null);
        this.done.set(result);
        this.imported.emit(result);
      },
      error: (err: unknown) => this.fail(dataProblem(err), true),
    });
  }

  protected cancel(): void {
    this.preview.set(null);
    this.error.set(null);
  }

  /** A game account of the preview: its name, else its UID (the game's word), else nothing to say. */
  protected accountName(account: DataImportResult['accounts'][number]): string {
    return account.nickname ?? (account.uid ? `${UID_TERM} ${account.uid}` : '-');
  }

  private start(): void {
    this.busy.set(true);
    this.error.set(null);
    this.done.set(null);
    this.preview.set(null);
  }

  /** `keepPreview`: a confirmed import that failed keeps its preview, so Confirm can be pressed again. */
  private fail(problem: unknown, keepPreview = false): void {
    this.busy.set(false);
    if (!keepPreview) {
      this.preview.set(null);
    }
    this.error.set(problem);
  }
}

/**
 * The file's problem as a key with its parameters, when the answer names one of PROBLEMS and gives what its
 * sentence needs - anything else as the failed request, for errorText() to word (errorKey()).
 */
export function dataProblem(error: unknown): unknown {
  const code = errorCode(error);
  const problem = code === null ? undefined : PROBLEMS.get(code);
  if (!problem || !(error instanceof HttpErrorResponse) || error.status !== 422) {
    return error;
  }
  const given = answerParams(error);
  const params: TranslationParams = {};
  for (const name of problem.needs) {
    const value = given[name];
    if (typeof value !== 'string' && typeof value !== 'number') {
      return error;
    }
    params[name] = value;
  }
  return { key: problem.key, params } satisfies Wording;
}

/** A failed request whose answer is a file (a Blob): its JSON body read, so its code can be. */
async function jsonError(error: unknown): Promise<unknown> {
  if (error instanceof HttpErrorResponse && error.error instanceof Blob) {
    try {
      const body: unknown = JSON.parse(await error.error.text());
      return new HttpErrorResponse({
        error: body,
        status: error.status,
        statusText: error.statusText,
        headers: error.headers,
      });
    } catch {
      // Not JSON: the failure as it is.
    }
  }
  return error;
}
