import { customName, distance, knownName } from './names.js';
import { levelFromMainValue, mainStatKey, MAIN_STATS, MAX_LEVEL, possibleRollCounts, SLOTS, STAT_NAMES, SUBSTAT_NAMES } from './stats.js';

// Turns what readPanel() saw into a GOOD item plus a list of problems. Names are matched loosely (a letter or
// two misread is fine) against the game's names; numbers are checked against what the game can roll.
// customNames: names the player chose, shown in "Equipped: <name>", with their GOOD key ({ ivantelle: 'Traveler' }).
// Every set, weapon and character name read is returned in `names` ({ kind, read, name, known, corrected }).

export function parseArtifact(panel, { customNames = {} } = {}) {
  const problems = [];
  const names = [];
  const t = panel.text;

  const slotName = matchName(t.slot, Object.keys(SLOTS));
  const slotKey = slotName ? SLOTS[slotName] : null;
  if (!slotKey) problems.push(`unknown slot "${t.slot}"`);

  const mainName = matchName(t.mainStat, Object.keys(STAT_NAMES));
  const mainKey = mainName ? mainStatKey(mainName, slotKey) : null;
  if (!mainKey) problems.push(`unknown main stat "${t.mainStat}"`);
  else if (slotKey && !MAIN_STATS[slotKey].includes(mainKey)) problems.push(`main stat ${mainKey} is not possible on a ${slotKey}`);

  const rarity = panel.rarity;
  if (rarity < 1) problems.push('no rarity stars found');

  const level = readLevel(t, mainKey, rarity, problems);

  const { substats, unactivatedSubstats, setName, bodyProblems } = parseBody(t.body);
  problems.push(...bodyProblems);
  if (!setName) problems.push('no set name found');

  for (const s of [...substats, ...unactivatedSubstats]) {
    if (s.key === mainKey) problems.push(`substat ${s.key} is the same as the main stat`);
    const counts = possibleRollCounts(s.key, s.value, rarity);
    if (counts && counts.length === 0) problems.push(`${s.key} ${s.value} can't be rolled on a ${rarity}★ artifact`);
    s.rolls = counts;
  }
  const keys = [...substats, ...unactivatedSubstats].map((s) => s.key);
  if (new Set(keys).size !== keys.length) problems.push('the same substat appears twice');
  if (keys.length > 4) problems.push(`${keys.length} substats`);
  if (rarity === 5 && level !== null) checkRollTotal(substats, unactivatedSubstats, level, problems);

  const location = readLocation(t.equippedBy, customNames, problems, names);
  const set = setName ? knownName('sets', setName) : null;
  if (set) names.push({ kind: 'set', read: setName, ...set });

  return {
    artifact: {
      setKey: set ? toKey(set.name) : '',
      slotKey: slotKey ?? '',
      level: level ?? 0,
      rarity,
      mainStatKey: mainKey ?? '',
      location,
      lock: panel.locked,
      substats: substats.map(({ key, value }) => ({ key, value })),
      unactivatedSubstats: unactivatedSubstats.map(({ key, value }) => ({ key, value })),
      astralMark: panel.astralMark,
      elixirCrafted: panel.elixirCrafted,
    },
    setName,
    rolls: [...substats, ...unactivatedSubstats].map((s) => s.rolls),
    problems,
    names,
  };
}

// The weapon's max level shows its ascension: Lv. 90/90 → 6, Lv. 20/40 → 1 (ascended once, not levelled yet).
const ASCENSION_BY_MAX_LEVEL = { 20: 0, 40: 1, 50: 2, 60: 3, 70: 4, 80: 5, 90: 6 };

export function parseWeapon(panel, { customNames = {} } = {}) {
  const problems = [];
  const names = [];
  const t = panel.text;

  const read = t.name
    .split('\n')
    .map((l) => l.trim())
    .filter(Boolean)
    .join(' ')
    .replace(/^[^A-Za-z0-9"]+|[^A-Za-z0-9"]+$/g, '');
  if (!read) problems.push(`unreadable name "${t.name}"`);
  const weapon = knownName('weapons', read);
  if (read) names.push({ kind: 'weapon', read, ...weapon });

  const m = /(\d{1,2})\s*\/\s*(\d{2})/.exec(t.level.replace(/[oO]/g, '0'));
  const level = m ? Number(m[1]) : null;
  const maxLevel = m ? Number(m[2]) : null;
  const ascension = ASCENSION_BY_MAX_LEVEL[maxLevel];
  if (!m || ascension === undefined) problems.push(`unreadable level "${t.level}"`);
  else {
    const caps = Object.keys(ASCENSION_BY_MAX_LEVEL).map(Number);
    const floor = caps.filter((c) => c < maxLevel).at(-1) ?? 1;
    if (level > maxLevel || level < floor) problems.push(`level ${level}/${maxLevel} is not possible`);
    if (panel.rarity <= 2 && maxLevel > 70) problems.push(`a ${panel.rarity}★ weapon can't go above level 70`);
  }

  // 1★ and 2★ weapons can't be refined and show no refinement line.
  const r = /Refinement\s*Rank\s*(\d)/i.exec(t.refinement);
  let refinement = r ? Number(r[1]) : 1;
  if (!r && panel.rarity > 2) problems.push(`unreadable refinement "${t.refinement}"`);
  if (refinement < 1 || refinement > 5) {
    problems.push(`refinement ${refinement} is not possible`);
    refinement = 1;
  }

  return {
    weapon: {
      key: toKey(weapon.name),
      level: level ?? 1,
      ascension: ascension ?? 0,
      refinement,
      location: readLocation(t.equippedBy, customNames, problems, names),
      lock: panel.locked,
    },
    name: weapon.name,
    problems,
    names,
  };
}

// "Equipped: Kaedehara Kazuha" → KaedeharaKazuha; a character shown under a name the player chose (the
// Traveler, the Manekin) → its key from customNames.
function readLocation(text, customNames, problems, names) {
  if (!text) return '';
  const equipped = /Equipped\s*[:;]?\s*(.+)$/i.exec(text.replace(/\s+/g, ' ').trim());
  if (!equipped) {
    problems.push(`unreadable equipped text "${text}"`);
    return '';
  }
  const shown = equipped[1].trim();
  const custom = customName(shown, customNames);
  if (custom) return custom;
  const character = knownName('characters', shown);
  names.push({ kind: 'character', read: shown, ...character });
  return toKey(character.name);
}

// The level box is small ("+11" can come out as "+1"); the big main stat value below the stat name tells
// the level too, so that wins when it fits a level.
function readLevel(t, mainKey, rarity, problems) {
  const m = /(\d{1,2})/.exec(t.level.replace(/[oO]/g, '0'));
  const fromBox = m ? Number(m[1]) : null;
  const fromValue = mainKey ? levelFromMainValue(mainKey, parseNumber(t.mainValue ?? '', mainKey.endsWith('_')), rarity) : null;
  const level = fromValue ?? fromBox;
  if (level === null) problems.push(`unreadable level "${t.level}" and main stat value "${t.mainValue}"`);
  else if (level > (MAX_LEVEL[rarity] ?? 20)) problems.push(`level ${level} is above the maximum for ${rarity}★`);
  return level;
}

// "1,530" → 1530; in a percentage a comma is a misread decimal point ("2,7%" or "4.,1%" → 2.7 / 4.1).
export function parseNumber(text, percent) {
  const m = /(\d[\d,.]*)/.exec(text);
  if (!m) return NaN;
  return Number(percent ? m[1].replace(/[.,]+/g, '.') : m[1].replace(/,/g, ''));
}

// The lines under the level: "· CRIT Rate+3.9%" … then the green "Set Name:" line, then the set bonus text.
// A long substat followed by "(unactivated)" wraps; the "(unactivated)" then stands on its own line.
// A long set name wraps too ("Nighttime Whispers in the Echoing" / "Woods:").
export function parseBody(body) {
  const substats = [];
  const unactivatedSubstats = [];
  const bodyProblems = [];
  let setName = null;
  let nameStart = '';
  for (const raw of body.split('\n')) {
    const line = raw.replace(/^[^A-Za-z]+/, '').trim(); // the "·" bullet comes out as "+", "*", "-"…
    if (!line) continue;
    if (/^unactivated/i.test(line)) {
      if (substats.length) unactivatedSubstats.push(substats.pop());
      continue;
    }
    if (/:\s*$/.test(line)) {
      setName = `${nameStart} ${line}`
        .replace(/\s*:\s*$/, '')
        .replace(/[’`]/g, "'")
        .trim();
      nameStart = '';
      break;
    }
    const m = /^(.+?)\s*\+\s*([\d.,]+)\s*(%?)(.*)$/.exec(line);
    if (!m) {
      nameStart = `${nameStart} ${line}`; // no "+value": the first part of a wrapped set name
      continue;
    }
    const name = closest(m[1], SUBSTAT_NAMES);
    if (!name) {
      bodyProblems.push(`unreadable substat line "${line}"`);
      continue;
    }
    const percent = m[3] === '%';
    const value = parseNumber(m[2], percent);
    const keys = STAT_NAMES[name];
    const key = keys.length === 2 ? keys[percent ? 1 : 0] : keys[0];
    if (!Number.isFinite(value)) bodyProblems.push(`unreadable value in "${line}"`);
    else if (key.endsWith('_') !== percent) bodyProblems.push(`"${line}": ${percent ? 'unexpected' : 'missing'} %`);
    else (/unactivated/i.test(m[4]) ? unactivatedSubstats : substats).push({ key, value });
  }
  if (nameStart) bodyProblems.push(`unreadable line "${nameStart.trim()}"`);
  return { substats, unactivatedSubstats, setName, bodyProblems };
}

// A name in OCR text that may carry noise: other lines, or bits of the artwork after a wide gap.
// Tries each line and the longest run of leading words that is close to a candidate.
export function matchName(text, candidates) {
  for (const line of text.split('\n')) {
    const words = line
      .split(/\s{3,}/)[0]
      .trim()
      .split(/\s+/)
      .filter(Boolean);
    for (let n = words.length; n >= 1; n--) {
      const found = closest(words.slice(0, n).join(' '), candidates);
      if (found) return found;
    }
  }
  return null;
}

// A 5★ artifact starts with 3 or 4 rolls and gets one more every 4 levels; an unactivated substat counts
// once it activates at +4. If no combination of the possible roll counts fits, a value was misread.
function checkRollTotal(substats, unactivated, level, problems) {
  if (substats.some((s) => !s.rolls?.length)) return; // already reported
  const upgrades = Math.floor(level / 4);
  const allowed = unactivated.length ? [3] : [3 + upgrades, 4 + upgrades];
  let totals = new Set([0]);
  for (const s of substats) {
    const next = new Set();
    for (const t of totals) for (const k of s.rolls) next.add(t + k);
    totals = next;
  }
  if (!allowed.some((n) => totals.has(n))) {
    problems.push(`substat values don't add up to the ${allowed.join(' or ')} rolls a +${level} artifact has`);
  }
}

// GOOD key: the English name in PascalCase without spaces or punctuation ("Gladiator's Finale" → GladiatorsFinale).
export function toKey(name) {
  return name
    .replace(/[’'`"“”]/g, '')
    .split(/[^A-Za-z0-9]+/)
    .filter(Boolean)
    .map((w) => w[0].toUpperCase() + w.slice(1))
    .join('');
}

// The candidate closest to the text (case-insensitive), if it is close enough to be the same word.
export function closest(text, candidates) {
  const clean = text
    .replace(/[^A-Za-z ]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();
  if (!clean) return null;
  let best = null;
  let bestDistance = Infinity;
  for (const c of candidates) {
    const d = distance(clean, c.toLowerCase());
    if (d < bestDistance) [best, bestDistance] = [c, d];
  }
  return bestDistance <= Math.max(1, Math.floor(best.length / 4)) ? best : null;
}
