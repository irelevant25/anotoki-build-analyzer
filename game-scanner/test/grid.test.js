import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { PNG } from 'pngjs';
import { frameFromPng } from '../src/frame.js';
import { cellHasCard, detectRows, detectTab, gridGray, isSelected, maxStepNotches, measureShift, scaleLayout, stepWindow } from '../src/grid.js';

// Real 2560x1440 screenshots of the inventory (test/fixtures/inventory: the detail panel and everything below the grid
// blacked out): the top of the list and its end.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const load = (name) => frameFromPng(PNG.sync.read(fs.readFileSync(path.join(root, 'test', 'fixtures', 'inventory', name))));
const first = load('first_artifacts.png');
const last = load('last_artifacts.png');
const layout = scaleLayout(2560, 1440);

const cards = (frame, row) => [...Array(layout.columns).keys()].map((c) => (cellHasCard(frame, layout, row, c) ? '#' : '.')).join('');

test('finds the four full rows at the top of the list', () => {
  const rows = detectRows(first, layout).filter((r) => r.fullyVisible);
  assert.equal(rows.length, 4);
  rows.forEach((r, i) => assert.ok(Math.abs(r.labelBottom - (440 + i * 234)) <= 2, `row ${i}: ${r.labelBottom}`));
  for (const r of rows) assert.equal(cards(first, r), '########');
});

test('finds the partly empty last row at the end of the list', () => {
  const rows = detectRows(last, layout);
  assert.equal(rows[0].fullyVisible, false, 'the half-hidden row at the top is not scanned');
  const full = rows.filter((r) => r.fullyVisible);
  assert.deepEqual(
    full.map((r) => cards(last, r)),
    ['########', '########', '########', '######..'],
  );
});

test('tells the Artifacts and Weapons tabs apart', () => {
  assert.equal(detectTab(first, 1), 'artifacts');
  assert.equal(detectTab(last, 1), 'artifacts');
  assert.equal(detectTab(load('first_weapons.png'), 1), 'weapons');
});

test('finds the weapon rows, which start higher than the artifact rows', () => {
  const weapons = scaleLayout(2560, 1440, 'weapons');
  const wCards = (frame, row) => [...Array(8).keys()].map((c) => (cellHasCard(frame, weapons, row, c) ? '#' : '.')).join('');
  const top = load('first_weapons.png');
  const topRows = detectRows(top, weapons).filter((r) => r.fullyVisible);
  assert.equal(topRows.length, 4);
  assert.ok(Math.abs(topRows[0].top - 160) <= 4, `first row starts at ${topRows[0].top}`);
  const end = load('last_weapons.png');
  assert.deepEqual(
    detectRows(end, weapons)
      .filter((r) => r.fullyVisible)
      .map((r) => wCards(end, r)),
    ['########', '########', '########', '######..'],
  );
});

// A gray copy of the grid as it would look after scrolling `shift` pixels (new content at the bottom is noise).
function scrolled(gray, shift) {
  const out = { ...gray, data: new Uint8Array(gray.data.length) };
  for (let y = 0; y < gray.h; y++) {
    for (let x = 0; x < gray.w; x++) {
      const from = y + shift;
      out.data[y * gray.w + x] = from >= 0 && from < gray.h ? gray.data[from * gray.w + x] : (x * 7 + y * 13) & 255;
    }
  }
  return out;
}

test('measures a full-row scroll, a short last scroll and no scroll', () => {
  const gray = gridGray(first, layout);
  for (const shift of [234, 233, 236, 120, 32, 0]) {
    const m = measureShift(gray, scrolled(gray, shift), layout);
    assert.equal(m.shift, shift, JSON.stringify(m));
    assert.ok(m.runnerUp - m.error > 4, `clear match for ${shift}: ${JSON.stringify(m)}`);
  }
});

test('measures a one-notch correction up and down', () => {
  const gray = gridGray(last, layout);
  for (const shift of [23, -23]) {
    const m = measureShift(gray, scrolled(gray, shift), layout, { minShift: -117, maxShift: 117 });
    assert.equal(m.shift, shift);
  }
});

// A copy of a frame as it would look after the grid scrolled `shift` pixels up (new rows at the bottom are noise).
function scrolledFrame(frame, shift) {
  const data = Buffer.alloc(frame.data.length);
  const rowBytes = frame.width * frame.channels;
  for (let y = 0; y < frame.height; y++) {
    const from = y + shift;
    if (from >= 0 && from < frame.height) frame.data.copy(data, y * rowBytes, from * rowBytes, (from + 1) * rowBytes);
    else for (let i = 0; i < rowBytes; i++) data[y * rowBytes + i] = (i * 7 + y * 13) & 255;
  }
  return { ...frame, data };
}

const selectedCards = (frame, layout) =>
  detectRows(frame, layout)
    .filter((r) => r.fullyVisible)
    .map((r) => [...Array(8).keys()].filter((c) => isSelected(frame, layout, r, c) === true));

test('sees the white frame of the clicked card', () => {
  const weapons = scaleLayout(2560, 1440, 'weapons');
  // 80 Alley Hunters; the second card of the top row was just clicked.
  assert.deepEqual(selectedCards(load('identical_weapons.png'), weapons), [[1], [], [], []]);
  // A "New" badge covers the top of the frame of the 7th card in the top row (which sits in the grid's fade).
  assert.deepEqual(selectedCards(load('new_badge_selected.png'), weapons), [[6], [], [], []]);
  // The first artifact, selected when the inventory opens.
  assert.deepEqual(selectedCards(first, layout), [[0], [], [], []]);
});

// Rows of identical weapons: the grid repeats every row (234 px), copied from one row of the fixture.
function repeatingRows(frame, layout, row) {
  const out = { ...frame, data: Buffer.alloc(frame.data.length) };
  const rowBytes = frame.width * frame.channels;
  const pitch = Math.round(layout.rowPitch);
  for (let y = 0; y < frame.height; y++) {
    const from = row.top - 17 + ((((y - row.top + 17) % pitch) + pitch) % pitch);
    frame.data.copy(out.data, y * rowBytes, from * rowBytes, (from + 1) * rowBytes);
  }
  return out;
}

test('measures 8-notch scroll steps exactly even when every row looks the same', () => {
  const weapons = scaleLayout(2560, 1440, 'weapons');
  assert.equal(maxStepNotches(weapons), 8);
  const fixture = load('identical_weapons.png');
  const frame = repeatingRows(fixture, weapons, detectRows(fixture, weapons).filter((r) => r.fullyVisible)[2]);
  // A full step (192 px), less near the end of the list, nothing at its end.
  for (const shift of [192, 150, 24, 6, 0]) {
    const m = measureShift(gridGray(frame, weapons), gridGray(scrolledFrame(frame, shift), weapons), weapons, stepWindow(weapons, 8));
    assert.equal(m.shift, shift, JSON.stringify(m));
  }
  // (A scroll of a whole row or more, 240 px, is the same picture as 6 px here; that's why steps stay under a row.)
});
