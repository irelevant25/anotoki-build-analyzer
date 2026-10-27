import fs from 'node:fs';
import path from 'node:path';
import { parentPort } from 'node:worker_threads';
import { encodePng } from './frame.js';

// The other end of ImageSaver: encodes each frame it gets as a PNG and writes it (making its folder).
parentPort.on('message', ({ id, file, frame }) => {
  try {
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, encodePng(frame));
    parentPort.postMessage({ id });
  } catch (e) {
    parentPort.postMessage({ id, error: `${file}: ${e.stack || e}` });
  }
});
