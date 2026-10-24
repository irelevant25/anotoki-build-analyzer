import { ApplicationRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AnotokiDialog, AnotokiDialogRef } from '@anotoki/lib/ui/dialog';
import { AuthService } from './auth';
import type { ChangelogEntry } from './changelog';
import { en } from './i18n/en';
import { TranslationService } from './i18n/translation';
import { ChangelogDialog } from './changelog-dialog';
import { provideKit } from './kit';

const ENTRIES: ChangelogEntry[] = [
  { version: '1.2', date: '2026-11-01', changes: { added: ['A new page', 'A second thing'], fixed: ['A fixed thing'] } },
  { version: '1.1', date: '2026-10-15', changes: { updated: ['A changed thing'] } },
];

/** The frame in Slovak; English underneath, as the server merges it. */
const SLOVAK = {
  ...en,
  'whatsNew.title': 'Čo je nové',
  'whatsNew.version': 'Verzia {version}',
  'whatsNew.latest': 'Najnovšia',
  'whatsNew.added': 'Nové',
  'whatsNew.fixed': 'Opravené',
  'whatsNew.updated': 'Zmenené',
  'common.close': 'Zavrieť',
};

describe('the What’s-new dialog: a frame in the reader’s language around entries that stay English', () => {
  let ref: AnotokiDialogRef | null = null;
  const stable = () => TestBed.inject(ApplicationRef).whenStable();
  /** The kit's dialog the changelog opened (AnotokiDialog puts it at the end of <body>). */
  const dialog = () => document.querySelector<HTMLElement>('anotoki-dialog')!;
  const texts = (selector: string) => [...dialog().querySelectorAll<HTMLElement>(selector)].map((element) => element.textContent?.replace(/\s+/g, ' ').trim());

  async function open(language: 'en' | 'sk'): Promise<void> {
    TestBed.configureTestingModule({
      providers: [
        language === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: en }) : provideTestTranslations(en),
        provideKit(),
        { provide: AuthService, useValue: { user: () => null, signedIn: () => false, preferences: () => null } },
      ],
    });
    ref = TestBed.inject(AnotokiDialog).open(ChangelogDialog, { data: ENTRIES, size: 'lg' });
    await stable();
  }

  afterEach(async () => {
    ref?.close();
    ref = null;
    await stable();
  });

  it('words its frame in Slovak: the title, the versions, the headings, the dates, Close', async () => {
    await open('sk');

    expect(texts('h2')).toEqual(['Čo je nové']);
    expect(texts('.version')).toEqual(['Verzia 1.2', 'Verzia 1.1']);
    expect(texts('anotoki-badge')).toEqual(['Najnovšia']);
    expect(texts('h3.kind')).toEqual(['Nové', 'Opravené', 'Zmenené']);
    expect(texts('.release-head .date')).toEqual(['1. novembra 2026', '15. októbra 2026']);
    expect(texts('.anotoki-dialog__footer button')).toEqual(['Zavrieť']);
  });

  it('shows the entries as they were written, each list saying it is English', async () => {
    await open('sk');

    expect(texts('li')).toEqual(['A new page', 'A second thing', 'A fixed thing', 'A changed thing']);
    const lists = [...dialog().querySelectorAll('ul')];
    expect(lists).toHaveLength(3);
    expect(lists.map((list) => list.getAttribute('lang'))).toEqual(['en', 'en', 'en']);
    // Only the lists: the frame around them is in the page's language.
    expect(dialog().querySelectorAll('.release [lang]')).toHaveLength(3);
  });

  it('is English through and through on an English page, with nothing marked', async () => {
    await open('en');

    expect(texts('h2')).toEqual(["What's new"]);
    expect(texts('.version')).toEqual(['Version 1.2', 'Version 1.1']);
    expect(texts('anotoki-badge')).toEqual(['Latest']);
    expect(texts('h3.kind')).toEqual(['New', 'Fixed', 'Changed']);
    expect(texts('.release-head .date')).toEqual(['1 November 2026', '15 October 2026']);
    expect(texts('.anotoki-dialog__footer button')).toEqual(['Close']);
    expect(dialog().querySelectorAll('.release [lang]')).toHaveLength(0);
  });

  it('is English on the English pages, whatever the reader’s language', async () => {
    await open('sk');
    TestBed.inject(TranslationService).noteAddress('/admin/builds');
    await stable();

    expect(texts('h2')).toEqual(["What's new"]);
    expect(texts('.release-head .date')).toEqual(['1 November 2026', '15 October 2026']);
    expect(dialog().querySelectorAll('.release [lang]')).toHaveLength(0);
  });

  it('closes with its Close', async () => {
    await open('en');
    dialog().querySelector<HTMLButtonElement>('.anotoki-dialog__footer button')!.click();
    await stable();
    expect(document.querySelector('anotoki-dialog')).toBeNull();
    expect(ref?.isClosed).toBe(true);
  });

  it('shows no key where a word should be', async () => {
    await open('sk');

    expect(dialog().textContent).not.toMatch(/\b(whatsNew|common)\.[A-Za-z]/);
  });
});
