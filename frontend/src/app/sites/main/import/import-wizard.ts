import { HttpErrorResponse } from '@angular/common/http';
import { Component, ElementRef, Injector, afterNextRender, computed, inject, signal, viewChild } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { CopyButtonComponent } from '@anotoki/lib/ui/copy';
import { DisclosureComponent } from '@anotoki/lib/ui/disclosure';
import { SelectComponent, SelectOption, TextFieldComponent, TextareaComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { StepItem, StepperComponent } from '@anotoki/lib/ui/stepper';
import { Api } from '../../../api/api';
import { AuthService } from '../../../shared/auth';
import { GAME_LABELS, GAMES } from '../../../shared/format';
import { GAME_TERMS, UID_TERM } from '../common/game-terms';
import { TranslationKey } from '../../../shared/i18n/en';
import { errorCode } from '../../../shared/i18n/error-text';
import { importFailure, importWarnings } from '../../../shared/i18n/import-words';
import { TranslatePipe } from '../../../shared/i18n/translate';
import { TranslationService, Worded, Wording } from '../../../shared/i18n/translation';
import { AccountSummary, GameKey, ImportRun } from '../../../api/models';
import { VisitorKey } from '../../../shared/visitor-key';
import { VisitorNotice } from '../common/visitor-notice/visitor-notice';
import { GUIDES, ImportMethodKey, METHODS } from './import-guides';

const MAX_FILE_BYTES = 20 * 1024 * 1024;

/** The file chosen for the import, as facts: the page words them ("name · 12 KB · Genshin Impact export detected"). */
export interface ChosenFile {
  name: string;
  kilobytes: number;
  /** The game the file is from, where its content says. */
  game: GameKey | null;
}

/** The wizard's four steps, in order, each with the key of its name. */
const STEPS: readonly { key: string; label: TranslationKey }[] = [
  { key: 'method', label: 'importWizard.step.method' },
  { key: 'prepare', label: 'importWizard.step.prepare' },
  { key: 'import', label: 'importWizard.step.import' },
  { key: 'done', label: 'importWizard.step.done' },
];

/** The value the target select gives "no account": a new one (a file), or the one the UID names (the showcase). */
const NO_ACCOUNT = '';

/**
 * Importing an account, in four steps: the way in (the in-game Character Showcase by UID, or an inventory file),
 * how to get the data ready (the guides, import-guides.ts), the import itself, and what came in. The game is the one
 * the site offers (GAMES; a second game brings back a step to choose it), or the one `?game=` names.
 *
 * The wizard is drawn with the anotoki family's kit: its row of steps is the family's stepper (anotoki-lib 0.6.0,
 * `@anotoki/lib/ui/stepper`: the step in hand aria-current="step" and heard "Step 2 of 4: Prepare", the steps done
 * buttons back to them, heard "Method, done"; on a narrow row their marks alone), and its card, fields (the UID, the
 * account to import into, the pasted text), buttons, alerts, disclosures, spinner and icons, and the Copy button of
 * the scanner's line (`@anotoki/lib/ui/copy`); its choice cards, the file's drop zone and the box that line stands in
 * stay the site's own. A step is shown at a time; going on moves the focus to the new step's heading, so a keyboard
 * and a screen reader follow.
 */
@Component({
  selector: 'app-import-wizard',
  imports: [
    RouterLink,
    AlertComponent,
    ButtonComponent,
    CardComponent,
    CopyButtonComponent,
    DisclosureComponent,
    IconComponent,
    PageHeaderComponent,
    SelectComponent,
    SpinnerComponent,
    StepperComponent,
    TextFieldComponent,
    TextareaComponent,
    TranslatePipe,
    VisitorNotice,
  ],
  templateUrl: './import-wizard.html',
  styleUrl: './import-wizard.scss',
})
export class ImportWizard {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly i18n = inject(TranslationService);
  private readonly injector = inject(Injector);

  private readonly panel = viewChild<ElementRef<HTMLElement>>('panel');

  /** The games' own words: never translated, handed to a sentence as they are. */
  protected readonly gameLabels = GAME_LABELS;
  protected readonly terms = GAME_TERMS;
  protected readonly uidTerm = UID_TERM;

  /** The step in hand: 0 the way in, 1 the guide, 2 the import, 3 what came in. */
  protected readonly step = signal(0);

  protected readonly game = signal<GameKey>(GAMES[0]);
  protected readonly method = signal<ImportMethodKey | null>(null);
  protected readonly prepared = signal(false);
  protected readonly result = signal<ImportRun | null>(null);

  protected readonly accounts = signal<AccountSummary[]>([]);
  protected readonly targetAccountId = signal<number | null>(null);
  /** Account the wizard was opened for ("Update data" on an account). */
  private readonly prefillAccount = signal<AccountSummary | null>(null);

  protected readonly uid = signal('');
  /** The UID's box has been left once (or an import asked for): from then on it says when the UID is not one. */
  protected readonly uidTouched = signal(false);
  protected readonly fileContent = signal<string | null>(null);
  protected readonly fileName = signal<string | null>(null);
  protected readonly fileInfo = signal<ChosenFile | null>(null);
  protected readonly pasteText = signal('');
  protected readonly dragging = signal(false);

  protected readonly busy = signal(false);
  /** What went wrong, as a key with what fills it - never a sentence: the template words it, so it follows the language. */
  protected readonly error = signal<Wording | null>(null);

  /**
   * The row of steps, as the family's stepper draws them: each by its name in the language on the page - every step
   * before the one in hand done, a way back to it (the stepper's own rule: a step is left only forwards, once made).
   */
  protected readonly stepItems = computed<StepItem[]>(() => STEPS.map((step) => ({ label: this.i18n.t(step.label) })));

  protected readonly methods = computed(() => METHODS[this.game()]);
  protected readonly guide = computed(() => (this.method() ? GUIDES[this.game()][this.method()!] : null));
  protected readonly gameAccounts = computed(() => this.accounts().filter((account) => account.game === this.game()));
  protected readonly uidValid = computed(() => /^\d{9,10}$/.test(this.uid().trim()));
  protected readonly hasFile = computed(() => !!this.fileContent() || this.pasteText().trim().length > 0);

  /** The UID's box says it is not one: only once left (or an import was asked for), and while something is typed. */
  protected readonly uidProblem = computed(() => this.uidTouched() && this.uid().length > 0 && !this.uidValid());

  /**
   * The accounts to import into, as the select offers them: "find it by the UID" (the showcase) or "a new
   * account" (a file) first, then the person's accounts of the game - each with its UID, or saying it has none
   * yet (the showcase, which can fill it in).
   */
  protected readonly targetOptions = computed<SelectOption[]>(() => {
    const showcase = this.method() === 'showcase';
    const first: SelectOption = {
      value: NO_ACCOUNT,
      label: this.i18n.t(showcase ? 'importWizard.target.auto' : 'importWizard.target.new'),
    };
    return [
      first,
      ...this.gameAccounts().map((account) => ({
        value: String(account.id),
        label: account.uid
          ? this.i18n.t('importWizard.target.accountUid', { name: account.nickname, uid: account.uid })
          : showcase
            ? this.i18n.t('importWizard.target.accountNoUid', { name: account.nickname })
            : (account.nickname ?? ''),
      })),
    ];
  });
  protected readonly targetValue = computed(() => {
    const id = this.targetAccountId();
    return id === null ? NO_ACCOUNT : String(id);
  });

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

  protected chooseMethod(method: ImportMethodKey): void {
    if (this.method() !== method) {
      this.prepared.set(false);
    }
    this.method.set(method);
    this.targetAccountId.set(this.defaultAccount(this.game(), method));
    this.goTo(1);
  }

  protected confirmPrepared(): void {
    this.prepared.set(true);
    this.goTo(2);
  }

  /** The account the select names: none for "find it by the UID" / "a new account". */
  protected chooseTarget(value: string): void {
    this.targetAccountId.set(value === NO_ACCOUNT ? null : Number(value));
  }

  protected importShowcase(event?: Event): void {
    event?.preventDefault();
    this.uidTouched.set(true);
    if (!this.uidValid() || this.busy()) {
      return;
    }
    this.run(this.api.importShowcase(this.game(), this.uid().trim(), this.targetAccountId()));
  }

  protected importFile(): void {
    const content = this.fileContent() ?? this.pasteText().trim();
    if (!content) {
      return;
    }
    this.run(
      this.api.importFile({
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

  /** Another import: of the same game, from the way in. */
  protected restart(): void {
    this.result.set(null);
    this.error.set(null);
    this.clearFile();
    this.pasteText.set('');
    this.uidTouched.set(false);
    this.prepared.set(false);
    this.method.set(null);
    this.goTo(0);
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

  /**
   * To a step: the one ahead (a choice made, the guide read, an import done), back one, or back to one done. Once
   * it is drawn, the focus goes to its heading, which says where the person is now.
   */
  protected goTo(step: number): void {
    this.step.set(Math.max(0, Math.min(STEPS.length - 1, step)));
    afterNextRender(() => this.panel()?.nativeElement.querySelector<HTMLElement>('.step-title')?.focus(), {
      injector: this.injector,
    });
  }

  private run(request: ReturnType<Api['importShowcase']>): void {
    this.busy.set(true);
    this.error.set(null);
    request.subscribe({
      next: (run) => {
        this.busy.set(false);
        this.result.set(run);
        this.goTo(3);
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
      this.fileContent.set(text);
      this.fileName.set(file.name);
      this.fileInfo.set({ name: file.name, kilobytes: Math.round(file.size / 1024), game: detected });
    });
  }

  private detectGame(text: string): GameKey | 'invalid' | null {
    try {
      const json = JSON.parse(text.replace(/^﻿/, '')) as Record<string, unknown>;
      return json['format'] === 'GOOD' ? 'genshin' : null;
    } catch {
      return 'invalid';
    }
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
   * ?game=genshin&account=3 (from "Update data") preselects the game, method and account.
   */
  private applyPrefill(accounts: AccountSummary[]): void {
    this.accounts.set(accounts);
    const params = this.route.snapshot.queryParamMap;
    const game = GAMES.find((offered) => offered === params.get('game'));
    const accountId = Number(params.get('account')) || null;
    const account = accountId ? accounts.find((a) => a.id === accountId) : undefined;

    if (game) {
      this.prefillAccount.set(account ?? null);
      this.game.set(game);
      if (account) {
        if (account.uid) {
          this.uid.set(account.uid);
        }
        const asked = params.get('method');
        const method: ImportMethodKey = asked === 'showcase' || asked === 'file' ? asked : account.has_full_inventory ? 'file' : 'showcase';
        this.chooseMethod(method);
        this.confirmPrepared();
      }
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
