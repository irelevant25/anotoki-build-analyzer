import { imageFromFrame } from './frame.js';
import { readCharacterTab, READ_VERSION } from './good/character.js';
import { readPanel } from './good/panel.js';
import { ImageSaver } from './saver.js';

// Reads the screenshots while a scan runs: the scanners hand each detail panel and character screen to
// save(file, frame, info) as soon as they capture it, and it goes straight to the OCR - only what was read is
// kept, so a scan of thousands of items leaves nothing on the disk. With keepImages the screenshots are written
// too (for `npm run good -- --fresh` after a reader changed). Debug screenshots (no info) are written as PNGs.
//
// readings: by the item's file - "images/0001.png" for a panel, "character-1" for a character (its three tabs
// put together as they arrive), as a scan folder's ocr.json holds them. onRead(info, reading, image) is called
// for every panel and tab read, info with the screenshot's full `path` (the image only for panels: the caller
// may keep a screenshot of one that didn't read cleanly).
export class ReadingSaver {
  constructor(ocr, { keepImages = false, onRead = () => {} } = {}) {
    this.ocr = ocr;
    this.keepImages = keepImages;
    this.onRead = onRead;
    this.pngs = null;
    this.readings = {};
    this.pending = new Set();
    this.failures = [];
  }

  save(file, frame, info) {
    if (!info || this.keepImages) (this.pngs ??= new ImageSaver()).save(file, frame);
    if (!info) return Promise.resolve();
    const image = imageFromFrame(frame);
    const shot = { ...info, path: file };
    const job = (info.kind === 'characters' ? this.readTab(shot, image) : this.readPanel(shot, image)).catch((e) => this.failures.push(`${info.file}: ${e.stack || e}`));
    this.pending.add(job);
    job.finally(() => this.pending.delete(job));
    return Promise.resolve();
  }

  async readPanel(info, image) {
    const reading = await readPanel(image, this.ocr, info.kind);
    this.readings[info.file] = reading;
    this.onRead(info, reading, image);
  }

  async readTab(info, image) {
    const reading = await readCharacterTab(info.tab, image, this.ocr);
    const key = `character-${info.index}`;
    this.readings[key] = Object.assign(this.readings[key] ?? { version: READ_VERSION }, reading);
    this.onRead(info, reading);
  }

  // Waits for the screenshots still being written (the scan's end); the readings may go on.
  async close() {
    return this.pngs ? this.pngs.close() : [];
  }

  // Waits until everything handed over is read; returns the readings and what failed.
  async finished() {
    while (this.pending.size) await Promise.all([...this.pending]);
    return { readings: this.readings, failures: this.failures };
  }

  get waiting() {
    return this.pending.size;
  }
}
