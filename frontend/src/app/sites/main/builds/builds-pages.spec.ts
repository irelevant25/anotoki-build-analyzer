import { HttpErrorResponse } from '@angular/common/http';
import { Type, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { Observable, Subject, of, throwError } from 'rxjs';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { Api } from '../../../api/api';
import { AuthService } from '../../../shared/auth';
import { en } from '../../../shared/i18n/en';
import { TranslationService } from '../../../shared/i18n/translation';
import { MetaStore } from '../../../shared/meta-store';
import { BuildCharacter, CharacterBuilds } from '../../../api/models';
import { BuildCharacterPage } from './build-character-page';
import { BuildsPage } from './builds-page';
import { provideKit } from '../../../shared/kit';

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
  'buildDetail.edit': 'Uprav buildy',
  'builds.role.main_dps': 'Hlavný DPS',
  'builds.role.sub_dps': 'Vedľajší DPS',
  'builds.role.support': 'Podpora',
  'builds.role.unknown': 'Build',
  'topbar.builds': 'Buildy',
  'buildDetail.substatPriority': 'Substats: priorita',
  'buildDetail.substatsDerived': 'Tento build ich neuvádza; analýza sa riadi tým, čo si žiadajú jeho main stats.',
  'buildDetail.noWeapons': 'Tento build tu nič neuvádza.',
  'builds.filter.element': 'Element',
  'builds.filter.rarity': 'Vzácnosť',
  'builds.filter.region': 'Región',
  'builds.filter.version': 'Verzia',
  'builds.filter.all': 'Všetky',
  'builds.filter.anyRegion': 'Akýkoľvek región',
  'builds.filter.anyVersion': 'Akákoľvek verzia',
  'builds.filter.stars.one': '{count} hviezdička',
  'builds.filter.stars.few': '{count} hviezdičky',
  'builds.filter.stars.other': '{count} hviezdičiek',
  'builds.filter.reset': 'Zrušiť filtre',
  'builds.filter.showing': 'Zobrazené: {shown} z {total}',
  'builds.filter.none': 'Týmto filtrom nezodpovedá žiadna postava.',
  'builds.filter.noneHint': 'Zmeň filtre alebo ich zruš a uvidíš všetky postavy.',
};

function character(key: string, name: string, builds: number, roles: string[], more: Partial<BuildCharacter> = {}): BuildCharacter {
  return {
    key,
    name,
    element: 'pyro',
    rarity: 5,
    weapon_type: 'polearm',
    icon_url: null,
    released_at: null,
    region: 'Liyue',
    version: '1.3',
    version_name: null,
    builds,
    roles,
    changed_at: null,
    ...more,
  };
}

// A role a newer server adds reads "Build".
const CHARACTERS = [character('hutao', 'Hu Tao', 1, ['main_dps']), character('xiangling', 'Xiangling', 3, ['main_dps', 'support']), character('bennett', 'Bennett', 7, ['support', 'healer'])];

/** Characters to filter: four elements, two weapon types, two rarities, three regions and none, four versions. */
const MANY = [
  character('ineffa', 'Ineffa', 2, ['main_dps'], { element: 'electro', region: 'Nod-Krai', version: '6.0', version_name: 'Luna I' }),
  character('furina', 'Furina', 2, ['support', 'sub_dps'], { element: 'hydro', weapon_type: 'sword', region: 'Fontaine', version: '4.2' }),
  character('xingqiu', 'Xingqiu', 1, ['sub_dps'], { element: 'hydro', weapon_type: 'sword', rarity: 4, version: '1.0' }),
  character('hutao', 'Hu Tao', 1, ['main_dps']),
  character('traveleranemo', 'Traveler (Anemo)', 1, ['support'], { element: 'anemo', weapon_type: 'sword', region: null, version: '1.0' }),
];

/** The game's facts as /api/meta sends them: its elements and weapon types in its own order, its word for a weapon. */
const GENSHIN_META = {
  game: () => ({ elements: ['pyro', 'hydro', 'anemo', 'electro', 'dendro', 'cryo', 'geo'], weapon_types: ['sword', 'claymore', 'polearm', 'bow', 'catalyst'], weapon_label: 'Weapon' }),
};

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
      teams: [],
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

  async function open<T>(
    page: Type<T>,
    language: 'en' | 'sk',
    api: Partial<Record<keyof Api, () => Observable<unknown>>>,
    inputs: Record<string, string>,
    options: { meta?: typeof GENSHIN_META; address?: string; staff?: boolean } = {},
  ): Promise<ComponentFixture<T>> {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        language === 'en' ? provideTestTranslations(en) : provideTestTranslations(SLOVAK, { language: 'sk', english: en }),
        provideKit(),
        // The metadata is not loaded, unless a case gives it: a build's detail then shows stat keys, which no case
        // here looks at, and the filters offer what the characters have by name.
        { provide: Api, useValue: api },
        ...(options.meta ? [{ provide: MetaStore, useValue: options.meta }] : []),
        // An editor: the admin panel's Builds page is a link for them.
        { provide: AuthService, useValue: { isStaff: signal(options.staff ?? true), user: signal(null), preferences: signal(null) } },
      ],
    });
    if (options.address) {
      await TestBed.inject(Router).navigateByUrl(options.address);
    }
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
    // One game: no switch between games - the choices are the filters'.
    expect([...host.querySelectorAll('[role="radiogroup"]')].map((group) => group.getAttribute('aria-label'))).toEqual(['Element', 'Weapon', 'Rarity']);
    expect(text('.anotoki-field__label')).toEqual(['Search Genshin Impact characters', 'Region', 'Version']);
    // How many builds, and what they are for - a role a newer server adds as "Build".
    expect(text('.card .small')).toEqual(['Pyro · Polearm', '1 build · Main DPS', 'Pyro · Polearm', '3 builds · Main DPS, Support', 'Pyro · Polearm', '7 builds · Support, Build']);
    expect(warn).not.toHaveBeenCalled();
  });

  it('lists them in Slovak: the count in the form the number asks for, the roles in Slovak, the game’s name as it is', async () => {
    await open(BuildsPage, 'sk', { buildCharacters: () => of(CHARACTERS) }, { game: 'genshin' });

    expect(text('h1')).toEqual(['Odporúčané buildy']);
    expect(text('.anotoki-field__label')).toEqual(['Hľadať postavy z hry Genshin Impact', 'Región', 'Verzia']);
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

  describe('the filters', () => {
    const names = () => text('.card .name');
    const choices = (group: string) => text(`[role="radiogroup"][aria-label="${group}"] [role="radio"]`);
    const chosen = (group: string) => text(`[role="radiogroup"][aria-label="${group}"] [role="radio"][aria-checked="true"]`);
    const selects = () => [...host.querySelectorAll<HTMLSelectElement>('.pick select')];
    const offered = (select: HTMLSelectElement) => [...select.options].map((option) => option.textContent?.trim());
    const address = () => TestBed.inject(Router).url;

    async function choose(fixture: ComponentFixture<unknown>, group: string, value: string): Promise<void> {
      host.querySelector<HTMLButtonElement>(`[role="radiogroup"][aria-label="${group}"] [data-value="${value}"]`)!.click();
      await fixture.whenStable();
    }

    async function pick(fixture: ComponentFixture<unknown>, select: HTMLSelectElement, value: string): Promise<void> {
      select.value = value;
      select.dispatchEvent(new Event('change'));
      await fixture.whenStable();
    }

    async function type(fixture: ComponentFixture<unknown>, words: string): Promise<void> {
      const field = host.querySelector<HTMLInputElement>('.search input')!;
      field.value = words;
      field.dispatchEvent(new Event('input'));
      await fixture.whenStable();
    }

    it('offer what the characters have - All first, in the game’s order, an element’s colour before its name - and find by each, saying how many show', async () => {
      const fixture = await open(BuildsPage, 'en', { buildCharacters: () => of(MANY) }, { game: 'genshin' }, { meta: GENSHIN_META });

      expect(choices('Element')).toEqual(['All', 'Pyro', 'Hydro', 'Anemo', 'Electro']);
      expect(host.querySelector<HTMLElement>('[data-value="hydro"] .anotoki-segmented__mark')?.style.backgroundColor).toBe('var(--el-mark-hydro, var(--anotoki-muted))');
      expect(choices('Weapon')).toEqual(['All', 'Sword', 'Polearm']);
      expect(choices('Rarity')).toEqual(['All', '5 stars', '4 stars']);
      expect([chosen('Element'), chosen('Weapon'), chosen('Rarity')]).toEqual([['All'], ['All'], ['All']]);
      const [region, version] = selects();
      expect(offered(region)).toEqual(['Any region', 'Fontaine', 'Liyue', 'Nod-Krai']);
      expect(offered(version)).toEqual(['Any version', '6.0 (Luna I)', '4.2', '1.3', '1.0']);
      expect(text('.count')).toEqual(['Showing 5 of 5']);
      expect(host.querySelector('.count')?.getAttribute('role')).toBe('status');
      expect(host.querySelector<HTMLButtonElement>('button.reset')?.disabled).toBe(true);

      await choose(fixture, 'Element', 'hydro');
      expect([names(), text('.count'), address()]).toEqual([['Furina', 'Xingqiu'], ['Showing 2 of 5'], '/?element=hydro']);
      await choose(fixture, 'Rarity', '4');
      expect([names(), chosen('Rarity'), address()]).toEqual([['Xingqiu'], ['4 stars'], '/?element=hydro&rarity=4']);
      expect(host.querySelector<HTMLButtonElement>('button.reset')?.disabled).toBe(false);

      host.querySelector<HTMLButtonElement>('button.reset')!.click();
      await fixture.whenStable();
      expect([names().length, chosen('Element'), address()]).toEqual([5, ['All'], '/']);

      await pick(fixture, region, 'Liyue');
      expect(names()).toEqual(['Xingqiu', 'Hu Tao']);
      await pick(fixture, version, '1.0');
      expect([names(), address()]).toEqual([['Xingqiu'], '/?region=Liyue&version=1.0']);
      await choose(fixture, 'Weapon', 'polearm');
      expect(names()).toEqual([]);

      // Nothing left: the kit's empty state, with a way back to every character.
      expect(text('.no-match .anotoki-empty-state__heading')).toEqual(['No character matches these filters.']);
      expect(text('.no-match .anotoki-empty-state__text')).toEqual(['Change the filters, or reset them to see every character.']);
      expect(text('.count')).toEqual(['Showing 0 of 5']);
      expect(host.querySelector('.no-builds')).toBeNull();
      host.querySelector<HTMLButtonElement>('.no-match button')!.click();
      await fixture.whenStable();
      expect([names().length, address()]).toEqual([5, '/']);

      await type(fixture, 'fur');
      expect([names(), address()]).toEqual([['Furina'], '/?name=fur']);
    });

    it('open as the address says: a link to a filtered list opens it filtered', async () => {
      await open(BuildsPage, 'en', { buildCharacters: () => of(MANY) }, { game: 'genshin' }, { meta: GENSHIN_META, address: '/?weapon=sword&version=1.0' });

      expect(names()).toEqual(['Xingqiu', 'Traveler (Anemo)']);
      expect([chosen('Weapon'), selects()[1].value, text('.count')]).toEqual([['Sword'], '1.0', ['Showing 2 of 5']]);
    });

    it('read in Slovak - the rarity in the form the number asks for -, the game’s names as they are', async () => {
      const fixture = await open(BuildsPage, 'sk', { buildCharacters: () => of(MANY) }, { game: 'genshin' }, { meta: GENSHIN_META });

      expect(text('.anotoki-labelled-group__label')).toEqual(['Element', 'Weapon', 'Vzácnosť']);
      expect(choices('Element')).toEqual(['Všetky', 'Pyro', 'Hydro', 'Anemo', 'Electro']);
      expect(choices('Weapon')).toEqual(['Všetky', 'Sword', 'Polearm']);
      expect(choices('Vzácnosť')).toEqual(['Všetky', '5 hviezdičiek', '4 hviezdičky']);
      expect(offered(selects()[0])).toEqual(['Akýkoľvek región', 'Fontaine', 'Liyue', 'Nod-Krai']);
      expect(offered(selects()[1])[0]).toBe('Akákoľvek verzia');
      expect(text('.count')).toEqual(['Zobrazené: 5 z 5']);
      expect(text('button.reset')).toEqual(['Zrušiť filtre']);

      await choose(fixture, 'Element', 'anemo');
      await type(fixture, 'nikto');
      expect(text('.no-match .anotoki-empty-state__heading')).toEqual(['Týmto filtrom nezodpovedá žiadna postava.']);
      expect(text('.no-match .anotoki-empty-state__text')).toEqual(['Zmeň filtre alebo ich zruš a uvidíš všetky postavy.']);
    });
  });

  it('shows one character’s builds: the way back and the count', async () => {
    await open(BuildCharacterPage, 'en', { characterBuilds: () => of(builds(1)) }, { game: 'genshin', key: 'hutao' });

    expect(text('a.back')).toEqual(['All characters']);
    expect(host.querySelector('a.back')?.getAttribute('href')).toBe('/builds/genshin');
    expect(text('.hero .muted')).toEqual(['Pyro · Polearm · 1 build']);
    // An editor's way to the character's builds editor in the admin panel.
    expect(text('a.edit-builds')).toEqual(['Edit builds']);
    expect(host.querySelector('a.edit-builds')?.getAttribute('href')).toBe('/admin/builds/genshin/hutao');
  });

  it('has no way to the builds editor for anybody but an editor or an admin', async () => {
    await open(BuildCharacterPage, 'en', { characterBuilds: () => of(builds(1)) }, { game: 'genshin', key: 'hutao' }, { staff: false });
    expect(host.querySelector('a.edit-builds')).toBeNull();
  });

  it('shows them in Slovak', async () => {
    const fixture = await open(BuildCharacterPage, 'sk', { characterBuilds: () => of(builds(2)) }, { game: 'genshin', key: 'hutao' });

    expect(text('a.back')).toEqual(['Všetky postavy']);
    expect(text('.hero .muted')).toEqual(['Pyro · Polearm · 2 buildy']);
    expect(text('a.edit-builds')).toEqual(['Uprav buildy']);
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

  it('shows the character its address names: a late answer for the one before never stands for it', async () => {
    // A teammate's link leads from one character to the next on this same page: Hu Tao's answer, still on its way when
    // Kazuha is asked for, arrives after his.
    const answers: Record<string, Subject<CharacterBuilds>> = { hutao: new Subject(), kaedeharakazuha: new Subject() };
    const fixture = await open(BuildCharacterPage, 'en', { characterBuilds: (...asked: unknown[]) => answers[String(asked[1])] }, { game: 'genshin', key: 'hutao' });
    fixture.componentRef.setInput('key', 'kaedeharakazuha');
    await fixture.whenStable();

    const kazuha = builds(1);
    kazuha.character = { ...kazuha.character, key: 'kaedeharakazuha', name: 'Kaedehara Kazuha', element: 'anemo', weapon_type: 'sword' };
    answers['kaedeharakazuha'].next(kazuha);
    answers['hutao'].next(builds(2));
    await fixture.whenStable();

    expect(text('h1')).toEqual(['Kaedehara Kazuha']);
    expect(text('.hero .muted')).toEqual(['Anemo · Sword · 1 build']);
    expect(answers['hutao'].observed).toBe(false);
  });

  it('words a character that is not there from the answer’s code, never with the server’s sentence', async () => {
    const refusal = new HttpErrorResponse({ status: 404, error: { message: 'Not found.', code: 'not_found' } });
    await open(BuildCharacterPage, 'sk', { characterBuilds: () => throwError(() => refusal) }, { game: 'genshin', key: 'nobody' });

    expect(text('.failure .anotoki-alert__text')).toEqual(['Toto sa nenašlo.']);
  });
});
