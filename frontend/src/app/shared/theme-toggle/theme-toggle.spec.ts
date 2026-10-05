import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '../../core/i18n/testing';
import { ThemeToggle } from './theme-toggle';

const SLOVAK = {
  'theme.label': 'Vzhľad',
  'theme.system': 'Systémový',
  'theme.light': 'Svetlý',
  'theme.dark': 'Tmavý',
};

describe('the theme switch in the top bar', () => {
  let fixture: ComponentFixture<ThemeToggle>;
  let host: HTMLElement;

  const choices = () => [...host.querySelectorAll<HTMLButtonElement>('[role="radio"]')];

  async function draw(): Promise<void> {
    TestBed.configureTestingModule({
      providers: [provideTestTranslations(SLOVAK, { language: 'sk', english: {} })],
    });
    fixture = TestBed.createComponent(ThemeToggle);
    host = fixture.nativeElement;
    document.body.appendChild(host);
    await fixture.whenStable();
  }

  beforeEach(() => localStorage.clear());

  afterEach(() => {
    host.remove();
    document.documentElement.classList.remove('theme-light', 'theme-dark');
  });

  it('is one choice of three, each named in the language on the page; as the device is set, at first', async () => {
    await draw();
    expect(host.querySelector('[role="radiogroup"]')?.getAttribute('aria-label')).toBe('Vzhľad');
    expect(
      choices().map((choice) => [
        choice.getAttribute('aria-label'),
        choice.getAttribute('aria-checked'),
        choice.tabIndex,
      ]),
    ).toEqual([
      ['Svetlý', 'false', -1],
      ['Tmavý', 'false', -1],
      ['Systémový', 'true', 0],
    ]);
  });

  it('puts the choice on <html> and remembers it on this device', async () => {
    await draw();
    choices()[1].click();
    await fixture.whenStable();
    expect(document.documentElement.classList.contains('theme-dark')).toBe(true);
    expect(localStorage.getItem('build-analyzer-theme')).toBe('dark');
    expect(choices().map((choice) => choice.getAttribute('aria-checked'))).toEqual([
      'false',
      'true',
      'false',
    ]);

    choices()[2].click();
    await fixture.whenStable();
    expect(document.documentElement.classList.contains('theme-dark')).toBe(false);
    expect(localStorage.getItem('build-analyzer-theme')).toBe('system');
  });

  it('moves the choice with the arrow keys, the focus with it', async () => {
    localStorage.setItem('build-analyzer-theme', 'light');
    await draw();
    choices()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
    await fixture.whenStable();
    expect(document.documentElement.classList.contains('theme-light')).toBe(false);
    expect(choices()[2].getAttribute('aria-checked')).toBe('true');
    expect(document.activeElement).toBe(choices()[2]);
  });
});
