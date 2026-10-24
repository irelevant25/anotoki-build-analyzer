import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AdminLanguage } from '@anotoki/lib/translations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../../shared/auth';
import { en } from '../../../shared/i18n/en';
import { provideTranslations } from '../../../shared/i18n/translations.config';
import { LanguagesPage } from './languages-page';

/** The languages as this site's server answers them (anotoki/lib's routes: snake_case, with how many strings each has). */
const LANGUAGES: AdminLanguage[] = [
  {
    code: 'en',
    name: 'English',
    native_name: 'English',
    enabled: true,
    sort_order: 1,
    seeded: true,
    strings: 450,
  },
  {
    code: 'sk',
    name: 'Slovak',
    native_name: 'Slovenčina',
    enabled: true,
    sort_order: 2,
    seeded: true,
    strings: 448,
  },
  {
    code: 'cs',
    name: 'Czech',
    native_name: 'Čeština',
    enabled: false,
    sort_order: 3,
    seeded: false,
    strings: 12,
  },
];

/** An element's words, its white space folded. */
const words = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim() ?? null;

describe("/admin/languages: the family's Languages page in the admin panel (ADMIN)", () => {
  const roles = signal<string[]>(['ADMIN']);
  const auth = {
    user: () => ({ sub: '1', locale: null }),
    preferences: () => null,
    isStaff: () => roles().includes('EDITOR') || roles().includes('ADMIN'),
    isAdmin: () => roles().includes('ADMIN'),
    savePreferences: vi.fn(),
  };
  let http: HttpTestingController;
  let fixture: ComponentFixture<LanguagesPage>;
  let host: HTMLElement;

  async function settle(): Promise<void> {
    for (let i = 0; i < 4; i++) {
      await new Promise((done) => setTimeout(done));
      await fixture.whenStable();
    }
  }

  async function draw(): Promise<void> {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]), provideTestTranslations(en), provideTranslations(), { provide: AuthService, useValue: auth }],
    });
    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(LanguagesPage);
    host = fixture.nativeElement;
    document.body.appendChild(host);
    await settle();
  }

  beforeEach(() => {
    sessionStorage.clear();
    roles.set(['ADMIN']);
  });

  afterEach(() => {
    http.verify();
    host?.remove();
    fixture?.destroy();
  });

  it("draws the library's page in the admin panel's frame: every language - English the fallback, Slovak released with the site", async () => {
    await draw();
    http.expectOne({ method: 'GET', url: '/api/admin/languages' }).flush({ languages: LANGUAGES });
    await settle();

    expect(host.querySelector('.page > anotoki-languages-page')).not.toBeNull();
    expect(words(host.querySelector('h1'))).toBe('Languages');
    const rows = Array.from(host.querySelectorAll<HTMLElement>('[data-language]'));
    expect(rows.map((row) => row.dataset['language'])).toEqual(['en', 'sk', 'cs']);
    expect(rows.map((row) => Array.from(row.querySelectorAll('anotoki-badge')).map(words))).toEqual([['en', 'Fallback'], ['sk', 'Released with the site'], ['cs']]);
    expect(fixture.componentInstance.canLeave()).toBe(true);
  });

  it("asks the server nothing for an editor: the languages are an admin's", async () => {
    roles.set(['EDITOR']);
    await draw();
    expect(words(host)).toBe('The languages are for the people the site lets change them.');
  });
});
