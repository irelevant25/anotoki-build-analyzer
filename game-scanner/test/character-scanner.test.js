import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { CHARACTER_PASSES } from '../src/config.js';
import { StopSignal } from '../src/input.js';
import { redirectLog } from '../src/log.js';
import { runScan } from '../src/scanner.js';
import { characterFrame } from './support/character-fixtures.js';
import { FakeCharacterScreen, readingOf } from './support/fake-character-screen.js';

// The real scanner against a simulated character screen whose tabs show real screenshots fading in
// (test/support/fake-character-screen.js).

async function runCharacterScan(game) {
  const out = fs.mkdtempSync(path.join(os.tmpdir(), 'character-scan-test-'));
  const lines = [];
  redirectLog((level, line) => lines.push({ level, line }));
  try {
    await runScan(new StopSignal(), out, game.io());
    const dir = fs.readdirSync(out)[0];
    const json = JSON.parse(fs.readFileSync(path.join(out, dir, 'scan.json'), 'utf8'));
    const problems = lines.filter((l) => l.level === 'warn' || l.level === 'error').map((l) => l.line.replace(/^\S+ \S+ +/, ''));
    const saved = (tab) => game.saved.filter((s) => s.file.includes(path.join('images', tab)));
    return { json, problems, saved };
  } finally {
    redirectLog(null);
    fs.rmSync(out, { recursive: true, force: true });
  }
}

const order = (count, start = 0) => Array.from({ length: count }, (_, i) => (start + i) % count);

// What a fully drawn tab of a fixture reads.
const fullReadings = new Map();
function fullReading(name, tab) {
  const key = `${name}-${tab}`;
  if (!fullReadings.has(key)) fullReadings.set(key, readingOf(tab, characterFrame(name, tab)));
  return fullReadings.get(key);
}

// Every screenshot of a tab shows the header's character, and the export will read it as if fully drawn:
// Attributes (read with OCR) drawn in full, Constellation and Talents reading what the drawn tab reads.
function assertReadable(game, saved, tab) {
  for (const s of saved) {
    assert.equal(s.content, s.header, `${s.file}: the data belongs to the header's character`);
    if (tab === 'attributes') assert.ok(s.alpha >= 0.95, `${s.file}: only ${s.alpha} faded in`);
    else assert.equal(s.reading, fullReading(game.fixture(s.content), tab), `${s.file}: read before the tab was ready`);
  }
}

test('goes through every character on the Attributes, Constellation and Talents tabs', async () => {
  const game = new FakeCharacterScreen(25);
  const { json, problems, saved } = await runCharacterScan(game);
  assert.deepEqual(problems, []);
  for (const tab of CHARACTER_PASSES) {
    assert.equal(json.passes[tab].length, 25, tab);
    assert.deepEqual(
      saved(tab).map((s) => s.header),
      order(25),
      `${tab}: every character once, in order`,
    );
    assertReadable(game, saved(tab), tab);
  }
});

test('starts with the character on screen and goes round to it', async () => {
  const game = new FakeCharacterScreen(12, { start: 7 });
  const { problems, saved } = await runCharacterScan(game);
  assert.deepEqual(problems, []);
  for (const tab of CHARACTER_PASSES) {
    assert.deepEqual(
      saved(tab).map((s) => s.header),
      order(12, 7),
      tab,
    );
    assertReadable(game, saved(tab), tab);
  }
});

test('opens Attributes first when started on another tab, and every first screenshot waits for the fade-in', async () => {
  const game = new FakeCharacterScreen(6, { tab: 'talents', fadeMs: 500 });
  const { problems, saved } = await runCharacterScan(game);
  assert.deepEqual(problems, []);
  for (const tab of CHARACTER_PASSES) {
    assert.deepEqual(
      saved(tab).map((s) => s.content),
      order(6),
      tab,
    );
    assertReadable(game, saved(tab), tab);
  }
});

test('takes a character that looks like the previous one (the two Manekins) after a wait', async () => {
  const game = new FakeCharacterScreen(4, { characters: ['traveler', 'manekina', 'manekin', 'ayaka'] });
  const { problems, saved } = await runCharacterScan(game);
  assert.deepEqual(problems, []);
  for (const tab of CHARACTER_PASSES) {
    assert.deepEqual(
      saved(tab).map((s) => s.content),
      order(4),
      tab,
    );
    assertReadable(game, saved(tab), tab);
  }
});

test('takes a tab that never becomes readable after a while, says so and goes on', async () => {
  const game = new FakeCharacterScreen(3, { characters: ['traveler', 'furina-fading', 'escoffier'] });
  const { json, problems, saved } = await runCharacterScan(game);
  for (const tab of CHARACTER_PASSES) {
    assert.equal(json.passes[tab].length, 3, tab);
    assert.deepEqual(
      saved(tab).map((s) => s.content),
      order(3),
      tab,
    );
  }
  assert.deepEqual(
    problems,
    CHARACTER_PASSES.map((tab) => `${tab} #2: never ready (nothing readable, or always moving); taken as it is`),
  );
});

test('stops after the first tab when E does not go back to the first character', async () => {
  const game = new FakeCharacterScreen(8, { wrap: false });
  const { json, problems, saved } = await runCharacterScan(game);
  assert.deepEqual(
    saved('attributes').map((s) => s.header),
    order(8),
  );
  assert.equal(json.passes.constellation, undefined);
  assert.deepEqual(problems, ['E stopped at the last character instead of going back to the first one, so the other tabs could not be scanned.']);
});
