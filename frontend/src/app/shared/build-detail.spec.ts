import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatTooltip } from '@angular/material/tooltip';
import { of } from 'rxjs';
import { Api } from '../core/api';
import { en } from '../core/i18n/en';
import { provideTestTranslations } from '../core/i18n/testing';
import { TranslationService } from '../core/i18n/translation';
import { MetaStore } from '../core/meta-store';
import { Build, Evaluation, GameKey, GameMeta, Meta } from '../core/models';
import { BuildDetail } from './build-detail';

/** The informal Slovak of a build's detail: the game's terms stay as they are, first or before a colon. */
const SLOVAK: Record<string, string> = {
  'buildDetail.optionAny.one': '(ľubovoľný 1)',
  'buildDetail.optionAny.few': '(ľubovoľné {count})',
  'buildDetail.optionAny.other': '(ľubovoľných {count})',
  'buildDetail.noWeapons': 'Tento zdroj tu nič neuvádza.',
  'buildDetail.equipped': 'Nasadené',
  'buildDetail.owned': 'Máš',
  'buildDetail.setsQualify.one': '{label}: vyhovuje {count} set',
  'buildDetail.setsQualify.few': '{label}: vyhovujú {count} sety',
  'buildDetail.setsQualify.other': '{label}: vyhovuje {count} setov',
  'buildDetail.inUse': 'Používaš',
  'buildDetail.mainStatMatches': 'Main stat sedí s odporúčaním',
  'buildDetail.mainStatYours': 'Máš: {stat}',
  'buildDetail.substatPriority': 'Substats: priorita',
  'buildDetail.substatsBorrowed': 'Tento zdroj ich neuvádza; analýza preberá priority z iného zdroja.',
  'buildDetail.talentPriority': '{talents}: priorita',
  'buildDetail.statTargets': 'Cieľové hodnoty',
  'buildDetail.targetYours': 'máš: {value}',
  'buildDetail.notesFrom': 'Poznámky: {source}',
  'buildDetail.version': 'Verzia {version}',
  'buildDetail.updatedAtSource': 'V zdroji aktualizované {when}',
  'buildDetail.checked': 'Skontrolované {when}',
  'buildDetail.checkedNever': 'Zatiaľ neskontrolované',
};

function gameMeta(key: GameKey): GameMeta {
  const genshin = key === 'genshin';
  return {
    key,
    label: genshin ? 'Genshin Impact' : 'Honkai: Star Rail',
    slots: [
      { key: 'flower', label: 'Flower of Life', variable: false },
      { key: 'sands', label: 'Sands of Eon', variable: true },
      { key: 'goblet', label: 'Goblet of Eonothem', variable: true },
    ],
    weapon_label: genshin ? 'Weapon' : 'Light Cone',
    item_label: genshin ? 'Artifact' : 'Relic',
    rank_label: genshin ? 'Constellation' : 'Eidolon',
    refinement_prefix: genshin ? 'R' : 'S',
    rank_prefix: genshin ? 'C' : 'E',
    elements: [],
    weapon_types: [],
    stats: {
      critRate_: { label: 'CRIT Rate', short: 'CR', percent: true },
      atk_: { label: 'ATK%', short: 'ATK%', percent: true },
      hp_: { label: 'HP%', short: 'HP%', percent: true },
      pyro_dmg_: { label: 'Pyro DMG Bonus', short: 'Pyro', percent: true },
    },
    talents: { skill: 'Elemental Skill', burst: 'Elemental Burst' },
  };
}

const META: Meta = { games: [gameMeta('genshin'), gameMeta('hsr')], version: '1.0.0', auth: { mode: 'none', issuer: null, client_id: null } };

const hoursAgo = (hours: number) => new Date(Date.now() - hours * 3600 * 1000 - 5000).toISOString();

function build(change: Partial<Build> = {}): Build {
  return {
    id: 11,
    source: { key: 'genshin-1', name: 'Source 1' },
    variant: 'Main DPS',
    role: null,
    recommended: true,
    weapons: [
      { key: 'homa', name: 'Staff of Homa', rank: 1, refinement: 1, note: 'Best in slot.', known: true },
      { key: 'bane', name: "Dragon's Bane", rank: 2, refinement: null, note: null, known: true },
      { key: 'deathmatch', name: 'Deathmatch', rank: 3, refinement: null, note: null, known: true },
    ],
    sets: [
      {
        rank: 1,
        kind: 'artifact',
        choose: null,
        pieces: [{ label: 'Crimson Witch of Flames', count: 4, keys: ['cw'], names: ['Crimson Witch of Flames'] }],
        note: 'The usual choice.',
      },
      {
        rank: 2,
        kind: 'artifact',
        choose: 2,
        pieces: [
          { label: 'Alpha', count: 2, keys: ['a'], names: ['Alpha'] },
          { label: 'Beta', count: 2, keys: ['b'], names: ['Beta'] },
          { label: 'ATK +18% set', count: 2, keys: ['c', 'd'], names: ['Gamma', 'Delta'] },
        ],
        note: null,
      },
    ],
    main_stats: {
      sands: [{ stat: 'hp_', priority: 1 }],
      goblet: [{ stat: 'elemental_dmg_', priority: 1 }],
    },
    substats: [
      { stat: 'critRate_', priority: 1 },
      { stat: 'hp_', priority: 2 },
    ],
    talent_priority: [
      { talent: 'skill', priority: 1 },
      { talent: 'burst', priority: 2 },
    ],
    stat_targets: [{ stat: 'critRate_', min: 70, max: null, text: 'At least 70% CRIT Rate.' }],
    notes: 'Keep her HP below half.',
    source_updated_at: hoursAgo(72),
    game_version: '5.2',
    fetched_at: hoursAgo(2),
    changed_at: null,
    ...change,
  };
}

const EVALUATION: Evaluation = {
  build: { id: 11, source: { key: 'genshin-1', name: 'Source 1' }, variant: 'Main DPS', role: null, recommended: true, source_updated_at: null, game_version: null, fetched_at: null },
  score: { overall: 71, grade: 'B', parts: { weapon: 80, sets: 50, main_stats: 66, substats: 70, targets: 40 } },
  weapon: {
    score: 80,
    equipped: { key: 'bane', name: "Dragon's Bane", icon_url: null, rarity: 4, level: 90, refinement: 5 },
    rank: 2,
    owned_better: [{ key: 'homa', name: 'Staff of Homa', rank: 1, refinement: 1, level: 90, equipped_on: 'Zhongli' }],
    total: 3,
  },
  sets: { score: 100, groups: [{ kind: 'artifact', score: 100, matched_rank: 1, equipped: [], best: '4pc Crimson Witch of Flames' }] },
  main_stats: {
    score: 50,
    slots: {
      sands: { current: 'atk_', recommended: ['hp_'], score: 0, ok: false },
      goblet: { current: 'pyro_dmg_', recommended: ['pyro_dmg_'], score: 100, ok: true },
    },
  },
  items: {},
  targets: [{ stat: 'critRate_', min: 70, max: null, text: 'At least 70% CRIT Rate.', current: 62, ok: false }],
  substat_weights: {},
  recommendations: [],
};

describe('BuildDetail: a recommended build in the reader’s words, the game’s and the source’s as they are', () => {
  let fixture: ComponentFixture<BuildDetail>;
  let host: HTMLElement;
  let warn: ReturnType<typeof vi.spyOn>;

  const text = (selector: string) => [...host.querySelectorAll<HTMLElement>(selector)].map((element) => element.textContent?.replace(/\s+/g, ' ').trim() ?? '');
  const langs = (selector: string) => [...host.querySelectorAll<HTMLElement>(selector)].map((element) => element.getAttribute('lang'));
  const tooltips = (selector: string) => fixture.debugElement.queryAll(By.css(selector)).map((element) => element.injector.get(MatTooltip).message);

  async function show(language: 'en' | 'sk', game: GameKey, shown: Build, evaluation: Evaluation | null = null): Promise<void> {
    TestBed.configureTestingModule({
      providers: [
        language === 'en' ? provideTestTranslations(en) : provideTestTranslations(SLOVAK, { language: 'sk', english: en }),
        { provide: Api, useValue: { meta: () => of(META) } },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    await TestBed.inject(MetaStore).load();
    fixture = TestBed.createComponent(BuildDetail);
    fixture.componentRef.setInput('build', shown);
    fixture.componentRef.setInput('game', game);
    fixture.componentRef.setInput('evaluation', evaluation);
    fixture.componentRef.setInput('element', 'pyro');
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  beforeEach(() => {
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
  });

  afterEach(() => vi.restoreAllMocks());

  it('reads in English as it did', async () => {
    await show('en', 'genshin', build(), EVALUATION);

    expect(text('h3')).toEqual(['Weapons', 'Artifact sets', 'Main stats', 'Substat priority', 'Talents priority', 'Stat targets']);
    expect(text('.weapons .chip')).toEqual(['Owned', 'Equipped']);
    expect(text('.w-name .small')).toEqual(['R1']);
    expect(text('.sets .w-text > div:first-child')).toEqual(['4pc Crimson Witch of Flames', '2pc Alpha + 2pc Beta + 2pc ATK +18% set (any 2)']);
    expect(text('.sets .w-text > .small:not(.note)')).toEqual(['ATK +18% set: 2 sets qualify']);
    expect(text('.sets .chip')).toEqual(['In use']);
    expect(text('.stat-row .chip')).toEqual(['HP%', 'Pyro DMG Bonus']);
    expect(tooltips('.check')).toEqual(['Yours: ATK%', 'Your main stat matches']);
    expect(text('.target > span')).toEqual(['CRIT Rate', '70.0%+', 'you: 62.0%', 'At least 70% CRIT Rate.']);
    expect(text('mat-panel-title')).toEqual(['Notes from Source 1']);
    expect(text('footer')).toEqual(['Version 5.2 · Updated at the source 3 days ago · Checked 2 hours ago']);
    expect(warn).not.toHaveBeenCalled();
  });

  it('reads in Slovak around the game’s terms', async () => {
    await show('sk', 'genshin', build(), EVALUATION);

    expect(text('h3')).toEqual(['Weapons', 'Artifact sets', 'Main stats', 'Substats: priorita', 'Talents: priorita', 'Cieľové hodnoty']);
    expect(text('.weapons .chip')).toEqual(['Máš', 'Nasadené']);
    expect(text('.sets .w-text > div:first-child')).toEqual(['4pc Crimson Witch of Flames', '2pc Alpha + 2pc Beta + 2pc ATK +18% set (ľubovoľné 2)']);
    expect(text('.sets .w-text > .small:not(.note)')).toEqual(['ATK +18% set: vyhovujú 2 sety']);
    expect(text('.sets .chip')).toEqual(['Používaš']);
    expect(tooltips('.check')).toEqual(['Máš: ATK%', 'Main stat sedí s odporúčaním']);
    expect(text('.target > span')).toEqual(['CRIT Rate', '70.0%+', 'máš: 62.0%', 'At least 70% CRIT Rate.']);
    expect(text('mat-panel-title')).toEqual(['Poznámky: Source 1']);
    expect(text('footer')).toEqual(['Verzia 5.2 · V zdroji aktualizované pred 3 dňami · Skontrolované pred 2 hodinami']);
    expect(warn).not.toHaveBeenCalled();
  });

  it('shows what the source wrote as it wrote it, marked as English while the page is in another language', async () => {
    await show('sk', 'genshin', build(), EVALUATION);

    expect(text('.note')).toEqual(['Best in slot.', 'The usual choice.']);
    expect(langs('.note')).toEqual(['en', 'en']);
    expect(langs('.t-text')).toEqual(['en']);
    expect(text('.notes-text')).toEqual(['Keep her HP below half.']);
    expect(langs('.notes-text')).toEqual(['en']);
    // Only those: the site's own words are in the page's language.
    expect(host.querySelectorAll('[lang]')).toHaveLength(4);

    await TestBed.inject(TranslationService).setLanguage('en');
    await fixture.whenStable();
    expect(host.querySelectorAll('[lang]')).toHaveLength(0);
    expect(text('footer')).toEqual(['Version 5.2 · Updated at the source 3 days ago · Checked 2 hours ago']);
  });

  it('says "(any N)" and the qualifying sets in the form the number asks for', async () => {
    const pieces = (count: number) => Array.from({ length: count }, (_, index) => ({ label: `Set ${index + 1}`, count: 2, keys: [`s${index}`], names: [`Set ${index + 1}`] }));
    const many = { label: 'Any', count: 2, keys: ['x'], names: ['One', 'Two', 'Three', 'Four', 'Five'] };
    await show(
      'sk',
      'genshin',
      build({
        sets: [
          { rank: 1, kind: 'artifact', choose: 1, pieces: pieces(2), note: null },
          { rank: 2, kind: 'artifact', choose: 3, pieces: pieces(4), note: null },
          { rank: 3, kind: 'artifact', choose: 5, pieces: [...pieces(5), many], note: null },
          // Every piece is needed: nothing to choose.
          { rank: 4, kind: 'artifact', choose: 2, pieces: pieces(2), note: null },
        ],
      }),
    );

    expect(text('.sets .w-text > div:first-child .small')).toEqual(['(ľubovoľný 1)', '(ľubovoľné 3)', '(ľubovoľných 5)']);
    expect(text('.sets .w-text > .small')).toEqual(['Any: vyhovuje 5 setov']);
  });

  it('uses the other game’s terms, and says so when a source lists nothing', async () => {
    await show(
      'sk',
      'hsr',
      build({
        weapons: [],
        sets: [
          { rank: 1, kind: 'relic', choose: null, pieces: [{ label: 'Musketeer of Wild Wheat', count: 4, keys: ['m'], names: ['Musketeer of Wild Wheat'] }], note: null },
          { rank: 1, kind: 'planar', choose: null, pieces: [{ label: 'Space Sealing Station', count: 2, keys: ['s'], names: ['Space Sealing Station'] }], note: null },
        ],
        substats: [],
        stat_targets: null,
        notes: null,
        game_version: null,
        source_updated_at: null,
        fetched_at: null,
      }),
    );

    expect(text('h3')).toEqual(['Light Cones', 'Cavern Relics', 'Planar Ornaments', 'Main stats', 'Substats: priorita', 'Traces: priorita']);
    expect(text('.block > p')).toEqual(['Tento zdroj tu nič neuvádza.', 'Tento zdroj ich neuvádza; analýza preberá priority z iného zdroja.']);
    expect(text('footer')).toEqual(['Zatiaľ neskontrolované']);
  });
});
