import { PNG } from 'pngjs';

// Small helpers on decoded PNGs ({ width, height, data } with RGBA bytes) for reading the detail panel.

export function readPng(buffer) {
  return PNG.sync.read(buffer);
}

export function rgb(png, x, y) {
  const i = (y * png.width + x) * 4;
  return [png.data[i], png.data[i + 1], png.data[i + 2]];
}

export function gray(png, x, y) {
  const [r, g, b] = rgb(png, x, y);
  return (r * 77 + g * 150 + b * 29) >> 8;
}

export function near(c, target, tolerance) {
  return c.every((v, i) => Math.abs(v - target[i]) <= tolerance);
}

// A black-on-white PNG of a rectangle, ready for OCR. `isText(r, g, b)` decides which pixels are text.
// A white margin is added around it because the OCR reads text at the very edge poorly; `scale` enlarges
// short texts (narrow digits like "11" run together at the original size).
export function binarize(png, rect, isText, { margin = 12, scale = 1 } = {}) {
  const x0 = Math.max(0, rect.x);
  const y0 = Math.max(0, rect.y);
  const w = Math.min(png.width, rect.x + rect.width) - x0;
  const h = Math.min(png.height, rect.y + rect.height) - y0;
  const out = new PNG({ width: w * scale + 2 * margin, height: h * scale + 2 * margin });
  out.data.fill(255);
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const i = ((y0 + y) * png.width + x0 + x) * 4;
      if (!isText(png.data[i], png.data[i + 1], png.data[i + 2])) continue;
      for (let dy = 0; dy < scale; dy++) {
        let o = ((y * scale + dy + margin) * out.width + x * scale + margin) * 4;
        for (let dx = 0; dx < scale; dx++, o += 4) out.data[o] = out.data[o + 1] = out.data[o + 2] = 0;
      }
    }
  }
  return PNG.sync.write(out);
}
