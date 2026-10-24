import { Injector, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideAnotokiTranslations } from '@anotoki/lib/translations';
import { LocalizationDrafts } from '@anotoki/lib/translations/admin';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { LANGUAGE_STORAGE } from './i18n/translations.config';
import { draftsInTab, localizationDrafts } from './localization-drafts';

describe("the drafts of the Translations and Languages pages, reached from outside them (the library's LocalizationDrafts)", () => {
  const person = signal<string | null>('7');

  beforeEach(() => {
    sessionStorage.clear();
    person.set('7');
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations({}),
        provideAnotokiTranslations(() => ({
          storageKeys: LANGUAGE_STORAGE,
          account: { language: () => null, userKey: () => person() },
        })),
      ],
    });
  });

  afterEach(() => sessionStorage.clear());

  const stored = (owner: string) => sessionStorage.setItem('build-analyzer-localization-drafts', JSON.stringify({ owner, strings: { 'topbar.import': { sk: 'Import' } }, languages: {} }));

  it('are none while the tab holds none - and nothing of the admin pages is loaded for them', async () => {
    expect(draftsInTab()).toBe(false);
    expect(await localizationDrafts(TestBed.inject(Injector))).toBeNull();
  });

  it("are this person's drafts brought back by a page load: made with them, so the browser asks before the tab closes", async () => {
    stored('7');
    expect(draftsInTab()).toBe(true);
    const drafts = await localizationDrafts(TestBed.inject(Injector));
    expect(drafts).toBe(TestBed.inject(LocalizationDrafts));
    expect(drafts?.count()).toBe(1);
    expect(drafts?.strings()).toEqual({ 'topbar.import': { sk: 'Import' } });
  });

  it("let go of somebody else's, never to be asked about", async () => {
    stored('99');
    const drafts = await localizationDrafts(TestBed.inject(Injector));
    expect(drafts?.count()).toBe(0);
    expect(draftsInTab()).toBe(false);
  });
});
