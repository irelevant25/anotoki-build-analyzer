import { HttpErrorResponse } from '@angular/common/http';
import { Type, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { Observable, of, throwError } from 'rxjs';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { TranslationService } from '../../core/i18n/translation';
import { BuildCharacter, CharacterBuilds } from '../../core/models';
import { BuildCharacterPage } from './build-character-page';
import { BuildsPage } from './builds-page';
import { provideKit } from '../../core/kit';

/** The informal Slovak of the two builds pages. */
const SLOVAK: Record<string, string> = {
  'error.offline': 'Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.',
  'error.not_found': 'Toto sa nenašlo.',
  'builds.title': 'Odporúčané buildy',
  'builds.subtitle': 'Odporúčané buildy každej postavy podľa toho, na čo slúžia.',
  'builds.search': 'Hľadať postavy z hry {game}',
  'builds.count.one': '{count} build',
  'builds.count.few': '{count} buildy',
  'builds.count.other': '{count} buildov',
  'builds.empty': 'Zatiaľ tu nie sú žiadne buildy. Editor ich importuje na stránke {link}.',
  'builds.adminPage': 'Builds',
  'builds.allCharacters': 'Všetky postavy',
  'builds.role.main_dps': 'Hlavný DPS',
  'builds.role.sub_dps': 'Vedľajší DPS',
  'builds.role.support': 'Podpora',
  'builds.role.unknown': 'Build',
  'topbar.builds': 'Buildy',
  'buildDetail.substatPriority': 'Substats: priorita',
  'buildDetail.substatsDerived': 'Tento build ich neuvádza; analýza sa riadi tým, čo si žiadajú jeho main stats.',
  'buildDetail.noWeapons': 'Tento build tu nič neuvádza.',
};

function character(key: string, name: string, builds: number, roles: string[]): BuildCharacter {
  return { key, name, element: 'pyro', rarity: 5, weapon_type: 'polearm', icon_url: null, released_at: null, builds, roles, changed_at: null };
}

// A role a newer server adds reads "Build".
const CHARACTERS = [character('hutao', 'Hu Tao', 1, ['main_dps']), character('xiangling', 'Xiangling', 3, ['main_dps', 'support']), character('bennett', 'Bennett', 7, ['support', 'healer'])];

/** Hu Tao's builds: the first her main DPS one, the second a Support one that needs C2. */
function builds(count: number): CharacterBuilds {
  return {
    character: { key: 'hutao', name: 'Hu Tao', element: 'pyro', rarity: 5, weapon_type: 'polearm', icon_url: null },
    builds: Array.from({ length: count }, (_, index) => ({
      id: index + 1,
      role: index === 0 ? 'main_dps' : 'support',
      qualifier: '',
      min_rank: index === 0 ? 0 : 2,
      position: index,
      weapons: [],
      sets: [],
      main_stats: {},
      substats: [],
      talent_priority: null,
      stat_targets: null,
      notes: null,
      game_version: null,
      checked_at: null,
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
        provideKit(),
        // The metadata is not loaded: a build's detail then shows stat keys, which no case here looks at.
        { provide: Api, useValue: api },
        // An editor: the admin panel's Builds page is a link for them.
        { provide: AuthService, useValue: { isStaff: signal(true), user: signal(null), preferences: signal(null) } },
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
    // The plain lead (the kit's own lead with markup, `[pageLead]`, is a sibling of it - empty here).
    expect(text('p.anotoki-page-header__lead')).toEqual(['The recommended builds of each character, by what they are for.']);
    // One game: no switch between games.
    expect(host.querySelector('anotoki-segmented')).toBeNull();
    expect(text('.anotoki-field__label')).toEqual(['Search Genshin Impact characters']);
    // How many builds, and what they are for - a role a newer server adds as "Build".
    expect(text('.card .small')).toEqual(['Pyro · Polearm', '1 build · Main DPS', 'Pyro · Polearm', '3 builds · Main DPS, Support', 'Pyro · Polearm', '7 builds · Support, Build']);
    expect(warn).not.toHaveBeenCalled();
  });

  it('lists them in Slovak: the count in the form the number asks for, the roles in Slovak, the game’s name as it is', async () => {
    await open(BuildsPage, 'sk', { buildCharacters: () => of(CHARACTERS) }, { game: 'genshin' });

    expect(text('h1')).toEqual(['Odporúčané buildy']);
    expect(text('.anotoki-field__label')).toEqual(['Hľadať postavy z hry Genshin Impact']);
    expect(text('.card .small')).toEqual(['Pyro · Polearm', '1 build · Hlavný DPS', 'Pyro · Polearm', '3 buildy · Hlavný DPS, Podpora', 'Pyro · Polearm', '7 buildov · Podpora, Build']);
    expect(warn).not.toHaveBeenCalled();
  });

  it('says there are none yet, with the link to the admin panel’s Builds page where the sentence has it', async () => {
    const fixture = await open(BuildsPage, 'sk', { buildCharacters: () => of([]) }, { game: 'genshin' });

    expect(text('.no-builds p')).toEqual(['Zatiaľ tu nie sú žiadne buildy. Editor ich importuje na stránke Builds.']);
    expect(text('.no-builds a')).toEqual(['Builds']);
    expect(host.querySelector('.no-builds a')?.getAttribute('href')).toBe('/admin/builds');

    await TestBed.inject(TranslationService).setLanguage('en');
    await fixture.whenStable();
    expect(text('.no-builds p')).toEqual(['No builds yet. An editor imports them on the Builds page.']);
    expect(text('.no-builds a')).toEqual(['Builds']);
  });

  it('words a failed list from what happened, in the language on the page', async () => {
    const fixture = await open(BuildsPage, 'sk', { buildCharacters: () => throwError(() => new HttpErrorResponse({ status: 0 })) }, { game: 'genshin' });

    expect(text('.failure .anotoki-alert__text')).toEqual(['Nepodarilo sa spojiť so serverom. Skontroluj pripojenie a skús to znova.']);
    expect(host.querySelector('.failure')?.getAttribute('role')).toBe('alert');

    await TestBed.inject(TranslationService).setLanguage('en');
    await fixture.whenStable();
    expect(text('.failure .anotoki-alert__text')).toEqual(['Cannot reach the server. Check your connection and try again.']);
  });

  it('shows one character’s builds: the way back and the count', async () => {
    await open(BuildCharacterPage, 'en', { characterBuilds: () => of(builds(1)) }, { game: 'genshin', key: 'hutao' });

    expect(text('a.back')).toEqual(['All characters']);
    expect(host.querySelector('a.back')?.getAttribute('href')).toBe('/builds/genshin');
    expect(text('.hero .muted')).toEqual(['Pyro · Polearm · 1 build']);
  });

  it('shows them in Slovak', async () => {
    const fixture = await open(BuildCharacterPage, 'sk', { characterBuilds: () => of(builds(2)) }, { game: 'genshin', key: 'hutao' });

    expect(text('a.back')).toEqual(['Všetky postavy']);
    expect(text('.hero .muted')).toEqual(['Pyro · Polearm · 2 buildy']);
    // The kit's tabs carry the builds' variations - the role in Slovak, the constellation in the game's notation -,
    // the first chosen; its panel is the build in hand.
    expect(text('[role="tab"]')).toEqual(['Hlavný DPS', 'Podpora · C2+']);
    expect(host.querySelector('[role="tablist"]')?.getAttribute('aria-label')).toBe('Buildy');
    const tabs = [...host.querySelectorAll<HTMLButtonElement>('[role="tab"]')];
    expect(tabs.map((tab) => tab.getAttribute('aria-selected'))).toEqual(['true', 'false']);
    expect(host.querySelector('[role="tabpanel"]')?.getAttribute('aria-labelledby')).toBe(tabs[0].id);

    tabs[1].click();
    await fixture.whenStable();
    expect(tabs.map((tab) => tab.getAttribute('aria-selected'))).toEqual(['false', 'true']);
    expect(host.querySelector('[role="tabpanel"]')?.getAttribute('aria-labelledby')).toBe(tabs[1].id);
  });

  it('words a character that is not there from the answer’s code, never with the server’s sentence', async () => {
    const refusal = new HttpErrorResponse({ status: 404, error: { message: 'Not found.', code: 'not_found' } });
    await open(BuildCharacterPage, 'sk', { characterBuilds: () => throwError(() => refusal) }, { game: 'genshin', key: 'nobody' });

    expect(text('.failure .anotoki-alert__text')).toEqual(['Toto sa nenašlo.']);
  });
});
