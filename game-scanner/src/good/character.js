import { frameFromPng } from '../frame.js';
import { ascensionCount, constellationCount, readAscension, readConstellations, readTalentLevels, RING_DARK, RING_LIT } from './character-screen.js';
import { binarize, readPng } from './image.js';
import { customName, distance, knownName } from './names.js';
import { closest, toKey } from './parse.js';

// Reads a character from the three screenshots of the character screen (Attributes, Constellation, Talents)
// and turns it into a GOOD character. The texts (element and name, level) are read with OCR; the ascension,
// the talent levels and the constellations from the pixels (character-screen.js). Positions are measured on a
// 2560x1440 screen and scaled by the image.

export const READ_VERSION = 4; // cached readings (ocr.json) of an older version are read again

const ELEMENTS = ['Pyro', 'Hydro', 'Anemo', 'Electro', 'Dendro', 'Cryo', 'Geo'];

// Gold "Hydro / Furina" at the top left, on every tab, up to the Q key hint; a long name takes two lines
// ("Anemo   Yumemizuki" / "/   Mizuki").
const HEADER = { x: 185, y: 22, width: 435, height: 84 };
const NAME = { x: 1945, y: 165, width: 560, height: 70 }; // white name, Attributes
const LEVEL = { x: 1945, y: 272, width: 420, height: 56 }; // "Level 90 / 90", Attributes

const isGold = (r, g, b) => r >= 170 && g >= 140 && b <= 170 && r - b >= 40;
const isWhite = (r, g, b) => r >= 210 && g >= 210 && b >= 210 && Math.max(r, g, b) - Math.min(r, g, b) <= 40;
const isLevel = (r, g, b) => r >= 120 && g >= 130 && b >= 140 && Math.max(r, g, b) - Math.min(r, g, b) <= 80; // with the grey max level

// images: { attributes, constellation, talents } - PNG buffers of the whole screen (any may be missing).
export async function readCharacter(images, ocr) {
  const read = { version: READ_VERSION };
  for (const tab of ['attributes', 'constellation', 'talents']) {
    if (images[tab]) Object.assign(read, await readCharacterTab(tab, readPng(images[tab]), ocr));
  }
  return read;
}

// What one tab's screenshot (a decoded image of the whole screen) shows of the character; readCharacter() puts
// the three together. Everything is taken from the image before the first wait, so it can go right after.
export async function readCharacterTab(tab, png, ocr) {
  const at = scaler(png);
  const header = ocr.recognize(binarize(png, rect(at, HEADER), isGold, { scale: 2 }));
  if (tab === 'attributes') {
    const texts = [
      header,
      ocr.recognize(binarize(png, rect(at, NAME), isWhite)),
      ocr.recognize(binarize(png, rect(at, LEVEL), isWhite, { scale: 2 })), // "Level 60": the white part alone
      ocr.recognize(binarize(png, rect(at, LEVEL), isLevel, { scale: 2 })), // "Level 60 / 60"
    ];
    const ascension = readAscension(frameFromPng(png)).map((b) => Math.round(b));
    const [h, name, current, level] = await Promise.all(texts);
    return { header: h, name, current, level, ascension };
  }
  if (tab === 'constellation') {
    const { none, rings } = readConstellations(frameFromPng(png));
    return { constellationHeader: await header, constellations: { none, rings: rings.map((b) => Math.round(b)) } };
  }
  const talents = readTalentLevels(frameFromPng(png));
  return { talentsHeader: await header, talents };
}

const ASCENSION_BY_MAX_LEVEL = { 20: 0, 40: 1, 50: 2, 60: 3, 70: 4, 80: 5, 90: 6, 95: 6, 100: 6 };
// The levels each ascension phase (0-6) spans: from the previous phase's highest to its own.
const LEVELS_BY_ASCENSION = [
  [1, 20],
  [20, 40],
  [40, 50],
  [50, 60],
  [60, 70],
  [70, 80],
  [80, 100],
];
// The highest talent level each ascension phase (0-6) allows.
const TALENT_CAP = [1, 1, 2, 4, 6, 8, 10];
// A talent level shown in cyan includes a bonus: +3 from a constellation (the 3rd and the 5th raise one talent
// each), or Tartaglia's +1 to Normal Attack from his passive.
const TALENT_BONUS = { Tartaglia: { auto: 1 } };

export function parseCharacter(read, { customNames = {} } = {}) {
  const problems = [];
  const names = [];

  // "Hydro / Furina": the element; the name is read from the big name on the right (the header's as a fallback).
  const header = headerParts(read.header);
  if (!header.element) problems.push(`unreadable element in "${oneLine(read.header)}"`);
  const shown = clean(read.name) || header.name;
  const custom = customName(shown, customNames);
  let key;
  if (custom) key = custom === 'Traveler' ? `Traveler${header.element ?? ''}` : custom;
  else if (shown) {
    const known = knownName('characters', shown);
    names.push({ kind: 'character', read: shown, ...known });
    key = toKey(known.name);
  }
  if (!key) problems.push('unreadable name');

  // The other tabs must show the same character (their header is read too).
  for (const [tab, text] of [
    ['constellation', read.constellationHeader],
    ['talents', read.talentsHeader],
  ]) {
    if (text === undefined) problems.push(`no ${tab} screenshot`);
    else if (!sameHeader(text, read.header)) problems.push(`the ${tab} screenshot shows "${oneLine(text)}", not "${oneLine(read.header)}"`);
  }

  // "Level 60 / 60": the level is the white number, read from the white pixels alone - sparkles around the grey
  // "/" have made "90 / 90" read "907/90" and "60 / 60" "60 760". The ascension is the stars' (the grey max
  // level only checks them), or the max level's without them.
  const number = (s) => s.replace(/[oO]/g, '0');
  const white = number(read.current || read.level || '');
  const current = /Level\D{0,3}?(\d{1,3})/i.exec(white) ?? /(\d{1,3})/.exec(white);
  const level = current ? Number(current[1]) : null;
  const max = /\d{1,3}[^\d/]{0,3}\/[^\d/]{0,3}(\d{2,3})/.exec(number(read.level ?? ''));
  const maxAscension = max ? ASCENSION_BY_MAX_LEVEL[Number(max[1])] : undefined;
  const stars = read.ascension ? ascensionCount(read.ascension) : null;
  const ascension = stars ?? maxAscension;
  if (stars !== null && maxAscension !== undefined && maxAscension !== stars) {
    problems.push(`the max level in "${oneLine(read.level)}" doesn't fit ${stars} ascension stars`);
  }
  if (ascension === undefined) problems.push(`unreadable ascension: "${oneLine(read.level)}", stars ${read.ascension?.join(', ') ?? 'not read'}`);
  if (level === null) problems.push(`unreadable level "${oneLine(read.level)}"`);
  else if (ascension !== undefined && (level < LEVELS_BY_ASCENSION[ascension][0] || level > LEVELS_BY_ASCENSION[ascension][1])) {
    problems.push(`level ${level} doesn't fit ascension ${ascension} ("${oneLine(read.level)}")`);
  }

  // Constellations unlock in order: the lit rings from the top.
  let constellation = 0;
  const c = read.constellations;
  if (c && !c.none) {
    constellation = constellationCount(c);
    if (constellation === null) {
      problems.push(`constellation rings neither lit nor dark (still fading in?): ${c.rings.join(', ')}`);
      const lit = c.rings.map((b) => b >= (RING_DARK + RING_LIT) / 2);
      constellation = lit.includes(false) ? lit.indexOf(false) : lit.length;
    }
    if (c.rings.slice(constellation).some((b) => b >= RING_LIT)) problems.push(`constellation rings out of order: ${c.rings.join(', ')}`);
  }

  // The first three talent rows with a level: Normal Attack, Elemental Skill, Elemental Burst.
  const levels = (read.talents ?? []).filter(Boolean).slice(0, 3);
  const talent = {};
  ['auto', 'skill', 'burst'].forEach((name, i) => {
    const row = levels[i];
    talent[name] = 1;
    if (!row) {
      problems.push(`no level found for ${name}`);
    } else if (row.level === null) {
      problems.push(`unreadable ${name} level`);
    } else {
      const base = row.boosted ? row.level - (TALENT_BONUS[key]?.[name] ?? 3) : row.level;
      if (base < 1 || base > 10) problems.push(`${name} level ${row.level}${row.boosted ? ' (raised)' : ''} gives ${base}`);
      talent[name] = Math.min(10, Math.max(1, base));
      if (ascension !== undefined && talent[name] > TALENT_CAP[ascension]) {
        problems.push(`${name} level ${talent[name]} is above what ascension ${ascension} allows (${TALENT_CAP[ascension]})`);
      }
    }
  });
  // The raised (cyan) levels must fit the constellations: a misread ring or colour shows here.
  if (levels.length === 3 && c) {
    const raised = levels.filter((row) => row.boosted).length;
    const expected = (constellation >= 3) + (constellation >= 5) + Object.keys(TALENT_BONUS[key] ?? {}).length;
    if (raised !== expected) problems.push(`${raised} talent level(s) shown raised, but C${constellation} raises ${expected}`);
  }

  return {
    character: { key: key ?? '', level: level ?? 1, constellation, ascension: ascension ?? 0, talent },
    problems,
    names,
  };
}

// The element a header reading names ("Hydro / Furina" → 'Hydro'), or null - never in a game that isn't in
// English.
export function headerElement(text) {
  return headerParts(text).element;
}

// "Hydro / Furina" → { element: 'Hydro', name: 'Furina' }; the lines of a two-line header are joined.
function headerParts(text = '') {
  const words = text
    .replace(/[^A-Za-z']+/g, ' ')
    .trim()
    .split(' ')
    .filter(Boolean);
  const i = words.findIndex((w) => closest(w, ELEMENTS));
  return i < 0 ? { element: null, name: words.join(' ') } : { element: closest(words[i], ELEMENTS), name: words.slice(i + 1).join(' ') };
}

// Two readings of the header show the same character (a letter or two may be read differently).
function sameHeader(a, b) {
  const [x, y] = [a, b].map((t) => {
    const { element, name } = headerParts(t);
    return `${element ?? ''}${name}`.toLowerCase().replace(/[^a-z]/g, '');
  });
  return distance(x, y) <= Math.max(2, Math.floor(Math.max(x.length, y.length) / 5));
}

// The name in an OCR reading without what the effects around it added: of the stretches between line breaks
// and wide gaps, the first with letters in it ("'   .\nFreminet", "Taren      o"), without stray marks at
// its ends.
function clean(text = '') {
  const parts = text.split(/\n|\s{2,}/).map((part) => part.replace(/^[^A-Za-z]+|[^A-Za-z.!?']+$/g, '').trim());
  return parts.find((part) => /[A-Za-z]{2}/.test(part)) ?? '';
}

function oneLine(text = '') {
  return text.replace(/\s*\n\s*/g, ' | ');
}

function scaler(png) {
  const s = png.height / 1440;
  return (v) => Math.round(v * s);
}

function rect(at, r) {
  return { x: at(r.x), y: at(r.y), width: at(r.width), height: at(r.height) };
}
