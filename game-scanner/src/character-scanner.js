import fs from 'node:fs';
import path from 'node:path';
import { BASE_HEIGHT, CHARACTER_PASSES, CHARACTER_SCREEN, SETTINGS } from './config.js';
import { countChanged, encodePng, maskRect } from './frame.js';
import { ascensionCount, constellationCount, readAscension, readConstellations, readTalentLevels } from './good/character-screen.js';
import { Stopped } from './input.js';
import { log, stamp } from './log.js';
import { detectCharacterTab } from './screens.js';

const textPixels = (mask) => mask.data.reduce((n, v) => n + (v ? 1 : 0), 0);

const TEXT_COLORS = {
  white: (r, g, b) => r >= 225 && g >= 225 && b >= 225 && Math.max(r, g, b) - Math.min(r, g, b) <= 30,
  gold: (r, g, b) => r >= 180 && g >= 150 && b >= 100 && b <= 180 && r - b >= 50,
  light: (r, g, b) => r >= 165 && g >= 165 && b >= 165 && Math.max(r, g, b) - Math.min(r, g, b) <= 40,
};

// A scan of the character screen (C). For each tab in CHARACTER_PASSES (Attributes, Constellation, Talents)
// it opens the tab (clicks its menu entry), saves a screenshot of the character shown, presses E for the next
// one and repeats until E brings back the character the scan started with - then the next tab. The character
// is told by the gold "Element / Name" at the top left, which every tab shows. A screenshot is only taken once
// the tab is ready - it fades in after a click or E, so too early it shows nothing or half of it.
// Writes scans/<date_time>_characters/: scan.json, debug/ (and images/<tab>/001.png … when the saver writes them).
// run() returns { kind: 'characters', dir, items, passes: { tab: count }, ended: 'complete' | 'stopped' |
// 'failed', problem? } - problem { code, message }: 'no-wrap' (E stops at the last character), 'tab' (a tab
// didn't open), 'crashed'.
export class CharacterScan {
  constructor(signal, outRoot, io, { scanJson = {} } = {}) {
    this.signal = signal;
    this.outRoot = outRoot;
    this.io = io;
    this.scanJson = scanJson; // more for scan.json (the names of named characters)
    this.passes = {};
  }

  async run() {
    const started = this.io.now();
    const size = await this.io.screenSize();
    this.scale = size.height / BASE_HEIGHT;
    this.dir = path.join(this.outRoot, `${stamp()}_characters`);
    this.debugDir = path.join(this.dir, 'debug');
    fs.mkdirSync(this.dir, { recursive: true });
    this.saver = this.io.createSaver();
    log.info(`Scan of the characters started (${CHARACTER_PASSES.join(', ')}), saving into ${this.dir}`);

    let ended = 'complete';
    let problem = null;
    try {
      await this.io.sleep(SETTINGS.keysReleaseMs, this.signal); // let go of Ctrl+S before anything is pressed
      for (const tab of CHARACTER_PASSES) {
        await this.openTab(tab);
        const end = await this.pass(tab);
        const count = this.passes[tab].length;
        log.info(`${tab}: ${count} characters`);
        if (tab !== CHARACTER_PASSES[0] && count !== this.passes[CHARACTER_PASSES[0]].length) {
          log.warn(`${tab} has ${count} characters, ${CHARACTER_PASSES[0]} ${this.passes[CHARACTER_PASSES[0]].length}.`);
        }
        if (end === 'end') {
          const message = 'E stopped at the last character instead of going back to the first one, so the other tabs could not be scanned.';
          log.warn(message);
          [ended, problem] = ['failed', { code: 'no-wrap', message }];
          break;
        }
      }
      await this.saveDebug('end');
      log.info(`Scan finished in ${this.seconds(started)} s, saved in ${this.dir}`);
    } catch (e) {
      if (!(e instanceof Stopped)) {
        log.error(`Scan failed: ${e.stack || e}`);
        await this.saveDebug('error').catch(() => {});
        [ended, problem] = ['failed', { code: e.code ?? 'crashed', message: e.message }];
      } else {
        log.info(`Scan stopped (${this.seconds(started)} s), saved in ${this.dir}`);
        ended = 'stopped';
      }
    } finally {
      for (const failure of await this.saver.close()) log.error(`Could not save an image: ${failure}`);
      fs.writeFileSync(path.join(this.dir, 'scan.json'), JSON.stringify({ kind: 'characters', ended, ...this.scanJson, passes: this.passes }, null, 2));
    }
    const passes = Object.fromEntries(Object.entries(this.passes).map(([tab, list]) => [tab, list.length]));
    return { kind: 'characters', dir: this.dir, items: this.passes[CHARACTER_PASSES[0]]?.length ?? 0, passes, ended, ...(problem ? { problem } : {}) };
  }

  // Opens the tab unless it is open.
  async openTab(tab) {
    const at = (v) => Math.round(v * this.scale);
    if (detectCharacterTab(await this.io.grab(), this.scale) !== tab) {
      await this.io.click(at(CHARACTER_SCREEN.menu.clickX), at(CHARACTER_SCREEN.menu.tabs[tab]), this.signal);
      const started = this.io.now();
      while (detectCharacterTab(await this.io.grab(), this.scale) !== tab) {
        if (this.io.now() - started > SETTINGS.tabOpenTimeoutMs) {
          await this.saveDebug(`no-${tab}-tab`);
          throw Object.assign(new Error(`The ${tab} tab did not open.`), { code: 'tab' });
        }
        await this.io.sleep(SETTINGS.characterPollMs, this.signal);
      }
    }
  }

  // Goes through the characters on the open tab. Returns 'round' when E came back to the first character,
  // 'end' when E stopped changing the character.
  async pass(tab) {
    this.passes[tab] = [];
    let { frame, look } = await this.settled(tab);
    // The list came round when the pass's own first header shows again (one tab: little noise, so two names a
    // letter apart stay apart). The other tabs start where the first pass did, its header on another background.
    const first = look.header;
    if (!this.first) this.first = look.header;
    else if (countChanged(this.first, look.header, 40) > this.pixels(SETTINGS.characterChangedPixels)) {
      log.warn(`The ${tab} pass does not start at the first character.`);
    }
    this.save(tab, frame);
    while (this.passes[tab].length < SETTINGS.maxCharacters) {
      await this.io.pressKey('E', this.signal);
      const next = await this.waitForNext(tab, look);
      if (!next) return 'end';
      if (countChanged(first, next.look.header, 40) <= this.pixels(SETTINGS.characterSamePixels)) return 'round';
      this.save(tab, next.frame);
      look = next.look;
    }
    return 'round';
  }

  // The header and the tab's text as masks of their colours, and what the export will read from the tab's
  // pixels: Attributes the ascension, Constellation the number of unlocked constellations, Talents the three
  // levels (null while not readable).
  look(frame, tab) {
    const mask = (r) => {
      const at = (v) => Math.round(v * this.scale);
      return maskRect(frame, at(r.x), at(r.y), at(r.width), at(r.height), TEXT_COLORS[r.color]);
    };
    let reading = null;
    if (tab === 'attributes') {
      const count = ascensionCount(readAscension(frame, this.scale));
      reading = count === null ? null : String(count);
    } else if (tab === 'constellation') {
      const count = constellationCount(readConstellations(frame, this.scale));
      reading = count === null ? null : String(count);
    } else if (tab === 'talents') {
      const levels = readTalentLevels(frame, this.scale).filter(Boolean).slice(0, 3);
      if (levels.length === 3 && levels.every((t) => t.level !== null)) reading = levels.map((t) => `${t.level}${t.boosted ? '+' : ''}`).join('/');
    }
    return { header: mask(CHARACTER_SCREEN.header), content: mask(CHARACTER_SCREEN.content[tab]), reading };
  }

  // Two looks in a row show a tab that is ready to be saved: the header is there and still, and the tab's data
  // is drawn - read the same in both, and on Attributes (whose name and level are read with OCR) enough white
  // text that stopped moving.
  ready(before, now, tab) {
    const still = (a, b) => countChanged(a, b, 40) <= this.pixels(SETTINGS.characterStablePixels);
    if (textPixels(now.header) < this.pixels(SETTINGS.characterMinHeaderPixels) || !still(before.header, now.header)) return false;
    if (now.reading === null || now.reading !== before.reading) return false;
    return tab !== 'attributes' || (textPixels(now.content) >= this.pixels(SETTINGS.characterMinAttributesPixels) && still(before.content, now.content));
  }

  // The screen once the open tab is ready (the first character of a pass).
  async settled(tab) {
    const started = this.io.now();
    let frame = await this.io.grab();
    let look = this.look(frame, tab);
    let steady = 0;
    while (steady < SETTINGS.characterSteadyLooks) {
      if (this.io.now() - started > SETTINGS.characterSwitchTimeoutMs) {
        log.warn(`The ${tab} tab never stood still or showed nothing readable; its first screenshot is taken as it is.`);
        await this.saveDebug(`unsettled-${tab}`);
        break;
      }
      await this.io.sleep(SETTINGS.characterPollMs, this.signal);
      const nextFrame = await this.io.grab();
      const nextLook = this.look(nextFrame, tab);
      steady = this.ready(look, nextLook, tab) ? steady + 1 : 0;
      [frame, look] = [nextFrame, nextLook];
    }
    return { frame, look };
  }

  // Waits for the next character: the header shows another name, then the tab's data changes too and is
  // ready. In between the screen is blank for a moment, which doesn't count. If the data still looks like the
  // previous character's after a while (the two Manekins look alike), it is taken as it is. null when the
  // header doesn't change at all.
  async waitForNext(tab, previous) {
    const started = this.io.now();
    let switchedAt = null;
    let last = null;
    let steady = 0;
    while (true) {
      await this.io.sleep(SETTINGS.characterPollMs, this.signal);
      const frame = await this.io.grab();
      const look = this.look(frame, tab);
      const now = this.io.now();
      if (switchedAt === null) {
        const hasHeader = textPixels(look.header) >= this.pixels(SETTINGS.characterMinHeaderPixels);
        if (hasHeader && countChanged(previous.header, look.header, 40) >= this.pixels(SETTINGS.characterChangedPixels)) switchedAt = now;
        else if (now - started > SETTINGS.characterSwitchTimeoutMs) return null;
      }
      if (switchedAt !== null && last) {
        steady = this.ready(last, look, tab) ? steady + 1 : 0;
        const contentShown =
          countChanged(previous.content, look.content, 40) >= this.pixels(SETTINGS.characterContentChangedPixels) && textPixels(look.content) >= this.pixels(SETTINGS.characterMinContentPixels);
        const waitedLong = now - switchedAt > SETTINGS.characterContentWaitMs;
        if (steady >= SETTINGS.characterSteadyLooks && (contentShown || waitedLong)) {
          if (!contentShown) log.debug(`${tab} #${this.passes[tab].length + 1}: the data looks like the previous character's; taken as it is`);
          return { frame, look };
        }
        if (now - switchedAt > SETTINGS.characterSwitchTimeoutMs) {
          log.warn(`${tab} #${this.passes[tab].length + 1}: never ready (nothing readable, or always moving); taken as it is`);
          await this.saveDebug(`unready-${tab}-${this.passes[tab].length + 1}`);
          return { frame, look };
        }
      }
      last = look;
    }
  }

  pixels(count) {
    return count * this.scale * this.scale;
  }

  save(tab, frame) {
    const index = this.passes[tab].length + 1;
    const file = `images/${tab}/${String(index).padStart(3, '0')}.png`;
    this.saver.save(path.join(this.dir, file), frame, { kind: 'characters', tab, index, file });
    this.passes[tab].push({ index, file });
    log.debug(`${tab} #${index}`);
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
  }
}
