import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { provideTestTranslations } from '../../core/i18n/testing';
import { TranslationService } from '../../core/i18n/translation';
import { LanguageSwitcher } from './language-switcher';

const ENGLISH = {
  'language.button': 'Language: {name} ({code})',
  'language.notLoaded': 'The language could not be loaded. Try again.',
};
const SLOVAK = {
  'language.button': 'Jazyk: {name} ({code})',
  'language.notLoaded': 'Jazyk sa nepodarilo načítať. Skús to znova.',
};

describe('the language switcher in the top bar', () => {
  let fixture: ComponentFixture<LanguageSwitcher>;
  let host: HTMLElement;
  let i18n: TranslationService;
  let snackBar: { open: ReturnType<typeof vi.fn> };

  const button = () => host.querySelector<HTMLButtonElement>('.switcher')!;
  const items = () => [...host.querySelectorAll<HTMLButtonElement>('[role="menuitemradio"]')];
  const words = (element: Element) => element.querySelector('.menu-label')?.textContent?.trim();

  async function open(): Promise<void> {
    button().click();
    await fixture.whenStable();
  }

  beforeEach(async () => {
    localStorage.clear();
    snackBar = { open: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        { provide: MatSnackBar, useValue: snackBar },
      ],
    });
    fixture = TestBed.createComponent(LanguageSwitcher);
    host = fixture.nativeElement;
    document.body.appendChild(host);
    i18n = TestBed.inject(TranslationService);
    await fixture.whenStable();
  });

  afterEach(() => {
    host.remove();
    vi.restoreAllMocks();
  });

  it("is a round button with the language's code, named for a screen reader in that language's own name and the code it shows", () => {
    expect(button().textContent?.trim()).toBe('SK');
    expect(button().getAttribute('aria-label')).toBe('Jazyk: Slovenčina (SK)');
    expect(button().getAttribute('aria-haspopup')).toBe('menu');
    expect(button().getAttribute('aria-expanded')).toBe('false');
  });

  it('offers the languages in their own names, each saying which language it is, the one on the page marked and focused', async () => {
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

  it('switches the language of the page, and remembers it on this device', async () => {
    await open();
    items()[0].click();
    await fixture.whenStable();

    expect(i18n.language()).toBe('en');
    expect(localStorage.getItem('build-analyzer-language')).toBe('en');
    expect(button().getAttribute('aria-label')).toBe('Language: English (EN)');
    expect(snackBar.open).not.toHaveBeenCalled();
    expect(items()).toEqual([]);
  });

  it('says so, and stays as it was, when the language could not be loaded', async () => {
    vi.spyOn(i18n, 'setLanguage').mockResolvedValue(false);
    await open();
    items()[0].click();
    await fixture.whenStable();

    expect(i18n.language()).toBe('sk');
    expect(snackBar.open).toHaveBeenCalledWith(
      'Jazyk sa nepodarilo načítať. Skús to znova.',
      undefined,
      { duration: 6000 },
    );
  });

  it('takes a second choice while the first is on its way - the last one wins, and the one overtaken is not called a failure', async () => {
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
