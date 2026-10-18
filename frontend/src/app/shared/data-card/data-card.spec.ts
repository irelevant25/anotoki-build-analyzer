import { HttpErrorResponse, HttpHeaders, HttpResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ToastService } from '@anotoki/lib/ui/toast';
import { Api } from '../../core/api';
import { DYNAMIC_KEYS } from '../../core/i18n/dynamic-keys';
import { en } from '../../core/i18n/en';
import { DataImportResult } from '../../core/models';
import { DATA_FILE_MAX_MB, DATA_PROBLEM_CODES, DataCard, OUTCOME_KEYS } from './data-card';
import { provideKit } from '../../core/kit';

/** Lets what a click or a file began finish, and the card be drawn. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const FILE_TEXT = '{"format":"build-analyzer.accounts","format_version":1,"accounts":[]}';

function result(dryRun: boolean, overrides: Partial<DataImportResult> = {}): DataImportResult {
  return {
    dry_run: dryRun,
    added: 1,
    replaced: 1,
    kept: 1,
    skipped: 3,
    accounts: [
      { game: 'genshin', uid: '700000001', nickname: 'Mona', outcome: 'added', skipped: 0 },
      { game: 'hsr', uid: '800000001', nickname: null, outcome: 'replaced', skipped: 3 },
      { game: 'genshin', uid: null, nickname: null, outcome: 'kept', skipped: 0 },
    ],
    ...overrides,
  };
}

function refused(status: number, body: unknown, asBlob = false): HttpErrorResponse {
  return new HttpErrorResponse({
    status,
    error: asBlob ? new Blob([JSON.stringify(body)], { type: 'application/json' }) : body,
  });
}

describe('the "Your data" card', () => {
  let fixture: ComponentFixture<DataCard>;
  let host: HTMLElement;
  let api: {
    exportData: ReturnType<typeof vi.fn>;
    importData: ReturnType<typeof vi.fn<(file: string, dryRun: boolean) => Observable<DataImportResult>>>;
  };
  let toasts: { success: ReturnType<typeof vi.fn> };
  let imported: DataImportResult[];

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const problem = () => text(host.querySelector('.problem .anotoki-alert__text'));
  const button = (selector: string) => host.querySelector<HTMLButtonElement>(`button.${selector}`)!;

  async function setup(): Promise<void> {
    api = {
      exportData: vi.fn(),
      importData: vi.fn<(file: string, dryRun: boolean) => Observable<DataImportResult>>((_file, dryRun) => of(result(dryRun))),
    };
    toasts = { success: vi.fn() };
    imported = [];
    TestBed.configureTestingModule({
      providers: [provideTestTranslations(en), provideKit(), { provide: Api, useValue: api }, { provide: ToastService, useValue: toasts }],
    });
    fixture = TestBed.createComponent(DataCard);
    fixture.componentInstance.imported.subscribe((done) => imported.push(done));
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  /** Chooses a file in the card's hidden input. */
  async function choose(file: { name?: string; size?: number; text?: () => Promise<string> } = {}): Promise<void> {
    const input = host.querySelector<HTMLInputElement>('input[type="file"]')!;
    const chosen = {
      name: file.name ?? 'build-analyzer-accounts-2026-10-04.json',
      size: file.size ?? FILE_TEXT.length,
      text: file.text ?? (() => Promise.resolve(FILE_TEXT)),
    };
    Object.defineProperty(input, 'files', { value: [chosen], configurable: true });
    input.dispatchEvent(new Event('change'));
    await settle();
    await fixture.whenStable();
  }

  afterEach(() => vi.restoreAllMocks());

  it('says what it is for, with Export and Import', async () => {
    await setup();
    expect(text(host.querySelector('h2'))).toBe('Your data');
    expect(text(host.querySelector('.data-card > p'))).toBe(en['data.text']);
    expect([text(button('export')), text(button('import'))]).toEqual(['Export', 'Import a file']);
    expect(host.querySelector<HTMLInputElement>('input[type="file"]')?.accept).toBe('application/json,.json');
  });

  it('exports: saves the file under the name the server gives, and says it is on its way', async () => {
    await setup();
    const created = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:file');
    let saved = '';
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      saved = this.download;
    });
    api.exportData.mockReturnValue(
      of(
        new HttpResponse({
          body: new Blob(['{}']),
          headers: new HttpHeaders({
            'Content-Disposition': 'attachment; filename="build-analyzer-accounts-2026-10-04.json"',
          }),
        }),
      ),
    );

    button('export').click();
    await settle();

    expect(created).toHaveBeenCalledTimes(1);
    expect(saved).toBe('build-analyzer-accounts-2026-10-04.json');
    expect(toasts.success).toHaveBeenCalledWith(en['data.exported']);
  });

  it('words an export the server refuses - its answer a file too - from the code and its numbers', async () => {
    await setup();
    api.exportData.mockReturnValue(throwError(() => refused(422, { message: 'Too big.', code: 'export_too_big', params: { max_mb: 8, max_accounts: 500 } }, true)));

    button('export').click();
    await settle();
    await fixture.whenStable();

    expect(problem()).toBe('Your data is too big for one file (more than 8 MB or 500 game accounts).');
  });

  it('imports in two steps: the dry run is the preview, Confirm does it, and the page reloads the accounts', async () => {
    await setup();
    await choose();

    expect(api.importData).toHaveBeenCalledTimes(1);
    expect(api.importData).toHaveBeenCalledWith(FILE_TEXT, true);
    expect(text(host.querySelector('.preview h3'))).toBe('What importing build-analyzer-accounts-2026-10-04.json does');
    expect(text(host.querySelector('.preview .summary'))).toBe('1 new, 1 replaced by a newer copy, 1 kept - yours is as new or newer.');
    expect(text(host.querySelector('.preview .skipped'))).toBe('3 entries this site does not know are left out.');
    expect([...host.querySelectorAll('.outcomes li')].map((item) => [text(item.querySelector('.account')), text(item.querySelector('anotoki-badge'))])).toEqual([
      ['Genshin Impact · Mona', 'new'],
      ['Honkai: Star Rail · UID 800000001', 'replaced by the newer copy'],
      ['Genshin Impact · -', 'kept'],
    ]);
    expect(imported).toEqual([]);

    button('confirm').click();
    await settle();
    await fixture.whenStable();

    expect(api.importData).toHaveBeenCalledTimes(2);
    expect(api.importData).toHaveBeenLastCalledWith(FILE_TEXT, false);
    expect(host.querySelector('.preview')).toBeNull();
    expect(text(host.querySelector('.done .anotoki-alert__text'))).toBe('Imported: 1 new, 1 replaced by a newer copy, 1 kept.');
    expect(host.querySelector('.done')?.getAttribute('role')).toBe('status');
    expect(imported).toEqual([result(false)]);
  });

  it('imports nothing on Cancel', async () => {
    await setup();
    await choose();
    button('cancel').click();
    await fixture.whenStable();

    expect(host.querySelector('.preview')).toBeNull();
    expect(api.importData).toHaveBeenCalledTimes(1);
    expect(imported).toEqual([]);
  });

  it('says a file without game accounts has none, and offers nothing to confirm', async () => {
    await setup();
    api.importData.mockReturnValueOnce(of(result(true, { added: 0, replaced: 0, kept: 0, skipped: 0, accounts: [] })));
    await choose();

    expect(text(host.querySelector('.preview p'))).toBe(en['data.empty']);
    expect(button('confirm').disabled).toBe(true);
  });

  it('refuses a file larger than the server takes before anything is sent', async () => {
    await setup();
    await choose({ size: DATA_FILE_MAX_MB * 1048576 + 1 });

    expect(api.importData).not.toHaveBeenCalled();
    expect(problem()).toBe('The file is larger than 8 MB.');
  });

  it('says so when the browser cannot read the file', async () => {
    await setup();
    await choose({ text: () => Promise.reject(new Error('NotReadableError')) });

    expect(api.importData).not.toHaveBeenCalled();
    expect(problem()).toBe(en['data.error.unreadable']);
  });

  it("words every problem the server names from its code and its parameters - never the server's sentence", async () => {
    await setup();
    const cases: [HttpErrorResponse, string][] = [
      [refused(422, { message: 'x', code: 'no_file', params: {} }), 'The file is empty.'],
      [refused(422, { message: 'x', code: 'file_too_big', params: { max_mb: 8 } }), 'The file is larger than 8 MB.'],
      [refused(422, { message: 'x', code: 'not_a_data_file', params: {} }), 'This is not a file of game accounts exported by this site.'],
      [refused(422, { message: 'x', code: 'newer_version', params: { version: 2 } }), 'This file comes from a newer version of the site (format 2). This copy cannot read it yet.'],
      [
        refused(422, {
          message: 'x',
          code: 'bad_entry',
          params: { path: 'accounts[2].characters[5].level' },
        }),
        'The file is damaged at accounts[2].characters[5].level. Export it again.',
      ],
      [refused(422, { message: 'x', code: 'too_many_accounts', params: { max: 500 } }), 'With this file you would have more than 500 game accounts.'],
      // A parameter missing, or a code nobody listed: worded as any failed request.
      [refused(422, { message: 'x', code: 'bad_entry', params: {} }), en['error.unknown']],
      [refused(422, { message: 'x', code: 'something_new', params: {} }), en['error.unknown']],
      [refused(429, { message: 'x', code: 'too_many_imports' }), en['error.too_many_imports']],
      [refused(401, { message: 'x', code: 'sign_in_required' }), en['error.sign_in_required']],
      [refused(0, null), en['error.offline']],
    ];
    for (const [answer, sentence] of cases) {
      api.importData.mockReturnValueOnce(throwError(() => answer));
      await choose();
      expect(problem(), JSON.stringify(answer.error)).toBe(sentence);
      expect(host.querySelector('.preview')).toBeNull();
    }
    expect(host.textContent).not.toContain('"x"');
  });

  it('keeps the preview when the import itself fails, so it can be confirmed again', async () => {
    await setup();
    await choose();
    api.importData.mockReturnValueOnce(throwError(() => refused(429, { message: 'x', code: 'too_many_imports' })));
    button('confirm').click();
    await settle();
    await fixture.whenStable();

    expect(problem()).toBe(en['error.too_many_imports']);
    expect(host.querySelector('.preview')).not.toBeNull();
    expect(imported).toEqual([]);
  });

  it('words only what the key scan is told about', () => {
    expect([...DATA_PROBLEM_CODES]).toEqual([...DYNAMIC_KEYS['data.error.']]);
    expect(Object.values(OUTCOME_KEYS)).toEqual(DYNAMIC_KEYS['data.outcome.'].map((tail) => `data.outcome.${tail}`));
  });
});
