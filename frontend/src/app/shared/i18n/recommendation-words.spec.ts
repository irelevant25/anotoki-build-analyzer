import { TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { Api } from '../../api/api';
import { MetaStore } from '../meta-store';
import { GameKey, KindParams } from '../../api/models';
import { DYNAMIC_KEYS } from './dynamic-keys';
import { en } from './en';
import { RECOMMENDATION_KINDS, recommendationText, setOptionText } from './recommendation-words';
import { TranslationService } from './translation';

/** The informal Slovak these kinds are seeded with: every game term stays as it is, first or after a colon. */
const SLOVAK: Record<string, string> = {
  'buildDetail.optionAny.one': '(ľubovoľný 1)',
  'buildDetail.optionAny.few': '(ľubovoľné {count})',
  'buildDetail.optionAny.other': '(ľubovoľných {count})',
  'recommendation.weapon_none': '{weapon_label}: nič nasadené. Najlepšie možnosti: {options}.',
  'recommendation.weapon_level': '{name}: zvýš level (teraz {level}/{max_level}).',
  'recommendation.weapon_unlisted': '{weapon_label}: {name} nie je v zozname tohto buildu. Odporúča sa: {options}.',
  'recommendation.weapon_unlisted_owned': '{weapon_label}: {name} nie je v zozname tohto buildu. Odporúča sa: {options}. Už máš {owned} ({refinement}).',
  'recommendation.weapon_unlisted_owned_on': '{weapon_label}: {name} nie je v zozname tohto buildu. Odporúča sa: {options}. Už máš {owned} ({refinement}), teraz na postave: {character}.',
  'recommendation.weapon_better_owned': 'Máš aj {owned} ({refinement}) – č. {owned_rank} v zozname; teraz používaš {name} (č. {rank}).',
  'recommendation.weapon_better_owned_on': 'Máš aj {owned} ({refinement}) – č. {owned_rank} v zozname, teraz na postave: {character}. Používaš {name} (č. {rank}).',
  'recommendation.weapon_low_rank': '{name} je možnosť č. {rank}. Lepšie možnosti: {options}.',
  'recommendation.sets_missing': '{set_label}: odporúča sa {best}. Teraz máš: {current}.',
  'recommendation.sets_missing_empty': '{set_label}: odporúča sa {best}. Teraz nemáš nasadené nič.',
  'recommendation.sets_lower_option': '{set_label}: teraz máš {current}, čo je možnosť č. {rank}. Najlepšia voľba: {best}.',
  'recommendation.main_stat_missing': '{slot_label}: nič nasadené. Hľadaj kus, ktorý má main stat: {wanted}.',
  'recommendation.main_stat_wrong': '{slot_label}: main stat je {stat}, tento build chce {wanted}.',
  'recommendation.item_level': '{slot_label}: zvýš level (+{level} → +{max_level}).',
  'recommendation.substats_weak': '{slot_label}: substats majú len {percent} % hodnoty ideálneho kusu. Vymeň tento kus za taký, ktorý má substats: {stats}.',
  'recommendation.target_below': '{stat}: máš {current}, build mieri na {min} a viac.',
  'recommendation.target_basic_below': '{stat}: máš {current}, základný cieľ pre túto zostavu je {min}.',
  'recommendation.talent_level': '{talent}: zvýš level prednostne (priorita č. 1, teraz {level}).',
  'recommendation.character_level': 'Zvýš level postavy (teraz {level}); väčšina jej hodnôt vychádza z levelu postavy.',
};

const HOMA = "Staff of Homa, Dragon's Bane";
const UNLISTED = { name: 'Favonius Lance', weapon_label: 'Weapon', options: HOMA };
const ANY_TWO = "2pc Shimenawa's Reminiscence + 2pc Emblem of Severed Fate + 2pc Gilded Dreams";

/** Every kind as the server sends it (the params of backend/tests/analysis.test.php), with its English and its Slovak. */
const CASES: { kind: string; game?: GameKey; params: KindParams; english: string; slovak: string }[] = [
  {
    kind: 'weapon_none',
    params: { weapon_label: 'Weapon', options: HOMA },
    english: "Weapon: none equipped. Best options: Staff of Homa, Dragon's Bane.",
    slovak: "Weapon: nič nasadené. Najlepšie možnosti: Staff of Homa, Dragon's Bane.",
  },
  {
    kind: 'weapon_level',
    params: { name: "Dragon's Bane", level: 70, max_level: 90 },
    english: "Level up Dragon's Bane (level 70/90).",
    slovak: "Dragon's Bane: zvýš level (teraz 70/90).",
  },
  {
    kind: 'weapon_unlisted',
    params: UNLISTED,
    english: "Weapon: Favonius Lance is not on this build's list. Recommended: Staff of Homa, Dragon's Bane.",
    slovak: "Weapon: Favonius Lance nie je v zozname tohto buildu. Odporúča sa: Staff of Homa, Dragon's Bane.",
  },
  {
    kind: 'weapon_unlisted_owned',
    params: { ...UNLISTED, owned: 'Staff of Homa', refinement: 'R1' },
    english: "Weapon: Favonius Lance is not on this build's list. Recommended: Staff of Homa, Dragon's Bane. You already own Staff of Homa (R1).",
    slovak: "Weapon: Favonius Lance nie je v zozname tohto buildu. Odporúča sa: Staff of Homa, Dragon's Bane. Už máš Staff of Homa (R1).",
  },
  {
    kind: 'weapon_unlisted_owned_on',
    params: { ...UNLISTED, owned: 'Staff of Homa', refinement: 'R1', character: 'Kaedehara Kazuha' },
    english: "Weapon: Favonius Lance is not on this build's list. Recommended: Staff of Homa, Dragon's Bane. You already own Staff of Homa (R1), currently on Kaedehara Kazuha.",
    slovak: "Weapon: Favonius Lance nie je v zozname tohto buildu. Odporúča sa: Staff of Homa, Dragon's Bane. Už máš Staff of Homa (R1), teraz na postave: Kaedehara Kazuha.",
  },
  {
    kind: 'weapon_better_owned',
    params: { owned: 'Staff of Homa', refinement: 'R1', owned_rank: 1, name: "Dragon's Bane", rank: 2 },
    english: "You own Staff of Homa (R1) (#1), which ranks above Dragon's Bane (#2).",
    slovak: "Máš aj Staff of Homa (R1) – č. 1 v zozname; teraz používaš Dragon's Bane (č. 2).",
  },
  {
    kind: 'weapon_better_owned_on',
    params: { owned: 'Staff of Homa', refinement: 'R1', owned_rank: 1, name: "Dragon's Bane", rank: 2, character: 'Kaedehara Kazuha' },
    english: "You own Staff of Homa (R1), currently on Kaedehara Kazuha (#1), which ranks above Dragon's Bane (#2).",
    slovak: "Máš aj Staff of Homa (R1) – č. 1 v zozname, teraz na postave: Kaedehara Kazuha. Používaš Dragon's Bane (č. 2).",
  },
  {
    kind: 'weapon_low_rank',
    params: { name: "Dragon's Bane", rank: 4, options: 'Staff of Homa, Staff of the Scarlet Sands, Deathmatch' },
    english: "Dragon's Bane is option #4. Upgrades to aim for: Staff of Homa, Staff of the Scarlet Sands, Deathmatch.",
    slovak: "Dragon's Bane je možnosť č. 4. Lepšie možnosti: Staff of Homa, Staff of the Scarlet Sands, Deathmatch.",
  },
  {
    kind: 'sets_missing',
    params: { set_label: 'Artifact set', best: '4pc Crimson Witch of Flames', best_choose: null, current: "2pc Gladiator's Finale + 2pc Shimenawa's Reminiscence" },
    english: "Artifact set: use the recommended 4pc Crimson Witch of Flames. Currently: 2pc Gladiator's Finale + 2pc Shimenawa's Reminiscence.",
    slovak: "Artifact set: odporúča sa 4pc Crimson Witch of Flames. Teraz máš: 2pc Gladiator's Finale + 2pc Shimenawa's Reminiscence.",
  },
  {
    // An "any two" option: the page adds the phrase to {best}.
    kind: 'sets_missing',
    params: { set_label: 'Artifact set', best: ANY_TWO, best_choose: 2, current: '4pc Crimson Witch of Flames + 1pc Emblem of Severed Fate' },
    english: `Artifact set: use the recommended ${ANY_TWO} (any 2). Currently: 4pc Crimson Witch of Flames + 1pc Emblem of Severed Fate.`,
    slovak: `Artifact set: odporúča sa ${ANY_TWO} (ľubovoľné 2). Teraz máš: 4pc Crimson Witch of Flames + 1pc Emblem of Severed Fate.`,
  },
  {
    kind: 'sets_missing_empty',
    params: { set_label: 'Artifact set', best: '4pc Crimson Witch of Flames', best_choose: null },
    english: 'Artifact set: use the recommended 4pc Crimson Witch of Flames. Nothing is equipped now.',
    slovak: 'Artifact set: odporúča sa 4pc Crimson Witch of Flames. Teraz nemáš nasadené nič.',
  },
  {
    kind: 'sets_lower_option',
    params: { set_label: 'Artifact set', current: "5pc Shimenawa's Reminiscence", rank: 2, best: '4pc Crimson Witch of Flames', best_choose: null },
    english: "Artifact set: yours (5pc Shimenawa's Reminiscence) is option #2. The top choice is 4pc Crimson Witch of Flames.",
    slovak: "Artifact set: teraz máš 5pc Shimenawa's Reminiscence, čo je možnosť č. 2. Najlepšia voľba: 4pc Crimson Witch of Flames.",
  },
  {
    kind: 'sets_lower_option',
    params: { set_label: 'Artifact set', current: "4pc Gladiator's Finale", rank: 3, best: ANY_TWO, best_choose: 1 },
    english: `Artifact set: yours (4pc Gladiator's Finale) is option #3. The top choice is ${ANY_TWO} (any 1).`,
    slovak: `Artifact set: teraz máš 4pc Gladiator's Finale, čo je možnosť č. 3. Najlepšia voľba: ${ANY_TWO} (ľubovoľný 1).`,
  },
  {
    kind: 'main_stat_missing',
    params: { slot_label: 'Sands of Eon', wanted: 'HP% / Elemental Mastery' },
    english: 'No Sands of Eon equipped. Look for one with HP% / Elemental Mastery.',
    slovak: 'Sands of Eon: nič nasadené. Hľadaj kus, ktorý má main stat: HP% / Elemental Mastery.',
  },
  {
    kind: 'main_stat_wrong',
    params: { slot_label: 'Goblet of Eonothem', stat: 'ATK%', wanted: 'Pyro DMG Bonus' },
    english: 'Goblet of Eonothem has ATK% as main stat; this build wants Pyro DMG Bonus.',
    slovak: 'Goblet of Eonothem: main stat je ATK%, tento build chce Pyro DMG Bonus.',
  },
  {
    kind: 'item_level',
    params: { slot_label: 'Sands of Eon', level: 16, max_level: 20 },
    english: 'Level up your Sands of Eon (+16 → +20).',
    slovak: 'Sands of Eon: zvýš level (+16 → +20).',
  },
  {
    kind: 'substats_weak',
    params: { slot_label: 'Sands of Eon', percent: 41, stats: 'CR, CD, ATK%' },
    english: 'Your Sands of Eon has weak substats (41% of an ideal piece). Replace it with one rolling CR, CD, ATK%.',
    slovak: 'Sands of Eon: substats majú len 41 % hodnoty ideálneho kusu. Vymeň tento kus za taký, ktorý má substats: CR, CD, ATK%.',
  },
  {
    // A percent target: the game's notation, the one the page's own targets have.
    kind: 'target_below',
    params: { stat: 'CRIT Rate', stat_key: 'critRate_', current: 62, min: 70 },
    english: 'CRIT Rate is 62.0%; the build aims for 70.0%+.',
    slovak: 'CRIT Rate: máš 62.0%, build mieri na 70.0% a viac.',
  },
  {
    // A flat one - a whole float arrives without its fraction, and is written with the game's thousands separator in every language.
    kind: 'target_below',
    params: { stat: 'HP', stat_key: 'hp', current: 28512, min: 30000 },
    english: 'HP is 28,512; the build aims for 30,000+.',
    slovak: 'HP: máš 28,512, build mieri na 30,000 a viac.',
  },
  {
    // A stat below the basic tier of a target worked out from the game's numbers: the tier's value, as the targets write it.
    kind: 'target_basic_below',
    params: { stat: 'CRIT DMG', stat_key: 'critDMG_', current: 90, min: 106.7 },
    english: 'CRIT DMG is 90.0%; the basic target for this setup is 106.7%.',
    slovak: 'CRIT DMG: máš 90.0%, základný cieľ pre túto zostavu je 106.7%.',
  },
  {
    kind: 'talent_level',
    params: { talent: 'Elemental Skill', level: 6 },
    english: 'Level Elemental Skill first (priority #1, currently 6).',
    slovak: 'Elemental Skill: zvýš level prednostne (priorita č. 1, teraz 6).',
  },
  {
    kind: 'character_level',
    params: { level: 70 },
    english: 'Level the character up (level 70); most of their stats come from character level.',
    slovak: 'Zvýš level postavy (teraz 70); väčšina jej hodnôt vychádza z levelu postavy.',
  },
];

describe('recommendationText(): what a build recommends, in the reader’s words', () => {
  function words(language: 'en' | 'sk') {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      // The metadata has not been loaded: formatStat() then reads a percent from the key itself ("critRate_").
      providers: [language === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: en }) : provideTestTranslations(en), { provide: Api, useValue: {} }],
    });
    return { i18n: TestBed.inject(TranslationService), meta: TestBed.inject(MetaStore) };
  }

  let warn: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
  });

  afterEach(() => vi.restoreAllMocks());

  it('words every kind in English from its parameters - the game’s own words as they came', () => {
    const { i18n, meta } = words('en');
    for (const { kind, game, params, english } of CASES) {
      expect(recommendationText(i18n, meta, game ?? 'genshin', { kind, params, message: 'The stored sentence.' }), kind).toEqual({ text: english, lang: null });
    }
    expect(warn).not.toHaveBeenCalled();
  });

  it('words every kind in Slovak around its English terms: a term first or after a colon, never declined', () => {
    const { i18n, meta } = words('sk');
    for (const { kind, game, params, slovak } of CASES) {
      expect(recommendationText(i18n, meta, game ?? 'genshin', { kind, params, message: 'The stored sentence.' }), kind).toEqual({ text: slovak, lang: null });
    }
    expect(warn).not.toHaveBeenCalled();
  });

  it('has a case here for every kind, and leaves no placeholder unfilled', () => {
    expect([...new Set(CASES.map((one) => one.kind))]).toEqual([...RECOMMENDATION_KINDS]);
    const { i18n, meta } = words('en');
    for (const { kind, game, params } of CASES) {
      expect(recommendationText(i18n, meta, game ?? 'genshin', { kind, params, message: '' }).text, kind).not.toMatch(/[{}]/);
    }
  });

  it('words exactly the kinds the key scan is told about - the server’s KINDS - and each has its English', () => {
    expect([...RECOMMENDATION_KINDS]).toEqual([...DYNAMIC_KEYS['recommendation.']]);
    expect(RECOMMENDATION_KINDS).toHaveLength(19);
    expect(RECOMMENDATION_KINDS.filter((kind) => !Object.hasOwn(en, `recommendation.${kind}`))).toEqual([]);
    expect(
      Object.keys(en)
        .filter((key) => key.startsWith('recommendation.'))
        .sort(),
    ).toEqual(RECOMMENDATION_KINDS.map((kind) => `recommendation.${kind}`).sort());
  });

  it('words an account’s top issue the same way, from its kind and params', () => {
    const { i18n, meta } = words('sk');
    const best = {
      top_issue: 'Goblet of Eonothem has ATK% as main stat; this build wants Pyro DMG Bonus.',
      top_issue_kind: 'main_stat_wrong',
      top_issue_params: { slot_label: 'Goblet of Eonothem', stat: 'ATK%', wanted: 'Pyro DMG Bonus' },
    };
    expect(recommendationText(i18n, meta, 'genshin', { kind: best.top_issue_kind, params: best.top_issue_params, message: best.top_issue })).toEqual({
      text: 'Goblet of Eonothem: main stat je ATK%, tento build chce Pyro DMG Bonus.',
      lang: null,
    });
  });

  it('shows the stored English sentence, marked as English, for a kind it does not know - never a key made from the kind', () => {
    const { i18n, meta } = words('sk');
    const message = 'Swap the feather for a shinier one.';
    for (const kind of ['weapon_shiny', 'constructor', 'toString', '__proto__', 'recommendation.weapon_none', '']) {
      expect(recommendationText(i18n, meta, 'genshin', { kind, params: { name: 'x' }, message }), kind).toEqual({ text: message, lang: 'en' });
    }
    expect(warn).not.toHaveBeenCalled();
  });

  it('shows the stored English sentence for a row without a kind: a top issue from before kinds were sent', () => {
    const { i18n, meta } = words('sk');
    const message = 'No weapon equipped. Best options: Staff of Homa.';
    expect(recommendationText(i18n, meta, 'genshin', { kind: null, params: null, message })).toEqual({ text: message, lang: 'en' });
    expect(recommendationText(i18n, meta, 'genshin', { message })).toEqual({ text: message, lang: 'en' });
    // Nothing at all to say is nothing.
    expect(recommendationText(i18n, meta, 'genshin', { kind: null, params: null, message: null })).toEqual({ text: '', lang: 'en' });
  });

  it('shows the stored English sentence rather than a sentence with a hole in it', () => {
    const { i18n, meta } = words('sk');
    const message = 'The stored sentence.';
    const stored = { text: message, lang: 'en' };
    // A parameter is missing, empty, null, or not the object's own.
    expect(recommendationText(i18n, meta, 'genshin', { kind: 'weapon_none', params: { weapon_label: 'Weapon' }, message })).toEqual(stored);
    expect(recommendationText(i18n, meta, 'genshin', { kind: 'weapon_none', params: { weapon_label: 'Weapon', options: '' }, message })).toEqual(stored);
    expect(recommendationText(i18n, meta, 'genshin', { kind: 'weapon_none', params: { weapon_label: 'Weapon', options: null }, message })).toEqual(stored);
    expect(recommendationText(i18n, meta, 'genshin', { kind: 'weapon_none', params: Object.create({ weapon_label: 'Weapon', options: 'x' }), message })).toEqual(stored);
    expect(recommendationText(i18n, meta, 'genshin', { kind: 'weapon_none', params: null, message })).toEqual(stored);
    expect(recommendationText(i18n, meta, 'genshin', { kind: 'character_level', params: { level: Number.NaN }, message })).toEqual(stored);
    // A target needs its stat's key and two numbers to be written in the game's notation.
    expect(recommendationText(i18n, meta, 'genshin', { kind: 'target_below', params: { stat: 'HP', stat_key: 'hp', current: '28512', min: 30000 }, message })).toEqual(stored);
    expect(recommendationText(i18n, meta, 'genshin', { kind: 'target_below', params: { stat: 'HP', current: 28512, min: 30000 }, message })).toEqual(stored);
  });

  it('does not mark the stored sentence while the page is English anyway', () => {
    const english = words('en');
    expect(recommendationText(english.i18n, english.meta, 'genshin', { kind: 'weapon_shiny', params: {}, message: 'Stored.' })).toEqual({ text: 'Stored.', lang: null });

    // The English pages of a Slovak reader: English too.
    const { i18n, meta } = words('sk');
    i18n.noteAddress('/admin/builds');
    expect(recommendationText(i18n, meta, 'genshin', { kind: 'weapon_shiny', params: {}, message: 'Stored.' })).toEqual({ text: 'Stored.', lang: null });
  });

  it('follows the language: the same recommendation is worded again', async () => {
    const { i18n, meta } = words('sk');
    const recommendation = { kind: 'talent_level', params: { talent: 'Elemental Skill', level: 6 }, message: 'Level Elemental Skill first (priority #1, currently 6).' };
    expect(recommendationText(i18n, meta, 'genshin', recommendation).text).toBe('Elemental Skill: zvýš level prednostne (priorita č. 1, teraz 6).');
    expect(await i18n.setLanguage('en', { remember: false })).toBe(true);
    expect(recommendationText(i18n, meta, 'genshin', recommendation).text).toBe('Level Elemental Skill first (priority #1, currently 6).');
  });
});

describe('setOptionText(): a set option with its "(any N)"', () => {
  it('adds the phrase only when some of the sets are enough', () => {
    TestBed.configureTestingModule({ providers: [provideTestTranslations(SLOVAK, { language: 'sk', english: en })] });
    const i18n = TestBed.inject(TranslationService);

    expect(setOptionText(i18n, '2pc A + 2pc B + 2pc C', 2)).toBe('2pc A + 2pc B + 2pc C (ľubovoľné 2)');
    expect(setOptionText(i18n, '2pc A + 2pc B + 2pc C', 1)).toBe('2pc A + 2pc B + 2pc C (ľubovoľný 1)');
    expect(setOptionText(i18n, '2pc A + 2pc B + 2pc C + 2pc D + 2pc E + 2pc F', 5)).toBe('2pc A + 2pc B + 2pc C + 2pc D + 2pc E + 2pc F (ľubovoľných 5)');
    expect(setOptionText(i18n, '4pc A', null)).toBe('4pc A');
    expect(setOptionText(i18n, '4pc A', undefined)).toBe('4pc A');
    expect(setOptionText(i18n, '4pc A', '2')).toBe('4pc A');
  });
});
