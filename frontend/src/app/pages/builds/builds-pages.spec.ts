import { HttpErrorResponse } from '@angular/common/http';
import { Type, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import { Observable, of, throwError } from 'rxjs';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { provideTestTranslations } from '../../core/i18n/testing';
import { TranslationService } from '../../core/i18n/translation';
import { BuildCharacter, CharacterBuilds } from '../../core/models';
import { BuildCharacterPage } from './build-character-page';
import { BuildsPage } from './builds-page';

/** The informal Slovak of the two builds pages. */
const SLOVAK: Record<string, string> = {
  'error.offline': 'Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.',
  'error.not_found': 'Toto sa nenašlo.',
  'builds.title': 'Odporúčané buildy',
  'builds.subtitle': 'Všetko, čo nazbierali synchronizácie buildov, podľa postáv a zdrojov.',
  'builds.game': 'Hra',
  'builds.search': 'Hľadať postavy z hry {game}',
  'builds.count.one': '{count} build',
  'builds.count.few': '{count} buildy',
  'builds.count.other': '{count} buildov',
  'builds.empty': 'Zatiaľ tu nie sú žiadne buildy. Synchronizáciu spustíš na stránke {link}.',
  'builds.sourcesPage': 'Zdroje',
  'builds.allCharacters': 'Všetky postavy',
  'buildDetail.substatPriority': 'Substats: priorita',
  'buildDetail.substatsBorrowed': 'Tento zdroj ich neuvádza; analýza preberá priority z iného zdroja.',
  'buildDetail.noWeapons': 'Tento zdroj tu nič neuvádza.',
  'buildDetail.checkedNever': 'Zatiaľ neskontrolované',
};

function character(key: string, name: string, builds: number, sources: string[]): BuildCharacter {
  return { key, name, element: 'pyro', rarity: 5, weapon_type: 'polearm', icon_url: null, released_at: null, builds, sources, changed_at: null };
}

const CHARACTERS = [
  character('hutao', 'Hu Tao', 1, ['Source 1']),
  character('xiangling', 'Xiangling', 3, ['Source 1', 'Source 2']),
  character('bennett', 'Bennett', 7, ['Source 2']),
];

function builds(count: number): CharacterBuilds {
  return {
    character: { key: 'hutao', name: 'Hu Tao', element: 'pyro', rarity: 5, weapon_type: 'polearm', icon_url: null },
    builds: Array.from({ length: count }, (_, index) => ({
      id: index + 1,
      source: { key: `genshin-${index + 1}`, name: `Source ${index + 1}` },
      variant: 'Main DPS',
      role: null,
      recommended: true,
      weapons: [],
      sets: [],
      main_stats: {},
      substats: [],
      talent_priority: null,
      stat_targets: null,
      notes: null,
      source_updated_at: null,
      game_version: null,
      fetched_at: null,
      changed_at: null,
    })),
  };
}

describe('the builds pages: the list of characters and one character’s builds', () => {
  let host: HTMLElement;
  let warn: ReturnType<typeof vi.spyOn>;

  const text = (selector: string) => [...host.querySelectorAll<HTMLElement>(selector)].map((element) => element.textContent?.replace(/\s+/g, ' ').trim() ?? '');

  async function open<T>(page: Type<T>, language: 'en' | 'sk', api: Partial<Record<keyof Api, () => Observable<unknown>>>, inputs: Record<string, string>): Promise<ComponentFixture<T>> {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        language === 'en' ? provideTestTranslations(en) : provideTestTranslations(SLOVAK, { language: 'sk', english: en }),
        // The metadata is not loaded: a build's detail then shows stat keys, which no case here looks at.
        { provide: Api, useValue: api },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        // An editor: the Sources page is a link for them.
        { provide: AuthService, useValue: { isStaff: signal(true), user: signal(null) } },
      ],
    });
    const fixture = TestBed.createComponent(page);
    for (const [name, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(name, value);
    }
    host = fixture.nativeElement;
    await fixture.whenStable();
    return fixture;
  }

  beforeEach(() => {
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
  });

  afterEach(() => vi.restoreAllMocks());

  it('lists the characters in English, one build and several', async () => {
    await open(BuildsPage, 'en', { buildCharacters: () => of(CHARACTERS) }, { game: 'genshin' });

    expect(text('h1')).toEqual(['Recommended builds']);
    expect(text('.subtitle')).toEqual(['Everything the build syncs collected, per character and source.']);
    expect(host.querySelector('mat-button-toggle-group')?.getAttribute('aria-label')).toBe('Game');
    expect(text('mat-button-toggle')).toEqual(['Genshin Impact', 'Honkai: Star Rail']);
    expect(text('mat-label')).toEqual(['Search Genshin Impact characters']);
    expect(text('.card .small')).toEqual(['Pyro · Polearm', '1 build · Source 1', 'Pyro · Polearm', '3 builds · Source 1, Source 2', 'Pyro · Polearm', '7 builds · Source 2']);
    expect(warn).not.toHaveBeenCalled();
  });

  it('lists them in Slovak: the count in the form the number asks for, the game’s and the sources’ names as they are', async () => {
    await open(BuildsPage, 'sk', { buildCharacters: () => of(CHARACTERS) }, { game: 'hsr' });

    expect(text('h1')).toEqual(['Odporúčané buildy']);
    expect(host.querySelector('mat-button-toggle-group')?.getAttribute('aria-label')).toBe('Hra');
    expect(text('mat-button-toggle')).toEqual(['Genshin Impact', 'Honkai: Star Rail']);
    expect(text('mat-label')).toEqual(['Hľadať postavy z hry Honkai: Star Rail']);
    expect(text('.card .small')).toEqual(['Pyro · Polearm', '1 build · Source 1', 'Pyro · Polearm', '3 buildy · Source 1, Source 2', 'Pyro · Polearm', '7 buildov · Source 2']);
    expect(warn).not.toHaveBeenCalled();
  });

  it('says there are none yet, with the link to the Sources page where the sentence has it', async () => {
    const fixture = await open(BuildsPage, 'sk', { buildCharacters: () => of([]) }, { game: 'genshin' });

    expect(text('.empty-state p')).toEqual(['Zatiaľ tu nie sú žiadne buildy. Synchronizáciu spustíš na stránke Zdroje.']);
    expect(text('.empty-state a')).toEqual(['Zdroje']);
    expect(host.querySelector('.empty-state a')?.getAttribute('href')).toBe('/admin/sources');

    await TestBed.inject(TranslationService).setLanguage('en');
    await fixture.whenStable();
    expect(text('.empty-state p')).toEqual(['No builds yet. Start a sync on the Sources page.']);
    expect(text('.empty-state a')).toEqual(['Sources']);
  });

  it('words a failed list from what happened, in the language on the page', async () => {
    const fixture = await open(BuildsPage, 'sk', { buildCharacters: () => throwError(() => new HttpErrorResponse({ status: 0 })) }, { game: 'genshin' });

    expect(text('.panel:not(.card)')).toEqual(['Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.']);

    await TestBed.inject(TranslationService).setLanguage('en');
    await fixture.whenStable();
    expect(text('.panel:not(.card)')).toEqual(['Cannot reach the server. Check your connection and try again.']);
  });

  it('shows one character’s builds: the way back and the count', async () => {
    await open(BuildCharacterPage, 'en', { characterBuilds: () => of(builds(1)) }, { game: 'genshin', key: 'hutao' });

    expect(text('a[mat-button]')).toEqual(['arrow_backAll characters']);
    expect(text('.hero .muted')).toEqual(['Pyro · Polearm · 1 build']);
  });

  it('shows them in Slovak', async () => {
    await open(BuildCharacterPage, 'sk', { characterBuilds: () => of(builds(2)) }, { game: 'genshin', key: 'hutao' });

    expect(text('a[mat-button]')).toEqual(['arrow_backVšetky postavy']);
    expect(text('.hero .muted')).toEqual(['Pyro · Polearm · 2 buildy']);
    // The tabs carry the sources' stored names.
    expect(text('[role="tab"]')).toEqual(['Source 1 · Main DPS', 'Source 2 · Main DPS']);
  });

  it('words a character that is not there from the answer’s code, never with the server’s sentence', async () => {
    const refusal = new HttpErrorResponse({ status: 404, error: { message: 'Not found.', code: 'not_found' } });
    await open(BuildCharacterPage, 'sk', { characterBuilds: () => throwError(() => refusal) }, { game: 'genshin', key: 'nobody' });

    expect(text('.panel')).toEqual(['Toto sa nenašlo.']);
  });
});
