import assert from 'node:assert/strict';
import test from 'node:test';
import { ascensionCount, constellationCount, readAscension, readConstellations, readTalentLevels } from '../src/good/character-screen.js';
import { characterFrame } from './support/character-fixtures.js';

// The ascension, talent levels and constellations read from the pixels of real screenshots
// (test/fixtures/characters).

test('reads the ascension from the stars under the name', () => {
  const ascension = (name) => ascensionCount(readAscension(characterFrame(name, 'attributes')));
  assert.equal(ascension('manekina'), 0); // Level 20 / 20
  assert.equal(ascension('alyosha'), 1); // 40 / 40
  assert.equal(ascension('gorou'), 3); // 60 / 60, the OCR read "60 760"
  assert.equal(ascension('kokomi'), 4); // 70 / 70
  assert.equal(ascension('amber'), 5); // 80 / 80
  assert.equal(ascension('traveler'), 6); // 90 / 90
  assert.equal(ascension('furina-fading'), null); // the stars not drawn yet
});

const levels = (name) =>
  readTalentLevels(characterFrame(name, 'talents'))
    .map((t) => (t === null ? '-' : `${t.level ?? '?'}${t.boosted ? '+' : ''}`))
    .join(' ');

test('reads the talent levels: white, raised in cyan, and the rows without a level', () => {
  assert.equal(levels('escoffier'), '1 9 6 - - - -');
  assert.equal(levels('raiden'), '10 13+ 13+ - - - -');
  assert.equal(levels('tartaglia'), '2+ 6 6 - - - -');
  assert.equal(levels('traveler'), '1 1 2 - - - -');
});

test('reads two-digit levels the OCR read as one digit, and a lone "1" it dropped', () => {
  assert.equal(levels('xiangling'), '6 11+ 13+ - - - -'); // the OCR read "Lv. 1" for the 11
  assert.equal(levels('amber'), '1 1 4+ - - - -'); // the OCR read "Lv." for the first 1
});

test('reads a level under or beside a sparkle', () => {
  assert.equal(levels('skirk'), '10 10 6 - - - -'); // a sparkle on the "L" of the burst's "Lv. 6"
  assert.equal(levels('kokomi'), '2 2 2 - - - -'); // a cyan bubble on the "L" of the skill's "Lv. 2"
  assert.equal(levels('lanyan'), '1 1 1 - - - -'); // a sparkle right after the burst's "Lv. 1"
});

test('skips a talent without a level between the skill and the burst (an alternate sprint)', () => {
  assert.equal(levels('ayaka'), '10 10 - 13+ - - -');
});

test('reads a level below a talent name of two lines', () => {
  assert.equal(levels('xingqiu'), '3 6+ 6+ - - - -');
  assert.equal(levels('mizuki'), '1 1 1 - - - -');
});

test('finds no level while the talent list is still fading in', () => {
  assert.equal(levels('furina-fading'), '- - - - - - -');
});

test('counts the unlocked constellations', () => {
  const count = (name) => constellationCount(readConstellations(characterFrame(name, 'constellation')));
  assert.equal(count('escoffier'), 0);
  assert.equal(count('traveler'), 1);
  assert.equal(count('ayaka'), 3);
  assert.equal(count('raiden'), 6);
});

test('knows a character without constellations, and rings still fading in', () => {
  for (const name of ['manekina', 'manekin']) {
    const read = readConstellations(characterFrame(name, 'constellation'));
    assert.equal(read.none, true, name);
    assert.equal(constellationCount(read), 0, name);
  }
  const fading = readConstellations(characterFrame('furina-fading', 'constellation'));
  assert.equal(fading.none, false);
  assert.equal(constellationCount(fading), null);
});
