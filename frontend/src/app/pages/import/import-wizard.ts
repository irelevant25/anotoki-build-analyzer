import { BreakpointObserver } from '@angular/cdk/layout';
import { HttpErrorResponse } from '@angular/common/http';
import { Component, afterNextRender, computed, inject, signal, viewChild } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatExpansionModule } from '@angular/material/expansion';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatStepper, MatStepperIntl, MatStepperModule } from '@angular/material/stepper';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { map } from 'rxjs';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { GAME_LABELS } from '../../core/format';
import { GAME_TERMS, UID_TERM } from '../../core/game-terms';
import { errorCode } from '../../core/i18n/error-text';
import { importFailure, importWarnings } from '../../core/i18n/import-words';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslationService, Worded, Wording } from '../../core/i18n/translation';
import { AccountSummary, GameKey, ImportRun } from '../../core/models';
import { VisitorKey } from '../../core/visitor-key';
import { VisitorNotice } from '../../shared/visitor-notice/visitor-notice';
import { GUIDES, ImportMethodKey, METHODS } from './import-guides';
import { StepperWords } from './stepper-words';

const MAX_FILE_BYTES = 20 * 1024 * 1024;

/** The file chosen for the import, as facts: the page words them ("name · 12 KB · Genshin Impact export detected"). */
export interface ChosenFile {
  name: string;
  kilobytes: number;
  /** The game the file is from, where its content says. */
  game: GameKey | null;
}

@Component({
  selector: 'app-import-wizard',
  imports: [
    FormsModule,
    RouterLink,
    MatStepperModule,
    MatButtonModule,
    MatIconModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    MatProgressBarModule,
    MatExpansionModule,
    TranslatePipe,
    VisitorNotice,
  ],
  // The stepper's own words ("Completed", "Editable") follow the language on the page.
  providers: [{ provide: MatStepperIntl, useClass: StepperWords }],
  templateUrl: './import-wizard.html',
  styleUrl: './import-wizard.scss',
})
export class ImportWizard {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslationService);

  private readonly stepper = viewChild.required<MatStepper>('stepper');

  protected readonly vertical = toSignal(
    inject(BreakpointObserver).observe('(max-width: 760px)').pipe(map((state) => state.matches)),
    { initialValue: false },
  );

  /** The games' own words: never translated, handed to a sentence as they are. */
  protected readonly gameLabels = GAME_LABELS;
  protected readonly terms = GAME_TERMS;
  protected readonly uidTerm = UID_TERM;
  protected readonly games: GameKey[] = ['genshin', 'hsr'];

  protected readonly game = signal<GameKey | null>(null);
  protected readonly method = signal<ImportMethodKey | null>(null);
  protected readonly prepared = signal(false);
  protected readonly result = signal<ImportRun | null>(null);

  protected readonly accounts = signal<AccountSummary[]>([]);
  protected readonly targetAccountId = signal<number | null>(null);
  /** Account the wizard was opened for ("Update data" on an account). */
  private readonly prefillAccount = signal<AccountSummary | null>(null);

  protected readonly uid = signal('');
  protected readonly fileContent = signal<string | null>(null);
  protected readonly fileName = signal<string | null>(null);
  protected readonly fileInfo = signal<ChosenFile | null>(null);
  protected readonly pasteText = signal('');
  protected readonly dragging = signal(false);

  protected readonly busy = signal(false);
  /** What went wrong, as a key with what fills it - never a sentence: the template words it, so it follows the language. */
  protected readonly error = signal<Wording | null>(null);

  protected readonly methods = computed(() => (this.game() ? METHODS[this.game()!] : []));
  protected readonly guide = computed(() => (this.game() && this.method() ? GUIDES[this.game()!][this.method()!] : null));
  protected readonly gameAccounts = computed(() => this.accounts().filter((account) => account.game === this.game()));
  protected readonly uidValid = computed(() => /^\d{9,10}$/.test(this.uid().trim()));
  protected readonly hasFile = computed(() => !!this.fileContent() || this.pasteText().trim().length > 0);

  /** The chosen file in the language on the page; the size is a count of the site's own, the game's name is not. */
  protected readonly fileInfoText = computed(() => {
    const info = this.fileInfo();
    if (!info) {
      return null;
    }
    const params = { name: info.name, kilobytes: this.i18n.number(info.kilobytes) };
    return info.game ? this.i18n.t('importWizard.file.infoDetected', { ...params, game: GAME_LABELS[info.game] }) : this.i18n.t('importWizard.file.info', params);
  });

  /** The finished import's warnings, each worded from its kind - or, without one, the stored English sentence. */
  protected readonly warnings = computed<Worded[]>(() => importWarnings(this.i18n, this.result()?.summary));

  constructor() {
    // Somebody signed in, or a visitor: their accounts, to import into one of them. Nobody has none - and the
    // server would only answer 401 - so nobody's page asks for nothing.
    if (inject(AuthService).signedIn() || inject(VisitorKey).current() !== null) {
      this.api.accounts().subscribe({ next: (accounts) => this.applyPrefill(accounts) });
    } else {
      afterNextRender(() => this.applyPrefill([]));
    }
  }

  protected chooseGame(game: GameKey): void {
    if (this.game() !== game) {
      this.method.set(null);
      this.prepared.set(false);
    }
    this.game.set(game);
    this.advance();
  }

  protected chooseMethod(method: ImportMethodKey): void {
    if (this.method() !== method) {
      this.prepared.set(false);
    }
    this.method.set(method);
    this.targetAccountId.set(this.defaultAccount(this.game()!, method));
    this.advance();
  }

  protected confirmPrepared(): void {
    this.prepared.set(true);
    this.advance();
  }

  protected importShowcase(): void {
    const game = this.game();
    if (!game || !this.uidValid()) {
      return;
    }
    this.run(this.api.importShowcase(game, this.uid().trim(), this.targetAccountId()));
  }

  protected importFile(): void {
    const content = this.fileContent() ?? this.pasteText().trim();
    if (!content) {
      return;
    }
    this.run(
      this.api.importFile({
        game: this.game() ?? undefined,
        account_id: this.targetAccountId(),
        content,
        file_name: this.fileName() ?? 'pasted.json',
      }),
    );
  }

  protected onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (file) {
      this.readFile(file);
    }
    input.value = '';
  }

  protected onDrop(event: DragEvent): void {
    event.preventDefault();
    this.dragging.set(false);
    const file = event.dataTransfer?.files?.[0];
    if (file) {
      this.readFile(file);
    }
  }

  protected onDragOver(event: DragEvent): void {
    event.preventDefault();
    this.dragging.set(true);
  }

  protected clearFile(): void {
    this.fileContent.set(null);
    this.fileName.set(null);
    this.fileInfo.set(null);
  }

  protected restart(): void {
    this.result.set(null);
    this.error.set(null);
    this.clearFile();
    this.pasteText.set('');
    this.prepared.set(false);
    this.method.set(null);
    this.stepper().reset();
    if (this.game()) {
      // reset() clears completion; keep the chosen game and continue with the method step.
      const game = this.game()!;
      this.game.set(null);
      queueMicrotask(() => this.chooseGame(game));
    }
  }

  protected openAccount(): void {
    const accountId = this.result()?.account_id;
    if (accountId) {
      void this.router.navigate(['/accounts', accountId]);
    }
  }

  protected unmatchedList(run: ImportRun): string[] {
    return Object.values(run.summary?.unmatched ?? {}).flat();
  }

  /** A count of the site's own (characters, pieces), written the way the language on the page writes numbers. */
  protected count(value: number | null | undefined): string {
    return typeof value === 'number' ? this.i18n.number(value) : '';
  }

  private run(request: ReturnType<Api['importShowcase']>): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (run) => {
        this.busy.set(false);
        this.result.set(run);
        this.advance();
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.error.set(refusal(err));
      },
    });
  }

  private readFile(file: File): void {
    this.error.set(null);
    if (file.size > MAX_FILE_BYTES) {
      this.error.set({ key: 'importWizard.fileTooLarge' });
      return;
    }
    file.text().then((text) => {
      const detected = this.detectGame(text);
      if (detected === 'invalid') {
        this.error.set({ key: 'import.error.file_not_json' });
        return;
      }
      const chosen = this.game();
      if (detected && chosen && detected !== chosen) {
        // The server's own refusal of such a file, said before anything is sent: the same key, the same parameters.
        this.error.set({ key: 'import.error.file_game_mismatch', params: { file_game: GAME_LABELS[detected], chosen_game: GAME_LABELS[chosen] } });
        return;
      }
      this.fileContent.set(text);
      this.fileName.set(file.name);
      this.fileInfo.set({ name: file.name, kilobytes: Math.round(file.size / 1024), game: detected });
    });
  }

  private detectGame(text: string): GameKey | 'invalid' | null {
    try {
      const json = JSON.parse(text.replace(/^﻿/, '')) as Record<string, unknown>;
      if (json['format'] === 'GOOD') {
        return 'genshin';
      }
      if (Array.isArray(json['relics'])) {
        return 'hsr';
      }
      return null;
    } catch {
      return 'invalid';
    }
  }

  private advance(): void {
    const stepper = this.stepper();
    if (stepper.selected) {
      stepper.selected.completed = true;
    }
    stepper.next();
  }

  /**
   * Files carry no reliable UID, so they default to the account the wizard was opened for or the only one.
   * A UID identifies its account by itself; only an account without a UID yet is targeted explicitly.
   */
  private defaultAccount(game: GameKey, method: ImportMethodKey): number | null {
    const prefill = this.prefillAccount();
    if (method === 'showcase') {
      return prefill && prefill.game === game && !prefill.uid ? prefill.id : null;
    }
    if (prefill && prefill.game === game) {
      return prefill.id;
    }
    const own = this.accounts().filter((account) => account.game === game);
    return own.length === 1 ? own[0].id : null;
  }

  /**
   * ?game=hsr&account=3 (from "Update data") preselects the game, method and account.
   */
  private applyPrefill(accounts: AccountSummary[]): void {
    this.accounts.set(accounts);
    const params = this.route.snapshot.queryParamMap;
    const game = params.get('game');
    const accountId = Number(params.get('account')) || null;
    const account = accountId ? accounts.find((a) => a.id === accountId) : undefined;

    if (game === 'genshin' || game === 'hsr') {
      queueMicrotask(() => {
        this.prefillAccount.set(account ?? null);
        this.chooseGame(game);
        if (account) {
          if (account.uid) {
            this.uid.set(account.uid);
          }
          const asked = params.get('method');
          const method: ImportMethodKey = asked === 'showcase' || asked === 'file' ? asked : account.has_full_inventory ? 'file' : 'showcase';
          this.chooseMethod(method);
          this.confirmPrepared();
        }
      });
    }
  }
}

/**
 * What to say of an import request the server refused or that failed - a key, never the server's sentence.
 *
 * The route checks what was sent before the import begins, and answers `invalid` with the fields it
 * refused: a UID that is not 9 or 10 digits (which the wizard's own check normally stops first), or an
 * account that is not the person's any more. Those two have sentences of their own among the import's;
 * everything else is worded by importFailure() - the import's code with its parameters, or the failure of
 * the request itself.
 */
function refusal(error: unknown): Wording {
  if (errorCode(error) === 'invalid') {
    if (refused(error, 'uid')) {
      return { key: 'import.error.uid_invalid' };
    }
    if (refused(error, 'account_id')) {
      return { key: 'import.error.account_missing' };
    }
  }
  return importFailure(error);
}

/** Whether an `invalid` answer names this field among the ones it refused. */
function refused(error: unknown, field: string): boolean {
  const body: unknown = error instanceof HttpErrorResponse ? error.error : null;
  const errors: unknown = typeof body === 'object' && body !== null ? (body as { errors?: unknown }).errors : null;
  return typeof errors === 'object' && errors !== null && Object.hasOwn(errors, field);
}
