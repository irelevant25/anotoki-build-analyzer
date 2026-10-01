import { HttpErrorResponse, HttpHeaders, HttpResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { provideRouter } from '@angular/router';
import { Observable, of, throwError } from 'rxjs';
import { Api } from '../../core/api';
import { AuthMode, AuthService } from '../../core/auth';
import { TranslationService } from '../../core/i18n/translation';
import { LocalizationDrafts } from '../../core/localization-drafts';
import { AdminLanguage } from '../../core/models';
import { LanguagesPage } from './languages-page';

const ENGLISH: AdminLanguage = { code: 'en', name: 'English', native_name: 'English', enabled: true, sort_order: 1, seeded: true, strings: 406 };
const SLOVAK: AdminLanguage = { code: 'sk', name: 'Slovak', native_name: 'Slovenčina', enabled: true, sort_order: 2, seeded: true, strings: 406 };
const CZECH: AdminLanguage = { code: 'cs', name: 'Czech', native_name: 'Čeština', enabled: false, sort_order: 3, seeded: false, strings: 12 };

function refusal(status: number, body: Record<string, unknown>): Observable<never> {
  return throwError(() => new HttpErrorResponse({ status, error: body }));
}

describe('LanguagesPage', () => {
  let fixture: ComponentFixture<LanguagesPage>;
  let host: HTMLElement;
  let api: Record<'languages' | 'createLanguage' | 'updateLanguage' | 'deleteLanguage' | 'exportTranslations', ReturnType<typeof vi.fn>>;
  let reload: ReturnType<typeof vi.fn>;
  let snackBar: { open: ReturnType<typeof vi.fn> };

  const clean = (text: string | null | undefined) => (text ?? '').replace(/\s+/g, ' ').trim();
  const rows = () => Array.from(host.querySelectorAll<HTMLElement>('.language'));
  const row = (code: string) => rows().find((candidate) => clean(candidate.querySelector('.head .tag')?.textContent) === code)!;
  const inputs = (within: ParentNode) => Array.from(within.querySelectorAll<HTMLInputElement>('input[matInput]'));
  const button = (label: string, within: ParentNode = host) => Array.from(within.querySelectorAll<HTMLButtonElement>('button')).find((candidate) => clean(candidate.textContent).endsWith(label))!;
  const overlay = () => document.querySelector<HTMLElement>('.cdk-overlay-container');
  const dialog = () => overlay()?.querySelector<HTMLElement>('mat-dialog-container') ?? null;

  async function settle(): Promise<void> {
    for (let i = 0; i < 3; i++) {
      await new Promise((done) => setTimeout(done));
      await fixture.whenStable();
    }
  }

  async function type(element: HTMLInputElement, value: string): Promise<void> {
    element.value = value;
    element.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
  }

  /** The question that is open: its title and what it says, then answered. */
  async function answer(yes: boolean): Promise<string> {
    const open = dialog();
    const asked = `${clean(open?.querySelector('h2')?.textContent)} | ${clean(open?.querySelector('.message')?.textContent)}`;
    open!.querySelectorAll<HTMLButtonElement>('mat-dialog-actions button')[yes ? 1 : 0].click();
    await settle();
    return asked;
  }

  async function page(languages: AdminLanguage[] = [ENGLISH, SLOVAK, CZECH]): Promise<void> {
    api = {
      languages: vi.fn(() => of({ languages })),
      createLanguage: vi.fn(),
      updateLanguage: vi.fn(),
      deleteLanguage: vi.fn(),
      exportTranslations: vi.fn(),
    };
    reload = vi.fn(async () => undefined);
    snackBar = { open: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: Api, useValue: api },
        { provide: AuthService, useValue: { mode: signal<AuthMode>('iam'), user: signal({ sub: '7', locale: null }) } },
        { provide: TranslationService, useValue: { reload } },
        { provide: MatSnackBar, useValue: snackBar },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(LanguagesPage);
    host = fixture.nativeElement;
    await settle();
  }

  beforeEach(() => sessionStorage.clear());

  afterEach(() => {
    vi.restoreAllMocks();
    overlay()?.replaceChildren();
    sessionStorage.clear();
  });

  it('is English, lists every language - the hidden ones too - and offers Delete only for one the site is not released in', async () => {
    await page();

    expect(host.querySelector('.page')?.getAttribute('lang')).toBe('en');
    expect(rows().map((element) => clean(element.querySelector('.name')?.textContent))).toEqual(['English', 'Slovak', 'Czech']);
    expect(row('cs').classList).toContain('is-hidden');
    expect(button('Delete', row('en'))).toBeUndefined();
    expect(button('Delete', row('sk'))).toBeUndefined();
    expect(button('Delete', row('cs'))).toBeDefined();
  });

  it('renames a language and moves it, sending only what changed', async () => {
    await page();
    const [name, , order] = inputs(row('cs'));
    await type(name, 'Czech language');
    await type(order, '0');
    api.updateLanguage.mockReturnValue(of({ language: { ...CZECH, name: 'Czech language', sort_order: 0 } }));

    button('Save', row('cs')).click();
    await settle();

    expect(api.updateLanguage).toHaveBeenCalledWith('cs', { name: 'Czech language', sort_order: 0 });
    expect(rows().map((element) => clean(element.querySelector('.name')?.textContent))).toEqual(['Czech language', 'English', 'Slovak']);
    expect(TestBed.inject(LocalizationDrafts).languages()).toEqual({});
    expect(reload).toHaveBeenCalledTimes(1);
  });

  describe('adding a language', () => {
    async function fill(): Promise<HTMLFormElement> {
      const form = host.querySelector<HTMLFormElement>('form.add')!;
      const [code, name, nativeName] = inputs(form);
      await type(code, 'DE');
      await type(name, 'German');
      await type(nativeName, 'Deutsch');
      return form;
    }

    it('adds it hidden unless told otherwise - the toggle starts off - and says so', async () => {
      await page();
      const form = await fill();
      const added: AdminLanguage = { code: 'de', name: 'German', native_name: 'Deutsch', enabled: false, sort_order: 4, seeded: false, strings: 0 };
      api.createLanguage.mockReturnValue(of({ language: added }));

      expect(form.querySelector<HTMLInputElement>('mat-slide-toggle button, mat-slide-toggle input')?.getAttribute('aria-checked')).toBe('false');
      button('Add the language', form).click();
      await settle();

      expect(api.createLanguage).toHaveBeenCalledWith({ code: 'de', name: 'German', native_name: 'Deutsch', enabled: false });
      expect(rows().map((element) => clean(element.querySelector('.name')?.textContent))).toEqual(['English', 'Slovak', 'Czech', 'German']);
      expect(snackBar.open).toHaveBeenCalledWith('German is added, hidden. Write its strings, then offer it.', undefined, { duration: 4000 });
      expect(inputs(form).map((input) => input.value)).toEqual(['', '', '']);
    });

    it('offers it at once when the toggle says so', async () => {
      await page();
      const form = await fill();
      form.querySelector<HTMLButtonElement>('mat-slide-toggle button')!.click();
      await settle();
      api.createLanguage.mockReturnValue(of({ language: { code: 'de', name: 'German', native_name: 'Deutsch', enabled: true, sort_order: 4, seeded: false, strings: 0 } }));

      button('Add the language', form).click();
      await settle();

      expect(api.createLanguage).toHaveBeenCalledWith({ code: 'de', name: 'German', native_name: 'Deutsch', enabled: true });
    });

    it("shows the server's refusal of a name that names another site, and keeps the form", async () => {
      await page();
      const form = await fill();
      api.createLanguage.mockReturnValue(
        refusal(422, { message: 'The name names another site (a web address). Names name no other site.', code: 'names_another_site', field: 'name', found: ['a web address'] }),
      );

      button('Add the language', form).click();
      await settle();

      expect(clean(host.querySelector('.add-error > span')?.textContent)).toBe('The name names another site (a web address). Names name no other site.');
      expect(inputs(form).map((input) => input.value)).toEqual(['DE', 'German', 'Deutsch']);
    });
  });

  describe('deleting a language', () => {
    it('offers the export first, then names how many strings go', async () => {
      await page();
      vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:file');
      vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
      const clicked = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
      api.exportTranslations.mockReturnValue(of(new HttpResponse({ body: new Blob(['{}']), headers: new HttpHeaders({ 'Content-Disposition': 'attachment; filename="translations-cs.json"' }) })));
      api.deleteLanguage.mockReturnValue(of({ strings: 12 }));

      button('Delete', row('cs')).click();
      await settle();
      expect(await answer(true)).toBe(
        'Export the 12 strings of Czech first? | Deleting a language deletes its strings, and they cannot be brought back. An exported file can be imported again on the Translations page.',
      );
      expect(api.exportTranslations).toHaveBeenCalledWith('cs');
      expect(clicked).toHaveBeenCalled();
      expect(await answer(true)).toBe('Delete Czech (cs)? | Its 12 strings go with it, and none of them can be brought back.');

      expect(api.deleteLanguage).toHaveBeenCalledWith('cs');
      expect(row('cs')).toBeUndefined();
      expect(snackBar.open).toHaveBeenCalledWith('Czech is deleted, and its 12 strings with it.', undefined, { duration: 4000 });
      expect(reload).toHaveBeenCalledTimes(1);
    });

    it('keeps the language when the answer is no', async () => {
      await page();

      button('Delete', row('cs')).click();
      await settle();
      await answer(false);
      await answer(false);

      expect(api.exportTranslations).not.toHaveBeenCalled();
      expect(api.deleteLanguage).not.toHaveBeenCalled();
      expect(row('cs')).toBeDefined();
    });
  });

  it('asks before it is left with a row changed and not saved - and "Leave" gives the changes up', async () => {
    await page();
    expect(fixture.componentInstance.canLeave()).toBe(true);
    await type(inputs(row('cs'))[0], 'Czech language');

    let left: boolean | undefined;
    void Promise.resolve(fixture.componentInstance.canLeave()).then((answer) => (left = answer));
    await settle();
    expect(await answer(true)).toBe('Leave without saving? | 1 language has changes that are not saved yet, and leaving loses them.');

    expect(left).toBe(true);
    expect(TestBed.inject(LocalizationDrafts).count()).toBe(0);
  });

  it('says to apply the waiting database update first while the strings are not in the database', async () => {
    api = undefined!;
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: Api, useValue: { languages: () => refusal(503, { message: 'Not yet.', code: 'translations_unavailable' }) } },
        { provide: AuthService, useValue: { mode: signal<AuthMode>('none'), user: signal(null) } },
        { provide: TranslationService, useValue: { reload: vi.fn() } },
        { provide: MatSnackBar, useValue: { open: vi.fn() } },
      ],
    });
    fixture = TestBed.createComponent(LanguagesPage);
    host = fixture.nativeElement;
    await settle();

    expect(Array.from(host.querySelectorAll('.load-error-text > *')).map((element) => clean(element.textContent)).join(' ')).toBe('The languages could not be loaded. Apply the waiting database update on the Sources page first.');
  });
});
