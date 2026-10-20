import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { of } from 'rxjs';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { Api } from '../core/api';
import { AuthService } from '../core/auth';
import { en } from '../core/i18n/en';
import { TranslationService } from '../core/i18n/translation';
import { MetaStore } from '../core/meta-store';
import { Build, Evaluation, GameKey, GameMeta, Meta } from '../core/models';
import { BuildDetail } from './build-detail';
import { provideKit } from '../core/kit';

/** The informal Slovak of a build's detail: the game's terms stay as they are, first or before a colon. */
const SLOVAK: Record<string, string> = {
  'buildDetail.optionAny.one': '(ľubovoľný 1)',
  'buildDetail.optionAny.few': '(ľubovoľné {count})',
  'buildDetail.optionAny.other': '(ľubovoľných {count})',
  'buildDetail.noWeapons': 'Tento build tu nič neuvádza.',
  'buildDetail.equipped': 'Nasadené',
  'buildDetail.owned': 'Máš',
  'buildDetail.setsQualify.one': '{label}: vyhovuje {count} set',
  'buildDetail.setsQualify.few': '{label}: vyhovujú {count} sety',
  'buildDetail.setsQualify.other': '{label}: vyhovuje {count} setov',
  'buildDetail.inUse': 'Používaš',
  'buildDetail.mainStatMatches': 'Main stat sedí s odporúčaním',
  'buildDetail.mainStatYours': 'Máš: {stat}',
  'buildDetail.substatPriority': 'Substats: priorita',
  'buildDetail.substatsDerived': 'Tento build ich neuvádza; analýza sa riadi tým, čo si žiadajú jeho main stats.',
  'buildDetail.talentPriority': '{talents}: priorita',
  'buildDetail.statTargets': 'Cieľové hodnoty',
  'buildDetail.targetYours': 'máš: {value}',
  'buildDetail.notes': 'Poznámky',
  'buildDetail.version': 'Verzia {version}',
  'buildDetail.checked': 'Skontrolované {when}',
};

function gameMeta(key: GameKey): GameMeta {
  return {
    key,
    label: 'Genshin Impact',
    slots: [
      { key: 'flower', label: 'Flower of Life', variable: false },
      { key: 'sands', label: 'Sands of Eon', variable: true },
      { key: 'goblet', label: 'Goblet of Eonothem', variable: true },
    ],
    weapon_label: 'Weapon',
    item_label: 'Artifact',
    rank_label: 'Constellation',
    refinement_prefix: 'R',
    rank_prefix: 'C',
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

const META: Meta = { games: [gameMeta('genshin')], version: '1.0.0', auth: { issuer: '', client_id: '' } };

const hoursAgo = (hours: number) => new Date(Date.now() - hours * 3600 * 1000 - 5000).toISOString();

function build(change: Partial<Build> = {}): Build {
  return {
    id: 11,
    role: 'main_dps',
    qualifier: '',
    min_rank: 0,
    position: 0,
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
    stat_targets: [{ stat: 'critRate_', min: 70, max: null, text: 'At least 70% CRIT Rate.', from_rank: 0 }],
    notes: 'Keep her HP below half.',
    game_version: '5.2',
    checked_at: hoursAgo(2),
    changed_at: null,
    ...change,
  };
}

const EVALUATION: Evaluation = {
  build: { id: 11, role: 'main_dps', qualifier: '', min_rank: 0, applies: true, position: 0, game_version: null, checked_at: null },
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

describe('BuildDetail: a recommended build in the reader’s words, the game’s and the build’s own as they are', () => {
  let fixture: ComponentFixture<BuildDetail>;
  let host: HTMLElement;
  let warn: ReturnType<typeof vi.spyOn>;

  const text = (selector: string) => [...host.querySelectorAll<HTMLElement>(selector)].map((element) => element.textContent?.replace(/\s+/g, ' ').trim() ?? '');
  const langs = (selector: string) => [...host.querySelectorAll<HTMLElement>(selector)].map((element) => element.getAttribute('lang'));
  /** The words of the kit's tooltip on each element (and the icon's own name: the same words). */
  const tooltips = (selector: string) => fixture.debugElement.queryAll(By.css(selector)).map((element) => element.injector.get(TooltipDirective).text());
  const labels = (selector: string) => [...host.querySelectorAll<HTMLElement>(selector)].map((element) => element.getAttribute('aria-label'));

  async function show(language: 'en' | 'sk', game: GameKey, shown: Build, evaluation: Evaluation | null = null): Promise<void> {
    TestBed.configureTestingModule({
      providers: [
        language === 'en' ? provideTestTranslations(en) : provideTestTranslations(SLOVAK, { language: 'sk', english: en }),
        provideKit(),
        { provide: Api, useValue: { meta: () => of(META) } },
        { provide: AuthService, useValue: { user: () => null, signedIn: () => false, preferences: () => null } },
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
    expect(text('.weapons anotoki-badge')).toEqual(['Owned', 'Equipped']);
    expect(text('.w-name .small')).toEqual(['R1']);
    expect(text('.sets .w-text > div:first-child')).toEqual(['4pc Crimson Witch of Flames', '2pc Alpha + 2pc Beta + 2pc ATK +18% set (any 2)']);
    expect(text('.sets .w-text > .small:not(.note)')).toEqual(['ATK +18% set: 2 sets qualify']);
    expect(text('.sets anotoki-badge')).toEqual(['In use']);
    expect(text('.stat-row anotoki-badge')).toEqual(['HP%', 'Pyro DMG Bonus']);
    expect(tooltips('.check')).toEqual(['Yours: ATK%', 'Your main stat matches']);
    expect(labels('.check svg')).toEqual(['Yours: ATK%', 'Your main stat matches']);
    expect(text('.target > span')).toEqual(['CRIT Rate', '70.0%+', 'you: 62.0%', 'At least 70% CRIT Rate.']);
    expect(text('.notes .anotoki-disclosure__button')).toEqual(['Notes']);
    expect(text('footer')).toEqual(['Version 5.2 · Checked 2 hours ago']);
    expect(warn).not.toHaveBeenCalled();
  });

  it('reads in Slovak around the game’s terms', async () => {
    await show('sk', 'genshin', build(), EVALUATION);

    expect(text('h3')).toEqual(['Weapons', 'Artifact sets', 'Main stats', 'Substats: priorita', 'Talents: priorita', 'Cieľové hodnoty']);
    expect(text('.weapons anotoki-badge')).toEqual(['Máš', 'Nasadené']);
    expect(text('.sets .w-text > div:first-child')).toEqual(['4pc Crimson Witch of Flames', '2pc Alpha + 2pc Beta + 2pc ATK +18% set (ľubovoľné 2)']);
    expect(text('.sets .w-text > .small:not(.note)')).toEqual(['ATK +18% set: vyhovujú 2 sety']);
    expect(text('.sets anotoki-badge')).toEqual(['Používaš']);
    expect(tooltips('.check')).toEqual(['Máš: ATK%', 'Main stat sedí s odporúčaním']);
    expect(text('.target > span')).toEqual(['CRIT Rate', '70.0%+', 'máš: 62.0%', 'At least 70% CRIT Rate.']);
    expect(text('.notes .anotoki-disclosure__button')).toEqual(['Poznámky']);
    expect(text('footer')).toEqual(['Verzia 5.2 · Skontrolované pred 2 hodinami']);
    expect(warn).not.toHaveBeenCalled();
  });

  it('shows what the build’s text says as it says it, marked as English while the page is in another language', async () => {
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
    expect(text('footer')).toEqual(['Version 5.2 · Checked 2 hours ago']);
  });

  it('marks a stat target from a constellation with it, in the game’s notation; the builds’ list shows a stat’s every target', async () => {
    await show(
      'sk',
      'genshin',
      build({
        stat_targets: [
          { stat: 'critRate_', min: 70, max: null, text: '', from_rank: 0 },
          { stat: 'critRate_', min: 60, max: null, text: '', from_rank: 2 },
        ],
      }),
    );

    expect(text('.t-stat')).toEqual(['CRIT Rate', 'CRIT Rate C2+']);
    expect(text('.t-rank')).toEqual(['C2+']);
    expect(text('.t-goal')).toEqual(['70.0%+', '60.0%+']);
  });

  it('writes a target with a top as its range, without the "+" of one that has none', async () => {
    await show(
      'en',
      'genshin',
      build({
        stat_targets: [
          { stat: 'enerRech_', min: 260, max: 280, text: '', from_rank: 0 },
          { stat: 'enerRech_', min: 200, max: 220, text: '', from_rank: 1 },
          { stat: 'critRate_', min: 70, max: null, text: '', from_rank: 0 },
        ],
      }),
    );

    expect(text('.t-goal')).toEqual(['260.0% – 280.0%', '200.0% – 220.0%', '70.0%+']);
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

  it('uses the game’s terms, and says so when a build lists nothing', async () => {
    await show(
      'sk',
      'genshin',
      build({
        weapons: [],
        sets: [{ rank: 1, kind: 'artifact', choose: null, pieces: [{ label: 'Crimson Witch of Flames', count: 4, keys: ['c'], names: ['Crimson Witch of Flames'] }], note: null }],
        substats: [],
        stat_targets: null,
        notes: null,
        game_version: null,
        checked_at: null,
      }),
    );

    expect(text('h3')).toEqual(['Weapons', 'Artifact sets', 'Main stats', 'Substats: priorita', 'Talents: priorita']);
    expect(text('.block > p')).toEqual(['Tento build tu nič neuvádza.', 'Tento build ich neuvádza; analýza sa riadi tým, čo si žiadajú jeho main stats.']);
    expect(text('footer')).toEqual(['']);
  });
});
