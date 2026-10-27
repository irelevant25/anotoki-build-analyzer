import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// The English names of sets, weapons and characters, which the OCR results are matched against: the OCR
// sometimes reads a letter wrong ("Aqua" as "Agua", "Xingqiu" as "Xinggiu"). The program fetches the current
// lists when it starts (catalog.js, useNames()); until then, and without a connection, the copy in
// data/names.json.

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
export const BUNDLED_NAMES = JSON.parse(fs.readFileSync(path.join(root, 'data', 'names.json'), 'utf8'));

const normal = (s) => s.toLowerCase().replace(/[^a-z0-9]/g, '');
const indexOf = (lists) => Object.fromEntries(['sets', 'weapons', 'characters'].map((kind) => [kind, lists[kind].map((name) => ({ name, key: normal(name) }))]));
let INDEX = indexOf(BUNDLED_NAMES);

// Matches against these lists from now on: { sets, weapons, characters } (arrays of names).
export function useNames(lists) {
  INDEX = indexOf(lists);
}

// The listed name the OCR text stands for: the same letters (ignoring case, spaces and punctuation), or a
// letter off (two for names of 10+ letters) when exactly one listed name is that close.
// Returns { name, known, corrected }; a name that isn't listed (new in the game) is kept as read.
export function knownName(kind, text) {
  const key = normal(text);
  const list = INDEX[kind];
  const exact = key && list.find((e) => e.key === key);
  if (exact) return { name: exact.name, known: true, corrected: false };
  const limit = key.length < 10 ? 1 : 2;
  let best = null;
  let bestDistance = Infinity;
  let tied = false;
  for (const e of key ? list : []) {
    if (Math.abs(e.key.length - key.length) > limit) continue;
    const d = distance(key, e.key);
    if (d < bestDistance) [best, bestDistance, tied] = [e, d, false];
    else if (d === bestDistance) tied = true;
  }
  if (best && bestDistance <= limit && !tied) return { name: best.name, known: true, corrected: true };
  return { name: text, known: false, corrected: false };
}

// The GOOD key of a name the player chose (customNames: { shown name: key }). Case counts first, as two
// characters can differ only in it ("ivantelle" the Traveler, "Ivantelle" a Manekin); ignoring case only when
// that leaves a single custom name. undefined for any other name.
export function customName(shown, customNames) {
  if (!shown) return undefined;
  if (Object.hasOwn(customNames, shown)) return customNames[shown];
  const same = Object.keys(customNames).filter((name) => name.toLowerCase() === shown.toLowerCase());
  return same.length === 1 ? customNames[same[0]] : undefined;
}

// Edit distance: the fewest single-letter insertions, deletions and replacements that turn a into b.
export function distance(a, b) {
  const row = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    let prev = row[0];
    row[0] = i;
    for (let j = 1; j <= b.length; j++) {
      const tmp = row[j];
      row[j] = Math.min(row[j] + 1, row[j - 1] + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
      prev = tmp;
    }
  }
  return row[b.length];
}
