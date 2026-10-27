import fs from 'node:fs';
import path from 'node:path';
import { BASE_HEIGHT, SETTINGS } from './config.js';
import { countChanged, encodePng, grayRect } from './frame.js';
import { cardCenterX, cellHasCard, detectRows, detectTab, gridChangedFraction, gridGray, gridRect, isSelected, maxStepNotches, measureShift, rowRect, scaleLayout, stepWindow } from './grid.js';
import { CharacterScan } from './character-scanner.js';
import { click, grab, pressKey, screenSize, scroll, sleep, Stopped } from './input.js';
import { log, stamp } from './log.js';
import { detectCharacterTab } from './screens.js';
import { ImageSaver } from './saver.js';

// The screen, mouse, clock and screenshot sink a scan works with; tests pass a simulated game instead. The
// program's sink reads each screenshot rather than writing it (reader.js); this one writes PNGs.
export const REAL_IO = { grab, click, scroll, pressKey, screenSize, sleep, now: () => Date.now(), createSaver: () => new ImageSaver() };

// Which scannable screen is open: { kind: 'characters', tab } (the character screen, any tab), { kind: 'weapons' }
// or { kind: 'artifacts' } (that tab of the inventory), or null.
export async function detectScreen(io = REAL_IO, frame = null) {
  const size = await io.screenSize();
  const scale = size.height / BASE_HEIGHT;
  frame ??= await io.grab();
  const tab = detectCharacterTab(frame, scale);
  if (tab) return { kind: 'characters', tab };
  const inventory = detectTab(frame, scale);
  return inventory ? { kind: inventory } : null;
}

// Starts the scan that fits the screen: the character screen (pressing E) or an inventory tab (clicking).
export async function runScan(signal, outRoot, io = REAL_IO, options = {}) {
  const screen = await detectScreen(io);
  if (screen?.kind === 'characters') return new CharacterScan(signal, outRoot, io, options).run();
  return new Scan(signal, outRoot, io, options).run();
}

// One scan of the open inventory tab (Artifacts or Weapons). Clicks every card of every fully visible row and
// hands the detail panel after each click to the saver, then scrolls the list a step (less than a row) and goes
// on with the rows that came into view. Every step is measured: that's how the scanner knows where the next
// row is, and that the list has ended when a step no longer moves it.
// Writes scans/<date_time>_<tab>/: scan.json, debug/ (and images/0001.png … when the saver writes them).
// run() returns { kind, dir, items, ended: 'complete' | 'stopped' | 'failed' | 'not-started', problem? }, the
// problem { code, message } saying what went wrong: 'no-tab', 'no-grid', 'not-at-top', 'clicks', 'lost-rows',
// 'crashed'.
export class Scan {
  constructor(signal, outRoot, io = REAL_IO, { scanJson = {} } = {}) {
    this.signal = signal;
    this.io = io;
    this.outRoot = outRoot;
    this.scanJson = scanJson; // more for scan.json (the names of named characters)
    this.manifest = [];
    this.rowIndex = 0;
    this.confirmedClicks = 0; // clicks that changed the panel or moved the selection: proof that clicks arrive
    this.unconfirmed = []; // items where neither the panel nor the selection frame showed the click
    this.unconfirmedInARow = 0;
  }

  async run() {
    const started = this.io.now();
    const size = await this.io.screenSize();
    const tab = detectTab(await this.io.grab(), size.height / BASE_HEIGHT);
    if (!tab) {
      const message = 'Neither the Artifacts nor the Weapons tab is open. Open one of them in the Inventory (game in full screen) and press the hotkey again.';
      log.error(message);
      return { kind: null, ended: 'not-started', items: 0, problem: { code: 'no-tab', message } };
    }
    this.tab = tab;
    const L = (this.layout = scaleLayout(size.width, size.height, tab));
    this.gridRect = gridRect(L);
    const p = L.panel;
    this.panelRect = { x: p.x, y: p.y, width: p.width, height: p.height };
    this.stepNotches = Math.min(SETTINGS.scrollTicksPerStep, maxStepNotches(L));
    this.dir = path.join(this.outRoot, `${stamp()}_${tab}`);
    this.debugDir = path.join(this.dir, 'debug');
    fs.mkdirSync(this.dir, { recursive: true });
    this.saver = this.io.createSaver();

    log.info(`Scan of ${tab} started, saving into ${this.dir}`);
    log.debug('Screen and layout', { size, layout: this.layout, stepNotches: this.stepNotches });
    if (!L.is16by9) log.warn(`Screen is ${size.width}x${size.height}; only 16:9 screens are supported, positions may be off.`);
    if (this.stepNotches < SETTINGS.scrollTicksPerStep) {
      log.warn(`scrollTicksPerStep ${SETTINGS.scrollTicksPerStep} scrolls too far to be measured; using ${this.stepNotches}.`);
    }
    await this.saveDebug('start');

    const result = (ended, problem) => {
      this.ended = ended;
      return { kind: tab, dir: this.dir, items: this.manifest.length, ended, ...(problem ? { problem } : {}) };
    };
    try {
      const frame = await this.io.grab(this.gridRect);
      const rows = detectRows(frame, L);
      log.debug('Rows on screen', rows);
      const first = rows.find((r) => r.fullyVisible);
      if (!first) {
        const message = `No ${tab} grid found. Is the game in full screen? Press the hotkey again to retry.`;
        log.error(message);
        return result('not-started', { code: 'no-grid', message });
      }
      if (rows.indexOf(first) > 0 || first.top > L.viewTop + L.rowPitch / 2) {
        const message = 'The list is not scrolled to the top. Scroll it to the very top and press the hotkey again.';
        log.error(message);
        return result('not-started', { code: 'not-at-top', message });
      }
      this.prevPanel = grayRect(await this.io.grab(this.panelRect), p.x, p.y, p.width, p.height);
      await this.scanAll(frame, first.labelBottom);
      await this.saveDebug('end');
      log.info(`Scan finished: ${this.manifest.length} items in ${this.seconds(started)} s, saved in ${this.dir}`);
      return result('complete');
    } catch (e) {
      if (e instanceof Stopped) {
        log.info(`Scan stopped after ${this.manifest.length} items (${this.seconds(started)} s), saved in ${this.dir}`);
        return result('stopped');
      }
      log.error(`Scan failed: ${e.stack || e}`);
      await this.saveDebug('error').catch(() => {});
      return result('failed', { code: e.code ?? 'crashed', message: e.message });
    } finally {
      for (const failure of await this.saver.close()) log.error(`Could not save an image: ${failure}`);
      if (this.unconfirmed.length) {
        log.warn(`${this.unconfirmed.length} items showed no sign of their click and may repeat the item before them: #${this.unconfirmed.join(', #')}`);
      }
      if (this.ended === 'not-started') fs.rmSync(this.dir, { recursive: true, force: true });
      else fs.writeFileSync(path.join(this.dir, 'scan.json'), JSON.stringify({ kind: this.tab, ended: this.ended, ...this.scanJson, items: this.manifest }, null, 2));
    }
  }

  // Scans the rows top to bottom. `next` is the screen y where the next row to scan has the bottom edge of its
  // label; every measured scroll step moves it up.
  async scanAll(frame, next) {
    const L = this.layout;
    let stepsWithoutRow = 0;
    while (true) {
      const rows = detectRows(frame, L);
      const row = rows.find((r) => Math.abs(r.labelBottom - next) <= L.rowPitch * 0.2);
      if (row?.fullyVisible) {
        stepsWithoutRow = 0;
        if (!(await this.scanRow(row, frame))) return; // a partly filled row is the last one
        next = row.labelBottom + L.rowPitch;
        const fresh = await this.lookAgain(frame);
        next -= fresh.shift;
        frame = fresh.frame;
        continue;
      }
      if (!row && next <= L.viewBottom - 2) {
        log.info('End of the list (no row below the last one).');
        log.debug('Rows on screen', { next, rows });
        return;
      }
      if (stepsWithoutRow >= SETTINGS.maxStepsWithoutRow) {
        await this.saveDebug(`lost-row-${this.rowIndex + 1}`);
        throw Object.assign(new Error(`Lost the rows: ${stepsWithoutRow} scroll steps and the next row (expected at y=${Math.round(next)}) is still not in view.`), { code: 'lost-rows' });
      }
      // The next row is below the view (or, which shouldn't happen, cut off at the top): scroll a step.
      const notches = row && row.top < L.viewTop ? -1 : this.stepNotches;
      const step = await this.scrollStep(frame, notches);
      stepsWithoutRow++;
      if (notches > 0 && Math.abs(step.shift) <= 2) {
        log.info('End of the list (scrolling no longer moves it).');
        return;
      }
      next -= step.shift;
      frame = step.frame;
    }
  }

  // Clicks and saves every card of the row. Returns false when the row was the last one (it has empty cells).
  async scanRow(row, frame) {
    const L = this.layout;
    this.rowIndex++;
    log.debug(`Row ${this.rowIndex}`, row);
    for (let column = 0; column < L.columns; column++) {
      this.signal.check();
      if (!cellHasCard(frame, L, row, column)) {
        log.info(`Row ${this.rowIndex}: ${column} items, end of the list (total ${this.manifest.length})`);
        return false;
      }
      await this.save(await this.clickCard(row, column), column);
    }
    log.info(`Row ${this.rowIndex}: ${L.columns} items (total ${this.manifest.length})`);
    return true;
  }

  // Clicks the card and waits for its detail panel. If nothing shows that the click arrived (the panel didn't
  // change and the card has no selection frame), clicks once more.
  async clickCard(row, column) {
    let shown;
    for (let attempt = 1; attempt <= 2; attempt++) {
      await this.io.click(cardCenterX(this.layout, column), row.clickY, this.signal);
      shown = { ...(await this.waitForPanel(row, column)), clicks: attempt };
      if (shown.changed || shown.selected !== false) return shown;
      log.debug(`Row ${this.rowIndex} column ${column + 1}: no sign of the click after ${shown.ms} ms`);
    }
    return shown;
  }

  // Waits until the detail panel shows something new and stops changing. An item identical to the previous one
  // (dozens of the same weapon) leaves the panel as it was: then the white frame on the clicked card shows that
  // the click arrived, and once panelSameItemMs have passed (longer than any panel took to update) the panel is
  // taken as it is.
  async waitForPanel(row, column) {
    const p = this.panelRect;
    const started = this.io.now();
    let changed = false;
    let last = null;
    while (true) {
      await this.io.sleep(SETTINGS.panelPollMs, this.signal);
      const frame = await this.io.grab(p);
      const gray = grayRect(frame, p.x, p.y, p.width, p.height);
      const ms = this.io.now() - started;
      if (!changed) {
        changed = countChanged(this.prevPanel, gray, SETTINGS.panelPixelThreshold) >= SETTINGS.panelChangedPixels;
        if (!changed && ms >= SETTINGS.panelSameItemMs) {
          const selected = await this.selectedNow(row, column);
          const sure = selected === true || (selected === null && ms >= SETTINGS.panelSameItemUnsureMs);
          if (sure || ms >= SETTINGS.panelChangeTimeoutMs) return { frame, gray, changed, selected, ms };
        }
      } else if (countChanged(last.gray, gray, SETTINGS.panelPixelThreshold) <= SETTINGS.panelStablePixels || ms >= SETTINGS.panelChangeTimeoutMs) {
        return { frame, gray, changed, selected: true, ms };
      }
      last = { frame, gray };
    }
  }

  // true: the card has the selection frame; false: it hasn't; null: can't tell (bright background).
  async selectedNow(row, column) {
    return isSelected(await this.io.grab(rowRect(this.layout, row)), this.layout, row, column);
  }

  async save(shown, column) {
    const index = this.manifest.length + 1;
    const file = `images/${String(index).padStart(4, '0')}.png`;
    this.saver.save(path.join(this.dir, file), SETTINGS.capture === 'full' ? await this.io.grab() : shown.frame, { kind: this.tab, index, file });
    const sameAsPrevious = !shown.changed && shown.selected !== false;
    this.manifest.push({ index, file, row: this.rowIndex, column: column + 1, panelChanged: shown.changed, sameAsPrevious, waitMs: shown.ms, clicks: shown.clicks });
    this.prevPanel = shown.gray;
    const how = shown.changed
      ? 'panel updated'
      : shown.selected === true
        ? 'same as the previous item'
        : shown.selected === null
          ? 'same as the previous item (frame not visible against the background)'
          : 'no sign of the click';
    log.debug(`#${index} row ${this.rowIndex} col ${column + 1}: ${how} after ${shown.ms} ms${shown.clicks > 1 ? ', clicked twice' : ''}`);

    // The first card is often selected before the scan starts, so its frame proves nothing.
    if (shown.changed || (shown.selected === true && index > 1)) this.confirmedClicks++;
    if (shown.changed || shown.selected !== false) {
      this.unconfirmedInARow = 0;
      return;
    }
    if (index === 1) return; // the first card is often selected already; its frame can be hard to see at the top
    this.unconfirmed.push(index);
    this.unconfirmedInARow++;
    log.warn(`#${index} (row ${this.rowIndex}, column ${column + 1}): no sign that the click arrived, even after a second click; saved what the panel shows.`);
    if (this.confirmedClicks === 0 && this.unconfirmedInARow >= 3) {
      await this.saveDebug(`clicks-not-arriving-${index}`);
      throw Object.assign(new Error('Clicks do not seem to reach the game (neither the detail panel nor the selected card ever changed). Run the terminal as administrator.'), { code: 'clicks' });
    }
  }

  // A fresh look at the grid after a row was clicked, to scroll from. The list shouldn't move while clicking;
  // if it did, the shift is returned and the next row is looked for where it went.
  async lookAgain(before) {
    const L = this.layout;
    const frame = await this.io.grab(this.gridRect);
    const range = Math.round(30 * L.scale);
    const m = measureShift(gridGray(before, L), gridGray(frame, L), L, { minShift: -range, maxShift: range });
    if (Math.abs(m.shift) > 2) log.warn(`The list moved ${m.shift} px while row ${this.rowIndex} was being clicked.`);
    return { frame, shift: Math.abs(m.shift) > 2 ? m.shift : 0 };
  }

  // Scrolls `notches` (negative: up), waits for the list to stop and measures how far it moved.
  async scrollStep(before, notches) {
    const L = this.layout;
    await this.io.scroll(notches, this.signal);
    const frame = await this.waitSettled();
    const window = notches > 0 ? stepWindow(L, notches) : { minShift: Math.round(notches * L.scrollNotch - 30 * L.scale), maxShift: Math.round(30 * L.scale) };
    const m = measureShift(gridGray(before, L), gridGray(frame, L), L, window);
    log.debug(`Scrolled ${notches} notches`, { ...m, window });
    this.checkShift(m);
    if (!this.scrolled) {
      this.scrolled = true;
      if (Math.abs(m.shift) <= 2) log.warn('The first scroll did not move the list. If the list is longer than one screen, scrolling does not reach the game.');
    }
    return { frame, shift: m.shift };
  }

  checkShift(m) {
    if (m.error > 10 || m.runnerUp - m.error < 3) {
      log.warn(`Unsure how far the list scrolled (shift ${m.shift}px, error ${m.error.toFixed(2)}, next best ${m.runnerUp.toFixed(2)}).`);
      this.saveDebug(`unsure-shift-row-${this.rowIndex}`).catch(() => {});
    }
  }

  // Waits for the grid to stop moving after a scroll and returns the settled grid frame.
  async waitSettled() {
    const L = this.layout;
    const started = this.io.now();
    await this.io.sleep(SETTINGS.settleMinDelayMs, this.signal);
    let prev = gridGray(await this.io.grab(this.gridRect), L);
    let stable = 0;
    while (true) {
      await this.io.sleep(SETTINGS.settlePollMs, this.signal);
      const frame = await this.io.grab(this.gridRect);
      const gray = gridGray(frame, L);
      const fraction = gridChangedFraction(prev, gray, L);
      stable = fraction <= SETTINGS.settleMaxChangedFraction ? stable + 1 : 0;
      if (stable >= SETTINGS.settleStableCount) {
        log.debug(`Grid settled after ${this.io.now() - started} ms`);
        return frame;
      }
      if (this.io.now() - started > SETTINGS.settleTimeoutMs) {
        log.warn(`Grid did not settle within ${SETTINGS.settleTimeoutMs} ms (last change ${(fraction * 100).toFixed(2)}%), continuing.`);
        return frame;
      }
      prev = gray;
    }
  }

  seconds(since) {
    return ((this.io.now() - since) / 1000).toFixed(1);
  }

  async saveDebug(name) {
    if (!SETTINGS.debugScreenshots) return;
    const file = path.join(this.debugDir, `${name}.png`);
    const frame = await this.io.grab();
    if (this.saver) this.saver.save(file, frame);
    else {
      fs.mkdirSync(this.debugDir, { recursive: true });
      fs.writeFileSync(file, encodePng(frame));
    }
    log.debug(`Debug screenshot ${file}`);
  }
}
