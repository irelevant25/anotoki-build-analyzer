import { HttpErrorResponse, HttpHeaders, HttpResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { provideRouter } from '@angular/router';
import { Observable, Subject, of, throwError } from 'rxjs';
import { Api } from '../../../core/api';
import { AuthService } from '../../../core/auth';
import { TranslationService } from '../../../core/i18n/translation';
import { LocalizationDrafts } from '../../../core/localization-drafts';
import { AdminLanguage, TranslationGrid, TranslationKeyRow } from '../../../core/models';
import { TranslationsPage } from './translations-page';

const ENGLISH: AdminLanguage = { code: 'en', name: 'English', native_name: 'English', enabled: true, sort_order: 1, seeded: true, strings: 5 };
const SLOVAK: AdminLanguage = { code: 'sk', name: 'Slovak', native_name: 'Slovenčina', enabled: true, sort_order: 2, seeded: true, strings: 4 };

const key = (name: string, values: Record<string, string>, description = ''): TranslationKeyRow => ({ name, description, values });

/** As the API sends it: the keys by name, so a family's forms come few, one, other. */
function grid(changes: Record<string, Record<string, string>> = {}): TranslationGrid {
  const keys = [
    key('common.close', { en: 'Close', sk: 'Zavrieť' }, 'A button that closes what is open.'),
    key('dashboard.accounts.few', { en: '{count} accounts', sk: '{count} účty' }),
    key('dashboard.accounts.one', { en: '{count} account', sk: '1 účet' }),
    key('dashboard.accounts.other', { en: '{count} accounts', sk: '{count} účtov' }),
    key('dashboard.title', { en: 'Your accounts' }, 'The dashboard: its heading.'),
  ];
  return { languages: [ENGLISH, SLOVAK], keys: keys.map((row) => ({ ...row, values: { ...row.values, ...changes[row.name] } })) };
}

function refusal(status: number, body: Record<string, unknown>): Observable<never> {
  return throwError(() => new HttpErrorResponse({ status, error: body }));
}

describe('TranslationsPage', () => {
  let fixture: ComponentFixture<TranslationsPage>;
  let host: HTMLElement;
  let api: Record<'translationGrid' | 'saveTranslations' | 'exportTranslations' | 'importTranslations', ReturnType<typeof vi.fn>>;
  let reload: ReturnType<typeof vi.fn>;
  let snackBar: { open: ReturnType<typeof vi.fn> };

  const clean = (text: string | null | undefined) => (text ?? '').replace(/\s+/g, ' ').trim();
  const text = (selector: string, within: ParentNode = host) => clean(within.querySelector(selector)?.textContent);
  const parts = (selector: string) => Array.from(host.querySelectorAll(selector)).map((element) => clean(element.textContent));
  const row = (name: string) => host.querySelector<HTMLElement>(`[id="key-${name}"]`)!;
  const box = (name: string, code: string) => row(name).querySelector<HTMLTextAreaElement>(`textarea[lang="${code}"]`)!;
  const button = (label: string) => Array.from(host.querySelectorAll<HTMLButtonElement>('button')).find((candidate) => clean(candidate.textContent).endsWith(label))!;
  const overlay = () => document.querySelector<HTMLElement>('.cdk-overlay-container');
  const dialog = () => overlay()?.querySelector<HTMLElement>('mat-dialog-container') ?? null;

  async function settle(): Promise<void> {
    for (let i = 0; i < 3; i++) {
      await new Promise((done) => setTimeout(done));
      await fixture.whenStable();
    }
  }

  async function type(element: HTMLTextAreaElement, value: string): Promise<void> {
    element.value = value;
    element.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
  }

  /** Answers the question that is open, and says what it asked. */
  async function answer(yes: boolean): Promise<string> {
    const open = dialog();
    const title = clean(open?.querySelector('h2')?.textContent);
    open!.querySelectorAll<HTMLButtonElement>('mat-dialog-actions button')[yes ? 1 : 0].click();
    await settle();
    return title;
  }

  async function page(answerWith: Observable<TranslationGrid> = of(grid())): Promise<void> {
    api = {
      translationGrid: vi.fn(() => answerWith),
      saveTranslations: vi.fn(),
      exportTranslations: vi.fn(),
      importTranslations: vi.fn(),
    };
    reload = vi.fn(async () => undefined);
    snackBar = { open: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: Api, useValue: api },
        { provide: AuthService, useValue: { user: signal({ sub: '7', locale: null }), isAdmin: signal(true) } },
        { provide: TranslationService, useValue: { reload } },
        { provide: MatSnackBar, useValue: snackBar },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(TranslationsPage);
    host = fixture.nativeElement;
    await settle();
  }

  beforeEach(() => sessionStorage.clear());

  afterEach(() => {
    vi.restoreAllMocks();
    overlay()?.replaceChildren();
    sessionStorage.clear();
  });

  it('is English, shows a row for every key with a box for every language, and holds a plural family together', async () => {
    await page();

    expect(host.querySelector('.page')?.getAttribute('lang')).toBe('en');
    expect(text('h1')).toBe('Translations');
    expect(Array.from(host.querySelectorAll('.key-name')).map((element) => clean(element.textContent))).toEqual([
      'common.close',
      'dashboard.accounts.one',
      'dashboard.accounts.few',
      'dashboard.accounts.other',
      'dashboard.title',
    ]);
    expect(box('common.close', 'sk').value).toBe('Zavrieť');
    expect(box('dashboard.title', 'sk').value).toBe('');
    expect(box('dashboard.title', 'sk').placeholder).toBe('Falls back to English');
    expect(parts('.sample[lang="sk"] li')).toEqual(['1 účet', '3 účty', '12 účtov']);
  });

  it('saves with one button only the boxes that changed, marks them until then, and takes the grid the server answers', async () => {
    await page();
    await type(box('dashboard.title', 'sk'), 'Tvoje účty');
    await type(box('common.close', 'en'), 'Close');

    expect(text('.save-state')).toBe('1 string changed, not saved yet');
    expect(text('.mark', row('dashboard.title'))).toBe('Changed');

    api.saveTranslations.mockReturnValue(of(grid({ 'dashboard.title': { sk: 'Tvoje účty' } })));
    button('Save').click();
    await settle();

    expect(api.saveTranslations).toHaveBeenCalledWith({ 'dashboard.title': { sk: 'Tvoje účty' } });
    expect(text('.save-state')).toBe('Everything is saved');
    expect(row('dashboard.title').querySelector('.mark')).toBeNull();
    expect(box('dashboard.title', 'sk').value).toBe('Tvoje účty');
    expect(snackBar.open).toHaveBeenCalledWith('The string is saved.', undefined, { duration: 4000 });
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("shows the server's refusal beside the key it is about, and keeps what was typed", async () => {
    await page();
    await type(box('dashboard.title', 'sk'), 'Tvoje {accounts}');
    api.saveTranslations.mockReturnValue(
      refusal(422, { message: 'The Slovak string of "dashboard.title" has {accounts}, which the English does not have.', code: 'unknown_placeholder', key: 'dashboard.title', language: 'sk' }),
    );

    button('Save').click();
    await settle();

    expect(text('.refused > span', row('dashboard.title'))).toBe('Nothing was saved. The Slovak string of "dashboard.title" has {accounts}, which the English does not have.');
    expect(box('dashboard.title', 'sk').getAttribute('aria-invalid')).toBe('true');
    expect(box('dashboard.title', 'sk').value).toBe('Tvoje {accounts}');
    expect(text('.save-state .refused > span')).toBe('Nothing was saved: see dashboard.title');
    expect(reload).not.toHaveBeenCalled();

    // Changing the box is the answer to it.
    await type(box('dashboard.title', 'sk'), 'Tvoje účty');
    expect(row('dashboard.title').querySelector('.refused')).toBeNull();
  });

  it('shows a string that names another site beside its key, in the server\'s words', async () => {
    await page();
    await type(box('common.close', 'sk'), 'Zavrieť (pozri example.com)');
    api.saveTranslations.mockReturnValue(
      refusal(422, {
        message: 'The Slovak string of "common.close" names another site (a web address). Strings name no other site.',
        code: 'names_another_site',
        key: 'common.close',
        language: 'sk',
        found: ['a web address'],
      }),
    );

    button('Save').click();
    await settle();

    expect(text('.refused > span', row('common.close'))).toBe('Nothing was saved. The Slovak string of "common.close" names another site (a web address). Strings name no other site.');
    expect(box('common.close', 'sk').getAttribute('aria-invalid')).toBe('true');
    expect(box('common.close', 'en').getAttribute('aria-invalid')).toBeNull();
  });

  it('says in the save bar a save that failed for no key, and takes it away at the next keystroke', async () => {
    await page();
    await type(box('dashboard.title', 'sk'), 'Tvoje účty');
    api.saveTranslations.mockReturnValue(refusal(0, {}));

    button('Save').click();
    await settle();

    expect(text('.save-state .refused > span')).toBe('Nothing was saved. Cannot reach the server. Check your connection and try again.');
    expect(host.querySelector('.key .refused')).toBeNull();

    await type(box('dashboard.title', 'sk'), 'Tvoje účty!');
    expect(text('.save-state')).toBe('1 string changed, not saved yet');
  });

  it('keeps what is typed while a save is on its way - even the old text typed back', async () => {
    await page();
    await type(box('dashboard.title', 'sk'), 'Tvoje účty');
    await type(box('common.close', 'en'), 'Shut');
    const answerLater = new Subject<TranslationGrid>();
    api.saveTranslations.mockReturnValue(answerLater);

    button('Save').click();
    await settle();
    // While it is out: the title typed on, and "Close" typed back over "Shut".
    await type(box('dashboard.title', 'sk'), 'Tvoje účty!');
    await type(box('common.close', 'en'), 'Close');

    answerLater.next(grid({ 'dashboard.title': { sk: 'Tvoje účty' }, 'common.close': { en: 'Shut' } }));
    answerLater.complete();
    await settle();

    expect(TestBed.inject(LocalizationDrafts).strings()).toEqual({ 'dashboard.title': { sk: 'Tvoje účty!' }, 'common.close': { en: 'Close' } });
    expect(box('dashboard.title', 'sk').value).toBe('Tvoje účty!');
    expect(box('common.close', 'en').value).toBe('Close');
    expect(text('.save-state')).toBe('2 strings changed, not saved yet');
  });

  it('asks before it is left with changes not saved, and not otherwise - and "Leave" gives them up', async () => {
    await page();
    expect(fixture.componentInstance.canLeave()).toBe(true);

    await type(box('dashboard.title', 'sk'), 'Tvoje účty');
    let left: boolean | undefined;
    void Promise.resolve(fixture.componentInstance.canLeave()).then((answer) => (left = answer));
    await settle();
    expect(await answer(false)).toBe('Leave without saving?');
    expect(left).toBe(false);
    expect(TestBed.inject(LocalizationDrafts).count()).toBe(1);

    void Promise.resolve(fixture.componentInstance.canLeave()).then((answer) => (left = answer));
    await settle();
    await answer(true);
    expect(left).toBe(true);
    expect(TestBed.inject(LocalizationDrafts).count()).toBe(0);
  });

  it('says to apply the waiting database update first while the strings are not in the database', async () => {
    await page(refusal(503, { message: 'The site\'s words are not in the database yet.', code: 'translations_unavailable' }));

    expect(parts('.load-error-text > *').join(' ')).toBe('The translations could not be loaded. An admin applies the waiting database update on the Migrations page first.');
    expect(host.querySelector('.load-error a')?.getAttribute('href')).toBe('/admin/migrations');
  });

  it('exports a language as the file the server makes, by the name it gives', async () => {
    await page();
    const saved = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:file');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    const clicked = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    const file = new Blob(['{\n    "common.close": "Zavrieť"\n}\n'], { type: 'application/json' });
    api.exportTranslations.mockReturnValue(
      of(new HttpResponse({ body: file, headers: new HttpHeaders({ 'Content-Disposition': 'attachment; filename="translations-sk.json"' }) })),
    );

    button('Export').parentElement!.querySelectorAll('button')[0].click();
    await settle();

    expect(api.exportTranslations).toHaveBeenCalledWith('en');
    expect(saved).toHaveBeenCalledWith(file);
    expect(clicked).toHaveBeenCalled();
    expect(snackBar.open).toHaveBeenCalledWith('1 string of English is downloaded.', undefined, { duration: 4000 });
  });
});
