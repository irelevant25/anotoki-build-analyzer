import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createScheduler, createWorker, PSM } from 'tesseract.js';

// Offline English model from the @tesseract.js-data/eng package (no download at run time).
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const LANG_PATH = path.join(root, 'node_modules', '@tesseract.js-data', 'eng', '4.0.0_best_int');

// A pool of OCR workers; recognize(png) returns the text of a black-on-white PNG, one line per text line.
export async function createOcr(workers = Math.max(1, Math.min(6, os.cpus().length - 1))) {
  const scheduler = createScheduler();
  for (let i = 0; i < workers; i++) {
    const worker = await createWorker('eng', 1, { langPath: LANG_PATH, cacheMethod: 'none', gzip: true });
    await worker.setParameters({ tessedit_pageseg_mode: PSM.SINGLE_BLOCK, preserve_interword_spaces: '1' });
    scheduler.addWorker(worker);
  }
  return {
    workers,
    async recognize(png) {
      const { data } = await scheduler.addJob('recognize', png);
      return data.text.trim();
    },
    terminate: () => scheduler.terminate(),
  };
}
