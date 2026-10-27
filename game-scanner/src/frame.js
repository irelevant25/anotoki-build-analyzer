import { PNG } from 'pngjs';

// A frame is a captured part of the screen: { left, top, width, height, data, channels, bgr }.
// Coordinates passed to the helpers below are screen coordinates, not offsets inside the frame.

export function frameFromNutImage(image, left, top) {
  return { left, top, width: image.width, height: image.height, data: image.data, channels: image.channels, bgr: true };
}

export function frameFromPng(png) {
  return { left: 0, top: 0, width: png.width, height: png.height, data: png.data, channels: 4, bgr: false };
}

export function rgbAt(frame, x, y) {
  const i = ((y - frame.top) * frame.width + (x - frame.left)) * frame.channels;
  const d = frame.data;
  return frame.bgr ? [d[i + 2], d[i + 1], d[i]] : [d[i], d[i + 1], d[i + 2]];
}

export function contains(frame, x, y) {
  return x >= frame.left && y >= frame.top && x < frame.left + frame.width && y < frame.top + frame.height;
}

// Gray copy of a rectangle (screen coordinates) of the frame: { x, y, w, h, data: Uint8Array }.
export function grayRect(frame, x, y, w, h) {
  const out = new Uint8Array(w * h);
  const d = frame.data;
  const ch = frame.channels;
  const [ri, bi] = frame.bgr ? [2, 0] : [0, 2];
  for (let row = 0; row < h; row++) {
    let i = ((y + row - frame.top) * frame.width + (x - frame.left)) * ch;
    let o = row * w;
    for (let col = 0; col < w; col++, i += ch) out[o++] = (d[i + ri] * 77 + d[i + 1] * 150 + d[i + bi] * 29) >> 8;
  }
  return { x, y, w, h, data: out };
}

// Mask of a rectangle (screen coordinates): 255 where test(r, g, b) holds, 0 elsewhere; countChanged() can
// compare two masks like two grays.
export function maskRect(frame, x, y, w, h, test) {
  const out = new Uint8Array(w * h);
  const d = frame.data;
  const ch = frame.channels;
  const [ri, bi] = frame.bgr ? [2, 0] : [0, 2];
  for (let row = 0; row < h; row++) {
    let i = ((y + row - frame.top) * frame.width + (x - frame.left)) * ch;
    let o = row * w;
    for (let col = 0; col < w; col++, i += ch) out[o++] = test(d[i + ri], d[i + 1], d[i + bi]) ? 255 : 0;
  }
  return { x, y, w, h, data: out };
}

// Number of pixels whose gray value differs by more than `threshold` (two grays of the same size).
export function countChanged(a, b, threshold) {
  let n = 0;
  const da = a.data;
  const db = b.data;
  for (let i = 0; i < da.length; i++) if (Math.abs(da[i] - db[i]) > threshold) n++;
  return n;
}

// An RGBA copy of a frame, like a decoded PNG ({ width, height, data }, its own coordinates from 0): what the
// readers (good/*) take.
export function imageFromFrame(frame) {
  const { width, height, channels } = frame;
  const data = Buffer.alloc(width * height * 4);
  const [ri, bi] = frame.bgr ? [2, 0] : [0, 2];
  const d = frame.data;
  for (let i = 0, o = 0; o < data.length; i += channels, o += 4) {
    data[o] = d[i + ri];
    data[o + 1] = d[i + 1];
    data[o + 2] = d[i + bi];
    data[o + 3] = 255;
  }
  return { width, height, data };
}

// PNG of a rectangle (screen coordinates) of the frame.
export function encodePng(frame, x = frame.left, y = frame.top, w = frame.width, h = frame.height) {
  const png = new PNG({ width: w, height: h });
  const d = frame.data;
  const ch = frame.channels;
  const [ri, bi] = frame.bgr ? [2, 0] : [0, 2];
  for (let row = 0; row < h; row++) {
    let i = ((y + row - frame.top) * frame.width + (x - frame.left)) * ch;
    let o = row * w * 4;
    for (let col = 0; col < w; col++, i += ch, o += 4) {
      png.data[o] = d[i + ri];
      png.data[o + 1] = d[i + 1];
      png.data[o + 2] = d[i + bi];
      png.data[o + 3] = 255;
    }
  }
  return PNG.sync.write(png, { deflateLevel: 3 });
}
