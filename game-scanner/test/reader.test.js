import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { PNG } from 'pngjs';
import { BASE_LAYOUT } from '../src/config.js';
import { parseCharacter } from '../src/good/character.js';
import { createOcr } from '../src/good/ocr.js';
import { parseArtifact, parseWeapon } from '../src/good/parse.js';
import { ReadingSaver } from '../src/reader.js';
import { characterImage } from './support/character-fixtures.js';

// Reading the screenshots as the scanners hand them over (reader.js): detail panels and character screens, read
// at once and kept only as what was read. test/fixtures/panels: two real detail panels.

const fixture = (name) => fs.readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', name));

// A screenshot as the scanner captures it: BGRA, at its place on the screen.
function captured(png, left = 0, top = 0) {
  const { width, height, data } = PNG.sync.read(png);
  const bgra = Buffer.alloc(data.length);
  for (let i = 0; i < data.length; i += 4) [bgra[i], bgra[i + 1], bgra[i + 2], bgra[i + 3]] = [data[i + 2], data[i + 1], data[i], 255];
  return { left, top, width, height, data: bgra, channels: 4, bgr: true };
}

let ocr;
test.before(async () => (ocr = await createOcr(2)));
test.after(() => ocr.terminate());

test('reads detail panels as they are captured, and writes no screenshot', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'reader-test-'));
  try {
    const seen = [];
    const saver = new ReadingSaver(ocr, { onRead: (info, reading, image) => seen.push([info.file, reading.kind, Boolean(image), info.path]) });
    const panel = BASE_LAYOUT.panel;
    saver.save(path.join(dir, 'images', '0001.png'), captured(fixture('panels/artifact.png'), panel.x, panel.y), { kind: 'artifacts', index: 1, file: 'images/0001.png' });
    saver.save(path.join(dir, 'images', '0002.png'), captured(fixture('panels/weapon.png'), panel.x, panel.y), { kind: 'weapons', index: 2, file: 'images/0002.png' });
    assert.deepEqual(await saver.close(), []);
    const { readings, failures } = await saver.finished();
    assert.deepEqual(failures, []);
    assert.deepEqual(fs.readdirSync(dir), [], 'nothing written');
    assert.deepEqual(seen.sort(), [
      ['images/0001.png', 'artifact', true, path.join(dir, 'images', '0001.png')],
      ['images/0002.png', 'weapon', true, path.join(dir, 'images', '0002.png')],
    ]);

    const artifact = parseArtifact(readings['images/0001.png']);
    assert.deepEqual(artifact.problems, []);
    assert.deepEqual(artifact.artifact, {
      setKey: 'WanderersTroupe',
      slotKey: 'circlet',
      level: 0,
      rarity: 5,
      mainStatKey: 'hp_',
      location: '',
      lock: false,
      substats: [
        { key: 'critDMG_', value: 5.4 },
        { key: 'atk', value: 14 },
        { key: 'hp', value: 209 },
      ],
      unactivatedSubstats: [{ key: 'def', value: 16 }],
      astralMark: false,
      elixirCrafted: false,
    });
    const weapon = parseWeapon(readings['images/0002.png']);
    assert.deepEqual(weapon.problems, []);
    assert.deepEqual(weapon.weapon, { key: 'AquaSimulacra', level: 90, ascension: 6, refinement: 1, location: 'Yelan', lock: true });
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test("puts a character's three tabs together as they arrive", async () => {
  const saver = new ReadingSaver(ocr);
  for (const tab of ['attributes', 'constellation', 'talents']) {
    saver.save(path.join('x', 'images', tab, '001.png'), captured(characterImage('ayaka', tab)), { kind: 'characters', tab, index: 1, file: `images/${tab}/001.png` });
  }
  const { readings } = await saver.finished();
  assert.deepEqual(Object.keys(readings), ['character-1']);
  const { character, problems } = parseCharacter(readings['character-1']);
  assert.deepEqual(problems, []);
  assert.deepEqual(character, { key: 'KamisatoAyaka', level: 90, constellation: 3, ascension: 6, talent: { auto: 10, skill: 10, burst: 10 } });
});

test('writes the screenshots too when asked to, and debug screenshots always', async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'reader-test-'));
  try {
    const saver = new ReadingSaver(ocr, { keepImages: true });
    const panel = captured(fixture('panels/weapon.png'));
    saver.save(path.join(dir, 'images', '0001.png'), panel, { kind: 'weapons', index: 1, file: 'images/0001.png' });
    saver.save(path.join(dir, 'debug', 'start.png'), panel);
    assert.deepEqual(await saver.close(), []);
    await saver.finished();
    assert.deepEqual(fs.readdirSync(dir).sort(), ['debug', 'images']);
    assert.deepEqual(fs.readdirSync(path.join(dir, 'images')), ['0001.png']);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
