import { ApplicationRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatDialog } from '@angular/material/dialog';
import type { ChangelogEntry } from '../core/changelog';
import { en } from '../core/i18n/en';
import { provideTestTranslations } from '../core/i18n/testing';
import { TranslationService } from '../core/i18n/translation';
import { ChangelogDialog } from './changelog-dialog';

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
  const stable = () => TestBed.inject(ApplicationRef).whenStable();
  const overlay = () => document.querySelector<HTMLElement>('.cdk-overlay-container')!;
  /** What each element says, without the names of its icons. */
  const texts = (selector: string) =>
    [...overlay().querySelectorAll<HTMLElement>(selector)].map((element) => {
      const copy = element.cloneNode(true) as Element;
      copy.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
      return copy.textContent?.replace(/\s+/g, ' ').trim();
    });

  async function open(language: 'en' | 'sk'): Promise<void> {
    TestBed.configureTestingModule({
      providers: [
        language === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: en }) : provideTestTranslations(en),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    TestBed.inject(MatDialog).open(ChangelogDialog, { data: ENTRIES });
    await stable();
  }

  afterEach(async () => {
    TestBed.inject(MatDialog).closeAll();
    await stable();
  });

  it('words its frame in Slovak: the title, the versions, the headings, the dates, Close', async () => {
    await open('sk');

    expect(texts('h2')).toEqual(['Čo je nové']);
    expect(texts('.version')).toEqual(['Verzia 1.2', 'Verzia 1.1']);
    expect(texts('.chip')).toEqual(['Najnovšia']);
    expect(texts('h3.kind')).toEqual(['Nové', 'Opravené', 'Zmenené']);
    expect(texts('.release-head .muted')).toEqual(['1. novembra 2026', '15. októbra 2026']);
    expect(texts('mat-dialog-actions button')).toEqual(['Zavrieť']);
  });

  it('shows the entries as they were written, each list saying it is English', async () => {
    await open('sk');

    expect(texts('li')).toEqual(['A new page', 'A second thing', 'A fixed thing', 'A changed thing']);
    const lists = [...overlay().querySelectorAll('ul')];
    expect(lists).toHaveLength(3);
    expect(lists.map((list) => list.getAttribute('lang'))).toEqual(['en', 'en', 'en']);
    // Only the lists: the frame around them is in the page's language.
    expect(overlay().querySelectorAll('[lang]')).toHaveLength(3);
  });

  it('is English through and through on an English page, with nothing marked', async () => {
    await open('en');

    expect(texts('h2')).toEqual(["What's new"]);
    expect(texts('.version')).toEqual(['Version 1.2', 'Version 1.1']);
    expect(texts('.chip')).toEqual(['Latest']);
    expect(texts('h3.kind')).toEqual(['New', 'Fixed', 'Changed']);
    expect(texts('.release-head .muted')).toEqual(['1 November 2026', '15 October 2026']);
    expect(texts('mat-dialog-actions button')).toEqual(['Close']);
    expect(overlay().querySelectorAll('[lang]')).toHaveLength(0);
  });

  it('is English on the English pages, whatever the reader’s language', async () => {
    await open('sk');
    TestBed.inject(TranslationService).noteAddress('/sources');
    await stable();

    expect(texts('h2')).toEqual(["What's new"]);
    expect(texts('.release-head .muted')).toEqual(['1 November 2026', '15 October 2026']);
    expect(overlay().querySelectorAll('[lang]')).toHaveLength(0);
  });

  it('shows no key where a word should be', async () => {
    await open('sk');

    expect(overlay().textContent).not.toMatch(/\b(whatsNew|common)\.[A-Za-z]/);
  });
});
