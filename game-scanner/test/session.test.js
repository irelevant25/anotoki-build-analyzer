import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { BUNDLED_NAMES } from '../src/good/names.js';
import { createOcr } from '../src/good/ocr.js';
import { StopSignal } from '../src/input.js';
import { redirectLog } from '../src/log.js';
import { Session } from '../src/session.js';
import { FakeCharacterScreen } from './support/fake-character-screen.js';
import { FakeGame } from './support/fake-game.js';

// The program as the player meets it (session.js), against simulated screens: a scripted console answers its
// questions and presses the hotkey on the screen the script shows; the scans and the readers are the real ones.

let shared;
const ocr = async () => ({ ...(shared ??= await createOcr(2)), terminate: async () => {} });
test.after(() => shared?.terminate());

// The game's screen as the script switches it: a simulated inventory or character screen, or nothing scannable.
function world() {
  let current = null;
  const ios = new Map();
  const call =
    (name) =>
    (...args) =>
      ios.get(current)[name](...args);
  return {
    io: Object.fromEntries(['now', 'sleep', 'screenSize', 'grab', 'click', 'scroll', 'pressKey'].map((name) => [name, call(name)])),
    show(game) {
      current = game;
      if (!ios.has(game)) ios.set(game, game.io());
    },
    get current() {
      return current;
    },
  };
}

// A screen showing nothing the scanner knows, `size` big.
const nothing = (size = { width: 2560, height: 1440 }) => ({
  t: 0,
  io() {
    return {
      now: () => this.t,
      sleep: async (ms) => void (this.t += ms),
      screenSize: async () => size,
      grab: async () => ({ left: 0, top: 0, width: size.width, height: size.height, data: Buffer.alloc(size.width * size.height * 4), channels: 4, bgr: true }),
    };
  },
});

function fakeHotkey() {
  const listeners = new Set();
  return {
    label: 'Ctrl+S',
    on(fn) {
      listeners.add(fn);
      return () => listeners.delete(fn);
    },
    press() {
      for (const fn of [...listeners]) fn();
    },
  };
}

// The console, answering from a script: a string for a question, true / false for a yes / no question, and
// while the program waits for the hotkey either a command ('g', 'q') or a function (show a screen, press it).
function scriptedUi(steps) {
  const lines = [];
  const take = (what, prompt) => {
    if (!steps.length) throw new Error(`the script has nothing left for ${what}: ${prompt}`);
    return steps.shift();
  };
  const say =
    (prefix) =>
    (text = '') =>
      lines.push(prefix + text);
  return {
    lines,
    print: say(''),
    heading: say(''),
    ok: say('OK '),
    warn: say('WARN '),
    error: say('ERROR '),
    beep: () => {},
    async ask(question) {
      lines.push(question);
      const answer = take('a question', question);
      assert.equal(typeof answer, 'string', `a string for "${question}"`);
      return answer;
    },
    async confirm(question) {
      lines.push(question);
      const answer = take('a yes / no question', question);
      assert.equal(typeof answer, 'boolean', `yes or no for "${question}"`);
      return answer;
    },
    commands(fn) {
      const step = take('the wait for the hotkey', '');
      if (typeof step !== 'function' && typeof step !== 'string') throw new Error(`the program waits for the hotkey, the script has ${step}:\n${lines.join('\n')}`);
      setImmediate(() => (typeof step === 'function' ? step() : fn(step)));
      return () => {};
    },
    async waitKey(prompt) {
      lines.push(prompt);
    },
    close() {},
  };
}

const names = ['ivantelle', '', 'Taren', 'Ivantelle']; // the Traveler, the Wanderer (kept), the Manekin, the Manekina

async function runSession(steps, { w = world(), first = nothing(), hotkey = fakeHotkey(), portable = true, ...deps } = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'session-test-'));
  const paths = { scans: path.join(dir, 'work', 'scans'), output: path.join(dir, 'downloads'), work: path.join(dir, 'work') };
  const ui = scriptedUi(steps);
  const clipboard = [];
  const opened = [];
  const log = [];
  w.show(first);
  redirectLog((level, line) => log.push({ level, line }));
  try {
    const session = new Session({
      ui,
      io: w.io,
      hotkey,
      createOcr: ocr,
      fetchNames: async () => BUNDLED_NAMES,
      isAdmin: async () => true,
      clipboard: async (text) => clipboard.push(text) > 0,
      openUrl: async (url) => opened.push(url),
      readTitle: async () => ({ weapons: 'Weapons', artifacts: 'Artifacts' })[w.current?.tab] ?? '',
      paths,
      portable,
      version: 'test',
      ...deps,
    });
    const code = await session.run();
    assert.deepEqual(steps, [], 'the script was used up');
    const left = { goodFile: Boolean(session.goodFile && fs.existsSync(session.goodFile)), work: fs.existsSync(paths.work) };
    return { code, lines: ui.lines, clipboard, opened, session, log, left };
  } finally {
    redirectLog(null);
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

const press = (w, hotkey, game) => () => {
  w.show(game);
  hotkey.press();
};
let nextId = 5000;
const items = (count) => Array.from({ length: count }, () => ({ look: nextId, panel: nextId++ }));

test('scans the three screens, makes the GOOD file, copies it, and deletes everything once it is imported', async () => {
  const w = world();
  const hotkey = fakeHotkey();
  const steps = [
    ...names,
    press(w, hotkey, new FakeGame(items(20), { tab: 'weapons' })),
    press(w, hotkey, new FakeGame(items(12), { tab: 'artifacts' })),
    press(w, hotkey, new FakeCharacterScreen(4, { characters: ['traveler', 'manekina', 'manekin', 'ayaka'], realHeaders: true })),
    true, // Everything is scanned. Make the GOOD file now?
    true, // Open the import page in your browser now?
    '', // Press Enter when you are done.
  ];
  const { code, lines, clipboard, opened, left } = await runSession(steps, { w, hotkey });
  assert.equal(code, 0);
  assert.deepEqual(left, { goodFile: false, work: false });
  const good = JSON.parse(clipboard[0]);
  assert.equal(good.format, 'GOOD');
  assert.deepEqual(good.weapons, []); // the simulated panels are no real items: read as other items, skipped
  assert.deepEqual(good.artifacts, []);
  assert.deepEqual(
    good.characters.map((c) => c.key),
    ['TravelerCryo', 'Manekina', 'Manekin', 'KamisatoAyaka'],
  );
  assert.deepEqual(good.characters[0], { key: 'TravelerCryo', level: 90, constellation: 1, ascension: 6, talent: { auto: 1, skill: 1, burst: 2 } });
  assert.deepEqual(opened, ['https://builder.anotoki.eu/import']);
  assert.ok(
    lines.some((l) => l.startsWith('OK Your GOOD file: ')),
    lines.join('\n'),
  );
  assert.ok(lines.includes('OK Characters: 4 characters read.'), lines.join('\n'));
  assert.ok(lines.includes('Deleted the GOOD file and everything this program saved.'), lines.join('\n'));
});

test('says how to run as administrator, and stops', async () => {
  const { code, lines } = await runSession([], { isAdmin: async () => false });
  assert.equal(code, 1);
  assert.ok(lines.includes('ERROR This window is not running as administrator.'), lines.join('\n'));
  assert.ok(
    lines.some((l) => l.includes('open PowerShell as administrator')),
    lines.join('\n'),
  );
});

test('says the screen must be 16:9, and stops', async () => {
  const { code, lines } = await runSession([], { first: nothing({ width: 2560, height: 1600 }) });
  assert.equal(code, 1);
  assert.ok(lines.includes('ERROR Your main screen is 2560x1600, which is not 16:9.'), lines.join('\n'));
});

test('uses the names that came with the program when the current ones cannot be fetched', async () => {
  const { code, lines } = await runSession([...names, 'q', true], {
    fetchNames: async () => {
      throw new Error('offline');
    },
  });
  assert.equal(code, 0);
  assert.ok(
    lines.some((l) => l.startsWith("WARN Could not fetch the game's current names (offline)")),
    lines.join('\n'),
  );
});

test('explains a screen it cannot scan: nothing open, another inventory tab, a game not in English', async () => {
  const w = world();
  const hotkey = fakeHotkey();
  let title = '';
  const steps = [
    ...names,
    press(w, hotkey, nothing()),
    () => {
      title = 'Food';
      hotkey.press();
    },
    () => {
      title = 'Armas';
      w.show(new FakeGame(items(8), { tab: 'weapons' }));
      hotkey.press();
    },
    'q',
    true,
  ];
  const { lines } = await runSession(steps, { w, hotkey, readTitle: async () => title });
  for (const expected of [
    'ERROR Neither the inventory nor the character screen is open.',
    'ERROR The Food tab of the inventory is open.',
    'ERROR The game does not seem to be in English.',
    `The tab's name reads "Armas" instead of "Weapons". The scanner reads the English game only:`,
  ]) {
    assert.ok(lines.includes(expected), `${expected}\n---\n${lines.join('\n')}`);
  }
  assert.ok(!lines.some((l) => l.startsWith('Scanning')), 'nothing was scanned');
});

// A simulated inventory where the hotkey is pressed at the given click.
function stoppedAt(clicks, hotkey, game) {
  const click = game.click.bind(game);
  game.click = (x, y) => {
    const result = click(x, y);
    if (game.clicks === clicks) hotkey.press();
    return result;
  };
  return game;
}

test('a scan stopped with the hotkey counts as unfinished, and the GOOD file warns of what is missing', async () => {
  const w = world();
  const hotkey = fakeHotkey();
  const steps = [
    ...names,
    press(w, hotkey, stoppedAt(10, hotkey, new FakeGame(items(40), { tab: 'weapons' }))),
    'g',
    false, // Make it all the same? (no complete weapons or artifacts scan)
    'q',
    true,
  ];
  const { code, lines, clipboard } = await runSession(steps, { w, hotkey });
  assert.equal(code, 0);
  assert.ok(
    lines.some((l) => /^WARN Weapons: 0 weapons read before you stopped it - scan again for all \(\d+ other items skipped\)\.$/.test(l)),
    lines.join('\n'),
  );
  assert.ok(lines.includes('WARN The file would hold no complete scan of the weapons or the artifacts. builder.anotoki.eu replaces all your weapons and artifacts with'), lines.join('\n'));
  assert.deepEqual(clipboard, []);
});

test('a stopped scan does not replace a complete one', async () => {
  const w = world();
  const hotkey = fakeHotkey();
  const steps = [...names, press(w, hotkey, new FakeGame(items(16), { tab: 'artifacts' })), press(w, hotkey, stoppedAt(4, hotkey, new FakeGame(items(16), { tab: 'artifacts' }))), 'q', true];
  const { lines, session } = await runSession(steps, { w, hotkey });
  assert.equal(session.done.artifacts.result.ended, 'complete');
  assert.ok(lines.includes('WARN Kept the earlier complete scan of the artifacts.'), lines.join('\n'));
});

test('a development run keeps the GOOD file and the scans', async () => {
  const w = world();
  const hotkey = fakeHotkey();
  const steps = [...names, press(w, hotkey, new FakeGame(items(8), { tab: 'weapons' })), 'g', true, false];
  const { left, lines } = await runSession(steps, { w, hotkey, portable: false });
  assert.deepEqual(left, { goodFile: true, work: true });
  assert.ok(
    lines.some((l) => l.startsWith('The GOOD file and the scans stay where they are')),
    lines.join('\n'),
  );
});

test('stops a character scan whose first characters name no element in English', () => {
  const session = new Session({ customNames: {} });
  const signal = new StopSignal();
  const watch = session.watchReadings(signal);
  for (const header of ['Hydro / Furina', 'Geo / Navia']) watch.onRead({ kind: 'characters', tab: 'attributes' }, { header });
  watch.onRead({ kind: 'characters', tab: 'constellation' }, { constellationHeader: '???' });
  assert.equal(signal.stopped, false);

  const other = new Session({ customNames: {} });
  const stop = new StopSignal();
  const unreadable = other.watchReadings(stop);
  // (a game in Spanish or Portuguese passes: Hidro, Crio are a letter off - and its characters read the same)
  for (const header of ['Гидро / Фурина', '水 / 芙宁娜', 'Пиро / Ху Тао']) unreadable.onRead({ kind: 'characters', tab: 'attributes' }, { header });
  assert.equal(stop.stopped, true);
  assert.equal(unreadable.notEnglish, true);
});
