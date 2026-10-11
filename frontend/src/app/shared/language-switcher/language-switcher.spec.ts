import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { provideAnotokiTranslations } from '@anotoki/lib/translations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { TranslationService } from '../../core/i18n/translation';
import { LANGUAGE_STORAGE } from '../../core/i18n/translations.config';
import { LanguageSwitcher } from './language-switcher';

// The library's words, as the database has them (anotoki_translations/002, and this site's 006).
const ENGLISH = {
  'anotoki.language.button': 'Language: {name} ({code})',
  'anotoki.language.notLoaded': 'The language could not be loaded. Try again.',
  'anotoki.language.notSaved':
    'The language could not be saved to your anotoki account - it holds for this visit only.',
};
const SLOVAK = {
  'anotoki.language.button': 'Jazyk: {name} ({code})',
  'anotoki.language.notLoaded': 'Jazyk sa nepodarilo načítať. Skús to znova.',
  'anotoki.language.notSaved':
    'Jazyk sa nepodarilo uložiť do tvojho anotoki účtu - platí len počas tejto návštevy.',
};

describe('the language switcher in the top bar', () => {
  let fixture: ComponentFixture<LanguageSwitcher>;
  let host: HTMLElement;
  let i18n: TranslationService;
  let snackBar: { open: ReturnType<typeof vi.fn> };
  /** What the account says to a language saved to it: taken, unless a test says otherwise. */
  let saved: (code: string) => Promise<unknown>;

  const button = () => host.querySelector<HTMLButtonElement>('.switcher')!;
  const items = () => [...host.querySelectorAll<HTMLButtonElement>('[role="menuitemradio"]')];
  const words = (element: Element) => element.querySelector('.menu-label')?.textContent?.trim();

  async function open(): Promise<void> {
    button().click();
    await fixture.whenStable();
  }

  /** Lets pending promises, timers of 0 and change detection run out. */
  async function settle(): Promise<void> {
    for (let i = 0; i < 3; i++) {
      await new Promise((done) => setTimeout(done));
      await fixture.whenStable();
    }
  }

  function start(account: { decides: boolean } = { decides: false }): void {
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        // This site's names in storage, and an account that keeps the person's choices when the test says so.
        provideAnotokiTranslations(() => ({
          storageKeys: LANGUAGE_STORAGE,
          account: {
            language: () => null,
            decides: () => account.decides,
            save: (code) => saved(code),
          },
        })),
        { provide: MatSnackBar, useValue: snackBar },
      ],
    });
    fixture = TestBed.createComponent(LanguageSwitcher);
    host = fixture.nativeElement;
    document.body.appendChild(host);
    i18n = TestBed.inject(TranslationService);
  }

  beforeEach(() => {
    localStorage.clear();
    snackBar = { open: vi.fn() };
    saved = () => Promise.resolve();
  });

  afterEach(() => {
    host.remove();
    vi.restoreAllMocks();
  });

  it("is a round button with the language's code, named for a screen reader in that language's own name and the code it shows", async () => {
    start();
    await fixture.whenStable();
    expect(button().textContent?.trim()).toBe('SK');
    expect(button().getAttribute('aria-label')).toBe('Jazyk: Slovenčina (SK)');
    expect(button().getAttribute('aria-haspopup')).toBe('menu');
    expect(button().getAttribute('aria-expanded')).toBe('false');
  });

  it('offers the languages in their own names, each saying which language it is, the one on the page marked and focused', async () => {
    start();
    await fixture.whenStable();
    await open();
    expect(
      items().map((item) => [
        words(item),
        item.getAttribute('lang'),
        item.getAttribute('aria-checked'),
      ]),
    ).toEqual([
      ['English', 'en', 'false'],
      ['Slovenčina', 'sk', 'true'],
    ]);
    expect(document.activeElement).toBe(items()[1]);
  });

  it('switches the language of the page, and remembers it on this device - under the name this site always kept it', async () => {
    start();
    await fixture.whenStable();
    await open();
    items()[0].click();
    await settle();

    expect(i18n.language()).toBe('en');
    expect(localStorage.getItem('build-analyzer-language')).toBe('en');
    expect(button().getAttribute('aria-label')).toBe('Language: English (EN)');
    expect(snackBar.open).not.toHaveBeenCalled();
    expect(items()).toEqual([]);
  });

  it('says so, and stays as it was, when the language could not be loaded', async () => {
    start();
    await fixture.whenStable();
    vi.spyOn(i18n, 'setLanguage').mockResolvedValue(false);
    await open();
    items()[0].click();
    await settle();

    expect(i18n.language()).toBe('sk');
    expect(snackBar.open).toHaveBeenCalledWith(
      'Jazyk sa nepodarilo načítať. Skús to znova.',
      undefined,
      { duration: 6000 },
    );
  });

  it('saves the choice to the account that keeps it - and says so, once, when the account does not take it', async () => {
    start({ decides: true });
    await fixture.whenStable();
    const asked: string[] = [];
    saved = (code) => {
      asked.push(code);
      return Promise.reject(Object.assign(new Error('offline'), { code: 'network' }));
    };
    await open();
    items()[0].click();
    await settle();

    expect(asked).toEqual(['en']);
    expect(i18n.language()).toBe('en');
    // In the language the page is in now: the choice holds for this visit.
    expect(snackBar.open).toHaveBeenCalledExactlyOnceWith(
      'The language could not be saved to your anotoki account - it holds for this visit only.',
      undefined,
      { duration: 6000 },
    );
    expect(i18n.languageNotSaved()).toBe(false);
  });

  it("says nothing of a language the account does not offer: it stays this site's own for the visit", async () => {
    start({ decides: true });
    await fixture.whenStable();
    saved = () =>
      Promise.reject(Object.assign(new Error('not offered'), { code: 'invalid_request' }));
    await open();
    items()[0].click();
    await settle();

    expect(i18n.language()).toBe('en');
    expect(snackBar.open).not.toHaveBeenCalled();
  });

  it('takes a second choice while the first is on its way - the last one wins, and the one overtaken is not called a failure', async () => {
    start();
    await fixture.whenStable();
    let overtaken!: (switched: boolean) => void;
    const slow = new Promise<boolean>((done) => (overtaken = done));
    const setLanguage = vi
      .spyOn(i18n, 'setLanguage')
      .mockImplementation((code: string) => (code === 'en' ? slow : Promise.resolve(true)));

    await open();
    items()[0].click();
    await fixture.whenStable();
    await open();
    items()[1].click();
    await fixture.whenStable();
    expect(setLanguage.mock.calls).toEqual([['en'], ['sk']]);

    overtaken(false);
    await fixture.whenStable();
    expect(snackBar.open).not.toHaveBeenCalled();
  });

  it('moves with the arrow keys, closes on Escape - and on Tab with the focus on its button, never on <body>', async () => {
    start();
    await fixture.whenStable();
    await open();
    items()[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    expect(document.activeElement).toBe(items()[0]);

    const tab = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    items()[0].dispatchEvent(tab);
    expect(tab.defaultPrevented).toBe(true);
    await fixture.whenStable();
    expect(items()).toEqual([]);
    expect(document.activeElement).toBe(button());

    await open();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await fixture.whenStable();
    expect(items()).toEqual([]);
    expect(document.activeElement).toBe(button());

    await open();
    document.body.click();
    await fixture.whenStable();
    expect(items()).toEqual([]);
  });
});
