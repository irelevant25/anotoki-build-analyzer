import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { StopSignal } from '../src/input.js';
import { redirectLog } from '../src/log.js';
import { Scan } from '../src/scanner.js';
import { FakeGame } from './support/fake-game.js';

// The real scanner against a simulated inventory (test/support/fake-game.js).

async function runScan(game) {
  const out = fs.mkdtempSync(path.join(os.tmpdir(), 'scanner-test-'));
  const lines = [];
  redirectLog((level, line) => lines.push({ level, line }));
  try {
    const scan = new Scan(new StopSignal(), out, game.io());
    await scan.run();
    const problems = lines.filter((l) => l.level === 'warn' || l.level === 'error').map((l) => l.line.replace(/^\S+ \S+ +/, ''));
    return { scan, problems, saved: game.saved.filter((s) => s.file.includes('images')).map((s) => s.panel) };
  } finally {
    redirectLog(null);
    fs.rmSync(out, { recursive: true, force: true });
  }
}

let nextId = 1000;
const distinct = () => {
  const id = nextId++;
  return { look: id, panel: id };
};
const same =
  (id, extra = {}) =>
  () => ({ look: id, panel: id, ...extra });
const inventory = (...groups) => groups.flatMap(([count, make]) => Array.from({ length: count }, make));

test('scans every weapon once and in order, through 8 rows of the same weapon', async () => {
  const items = inventory([13, distinct], [64, same(7)], [23, distinct]);
  const game = new FakeGame(items);
  const { scan, problems, saved } = await runScan(game);
  assert.deepEqual(problems, []);
  assert.deepEqual(
    saved,
    items.map((i) => i.panel),
  );
  assert.equal(game.clicks, items.length);
  assert.deepEqual(
    scan.manifest.filter((m) => m.waitMs >= 1000),
    [],
    'no item waited for the timeout',
  );
});

test('scans a list where every item is the same, without waiting for timeouts', async () => {
  // The panel never changes, so only the selection frame shows the clicks arrive - also on rows scanned
  // just above where the grid fades out.
  const items = inventory([400, same(3)]);
  const game = new FakeGame(items);
  const { scan, problems, saved } = await runScan(game);
  assert.deepEqual(problems, []);
  assert.equal(saved.length, items.length);
  assert.deepEqual(
    scan.manifest.filter((m) => m.waitMs >= 1000),
    [],
  );
});

test('handles "New" badges on identical weapons without waiting for the timeout', async () => {
  const items = inventory([10, distinct], [30, same(8, { isNew: true })], [12, same(9)], [7, distinct]);
  const game = new FakeGame(items);
  const { scan, problems, saved } = await runScan(game);
  assert.deepEqual(problems, []);
  assert.deepEqual(
    saved,
    items.map((i) => i.panel),
  );
  assert.deepEqual(
    scan.manifest.filter((m) => m.waitMs >= 1000),
    [],
  );
});

test('clicks a card again when the first click is lost', async () => {
  const items = inventory([20, distinct], [20, same(5)], [5, distinct]);
  const game = new FakeGame(items, { dropClicks: [12, 30] }); // one distinct weapon, one identical one
  const { problems, saved } = await runScan(game);
  assert.deepEqual(problems, []);
  assert.deepEqual(
    saved,
    items.map((i) => i.panel),
  );
  assert.equal(game.clicks, items.length + 2);
});

test('scans artifacts down to a partly filled last row', async () => {
  const items = inventory([101, distinct]);
  const game = new FakeGame(items, { tab: 'artifacts' });
  const { problems, saved } = await runScan(game);
  assert.deepEqual(problems, []);
  assert.deepEqual(
    saved,
    items.map((i) => i.panel),
  );
});

test('ends a list that fills its last row and cannot scroll further', async () => {
  const items = inventory([88, distinct]); // 11 full rows
  const game = new FakeGame(items);
  const { problems, saved } = await runScan(game);
  assert.deepEqual(problems, []);
  assert.deepEqual(
    saved,
    items.map((i) => i.panel),
  );
});

test('ends a short list that does not scroll at all', async () => {
  const items = inventory([32, distinct]); // 4 full rows, all on the first screen
  const game = new FakeGame(items);
  const { problems, saved } = await runScan(game);
  assert.deepEqual(
    saved,
    items.map((i) => i.panel),
  );
  assert.deepEqual(problems, ['The first scroll did not move the list. If the list is longer than one screen, scrolling does not reach the game.']);
});

test('stops the scan when clicks never reach the game', async () => {
  const items = inventory([40, distinct]);
  const game = new FakeGame(items, { dropClicks: Array.from({ length: 100 }, (_, i) => i + 1) });
  const { problems, saved } = await runScan(game);
  assert.ok(
    problems.some((p) => p.includes('Clicks do not seem to reach the game')),
    problems.join('\n'),
  );
  assert.ok(saved.length <= 4, `saved ${saved.length}`);
});
