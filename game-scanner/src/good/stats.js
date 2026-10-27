// Names the English game client shows, mapped to GOOD keys.

export const SLOTS = {
  'Flower of Life': 'flower',
  'Plume of Death': 'plume',
  'Sands of Eon': 'sands',
  'Goblet of Eonothem': 'goblet',
  'Circlet of Logos': 'circlet',
};

// Stat names as shown; HP, ATK and DEF become the % key when the value has a "%".
export const STAT_NAMES = {
  HP: ['hp', 'hp_'],
  ATK: ['atk', 'atk_'],
  DEF: ['def', 'def_'],
  'Elemental Mastery': ['eleMas'],
  'Energy Recharge': ['enerRech_'],
  'CRIT Rate': ['critRate_'],
  'CRIT DMG': ['critDMG_'],
  'Healing Bonus': ['heal_'],
  'Physical DMG Bonus': ['physical_dmg_'],
  'Pyro DMG Bonus': ['pyro_dmg_'],
  'Hydro DMG Bonus': ['hydro_dmg_'],
  'Electro DMG Bonus': ['electro_dmg_'],
  'Cryo DMG Bonus': ['cryo_dmg_'],
  'Anemo DMG Bonus': ['anemo_dmg_'],
  'Geo DMG Bonus': ['geo_dmg_'],
  'Dendro DMG Bonus': ['dendro_dmg_'],
};

export const SUBSTAT_NAMES = ['HP', 'ATK', 'DEF', 'Elemental Mastery', 'Energy Recharge', 'CRIT Rate', 'CRIT DMG'];

const ELEMENT_DMG = ['physical_dmg_', 'pyro_dmg_', 'hydro_dmg_', 'electro_dmg_', 'cryo_dmg_', 'anemo_dmg_', 'geo_dmg_', 'dendro_dmg_'];

export const MAIN_STATS = {
  flower: ['hp'],
  plume: ['atk'],
  sands: ['hp_', 'atk_', 'def_', 'eleMas', 'enerRech_'],
  goblet: ['hp_', 'atk_', 'def_', 'eleMas', ...ELEMENT_DMG],
  circlet: ['hp_', 'atk_', 'def_', 'eleMas', 'critRate_', 'critDMG_', 'heal_'],
};

// The main stat's GOOD key: the panel shows "HP" both on flowers (flat) and on sands/goblets/circlets (%).
export function mainStatKey(name, slotKey) {
  const keys = STAT_NAMES[name];
  if (!keys) return null;
  if (keys.length === 1) return keys[0];
  return slotKey === 'flower' || slotKey === 'plume' ? keys[0] : keys[1];
}

export const MAX_LEVEL = { 1: 4, 2: 4, 3: 12, 4: 16, 5: 20 };

// Main stat value at level 0 and at the maximum level; it grows by (almost exactly) the same step each level.
const ELEMENT_DMG_RANGE = { 5: [7.0, 46.6], 4: [6.3, 34.8] };
const MAIN_VALUES = {
  5: {
    hp: [717, 4780],
    atk: [47, 311],
    hp_: [7.0, 46.6],
    atk_: [7.0, 46.6],
    def_: [8.7, 58.3],
    eleMas: [28.0, 186.5],
    enerRech_: [7.8, 51.8],
    critRate_: [4.7, 31.1],
    critDMG_: [9.3, 62.2],
    heal_: [5.4, 35.9],
    physical_dmg_: [8.7, 58.3],
  },
  4: {
    hp: [645, 3571],
    atk: [42, 232],
    hp_: [6.3, 34.8],
    atk_: [6.3, 34.8],
    def_: [7.9, 43.5],
    eleMas: [25.2, 139.3],
    enerRech_: [7.0, 38.7],
    critRate_: [4.2, 23.2],
    critDMG_: [8.4, 46.4],
    heal_: [4.8, 26.8],
    physical_dmg_: [7.9, 43.5],
  },
};

// The level a main stat value belongs to ("28.8" ATK% on a 5★ → 11), or null if it fits no level.
export function levelFromMainValue(key, value, rarity) {
  const range = MAIN_VALUES[rarity]?.[key] ?? (key.endsWith('_dmg_') ? ELEMENT_DMG_RANGE[rarity] : null);
  if (!range || !Number.isFinite(value)) return null;
  const max = MAX_LEVEL[rarity];
  const step = (range[1] - range[0]) / max;
  const level = Math.round((value - range[0]) / step);
  if (level < 0 || level > max) return null;
  return Math.abs(range[0] + level * step - value) <= step * 0.3 ? level : null;
}

// Values of one substat roll (the four tiers) by rarity.
export const ROLLS = {
  5: {
    hp: [209.13, 239.0, 268.88, 298.75],
    atk: [13.62, 15.56, 17.51, 19.45],
    def: [16.2, 18.52, 20.83, 23.15],
    hp_: [4.08, 4.66, 5.25, 5.83],
    atk_: [4.08, 4.66, 5.25, 5.83],
    def_: [5.1, 5.83, 6.56, 7.29],
    eleMas: [16.32, 18.65, 20.98, 23.31],
    enerRech_: [4.53, 5.18, 5.83, 6.48],
    critRate_: [2.72, 3.11, 3.5, 3.89],
    critDMG_: [5.44, 6.22, 6.99, 7.77],
  },
  4: {
    hp: [167.3, 191.2, 215.1, 239.0],
    atk: [10.89, 12.45, 14.0, 15.56],
    def: [12.96, 14.82, 16.67, 18.52],
    hp_: [3.26, 3.73, 4.2, 4.66],
    atk_: [3.26, 3.73, 4.2, 4.66],
    def_: [4.08, 4.66, 5.25, 5.83],
    eleMas: [13.06, 14.92, 16.79, 18.65],
    enerRech_: [3.63, 4.14, 4.66, 5.18],
    critRate_: [2.18, 2.49, 2.8, 3.11],
    critDMG_: [4.35, 4.97, 5.6, 6.22],
  },
};

const rollCountCache = new Map();

// The numbers of rolls (1-6) that can add up to the shown value, e.g. CRIT Rate 7.0 → [2]. Empty when no
// number of rolls can give that value (usually a misread). null when the rarity has no roll table here.
// The roll values above are rounded, so a sum may land half a unit (flat) or 0.05 (%) off the shown value.
export function possibleRollCounts(key, value, rarity) {
  const tiers = ROLLS[rarity]?.[key];
  if (!tiers) return null;
  const cacheKey = `${rarity}:${key}`;
  let sums = rollCountCache.get(cacheKey);
  if (!sums) {
    sums = [];
    for (let k = 1; k <= 6; k++) sums.push({ k, values: sumsOf(tiers, k) });
    rollCountCache.set(cacheKey, sums);
  }
  const tolerance = key.endsWith('_') ? 0.051 : 0.51;
  return sums.filter(({ values }) => values.some((v) => Math.abs(v - value) <= tolerance)).map(({ k }) => k);
}

function sumsOf(tiers, k) {
  const out = [];
  const walk = (start, left, sum) => {
    if (left === 0) return void out.push(sum);
    for (let i = start; i < tiers.length; i++) walk(i, left - 1, sum + tiers[i]);
  };
  walk(0, k, 0);
  return out;
}
