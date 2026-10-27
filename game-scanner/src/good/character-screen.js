// What the character screen shows that is read from its pixels, without OCR: the ascension, the talent levels
// ("Lv. 13") and the unlocked constellations. The scan uses it to wait until a tab shows what the export will
// read; the export reads the saved screenshots with it. `frame` is a captured frame or a decoded screenshot
// (frame.js); positions are measured on a 2560x1440 screen and scaled.

// Attributes: six stars under the name, white for each ascension phase reached, solid grey for the others.
// Their middles; the darkest channel of a 7x7 square there is ~255 lit, ~127 not.
const ASCENSION_STARS = { xs: [1971, 2006, 2041, 2076, 2111, 2146], y: 250, half: 3 };
const STAR_LIT = 200;
const STAR_DIM = [100, 160]; // a grey star; darker = not drawn yet

// Talent list: the middle of each row (its icon). A combat talent's last text line, right-aligned before the
// icon, is "Lv. N": white, or cyan when a constellation (or Tartaglia's passive) raised it.
const TALENTS = { rows: [224, 346, 467, 589, 710, 832, 954], x0: 2120, x1: 2285, reach: 60 };
// Constellation icons (top to bottom = C1 to C6): an unlocked one has a bright ring, a locked one a padlock on
// a dark disc. Characters without constellations (the Manekins) show a grey "⊘" icon instead.
const CONSTELLATIONS = [
  [2026, 369],
  [2099, 517],
  [2147, 666],
  [2147, 813],
  [2099, 960],
  [2003, 1108],
];
const RING = { inner: 40, outer: 48 };
export const RING_LIT = 185; // an unlocked ring measures ~220
export const RING_DARK = 130; // a locked one ~60-100
const NO_CONSTELLATIONS = { x: 2240, y: 230, inner: 19, outer: 23, share: 0.5 };

const spread = (r, g, b) => Math.max(r, g, b) - Math.min(r, g, b);
const isLight = (r, g, b) => r >= 150 && g >= 150 && b >= 150 && spread(r, g, b) <= 50;
const isCyan = (r, g, b) => g >= 225 && b >= 225 && r <= 110;
const isText = (r, g, b) => isLight(r, g, b) || isCyan(r, g, b);
const isGrey = (r, g, b) => r >= 110 && g >= 110 && b >= 110 && spread(r, g, b) <= 45;
const brightness = (r, g, b) => Math.max(r, g, b);

// The digits of a level: how much of each of 8x12 cells of the glyph's bounding box is text (' ' none to
// '@' all), and the glyph's width / height. Averaged from the 284 digits of a real scan.
const SHADES = ' .:-=+*#%@';
const DIGITS = {
  0: [0.78, '  :@@*  ', ' =@#+@# ', ':@#. =@=', '*@*   @#', '#@+   @%', '#@:   %@', '@@:   %@', '#@=   %@', '*@+   @%', '=@#  :@*', ' %%-.#@:', ' .*%@%- '],
  1: [0.36, '     .**', '  -+%@@@', '*#%@@@@@', '  :*@@@@', '   =@@@@', '   -@@@@', '   -@@@@', '   -@@@@', '   -@@@@', '   -@@@@', '   -@@@*', '   -@*=.'],
  2: [0.67, '  .%@*. ', ' -@%#@@:', '=@#  =@#', '@@+   @@', '**:  -@@', '     #@+', '   .*@# ', '  :#@=  ', ' :@@.   ', ':%@=    ', '@@@%####', '@@@@@@@%'],
  3: [0.7, ' .*%%+  ', '.#@#%@# ', '*@-  *@=', '%%   -@+', '    .*@-', '  :*%@*.', '  :#%@# ', '     +@*', '+-   :@@', '@@.  -@#', '+@+-=#@=', ' +%@@#+.'],
  4: [0.81, '    +@: ', '   .@@= ', '   =@@= ', '   =+@= ', '  *.+@= ', ' -* +@= ', ' %  +@= ', '=%..#@+.', '%@@@@@@@', ' ...*@+.', '    +@- ', '    +*  '],
  5: [0.71, ' =@@@@@=', ' =@@@@@:', ' %%:::. ', ' %+     ', '.%*:-.  ', '=@%#@@#.', '.-  .@@+', '     =@@', '=:   =@@', '@%   =@%', '+@+:-%@=', ' +@@@%= '],
  6: [0.75, '    .%  ', '   =@+  ', '  #@-   ', ' #@+    ', '-@@ ..  ', '*@@%%@@:', '@@#. =@*', '@@=   @@', '@@=   @@', '*@+   @%', ':@@- *@+', ' :*%@%+ '],
  7: [0.72, '#@@@@@@@', '@%%%%@@+', ':::::+% ', '    :@- ', '    #@  ', '   -@*  ', '   *@-  ', '  .%@.  ', '  :@@   ', '  =@*   ', '  *@+   ', '  *#.   '],
  8: [0.79, '  :@@#. ', ' =@%#@@:', ':@@. =@+', '=@@  -@*', '.@@+.*@=', ' -@@@@# ', ' -@@@@@:', '-@@:.#@*', '#@+   %@', '#@=   %@', '-@@: =@*', ' -#@@%*.'],
  9: [0.74, '  =%@*. ', '.#@*+%%.', '+@+  :@*', '@@-   @%', '@@-   @@', '*@+  .@%', '.@@*=%@#', ' :*#**@*', '     *@:', '    -@* ', '   :%#  ', '  :#-   '],
};
const GRID = { w: 8, h: 12 };
const TEMPLATES = Object.entries(DIGITS).map(([digit, [aspect, ...rows]]) => ({
  digit,
  aspect,
  cells: rows
    .join('')
    .split('')
    .map((c) => SHADES.indexOf(c) / (SHADES.length - 1)),
}));
const MAX_DISTANCE = 0.3; // mean cell difference + aspect difference to the best template...
const MIN_MARGIN = 0.05; // ...and how much closer it is than the next best one

// The ascension stars' brightness (the darkest channel around each middle), left to right.
export function readAscension(frame, scale = frame.height / 1440) {
  const at = (v) => Math.round(v * scale);
  const px = pixels(frame);
  const half = Math.max(1, at(ASCENSION_STARS.half));
  return ASCENSION_STARS.xs.map((x) => {
    let sum = 0;
    let n = 0;
    for (let dy = -half; dy <= half; dy++) {
      for (let dx = -half; dx <= half; dx++) {
        sum += px(at(x) + dx, at(ASCENSION_STARS.y) + dy, Math.min);
        n++;
      }
    }
    return sum / n;
  });
}

// The ascension phase (0-6) the stars show, or null while one is neither clearly lit nor grey (fading in, a
// sparkle over it) or a lit one follows a grey one.
export function ascensionCount(stars) {
  const lit = stars.map((b) => (b >= STAR_LIT ? true : b >= STAR_DIM[0] && b <= STAR_DIM[1] ? false : null));
  if (lit.includes(null)) return null;
  const count = lit.includes(false) ? lit.indexOf(false) : lit.length;
  return lit.slice(count).some(Boolean) ? null : count;
}

// The levels shown in the talent list, per row: null when the row has no "Lv. N" line (a passive, an
// alternate sprint), else { level, boosted } - level null when a digit is unreadable. Normal Attack, Elemental
// Skill and Elemental Burst are the first three rows with a level.
export function readTalentLevels(frame, scale = frame.height / 1440) {
  const at = (v) => Math.round(v * scale);
  const px = pixels(frame);
  return TALENTS.rows.map((middle) => {
    for (const band of textLines(px, at, at(middle))) {
      const line = levelLine(px, at, band);
      if (!line) continue;
      const digits = line.digits.map((g) => classify(px, g));
      const box = { x: line.lv[0].x, top: Math.min(...line.lv.map((g) => g.top)), right: line.digits.at(-1).x + line.digits.at(-1).w, bottom: line.baseline };
      const cyan = countIn(px, box, isCyan);
      return {
        level: digits.includes(null) ? null : Number(digits.join('')),
        boosted: cyan > countIn(px, box, isLight) && cyan >= at(30),
      };
    }
    return null;
  });
}

// Which constellations are unlocked: `none` for a character without constellations, else the brightness of
// the ring around each icon (C1 to C6): >= RING_LIT unlocked, <= RING_DARK locked, in between still fading in.
export function readConstellations(frame, scale = frame.height / 1440) {
  const at = (v) => Math.round(v * scale);
  const px = pixels(frame);
  const n = NO_CONSTELLATIONS;
  const none = onRing(px, at(n.x), at(n.y), at(n.inner), at(n.outer), (r, g, b) => (isGrey(r, g, b) ? 1 : 0)) >= n.share;
  const rings = CONSTELLATIONS.map(([x, y]) => onRing(px, at(x), at(y), at(RING.inner), at(RING.outer), brightness));
  return { none, rings };
}

// The number of unlocked constellations, or null while a ring is neither clearly lit nor dark.
export function constellationCount({ none, rings }) {
  if (none) return 0;
  if (rings.some((b) => b > RING_DARK && b < RING_LIT)) return null;
  const lit = rings.map((b) => b >= RING_LIT);
  return lit.includes(false) ? lit.indexOf(false) : lit.length;
}

// px(x, y, fn) = fn(r, g, b) of a pixel, in screen coordinates.
function pixels(frame) {
  const { data, width, left, top, channels } = frame;
  const [ri, bi] = frame.bgr ? [2, 0] : [0, 2];
  return (x, y, fn) => {
    const i = ((y - top) * width + (x - left)) * channels;
    return fn(data[i + ri], data[i + 1], data[i + bi]);
  };
}

// Text lines in a talent row right of x0 (bands of rows with text), lowest first.
function textLines(px, at, middle) {
  const x0 = at(TALENTS.x0);
  const x1 = at(TALENTS.x1);
  const minPixels = Math.max(2, at(4));
  const lines = [];
  let bottom = null;
  for (let y = middle + at(TALENTS.reach); y >= middle - at(TALENTS.reach); y--) {
    let n = 0;
    for (let x = x0; x < x1; x++) if (px(x, y, isText)) n++;
    if (n >= minPixels && bottom === null) bottom = y;
    if (n < minPixels && bottom !== null) {
      if (bottom - y >= at(12)) lines.push({ top: y + 1, bottom });
      bottom = null;
    }
  }
  return lines;
}

// "Lv. 13" on a text line: "L" and "v" (they may touch), a dot on the baseline, then one or two digits; small
// sparkles on the line are ignored. Returns the glyphs of "Lv" and of the digits, or null.
function levelLine(px, at, band) {
  const glyphs = glyphsOf(px, at, band);
  const isDigit = (g) => g.h >= at(17) && g.h <= at(26) && g.w >= at(5) && g.w <= at(21);
  const isNoise = (g) => g.h < at(13) && g.w < at(16);
  for (let d = glyphs.length - 1; d >= 0; d--) {
    const dot = glyphs[d];
    if (dot.w > at(8) || dot.h > at(8)) continue;
    const digits = glyphs.slice(d + 1).filter((g) => !isNoise(g));
    if (!digits.length || digits.length > 2 || !digits.every(isDigit)) continue;
    const baseline = Math.max(...digits.map((g) => g.bottom));
    if (Math.abs(dot.bottom - baseline) > at(3)) continue;
    const lv = glyphs.slice(0, d).filter((g) => !isNoise(g));
    if (!lv.length || lv.length > 2 || lv[0].x < dot.x - at(45) || !lv.some((g) => g.h >= at(17))) continue;
    return { lv, digits, baseline };
  }
  return null;
}

// Glyphs on a text line: runs of columns that have text, with their bounding box.
function glyphsOf(px, at, band) {
  const top = band.top - at(2);
  const bottom = band.bottom + at(2);
  const glyphs = [];
  let current = null;
  const close = () => {
    if (current) glyphs.push({ ...current, h: current.bottom - current.top + 1 });
    current = null;
  };
  for (let x = at(TALENTS.x0); x < at(TALENTS.x1); x++) {
    let first = null;
    let last = null;
    for (let y = top; y <= bottom; y++) {
      if (!px(x, y, isText)) continue;
      if (first === null) first = y;
      last = y;
    }
    if (first === null) {
      close();
      continue;
    }
    if (!current) current = { x, w: 0, top: first, bottom: last };
    current.w++;
    current.top = Math.min(current.top, first);
    current.bottom = Math.max(current.bottom, last);
  }
  close();
  return glyphs;
}

// How much of each grid cell of a glyph is text, and the glyph's width / height.
function shape(px, g) {
  const cells = [];
  for (let cy = 0; cy < GRID.h; cy++) {
    for (let cx = 0; cx < GRID.w; cx++) {
      const x0 = g.x + Math.floor((cx * g.w) / GRID.w);
      const x1 = Math.max(x0 + 1, g.x + Math.floor(((cx + 1) * g.w) / GRID.w));
      const y0 = g.top + Math.floor((cy * g.h) / GRID.h);
      const y1 = Math.max(y0 + 1, g.top + Math.floor(((cy + 1) * g.h) / GRID.h));
      cells.push(countIn(px, { x: x0, top: y0, right: x1, bottom: y1 - 1 }, isText) / ((x1 - x0) * (y1 - y0)));
    }
  }
  return { cells, aspect: g.w / g.h };
}

// The digit a glyph shows, or null when no template fits clearly.
function classify(px, g) {
  const { cells, aspect } = shape(px, g);
  const scored = TEMPLATES.map((t) => ({
    digit: t.digit,
    distance: t.cells.reduce((sum, v, i) => sum + Math.abs(v - cells[i]), 0) / cells.length + Math.abs(t.aspect - aspect),
  })).sort((a, b) => a.distance - b.distance);
  const [best, next] = scored;
  return best.distance <= MAX_DISTANCE && next.distance - best.distance >= MIN_MARGIN ? best.digit : null;
}

// Pixels passing `test` in a box (x to right exclusive, top to bottom inclusive).
function countIn(px, box, test) {
  let n = 0;
  for (let y = box.top; y <= box.bottom; y++) for (let x = box.x; x < box.right; x++) if (px(x, y, test)) n++;
  return n;
}

// Average of fn(r, g, b) on a ring around a point.
function onRing(px, cx, cy, inner, outer, fn) {
  let sum = 0;
  let n = 0;
  for (let dy = -outer; dy <= outer; dy++) {
    for (let dx = -outer; dx <= outer; dx++) {
      const d = Math.hypot(dx, dy);
      if (d < inner || d > outer) continue;
      sum += px(cx + dx, cy + dy, fn);
      n++;
    }
  }
  return sum / n;
}
