import { Worker } from 'node:worker_threads';

// Encodes and writes screenshots on a worker thread: a PNG takes ~50 ms to encode, which the scan would
// otherwise wait for after every click. close() waits until everything is on disk.
export class ImageSaver {
  constructor() {
    this.worker = new Worker(new URL('./save-worker.js', import.meta.url), { execArgv: [] });
    this.resolvers = new Map(); // job id → resolve of its promise
    this.outstanding = new Set(); // promises of the jobs not written yet
    this.nextId = 1;
    this.failures = [];
    this.worker.on('message', ({ id, error }) => {
      if (error) this.failures.push(error);
      this.resolvers.get(id)?.();
      this.resolvers.delete(id);
    });
    this.worker.on('error', (e) => {
      this.failures.push(String(e.stack || e));
      for (const resolve of this.resolvers.values()) resolve();
      this.resolvers.clear();
    });
  }

  // Saves a frame (see frame.js) as a PNG. The pixels are copied, so the frame can be reused right away.
  save(file, frame) {
    const data = new Uint8Array(frame.data.length);
    data.set(frame.data);
    const id = this.nextId++;
    const written = new Promise((resolve) => this.resolvers.set(id, resolve));
    this.outstanding.add(written);
    written.then(() => this.outstanding.delete(written));
    this.worker.postMessage({ id, file, frame: { ...frame, data } }, [data.buffer]);
    return written;
  }

  // Waits for every image to be written, stops the worker and returns the errors, if any.
  async close() {
    await Promise.all(this.outstanding);
    await this.worker.terminate();
    return this.failures;
  }
}
