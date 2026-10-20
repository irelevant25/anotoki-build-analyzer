import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { Observable, of, throwError } from 'rxjs';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { Api } from '../../core/api';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { TranslationService } from '../../core/i18n/translation';
import { MetaStore } from '../../core/meta-store';
import { AnalyzedItem, Build, CharacterAnalysis, Evaluation, GameKey, GameMeta, Meta, Recommendation, SetPlan, Suggestion } from '../../core/models';
import { CharacterPage } from './character-page';
import { provideKit } from '../../core/kit';

/** The informal Slovak of the strings these cases look at: every game term stays as it is, first or after a colon. */
const SLOVAK: Record<string, string> = {
  'error.editor_only': 'Toto môže urobiť len editor alebo správca.',
  'builds.sourcesPage': 'Zdroje',
  'buildDetail.optionAny.one': '(ľubovoľný 1)',
  'buildDetail.optionAny.few': '(ľubovoľné {count})',
  'buildDetail.optionAny.other': '(ľubovoľných {count})',
  'recommendation.main_stat_wrong': '{slot_label}: main stat je {stat}, tento build chce {wanted}.',
  'recommendation.sets_missing': '{set_label}: odporúča sa {best}. Teraz máš: {current}.',
  'recommendation.target_below': '{stat}: máš {current}, build mieri na {min} a viac.',
  'character.talentBonus': '{ranks}: +{bonus}',
  'character.noBuilds': '{name}: zatiaľ žiadne buildy. Pribudnú po ďalšej synchronizácii buildov (správcovia ju spúšťajú na stránke {link}).',
  'character.yourWeapon': '{weapon_label}:',
  'character.weaponOption': 'možnosť č. {rank} / {total}',
  'character.category.sets': 'Sety',
  'character.category.targets': 'Cieľová hodnota',
  'character.category.unknown': 'Iné',
  'character.yourItems': '{items} na postave',
  'character.legend': 'Substats: čo je {color}, je pre tento build najdôležitejšie; čo je sivé, je preň zbytočné.',
  'character.legendColor': 'farebné',
  'character.switchTo': 'Prejdi na set: {set}',
  'character.planOwned.one': 'Máš {owned} / {count} kus',
  'character.planOwned.few': 'Máš {owned} / {count} kusy',
  'character.planOwned.other': 'Máš {owned} / {count} kusov',
  'character.currentlyOn': 'Teraz na postave: {character}',
  'character.notEquipped': 'Nie je na žiadnej postave',
  'character.planMissing.one': 'Na celý bonus ti chýba ešte {count} kus tohto setu.',
  'character.planMissing.few': 'Na celý bonus ti chýbajú ešte {count} kusy tohto setu.',
  'character.planMissing.other': 'Na celý bonus ti chýba ešte {count} kusov tohto setu.',
  'character.reason.betterSubstats.one': 'Substats: +{count} bod kvality',
  'character.reason.betterSubstats.few': 'Substats: +{count} body kvality',
  'character.reason.betterSubstats.other': 'Substats: +{count} bodov kvality',
  'character.reason.completesSet': 'Doplní set bonus podľa odporúčania',
  'character.reason.unknown': 'Lepšie pre tento build',
  'character.currentPiece': 'Terajší kus: {percent} %',
  'character.consensus.builds.one': '{count} build, zdroje: {sources}',
  'character.consensus.builds.few': '{count} buildy, zdroje: {sources}',
  'character.consensus.builds.other': '{count} buildov, zdroje: {sources}',
  'character.consensus.sets': 'Sety',
  'character.consensus.bestWeapon': '{weapon_label} – najlepšie voľby',
  'character.stats.fromShowcase': 'Celkové hodnoty z hry, zdroj: Character Showcase, {when}.',
  'character.stats.itemsOnly': 'Zdroj: len {items} (celkové hodnoty z hry uvidíš po importe zadaním UID):',
};

/** Every string wrapped in ⟦ ⟧: what is left outside the brackets was not worded through a key. */
const MARKED: Record<string, string> = Object.fromEntries(Object.entries(en).map(([key, value]) => [key, `⟦${value}⟧`]));

function gameMeta(key: GameKey): GameMeta {
  return {
    key,
    label: 'Genshin Impact',
    slots: [
      { key: 'flower', label: 'Flower of Life', variable: false },
      { key: 'sands', label: 'Sands of Eon', variable: true },
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
      critDMG_: { label: 'CRIT DMG', short: 'CD', percent: true },
      atk_: { label: 'ATK%', short: 'ATK%', percent: true },
      hp_: { label: 'HP%', short: 'HP%', percent: true },
      hp: { label: 'HP', short: 'HP', percent: false },
    },
    talents: { skill: 'Elemental Skill' },
  };
}

const META: Meta = { games: [gameMeta('genshin')], version: '1.0.0', auth: { issuer: '', client_id: '' } };

const RECOMMENDATIONS: Recommendation[] = [
  {
    severity: 'high',
    category: 'main_stats',
    kind: 'main_stat_wrong',
    params: { slot_label: 'Sands of Eon', stat: 'ATK%', wanted: 'HP%' },
    message: 'Sands of Eon has ATK% as main stat; this build wants HP%.',
    slot: 'sands',
  },
  {
    severity: 'medium',
    category: 'sets',
    kind: 'sets_missing',
    params: { set_label: 'Artifact set', best: '2pc Alpha + 2pc Beta + 2pc Gamma', best_choose: 2, current: '4pc Delta' },
    message: 'Use the recommended artifact set: 2pc Alpha + 2pc Beta + 2pc Gamma (any 2). Currently: 4pc Delta.',
  },
  {
    severity: 'low',
    category: 'targets',
    kind: 'target_below',
    params: { stat: 'CRIT Rate', stat_key: 'critRate_', current: 62, min: 70 },
    message: 'CRIT Rate is 62.0%; the build aims for 70.0%+.',
  },
  {
    severity: 'low',
    category: 'pets' as Recommendation['category'],
    kind: 'pet_missing',
    params: { pet: 'Guoba' },
    message: 'A sentence only a newer server knows.',
  },
];

function item(slot: string): AnalyzedItem {
  return {
    id: 1,
    slot,
    set: { key: 'delta', name: 'Delta', icon_url: null },
    rarity: 5,
    level: 16,
    max_level: 20,
    main_stat: 'atk_',
    main_value: 38.7,
    main_ok: false,
    score: 20,
    ideal: 50,
    percent: 41,
    grade: 'C',
    useful_rolls: 3,
    substats: [{ key: 'critRate_', value: 7.8, rolls: 2, weight: 1, roll_value: 0.8 }],
  };
}

function build(id: number, source: string): Build {
  return {
    id,
    source: { key: `genshin-${id}`, name: source },
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
  };
}

function evaluation(id: number, source: string, recommendations: Recommendation[]): Evaluation {
  return {
    build: { id, source: { key: `genshin-${id}`, name: source }, variant: 'Main DPS', role: null, recommended: true, source_updated_at: null, game_version: null, fetched_at: null },
    score: { overall: 71, grade: 'B', parts: { weapon: 80, sets: 50, main_stats: 66, substats: 70, targets: null } },
    weapon: {
      score: 80,
      equipped: { key: 'dragons_bane', name: "Dragon's Bane", icon_url: null, rarity: 4, level: 90, refinement: 5 },
      rank: 2,
      owned_better: [],
      total: 5,
    },
    sets: null,
    main_stats: null,
    items: { sands: item('sands') },
    targets: [{ stat: 'critRate_', min: 70, max: null, text: 'At least 70% CRIT Rate.', current: 62, ok: false }],
    substat_weights: {},
    recommendations,
  };
}

function suggestion(slot: string, reason: string, gain: number, equippedOn: string | null): Suggestion {
  const { id, set, rarity, level, main_stat, main_value, score, ideal, percent, grade, useful_rolls, substats } = item(slot);
  return {
    slot,
    reason: reason as Suggestion['reason'],
    gain,
    current_percent: 41,
    item: { id, set, rarity, level, main_stat, main_value, score, ideal, percent, grade, useful_rolls, substats, equipped_on: equippedOn },
  };
}

function plan(kind: SetPlan['kind'], needed: number, owned: number): SetPlan {
  return { kind, set: '4pc Alpha', needed, owned, pieces: [] };
}

function analysis(game: GameKey = 'genshin', change: Partial<CharacterAnalysis> = {}): CharacterAnalysis {
  return {
    account: {
      id: 3,
      game,
      uid: '700000001',
      nickname: 'Mona',
      level: 60,
      world_level: 8,
      signature: null,
      has_full_inventory: true,
      last_import_at: null,
      counts: { characters: 1, weapons: 1, items: 1 },
      meta: null,
    },
    character: {
      id: 9,
      key: 'hutao',
      name: 'Hu Tao',
      element: 'pyro',
      rarity: 5,
      weapon_type: 'polearm',
      icon_url: null,
      level: 90,
      rank: 1,
      weapon: null,
      sets: [],
      items_count: 1,
      talents: { skill: 12, bonus: { skill: 3 } },
      final_stats: { critRate_: 62 },
      final_stats_source: 'showcase',
      synced_at: new Date(Date.now() - 3 * 3600 * 1000 - 5000).toISOString(),
    },
    equipment: { totals: { critRate_: 7.8 }, crit_value: 182.4, slots: ['sands'] },
    evaluations: [evaluation(11, 'Source 1', RECOMMENDATIONS), evaluation(12, 'Source 2', [])],
    best_build_id: 11,
    focus_build_id: null,
    consensus: {
      builds: 2,
      sources: ['Source 1', 'Source 2'],
      main_stats: { sands: [{ name: 'hp_', count: 2 }] },
      sets: {
        artifact: [
          { name: '2pc Alpha + 2pc Beta + 2pc Gamma (any 2)', pieces: '2pc Alpha + 2pc Beta + 2pc Gamma', choose: 2, count: 1 },
          { name: '4pc Delta', pieces: '4pc Delta', choose: null, count: 1 },
        ],
        mystery: [{ name: '2pc Omega', pieces: '2pc Omega', choose: null, count: 2 }],
      },
      weapons: [{ name: 'Staff of Homa', count: 2 }],
    },
    suggestions: [suggestion('flower', 'better_substats', 3, 'Xiangling'), suggestion('sands', 'completes_set', 0, null), suggestion('goblet', 'sparkles', 0, null)],
    set_plans: [plan('artifact', 4, 2)],
    has_full_inventory: true,
    builds: [build(11, 'Source 1'), build(12, 'Source 2')],
    ...change,
  };
}

describe('CharacterPage: the site’s words are keys, the game’s words stay', () => {
  let fixture: ComponentFixture<CharacterPage>;
  let host: HTMLElement;
  let warn: ReturnType<typeof vi.spyOn>;

  const text = (selector: string) => [...host.querySelectorAll<HTMLElement>(selector)].map((element) => element.textContent?.replace(/\s+/g, ' ').trim() ?? '');
  /** The words of the kit's tooltip on an element. */
  const tooltip = (selector: string) => fixture.debugElement.query(By.css(selector)).injector.get(TooltipDirective).text();
  /** The names of the kit's icons. */
  const icons = (selector: string) => fixture.debugElement.queryAll(By.css(selector)).map((icon) => (icon.componentInstance as IconComponent).name());

  /** Who looks: somebody signed in (an editor: the Sources page is a link), or nobody. */
  async function open(language: 'en' | 'sk' | 'marked', answer: Observable<CharacterAnalysis> = of(analysis()), who: 'editor' | 'member' | 'nobody' = 'editor'): Promise<void> {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        language === 'en' ? provideTestTranslations(en) : provideTestTranslations(language === 'sk' ? SLOVAK : MARKED, { language: 'sk', english: en }),
        provideKit(),
        { provide: Api, useValue: { meta: () => of(META), character: () => answer } },
        {
          provide: AuthService,
          useValue: { signedIn: signal(who !== 'nobody'), isStaff: signal(who === 'editor'), available: signal(true), failure: signal(null), user: signal(null), preferences: signal(null) },
        },
      ],
    });
    await TestBed.inject(MetaStore).load();
    fixture = TestBed.createComponent(CharacterPage);
    fixture.componentRef.setInput('id', '3');
    fixture.componentRef.setInput('key', 'hutao');
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  beforeEach(() => {
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
  });

  afterEach(() => vi.restoreAllMocks());

  it('reads in English as it did, with every game term as the game writes it', async () => {
    await open('en');

    expect(text('.hero anotoki-badge')).toEqual(['Pyro', 'Polearm', 'Lv. 90', 'C1', 'Elemental Skill 12+3']);
    expect(tooltip('.hero anotoki-badge:last-child')).toBe('+3 from Constellations');
    expect(text('.hero-grade .small')).toEqual(['vs. Source 1 · Main DPS']);
    // The kit's tabs: a build each, its score the tab's badge - seen as the bare number, heard in words after the
    // name -; the build closest to the setup marked by an icon and a filled badge, and heard so.
    expect(text('[role="tab"] .anotoki-tabs__label')).toEqual(['Source 1 · Main DPS', 'Source 2 · Main DPS']);
    expect(text('[role="tab"] .anotoki-tabs__badge')).toEqual(['71', '71']);
    expect([...host.querySelectorAll('[role="tab"] .anotoki-tabs__badge')].map((badge) => [badge.getAttribute('aria-hidden'), badge.getAttribute('data-tone')])).toEqual([
      ['true', 'primary'],
      ['true', 'neutral'],
    ]);
    expect(text('[role="tab"] .anotoki-visually-hidden')).toEqual([', score 71, Closest match to your current setup', ', score 71']);
    expect([...host.querySelectorAll('[role="tab"]')].map((tab) => tab.querySelector('anotoki-icon') !== null)).toEqual([true, false]);
    expect(text('.build-card [cardActions] anotoki-badge')).toEqual(['Closest match to your current setup']);
    expect(text('.top-grid .anotoki-card__title')).toEqual(['Score breakdown', 'What to improve']);
    expect(text('.bottom-grid .anotoki-card__title')).toEqual(['What the sources agree on', 'Stats']);
    expect(text('app-score-bar .anotoki-progress-bar__label')).toEqual(['Weapon', 'Set bonuses', 'Main stats', 'Substats', 'Stat targets']);
    expect(text('app-score-bar .anotoki-progress-bar__value')).toEqual(['80', '50', '66', '70', 'n/a']);
    // The kit's progress bar: a part without a score is heard as the page says it, never as a 0.
    expect([...host.querySelectorAll('app-score-bar [role="progressbar"]')].map((bar) => bar.getAttribute('aria-valuetext'))).toEqual(['80', '50', '66', '70', 'n/a']);
    expect(text('.targets-note')).toEqual(['Stat targets need in-game totals; import this character by UID (Character Showcase) to check them.']);
    expect(text('.weapon-line > *')).toEqual(['Your Weapon:', "Dragon's Bane R5", 'Lv. 90 · option #2 of 5']);
    expect(text('.section-head h2')).toEqual(['Your Artifacts', 'Better pieces you already own']);
    expect(text('.build-card > .anotoki-card__header .anotoki-card__title')).toEqual(['Source 1 · Main DPS']);
    expect(text('.crit-value')).toEqual(['Crit value 182.4']);
    expect(tooltip('.crit-value')).toBe('2 × CRIT Rate + CRIT DMG from Artifacts');
    expect(text('.legend')).toEqual(['Substats in color matter most for this build; grayed ones are useless for it.']);
    expect(text('.legend .stat-good')).toEqual(['color']);
    expect(text('.empty-slot')).toEqual(['Flower of LifeNothing equipped']);
    expect(text('.stat-table')[0]).toBe('CRIT Rate62.0%');
    expect(text('.bottom-grid > anotoki-card:last-child .anotoki-card__body > p')).toEqual(['In-game totals from your Character Showcase, 3 hours ago.']);
    // A key the page has no string for would stand on the page as itself, and warn.
    expect(warn).not.toHaveBeenCalled();
  });

  it('words every recommendation from its kind - and shows the stored English of a kind it does not know', async () => {
    await open('en');

    expect(text('.rec-cat')).toEqual(['Main stat · Sands of Eon', 'Sets', 'Stat target', 'Other']);
    expect(text('.rec-cat + div')).toEqual([
      'Sands of Eon has ATK% as main stat; this build wants HP%.',
      'Artifact set: use the recommended 2pc Alpha + 2pc Beta + 2pc Gamma (any 2). Currently: 4pc Delta.',
      'CRIT Rate is 62.0%; the build aims for 70.0%+.',
      'A sentence only a newer server knows.',
    ]);
    // English on an English page needs no mark.
    expect(host.querySelectorAll('.rec [lang]')).toHaveLength(0);
    expect(icons('.rec anotoki-icon')).toEqual(['alert', 'alert', 'info', 'info']);
  });

  it('words them in Slovak around the game’s terms, and marks the stored English as English', async () => {
    await open('sk');

    expect(text('.rec-cat')).toEqual(['Main stat · Sands of Eon', 'Sety', 'Cieľová hodnota', 'Iné']);
    expect(text('.rec-cat + div')).toEqual([
      'Sands of Eon: main stat je ATK%, tento build chce HP%.',
      'Artifact set: odporúča sa 2pc Alpha + 2pc Beta + 2pc Gamma (ľubovoľné 2). Teraz máš: 4pc Delta.',
      'CRIT Rate: máš 62.0%, build mieri na 70.0% a viac.',
      'A sentence only a newer server knows.',
    ]);
    expect([...host.querySelectorAll('.rec-cat + div')].map((element) => element.getAttribute('lang'))).toEqual([null, null, null, 'en']);
    // The legend is cut where its coloured word stands.
    expect(text('.legend')).toEqual(['Substats: čo je farebné, je pre tento build najdôležitejšie; čo je sivé, je preň zbytočné.']);
    expect(text('.legend .stat-good')).toEqual(['farebné']);
  });

  it('says the counted sentences in the form the number asks for', async () => {
    await open('sk');

    expect(text('.plan .anotoki-card__title')).toEqual(['Prejdi na set: 4pc Alpha']);
    expect(text('.plan [cardActions] anotoki-badge')).toEqual(['Máš 2 / 4 kusy']);
    expect(text('.plan .plan-missing')).toEqual(['Na celý bonus ti chýbajú ešte 2 kusy tohto setu.']);
    expect(text('.suggestion-head anotoki-badge')).toEqual(['Substats: +3 body kvality', 'Doplní set bonus podľa odporúčania', 'Lepšie pre tento build']);
    expect(text('.suggestion > .small')).toEqual(['Terajší kus: 41 % · Teraz na postave: Xiangling', 'Terajší kus: 41 % · Nie je na žiadnej postave', 'Terajší kus: 41 % · Nie je na žiadnej postave']);
    expect(text('.bottom-grid .anotoki-card__body > p')[0]).toBe('2 buildy, zdroje: Source 1, Source 2');
  });

  it('says them in English too, one piece and several', async () => {
    await open('en');

    expect(text('.plan [cardActions] anotoki-badge')).toEqual(['You own 2 of 4 pieces']);
    expect(text('.plan .plan-missing')).toEqual(['Farm 2 more pieces of this set to complete the bonus.']);
    expect(text('.suggestion-head anotoki-badge')).toEqual(['+3 points of substat quality', 'Completes the recommended set bonus', 'Better for this build']);
    expect(text('.suggestion > .small')).toEqual(['Current piece: 41% · Currently on Xiangling', 'Current piece: 41% · Not equipped by anyone', 'Current piece: 41% · Not equipped by anyone']);
    expect(text('.bottom-grid .anotoki-card__body > p')[0]).toBe('2 builds from Source 1, Source 2');
  });

  it('shows what the sources agree on: each set as its pieces, with the any-phrase where only some are needed', async () => {
    await open('sk');

    expect(text('.consensus-row > .muted')).toEqual(['Sands of Eon', 'Artifact sets', 'Sety', 'Weapon – najlepšie voľby']);
    expect(text('.consensus-row anotoki-badge')).toEqual(['HP% 2×', '2pc Alpha + 2pc Beta + 2pc Gamma (ľubovoľné 2) 1×', '4pc Delta 1×', '2pc Omega 2×', 'Staff of Homa 2×']);
  });

  it('cuts the sentence with a link inside at its placeholder', async () => {
    await open('sk', of(analysis('genshin', { evaluations: [] })));

    expect(text('.no-builds p')).toEqual(['Hu Tao: zatiaľ žiadne buildy. Pribudnú po ďalšej synchronizácii buildov (správcovia ju spúšťajú na stránke Zdroje).']);
    const link = host.querySelector<HTMLAnchorElement>('.no-builds a')!;
    expect(link.textContent).toBe('Zdroje');
    expect(link.getAttribute('href')).toBe('/admin/sources');
  });

  it('names the Sources page without a link for somebody who cannot open it', async () => {
    await open('sk', of(analysis('genshin', { evaluations: [] })), 'member');

    expect(text('.no-builds p')).toEqual(['Hu Tao: zatiaľ žiadne buildy. Pribudnú po ďalšej synchronizácii buildov (správcovia ju spúšťajú na stránke Zdroje).']);
    expect(host.querySelector('.no-builds a')).toBeNull();
  });

  it('shows nobody the ways in, not an error, when the account is not theirs to open (401)', async () => {
    await open(
      'en',
      throwError(() => new HttpErrorResponse({ status: 401, error: { message: 'Sign in.', code: 'sign_in_required' } })),
      'nobody',
    );

    expect(host.querySelector('app-visitor-hero')).not.toBeNull();
    expect(host.querySelector('anotoki-alert.failure')).toBeNull();
    expect(host.textContent).toContain(en['home.title']);
  });

  it('draws no link and no coloured word where a language’s sentence leaves the placeholder out', async () => {
    const kept = { legend: SLOVAK['character.legend'], noBuilds: SLOVAK['character.noBuilds'] };
    SLOVAK['character.legend'] = 'Substats: čo je sivé, je pre tento build zbytočné.';
    SLOVAK['character.noBuilds'] = '{name}: zatiaľ žiadne buildy.';
    try {
      await open('sk');
      expect(text('.legend')).toEqual(['Substats: čo je sivé, je pre tento build zbytočné.']);
      expect(host.querySelector('.legend .stat-good')).toBeNull();

      TestBed.resetTestingModule();
      await open('sk', of(analysis('genshin', { evaluations: [] })));
      expect(text('.no-builds p')).toEqual(['Hu Tao: zatiaľ žiadne buildy.']);
      expect(host.querySelector('.no-builds a')).toBeNull();
    } finally {
      SLOVAK['character.legend'] = kept.legend;
      SLOVAK['character.noBuilds'] = kept.noBuilds;
    }
  });

  it.each([
    { needed: 1, owned: 0, sk: ['Máš 0 / 1 kus', 'Na celý bonus ti chýba ešte 1 kus tohto setu.'], en: ['You own 0 of 1 piece', 'Farm 1 more piece of this set to complete the bonus.'] },
    { needed: 5, owned: 0, sk: ['Máš 0 / 5 kusov', 'Na celý bonus ti chýba ešte 5 kusov tohto setu.'], en: ['You own 0 of 5 pieces', 'Farm 5 more pieces of this set to complete the bonus.'] },
  ])('says a set plan of $owned / $needed in the form the number asks for, in Slovak and in English', async ({ needed, owned, sk, en: english }) => {
    await open('sk', of(analysis('genshin', { set_plans: [plan('artifact', needed, owned)] })));
    expect([...text('.plan [cardActions] anotoki-badge'), ...text('.plan .plan-missing')]).toEqual(sk);

    expect(await TestBed.inject(TranslationService).setLanguage('en', { remember: false })).toBe(true);
    await fixture.whenStable();
    expect([...text('.plan [cardActions] anotoki-badge'), ...text('.plan .plan-missing')]).toEqual(english);
  });

  it('leaves nothing of its own outside a key', async () => {
    await open('marked');

    // Take the worded strings away, innermost first (a sentence can hold a worded phrase): what is left
    // is the game's words and the account's data.
    let rest = host.textContent ?? '';
    for (let before = ''; before !== rest; ) {
      before = rest;
      rest = rest.replace(/⟦[^⟦⟧]*⟧/g, ' ');
    }
    expect(rest).not.toMatch(/[⟦⟧]/);
    for (const word of [
      'Your',
      'Score',
      'improve',
      'Nothing',
      'Better',
      'Switch',
      'You own',
      'Farm',
      'Currently',
      'Not equipped',
      'Current piece',
      'agree',
      'builds',
      'Best',
      'Stats',
      'totals',
      'vs.',
      'option',
      'Crit value',
      'color',
      'points',
      'Completes',
      'of ideal',
      'n/a',
      'Checked',
    ]) {
      expect(rest, word).not.toContain(word);
    }
    // The game's words are there as they are.
    for (const term of ['Set bonuses', 'Main stats', 'Substats', 'Weapon', 'Artifact sets', 'Lv. 90', 'Source 1 · Main DPS']) {
      expect(rest, term).toContain(term);
    }
    expect(host.querySelector('app-grade-badge .badge')?.getAttribute('aria-label')).toBe('⟦Grade B⟧');
  });

  it('words a failed request from its code, never with the server’s sentence, and follows the language', async () => {
    await open(
      'sk',
      throwError(() => new HttpErrorResponse({ status: 403, error: { message: 'Only an editor or an admin can do this.', code: 'editor_only' } })),
    );

    expect(text('.failure .anotoki-alert__text')).toEqual(['Toto môže urobiť len editor alebo správca.']);

    await TestBed.inject(TranslationService).setLanguage('en');
    await fixture.whenStable();
    expect(text('.failure .anotoki-alert__text')).toEqual(['Only an editor or an admin can do this.']);
  });

  it('words again when the language changes: state holds kinds and keys, not sentences', async () => {
    await open('sk');
    expect(text('.rec-cat + div')[0]).toBe('Sands of Eon: main stat je ATK%, tento build chce HP%.');
    expect(text('.plan [cardActions] anotoki-badge')[0]).toBe('Máš 2 / 4 kusy');

    await TestBed.inject(TranslationService).setLanguage('en');
    await fixture.whenStable();
    expect(text('.rec-cat + div')[0]).toBe('Sands of Eon has ATK% as main stat; this build wants HP%.');
    expect(text('.rec-cat')).toEqual(['Main stat · Sands of Eon', 'Sets', 'Stat target', 'Other']);
    expect(text('.plan [cardActions] anotoki-badge')[0]).toBe('You own 2 of 4 pieces');
    expect(text('.suggestion-head anotoki-badge')[0]).toBe('+3 points of substat quality');
    expect(text('.consensus-row anotoki-badge')[1]).toBe('2pc Alpha + 2pc Beta + 2pc Gamma (any 2) 1×');
    expect(host.querySelectorAll('.rec [lang]')).toHaveLength(0);
  });
});
