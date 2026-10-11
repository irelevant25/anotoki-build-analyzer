import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslationGrid } from '@anotoki/lib/translations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../../core/auth';
import { en } from '../../../core/i18n/en';
import { provideTranslations } from '../../../core/i18n/translations.config';
import { TranslationsPage } from './translations-page';

/** The grid as this site's server answers it (anotoki/lib's routes: snake_case). */
const GRID: TranslationGrid = {
  languages: [
    {
      code: 'en',
      name: 'English',
      native_name: 'English',
      enabled: true,
      sort_order: 1,
      seeded: true,
      strings: 2,
    },
    {
      code: 'sk',
      name: 'Slovak',
      native_name: 'Slovenčina',
      enabled: true,
      sort_order: 2,
      seeded: true,
      strings: 1,
    },
  ],
  keys: [
    {
      name: 'title.builds',
      description: "The browser tab's title on the list of builds.",
      values: { en: 'Builds', sk: 'Buildy' },
    },
    {
      name: 'topbar.import',
      description: 'The top bar: the link to the import guide.',
      values: { en: 'Import' },
    },
  ],
};

/** jsdom has <dialog> but neither showModal() nor close(): enough of both for the page's own questions. */
function dialogsThatOpen(): void {
  const dialogs = HTMLDialogElement.prototype;
  if (typeof dialogs.showModal !== 'function') {
    dialogs.showModal = function (this: HTMLDialogElement): void {
      this.setAttribute('open', '');
    };
    dialogs.close = function (this: HTMLDialogElement): void {
      if (this.hasAttribute('open')) {
        this.removeAttribute('open');
        this.dispatchEvent(new Event('close'));
      }
    };
  }
}

/** An element's words, its white space folded. */
const words = (element: Element | null | undefined) =>
  element?.textContent?.replace(/\s+/g, ' ').trim() ?? null;

describe("/admin/translations: the family's Translations page in the admin panel (EDITOR and ADMIN)", () => {
  const roles = signal<string[]>(['EDITOR']);
  const auth = {
    user: () => ({ sub: '7', locale: null }),
    preferences: () => null,
    isStaff: () => roles().includes('EDITOR') || roles().includes('ADMIN'),
    isAdmin: () => roles().includes('ADMIN'),
    savePreferences: vi.fn(),
  };
  let http: HttpTestingController;
  let fixture: ComponentFixture<TranslationsPage>;
  let host: HTMLElement;

  async function settle(): Promise<void> {
    for (let i = 0; i < 4; i++) {
      await new Promise((done) => setTimeout(done));
      await fixture.whenStable();
    }
  }

  async function draw(): Promise<void> {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTestTranslations(en),
        provideTranslations(),
        { provide: AuthService, useValue: auth },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(TranslationsPage);
    host = fixture.nativeElement;
    document.body.appendChild(host);
    await settle();
  }

  beforeEach(() => {
    dialogsThatOpen();
    sessionStorage.clear();
    roles.set(['EDITOR']);
  });

  afterEach(() => {
    http.verify();
    host?.remove();
    fixture?.destroy();
  });

  it("draws the library's page in the admin panel's frame, with this site's words of what it is - for an editor", async () => {
    await draw();
    http.expectOne({ method: 'GET', url: '/api/admin/translations' }).flush(GRID);
    await settle();

    expect(host.querySelector('.page > anotoki-translations-page')).not.toBeNull();
    expect(words(host.querySelector('h1'))).toBe('Translations');
    expect(words(host.querySelector('anotoki-page-header'))).toContain(
      "Game terms and values are the games' own and are not here.",
    );
    expect(host.querySelector('textarea[aria-label="Slovak: topbar.import"]')).not.toBeNull();
  });

  it('asks the server nothing for somebody this site does not let reword its strings', async () => {
    roles.set(['MEMBER']);
    await draw();
    expect(words(host)).toBe('The translations are for the people the site lets change its words.');
  });

  it("leaves at once with nothing typed, and asks first with something typed and not saved - the library's page answers for it", async () => {
    await draw();
    http.expectOne({ method: 'GET', url: '/api/admin/translations' }).flush(GRID);
    await settle();
    expect(fixture.componentInstance.canLeave()).toBe(true);

    const box = host.querySelector<HTMLTextAreaElement>(
      'textarea[aria-label="Slovak: topbar.import"]',
    )!;
    box.value = 'Import';
    box.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();

    const leaving = fixture.componentInstance.canLeave();
    expect(leaving).toBeInstanceOf(Promise);
    await settle();
    expect(words(document.querySelector('[data-question] h2'))).toBe('Leave without saving?');
    document.querySelector<HTMLButtonElement>('[data-question-no]')!.click();
    expect(await leaving).toBe(false);
  });
});
