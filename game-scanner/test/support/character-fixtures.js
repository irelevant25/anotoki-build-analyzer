import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { frameFromPng } from '../../src/frame.js';
import { readPng } from '../../src/good/image.js';

// Screenshots of the character screen cut from a real scan (test/fixtures/characters/<name>-<tab>.png). Only
// the pixels the readers and the scanner look at are kept - the gold header, the name and level, the rings
// around the constellation icons, the light text of the constellations and talents -, the rest is black; they
// read exactly like the full screenshots did. "furina-fading" was taken while the tabs were still fading in.
// The names a player gave are not the player's own: the Traveler's "ivantelle", the Manekina's "Ivantelle" and
// the Manekin's "Taren" were put together from glyphs of the same screenshots, where the names stood - the
// letters after the Manekina's wider capital I one pixel further right than the Traveler's (two in the white
// name), as two names a capital letter apart are on screen.

const dir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'fixtures', 'characters');

export const TABS = ['attributes', 'constellation', 'talents'];

export function characterImage(name, tab) {
  return fs.readFileSync(path.join(dir, `${name}-${tab}.png`));
}

export function characterImages(name) {
  return Object.fromEntries(TABS.map((tab) => [tab, characterImage(name, tab)]));
}

export function characterFrame(name, tab) {
  return frameFromPng(readPng(characterImage(name, tab)));
}
