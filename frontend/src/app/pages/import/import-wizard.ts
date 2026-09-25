import { BreakpointObserver } from '@angular/cdk/layout';
import { Component, computed, inject, signal, viewChild } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatExpansionModule } from '@angular/material/expansion';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatStepper, MatStepperModule } from '@angular/material/stepper';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { map } from 'rxjs';
import { Api, errorMessage } from '../../core/api';
import { GAME_LABELS } from '../../core/format';
import { AccountSummary, GameKey, ImportRun } from '../../core/models';
import { GUIDES, ImportMethodKey, METHODS } from './import-guides';

const MAX_FILE_BYTES = 20 * 1024 * 1024;

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
  ],
  templateUrl: './import-wizard.html',
  styleUrl: './import-wizard.scss',
})
export class ImportWizard {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  private readonly stepper = viewChild.required<MatStepper>('stepper');

  protected readonly vertical = toSignal(
    inject(BreakpointObserver).observe('(max-width: 760px)').pipe(map((state) => state.matches)),
    { initialValue: false },
  );

  protected readonly gameLabels = GAME_LABELS;
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
  protected readonly fileInfo = signal<string | null>(null);
  protected readonly pasteText = signal('');
  protected readonly dragging = signal(false);

  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);

  protected readonly methods = computed(() => (this.game() ? METHODS[this.game()!] : []));
  protected readonly guide = computed(() => (this.game() && this.method() ? GUIDES[this.game()!][this.method()!] : null));
  protected readonly gameAccounts = computed(() => this.accounts().filter((account) => account.game === this.game()));
  protected readonly uidValid = computed(() => /^\d{9,10}$/.test(this.uid().trim()));
  protected readonly hasFile = computed(() => !!this.fileContent() || this.pasteText().trim().length > 0);

  constructor() {
    this.api.accounts().subscribe({ next: (accounts) => this.applyPrefill(accounts) });
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

  protected importEnka(): void {
    const game = this.game();
    if (!game || !this.uidValid()) {
      return;
    }
    this.run(this.api.importEnka(game, this.uid().trim(), this.targetAccountId()));
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

  private run(request: ReturnType<Api['importEnka']>): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (run) => {
        this.busy.set(false);
        this.result.set(run);
        this.advance();
      },
      error: (err) => {
        this.busy.set(false);
        this.error.set(errorMessage(err));
      },
    });
  }

  private readFile(file: File): void {
    this.error.set(null);
    if (file.size > MAX_FILE_BYTES) {
      this.error.set('The file is larger than 20 MB; that is not an exporter file.');
      return;
    }
    file.text().then((text) => {
      const detected = this.detectGame(text);
      if (detected === 'invalid') {
        this.error.set('This file is not valid JSON.');
        return;
      }
      if (detected && this.game() && detected !== this.game()) {
        this.error.set(`This file contains ${GAME_LABELS[detected]} data, but you chose ${GAME_LABELS[this.game()!]}.`);
        return;
      }
      this.fileContent.set(text);
      this.fileName.set(file.name);
      this.fileInfo.set(`${file.name} · ${(file.size / 1024).toFixed(0)} KB${detected ? ' · ' + GAME_LABELS[detected] + ' export detected' : ''}`);
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
    if (method === 'enka') {
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
          const method = (params.get('method') as ImportMethodKey | null) ?? (account.has_full_inventory ? 'file' : 'enka');
          this.chooseMethod(method);
          this.confirmPrepared();
        }
      });
    }
  }
}
