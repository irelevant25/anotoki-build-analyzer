import { PNG } from 'pngjs';
import { uIOhook } from 'uiohook-napi';
import { createOcr } from './good/ocr.js';
import { BUNDLED_NAMES } from './good/names.js';
import { screenSize } from './input.js';

// node src/selftest.js - what the release build runs with the node.exe it ships (tools/package.mjs): the
// native modules load (mouse and screen, the hotkey), the OCR starts with its engine and language data and reads
// an image, the names are there. Exits 1 on a failure.

const ok = (what) => console.log(`self-test: ${what}`);
try {
  if (typeof uIOhook.start !== 'function') throw new Error('uiohook-napi did not load');
  ok('hotkey module loaded');
  // (a build server may have no screen to ask: loading the module is what counts)
  ok(`screen ${await screenSize().then(JSON.stringify, (e) => `not available here (${e.message})`)}`);
  const ocr = await createOcr(1);
  const blank = new PNG({ width: 64, height: 32 });
  blank.data.fill(255);
  const text = await ocr.recognize(PNG.sync.write(blank));
  await ocr.terminate();
  ok(`OCR read a blank image as "${text}"`);
  if (!BUNDLED_NAMES.characters.length) throw new Error('no names in data/names.json');
  ok(`${BUNDLED_NAMES.characters.length} character names`);
} catch (e) {
  console.error(`self-test failed: ${e.stack || e}`);
  process.exit(1);
}
