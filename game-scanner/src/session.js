import fs from 'node:fs';
import path from 'node:path';
import { CharacterScan } from './character-scanner.js';
import { APP, BASE_HEIGHT, CHARACTER_PASSES, INVENTORY_TITLE, NAMED_CHARACTERS } from './config.js';
import { encodePng, frameFromPng, imageFromFrame } from './frame.js';
import { headerElement } from './good/character.js';
import { buildGood, characterItems, reportText } from './good/good-file.js';
import { binarize } from './good/image.js';
import { useNames } from './good/names.js';
import { closest, parseArtifact, parseWeapon } from './good/parse.js';
import { StopSignal } from './input.js';
import { log, stamp } from './log.js';
import { ReadingSaver } from './reader.js';
import { detectScreen, Scan } from './scanner.js';

// The program as the player sees it: checks that it can work (administrator, the screen), asks for the names
// of the characters the player named, then waits for the hotkey on one of the three screens - weapons,
// artifacts, characters, in any order, each read while it is scanned -, says what is done and what is left,
// makes the GOOD file (copied to the clipboard too) and, once it is imported, deletes what it made.
//
// deps: ui (ui.js), io (the game: scanner.js REAL_IO), hotkey { label, on(fn) → off }, createOcr(workers),
// fetchNames(), isAdmin(), clipboard(text) → true | false, openUrl(url), readTitle(frame, scale) → the open
// inventory tab's name, paths { scans, output, work? }, portable (delete everything at the end: the GOOD file
// and paths.work), keepImages, version, closeLog().

const KINDS = ['weapons', 'artifacts', 'characters'];
const LABEL = { weapons: 'Weapons', artifacts: 'Artifacts', characters: 'Characters' };
const HOW = {
  weapons: 'in the game press B (Inventory), open the Weapons tab, scroll it to the top',
  artifacts: 'in the game press B (Inventory), open the Artifacts tab, scroll it to the top',
  characters: 'in the game press C (Characters) - any character, any tab',
};
// The inventory's tabs, as their names show at its top left.
const INVENTORY_TABS = ['Weapons', 'Artifacts', 'Character Development Items', 'Food', 'Materials', 'Gadget', 'Quest', 'Precious Items', 'Furnishings'];
// The first characters read must name their element in English, or the game isn't in English.
const LANGUAGE_CHECK = 3;

export class Session {
  constructor(deps) {
    Object.assign(this, { readTitle: readInventoryTitle, keepImages: false, portable: false, closeLog: async () => {} }, deps);
    this.done = {}; // kind → the scan kept for the GOOD file
    this.goodFile = null;
  }

  // Returns the exit code.
  async run() {
    const { ui } = this;
    ui.heading(`${APP.title}  ${this.version}`);
    ui.print('It reads your weapons, artifacts and characters in the game and makes a GOOD file to import on builder.anotoki.eu.');
    ui.print();
    if (!(await this.setupOk())) return this.finish(1);
    const ocr = this.createOcr(APP.ocrWorkers);
    ocr.catch(() => {});
    await this.loadNames();
    this.customNames = await this.askNames();
    try {
      this.ocr = await ocr;
    } catch (e) {
      log.error(`The OCR could not start: ${e.stack || e}`);
      ui.error(`The text reader could not start: ${e.message}`);
      return this.finish(1);
    }
    while (true) {
      this.showChecklist();
      const action = await this.nextAction();
      if (action === 'quit') {
        if (await ui.confirm('Quit without a GOOD file?', false)) return this.finish(0);
        continue;
      }
      if (action === 'good') {
        if (await this.makeGood()) return this.finish(0);
        continue;
      }
      await this.scanOnce();
      if (KINDS.every((k) => this.done[k]?.result.ended === 'complete')) {
        ui.print();
        if ((await ui.confirm('Everything is scanned. Make the GOOD file now?')) && (await this.makeGood())) return this.finish(0);
      }
    }
  }

  async setupOk() {
    const { ui } = this;
    if (!(await this.isAdmin())) {
      ui.error('This window is not running as administrator.');
      ui.print('The game runs as administrator, so Windows blocks the clicks and key presses of programs that are not.');
      ui.print(
        this.portable
          ? 'Close this window, open PowerShell as administrator (right-click the Start button, then "Terminal (Admin)" or "Windows PowerShell (Admin)") and paste the command again.'
          : 'Start the terminal as administrator and run the program again.',
      );
      return false;
    }
    const { width, height } = await this.io.screenSize();
    log.info(`Main screen ${width}x${height}`);
    if (Math.abs(width / height - 16 / 9) > 0.01) {
      ui.error(`Your main screen is ${width}x${height}, which is not 16:9.`);
      ui.print('The scanner reads the game in full screen on the main screen at a 16:9 resolution, such as 1920x1080, 2560x1440 or 3840x2160. Other screen shapes are not supported.');
      return false;
    }
    if (height < 1080) ui.warn(`Your screen is ${width}x${height}: the scanner reads best at 1920x1080 or more, and may misread smaller text.`);
    return true;
  }

  async loadNames() {
    try {
      const names = await this.fetchNames();
      useNames(names);
      log.info(`Names from the catalog: ${names.characters.length} characters, ${names.weapons.length} weapons, ${names.sets.length} sets`);
      this.ui.print(`The game's names: ${names.characters.length} characters, ${names.weapons.length} weapons, ${names.sets.length} artifact sets (the current lists).`);
    } catch (e) {
      log.warn(`The current names could not be fetched: ${e.stack || e}`);
      this.ui.warn(`Could not fetch the game's current names (${e.message}); using the list that came with this program.`);
      this.ui.warn('A character, weapon or set newer than this program is read as it is.');
    }
  }

  async askNames() {
    const { ui } = this;
    ui.print();
    ui.print('The game shows some characters under the name you gave them. Type each name exactly as the game shows it');
    ui.print("(capital letters count). Press Enter to keep the name in [brackets] - also when you don't have that character.");
    const names = {};
    for (const c of NAMED_CHARACTERS) {
      while (true) {
        const name = (await ui.ask(`  The name of ${c.who}${c.fallback ? ` [${c.fallback}]` : ''}: `)) || c.fallback;
        if (!name) {
          ui.warn('    Type the name you chose for your Traveler: the game shows it in "Equipped: ..." and on the character screen.');
          continue;
        }
        if (names[name]) ui.warn(`    ${name} is the ${names[name]}'s name too: equipment on either is put on the ${names[name]}.`);
        else names[name] = c.key;
        break;
      }
    }
    log.info('Named characters', names);
    return names;
  }

  showChecklist() {
    const { ui } = this;
    ui.print();
    ui.heading('What to scan, in any order:');
    for (const kind of KINDS) {
      const done = this.done[kind];
      const line = `  ${LABEL[kind].padEnd(11)} ${done ? done.summary : HOW[kind]}`;
      if (!done) ui.print(`[ ]${line}`);
      else if (done.result.ended === 'complete') ui.ok(`[x]${line}`);
      else ui.warn(`[!]${line}`);
    }
    ui.print(`Open one of them, then press ${this.hotkey.label} in the game. Hands off the mouse and keyboard while it scans; ${this.hotkey.label} again stops it.`);
    ui.print('It beeps when it is done. To make the GOOD file with what is scanned so far, type G and Enter here; to quit, Q and Enter.');
  }

  // 'scan' (the hotkey was pressed), 'good' or 'quit' (typed here).
  nextAction() {
    return new Promise((resolve) => {
      const done = (action) => {
        offKey();
        offLines();
        resolve(action);
      };
      const offKey = this.hotkey.on(() => done('scan'));
      const offLines = this.ui.commands((line) => {
        const command = line.trim().toLowerCase();
        if (command === 'g') done('good');
        else if (command === 'q') done('quit');
        else if (command) this.ui.print(`Type G (make the GOOD file) or Q (quit) and Enter - or press ${this.hotkey.label} in the game to scan.`);
      });
    });
  }

  // One scan of the screen that is open, read as it goes; what it read is kept for the GOOD file.
  async scanOnce() {
    const { ui } = this;
    const frame = await this.io.grab();
    const screen = await detectScreen(this.io, frame);
    const problem = await this.screenProblem(screen, frame);
    if (problem) {
      ui.beep();
      ui.print();
      ui.error(problem.title);
      for (const line of problem.lines) ui.print(line);
      return;
    }
    const kind = screen.kind;
    this.scanned = true;
    ui.print();
    ui.print(`Scanning the ${kind}... (${this.hotkey.label} in the game stops it)`);
    const signal = new StopSignal();
    const offKey = this.hotkey.on(() => {
      if (signal.stopped) return;
      log.info(`${this.hotkey.label} pressed, stopping the scan...`);
      signal.stop();
    });
    const watch = this.watchReadings(signal);
    const saver = new ReadingSaver(this.ocr, { keepImages: this.keepImages, onRead: watch.onRead });
    const io = { ...this.io, createSaver: () => saver };
    const options = { scanJson: { customNames: this.customNames } };
    const scan = kind === 'characters' ? new CharacterScan(signal, this.paths.scans, io, options) : new Scan(signal, this.paths.scans, io, options);
    let result;
    try {
      result = await scan.run();
    } finally {
      offKey();
    }
    ui.beep();
    if (result.ended === 'not-started') return this.explain(result.problem);
    if (saver.waiting) ui.print(saver.waiting === 1 ? 'Reading the last screenshot...' : `Reading the last ${saver.waiting} screenshots...`);
    const { readings, failures } = await saver.finished();
    for (const failure of failures) log.error(`Could not read a screenshot: ${failure}`);
    fs.writeFileSync(path.join(result.dir, 'ocr.json'), JSON.stringify(readings));

    const { items, incomplete } = kind === 'characters' ? characterItems(scan.passes, CHARACTER_PASSES) : { items: scan.manifest, incomplete: null };
    const record = { kind, result, dir: result.dir, items, incomplete, readings };
    if (watch.notEnglish) {
      result.ended = 'failed';
      result.problem = { code: 'language', message: 'The game does not seem to be in English.' };
    }
    const r = buildGood({ [kind]: record }, { customNames: this.customNames }).results[kind];
    fs.writeFileSync(path.join(result.dir, 'good-report.txt'), reportText(kind, record, r));
    record.parsed = r;
    record.summary = this.summary(record);

    const kept = this.done[kind];
    if (result.ended === 'complete' || !kept || kept.result.ended !== 'complete') this.done[kind] = record;
    ui.print();
    if (result.ended === 'complete') ui.ok(`${LABEL[kind]}: ${record.summary}.`);
    else ui.warn(`${LABEL[kind]}: ${record.summary}.`);
    if (result.problem) this.explain(result.problem);
    if (kept && this.done[kind] === kept) ui.warn(`Kept the earlier complete scan of the ${kind}.`);
    for (const line of this.problemLines(record)) ui.print(line);
  }

  // What is wrong with the screen for a scan, or null: an inventory tab other than Weapons and Artifacts, or a
  // game not in English (the tab's name reads as something else); nothing scannable at all.
  async screenProblem(screen, frame) {
    if (screen?.kind === 'characters') return null; // its language: the first characters read (watchReadings)
    const { height } = await this.io.screenSize();
    const title = await this.readTitle(frame, height / BASE_HEIGHT, this.ocr);
    log.info(`Inventory title read: "${title}"`);
    if (screen) {
      if (closest(title, [LABEL[screen.kind]])) return null;
      return {
        title: 'The game does not seem to be in English.',
        lines: [
          `The tab's name reads "${title || '?'}" instead of "${LABEL[screen.kind]}". The scanner reads the English game only:`,
          `in the game, Settings → Language → Game Language: English. Then press ${this.hotkey.label} again.`,
        ],
      };
    }
    const tab = closest(title, INVENTORY_TABS);
    if (tab)
      return { title: `The ${tab} tab of the inventory is open.`, lines: [`Open the Weapons or the Artifacts tab (Q and E switch tabs), scrolled to the top, then press ${this.hotkey.label} again.`] };
    return {
      title: 'Neither the inventory nor the character screen is open.',
      lines: [
        `Open the Weapons or the Artifacts tab of the inventory (B), scrolled to the top, or the character screen (C), then press ${this.hotkey.label} again.`,
        'The game must be in full screen on your main screen.',
      ],
    };
  }

  // Watches what is read while the scan runs: stops it when the first characters read name no element in
  // English (the game is in another language), and keeps the screenshot of a panel that didn't read cleanly
  // (its scan folder's problems/).
  watchReadings(signal) {
    const watch = { notEnglish: false, headers: 0, english: 0 };
    watch.onRead = (info, reading, image) => {
      // so far: for a look at the console during a long scan
      watch.read = (watch.read ?? 0) + 1;
      const step = info.kind === 'characters' ? 30 : 100;
      if (watch.read % step === 0) this.ui.print(`  ... ${watch.read} ${info.kind === 'characters' ? 'character screens' : 'items'} read`);
      if (info.kind === 'characters') {
        if (info.tab !== 'attributes' || watch.headers >= LANGUAGE_CHECK) return;
        watch.headers++;
        if (headerElement(reading.header)) watch.english++;
        if (watch.headers === LANGUAGE_CHECK && !watch.english) {
          watch.notEnglish = true;
          log.error(`The first ${LANGUAGE_CHECK} characters' headers name no element in English; stopping`);
          signal.stop();
        }
        return;
      }
      if (reading.kind !== 'artifact' && reading.kind !== 'weapon') return;
      const parsed = reading.kind === 'weapon' ? parseWeapon(reading, { customNames: this.customNames }) : parseArtifact(reading, { customNames: this.customNames });
      if (!parsed.problems.length || !image) return;
      try {
        writePng(path.join(path.dirname(path.dirname(info.path)), 'problems', path.basename(info.path)), image);
      } catch (e) {
        log.warn(`Could not keep the screenshot of #${info.index}: ${e.message}`);
      }
    };
    return watch;
  }

  summary({ kind, result, parsed: r }) {
    const count = kind === 'characters' ? `${r.list.length} characters` : `${r.list.length} ${kind}`;
    const extra = [];
    if (kind !== 'characters' && r.skipped.length) extra.push(`${r.skipped.length} other items skipped`);
    if (r.withProblems.length) extra.push(kind === 'characters' ? `${r.withProblems.length} left out` : `${r.withProblems.length} unclear`);
    if (r.unread.length) extra.push(`${r.unread.length} not read`);
    const how = { complete: 'read', stopped: 'read before you stopped it - scan again for all', failed: 'read before it went wrong - scan again for all' }[result.ended];
    return `${count} ${how}${extra.length ? ` (${extra.join(', ')})` : ''}`;
  }

  // The items that didn't read cleanly, a few of them.
  problemLines({ kind, parsed: r }) {
    if (!r.withProblems.length) return [];
    if (kind === 'characters') {
      const names = r.withProblems.map((item) => item.character.key || item.entry.file);
      return [`Left out, as they didn't read cleanly (the site keeps what it has for them): ${names.join(', ')}. Scanning the characters again may read them.`];
    }
    const shown = r.withProblems.slice(0, 5).map((item) => `  #${item.entry.index} (row ${item.entry.row}, column ${item.entry.column}): ${item.problems.join('; ')}`);
    const more = r.withProblems.length > shown.length ? [`  ... and ${r.withProblems.length - shown.length} more`] : [];
    return [`Read with problems (in the file all the same - check them on the site):`, ...shown, ...more];
  }

  explain(problem) {
    if (!problem) return;
    const { ui } = this;
    const lines = {
      'no-tab': [`Open the Weapons or the Artifacts tab of the inventory, then press ${this.hotkey.label} again.`],
      'no-grid': [`The list of items was not found. Is the game in full screen on your main screen? Then press ${this.hotkey.label} again.`],
      'not-at-top': [`Scroll the list to the very top first, then press ${this.hotkey.label} again.`],
      clicks: ["The clicks don't reach the game. This window must run as administrator (the game does): close it and start it again as administrator."],
      'lost-rows': ['The list moved in a way the scanner did not follow. Scroll it to the top and scan it again - and keep the mouse still while it scans.'],
      'no-wrap': ['E stopped at the last character instead of going back to the first one, so the scan could not go round. Scan the characters again.'],
      tab: [`A tab of the character screen didn't open when clicked. Close the character screen, open it again (C) and press ${this.hotkey.label}.`],
      language: ['The scanner reads the English game only: in the game, Settings → Language → Game Language: English. Then scan again.'],
    }[problem.code] ?? [`Something went wrong: ${problem.message}`, 'Try the scan again; if it keeps failing, the log file says more.'];
    for (const line of lines) ui.print(line);
  }

  // Makes the GOOD file, copies it to the clipboard, says where to import it and - once it is imported -
  // deletes it with everything else. false when the player decides not to after a warning.
  async makeGood() {
    const { ui } = this;
    ui.print();
    if (!Object.keys(this.done).length) {
      ui.warn('Nothing is scanned yet.');
      return false;
    }
    const missing = ['weapons', 'artifacts'].filter((k) => this.done[k]?.result.ended !== 'complete');
    if (missing.length) {
      ui.warn(`The file would hold no complete scan of the ${missing.join(' or the ')}. builder.anotoki.eu replaces all your weapons and artifacts with`);
      ui.warn("a file's, so whatever isn't in it is removed from your account there.");
      if (!(await ui.confirm('Make it all the same?', false))) return false;
    }
    const scans = Object.fromEntries(Object.entries(this.done).map(([kind, record]) => [kind, record]));
    const { good } = buildGood(scans, { customNames: this.customNames });
    const text = JSON.stringify(good);
    fs.mkdirSync(this.paths.output, { recursive: true });
    this.goodFile = path.join(this.paths.output, `genshin-good_${stamp()}.json`);
    fs.writeFileSync(this.goodFile, text);
    log.info(`GOOD file ${this.goodFile}: ${good.artifacts?.length ?? 0} artifacts, ${good.weapons?.length ?? 0} weapons, ${good.characters?.length ?? 0} characters`);

    ui.ok(`Your GOOD file: ${this.goodFile}`);
    ui.print(`  ${good.weapons?.length ?? 0} weapons, ${good.artifacts?.length ?? 0} artifacts, ${good.characters?.length ?? 0} characters`);
    const copied = await this.clipboard(text).catch(() => false);
    if (copied) ui.print('  It is on the clipboard too.');
    ui.print();
    ui.print(`Import it on ${APP.importUrl} - choose the file there${copied ? ', or paste it into the box (Ctrl+V)' : ''}.`);
    if (await ui.confirm('Open the import page in your browser now?')) await this.openUrl(APP.importUrl).catch((e) => log.warn(`Could not open the browser: ${e.message}`));
    ui.print();
    if (this.portable) {
      ui.warn('When you have imported it, come back and press Enter: the GOOD file and everything else this program saved are');
      ui.warn('deleted then. Copy the file somewhere else first if you want to keep it.');
      await ui.ask('Press Enter when you are done. ');
    }
    return true;
  }

  // The end: in a portable run deletes the GOOD file and everything the program saved, then waits for a key.
  async finish(code) {
    const { ui } = this;
    await this.ocr?.terminate().catch(() => {});
    if (this.portable) {
      await this.closeLog();
      for (const target of [this.goodFile, this.paths.work].filter(Boolean)) {
        try {
          fs.rmSync(target, { recursive: true, force: true });
        } catch (e) {
          ui.warn(`Could not delete ${target}: ${e.message}`);
        }
      }
      if (this.goodFile) ui.print('Deleted the GOOD file and everything this program saved.');
      else if (this.scanned) ui.print('Deleted what this program saved.');
    } else if (this.goodFile) {
      ui.print(`The GOOD file and the scans stay where they are (${path.dirname(this.goodFile)}, ${this.paths.scans}).`);
    }
    await ui.waitKey('Press any key to exit.');
    return code;
  }
}

// The open inventory tab's name at the top left of the screen ("Weapons"), read with OCR.
export async function readInventoryTitle(frame, scale, ocr) {
  const at = (v) => Math.round(v * scale);
  const r = INVENTORY_TITLE;
  const image = imageFromFrame(frame);
  const light = (red, green, blue) => (red * 77 + green * 150 + blue * 29) >> 8 > 150;
  return (await ocr.recognize(binarize(image, { x: at(r.x), y: at(r.y), width: at(r.width), height: at(r.height) }, light, { scale: 2 }))).replace(/\s+/g, ' ').trim();
}

// For a screenshot of a panel that didn't read cleanly.
export function writePng(file, image) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, encodePng(frameFromPng(image)));
}
