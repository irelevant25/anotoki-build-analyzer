import { BASE_HEIGHT, BASE_LAYOUT, BASE_WIDTH, LABEL_COLOR, LABEL_TOLERANCE, TAB_LINE, TABS } from './config.js';
import { contains, grayRect, rgbAt } from './frame.js';

// The layout of a tab ('artifacts' or 'weapons') scaled to the screen size. Only 16:9 screens match the
// game's layout exactly.
export function scaleLayout(screenWidth, screenHeight, tab = 'artifacts') {
  const s = screenHeight / BASE_HEIGHT;
  const r = (v) => Math.round(v * s);
  const b = BASE_LAYOUT;
  const layout = {
    tab,
    scale: s,
    is16by9: Math.abs(screenWidth / screenHeight - BASE_WIDTH / BASE_HEIGHT) < 0.01,
    columns: b.columns,
    firstCardLeft: b.firstCardLeft * s,
    columnPitch: b.columnPitch * s,
    cardWidth: r(b.cardWidth),
    cardHeight: r(b.cardHeight),
    rowPitch: b.rowPitch * s,
    scrollNotch: b.scrollNotch * s,
    labelStripHeight: r(b.labelStripHeight),
    viewTop: r(TABS[tab].viewTop),
    viewBottom: r(b.viewBottom),
    panel: { x: r(b.panel.x), y: r(b.panel.y), width: r(b.panel.width), height: r(b.panel.height) },
  };
  layout.gridLeft = cardLeft(layout, 0);
  layout.gridRight = cardLeft(layout, layout.columns - 1) + layout.cardWidth;
  return layout;
}

// Which inventory tab is open (by the gold line under its icon), or null when it's none of the scannable ones.
// `frame` must contain the line (screen row TAB_LINE.y, scaled by `scale`).
export function detectTab(frame, scale) {
  const y = Math.round(TAB_LINE.y * scale);
  for (const [tab, { tabX }] of Object.entries(TABS)) {
    const c = rgbAt(frame, Math.round(tabX * scale), y);
    if (c.every((v, i) => Math.abs(v - TAB_LINE.color[i]) <= TAB_LINE.tolerance)) return tab;
  }
  return null;
}

export function cardLeft(layout, column) {
  return Math.round(layout.firstCardLeft + column * layout.columnPitch);
}

// Screen rectangle that holds the whole grid viewport (what to capture to find rows and measure scrolls),
// with room on both sides for the selected card's white frame.
export function gridRect(layout) {
  const m = Math.round(16 * layout.scale);
  return {
    x: layout.gridLeft - m,
    y: layout.viewTop,
    width: layout.gridRight - layout.gridLeft + 2 * m,
    height: layout.viewBottom - layout.viewTop + 1,
  };
}

function isLabel(frame, x, y) {
  if (!contains(frame, x, y)) return false;
  const c = rgbAt(frame, x, y);
  return Math.abs(c[0] - LABEL_COLOR[0]) <= LABEL_TOLERANCE && Math.abs(c[1] - LABEL_COLOR[1]) <= LABEL_TOLERANCE && Math.abs(c[2] - LABEL_COLOR[2]) <= LABEL_TOLERANCE;
}

// Sample points on the label strip of a card, away from the centred "+20" text.
function labelSampleXs(layout, column) {
  const left = cardLeft(layout, column);
  const right = left + layout.cardWidth;
  const s = layout.scale;
  return [left + Math.round(10 * s), left + Math.round(24 * s), right - Math.round(24 * s), right - Math.round(10 * s)];
}

// Rows of cards visible in the frame, top to bottom, found by the beige label strips of the first column.
// Each row: { labelBottom, top, clickY, fullyVisible }.
export function detectRows(frame, layout) {
  const xs = labelSampleXs(layout, 0);
  const minRun = Math.max(6, Math.round(layout.labelStripHeight / 3));
  const rows = [];
  let runStart = -1;
  for (let y = layout.viewTop; y <= layout.viewBottom + 1; y++) {
    const label = y <= layout.viewBottom && xs.every((x) => isLabel(frame, x, y));
    if (label && runStart < 0) runStart = y;
    if (!label && runStart >= 0) {
      const labelBottom = y - 1;
      if (labelBottom - runStart + 1 >= minRun) rows.push(rowAt(layout, labelBottom));
      runStart = -1;
    }
  }
  return rows;
}

// A row is fully visible when its card is, and there is room below its label for the selected card's white
// frame before the grid fades out (the frame shows that a click arrived).
export function rowAt(layout, labelBottom) {
  const top = labelBottom - layout.cardHeight + 1;
  return {
    labelBottom,
    top,
    clickY: Math.round(labelBottom - layout.cardHeight * 0.55),
    fullyVisible: top >= layout.viewTop && labelBottom + Math.round(14 * layout.scale) <= layout.viewBottom,
  };
}

// Whether a card sits in this column of the row (the last row of the inventory can be partly empty).
export function cellHasCard(frame, layout, row, column) {
  const ys = [row.labelBottom - Math.round(6 * layout.scale), row.labelBottom - Math.round(16 * layout.scale)];
  return labelSampleXs(layout, column).every((x, i) => isLabel(frame, x, ys[i % 2]));
}

// The white frame around the selected card, checked on its left, right and bottom edges only: a "New" badge
// covers the top edge and the top of the right edge, and on the top row the top edge lies in the grid's fade.
// Each edge must be a straight white line - three points along it at the same distance from the card (weapon
// art inside a card is never a whole white line). The frame is drawn 6-10 px out; a row found by a selected
// card is off by a pixel or two, so 4-13 px out is searched.
function hasFrame(frame, layout, row, column) {
  const at = (v) => Math.round(v * layout.scale);
  const left = cardLeft(layout, column);
  const right = left + layout.cardWidth - 1;
  const xs = [left + at(30), left + Math.round(layout.cardWidth / 2), right - at(30)];
  const ys = [row.top + at(50), row.top + Math.round(layout.cardHeight / 2), row.labelBottom - at(40)];
  const white = (x, y) => contains(frame, x, y) && rgbAt(frame, x, y).every((v) => v > 220);
  const edges = [(d) => ys.every((y) => white(left - d, y)), (d) => ys.every((y) => white(right + d, y)), (d) => xs.every((x) => white(x, row.labelBottom + d))];
  return edges.every((edge) => {
    for (let d = at(4); d <= at(13); d++) if (edge(d)) return true;
    return false;
  });
}

// Whether the last click selected this card: true when it has the white frame and its neighbour doesn't; null
// when the neighbour seems to have one too (a bright background behind the menu passes for white lines), so
// it can't be told. Only right after a click: the game reuses card slots when the list scrolls, and a frame
// stays on its slot, around another item. `frame` must hold the row with ~14 px around it (rowRect()).
export function isSelected(frame, layout, row, column) {
  if (!hasFrame(frame, layout, row, column)) return false;
  const neighbour = column + 1 < layout.columns ? column + 1 : column - 1;
  return hasFrame(frame, layout, row, neighbour) ? null : true;
}

export function rowRect(layout, row) {
  const grid = gridRect(layout);
  const m = Math.round(14 * layout.scale);
  return { x: grid.x, y: row.top - m, width: grid.width, height: row.labelBottom - row.top + 1 + 2 * m };
}

// The range to search for one scroll step of `notches` wheel notches. Rows of identical items look the same
// every row (rowPitch), so the range must be narrower than a row: then it holds the true distance (anything
// from 0 at the end of the list to the full step) and none of its lookalikes a row away.
export function stepWindow(layout, notches) {
  const pitch = Math.round(layout.rowPitch);
  const minShift = -Math.round(20 * layout.scale);
  const maxShift = Math.min(Math.round(notches * layout.scrollNotch + 30 * layout.scale), pitch + minShift - Math.round(4 * layout.scale));
  return { minShift, maxShift };
}

// The most notches one step may scroll and still be measured in stepWindow() with some room to spare.
export function maxStepNotches(layout) {
  const { maxShift } = stepWindow(layout, 100);
  return Math.floor((maxShift - 10 * layout.scale) / layout.scrollNotch);
}

export function cardCenterX(layout, column) {
  return cardLeft(layout, column) + Math.round(layout.cardWidth / 2);
}

// Gray copy of the grid viewport, used to measure how far a scroll moved the grid.
export function gridGray(frame, layout) {
  const r = gridRect(layout);
  return grayRect(frame, r.x, r.y, r.width, r.height);
}

// x offsets (inside the grid gray) that lie inside cards; the gaps between columns show the live game
// world behind the menu and are left out.
function cardInteriorXs(layout, gray, step) {
  const xs = [];
  const margin = Math.round(8 * layout.scale);
  for (let c = 0; c < layout.columns; c++) {
    const left = cardLeft(layout, c) - gray.x;
    for (let x = left + margin; x < left + layout.cardWidth - margin; x += step) xs.push(x);
  }
  return xs;
}

// How many pixels the grid moved up between `before` and `after` (both from gridGray). Tries every shift
// in [minShift, maxShift] on a quarter of the pixels, then the best few again on all of them, and returns the
// one where the two images line up best.
export function measureShift(before, after, layout, { minShift, maxShift } = {}) {
  const pitch = Math.round(layout.rowPitch);
  minShift ??= -Math.round(pitch / 4);
  maxShift ??= pitch + Math.round(pitch / 4);
  const w = before.w;
  const h = before.h;
  const a = before.data;
  const b = after.data;
  const errorAt = (d, xs, yStep) => {
    const yFrom = Math.max(0, -d);
    const yTo = Math.min(h, h - d);
    if (yTo - yFrom < h * 0.4) return null;
    let sum = 0;
    let n = 0;
    for (let y = yFrom; y < yTo; y += yStep) {
      const ra = (y + d) * w;
      const rb = y * w;
      for (const x of xs) {
        sum += Math.abs(a[ra + x] - b[rb + x]);
        n++;
      }
    }
    return sum / n;
  };

  const coarseXs = cardInteriorXs(layout, before, 6);
  const errors = new Map();
  let coarse = { shift: 0, error: Infinity };
  for (let d = minShift; d <= maxShift; d++) {
    const error = errorAt(d, coarseXs, 4);
    if (error === null) continue;
    errors.set(d, error);
    if (error < coarse.error) coarse = { shift: d, error };
  }
  const fineXs = cardInteriorXs(layout, before, 3);
  let best = { shift: coarse.shift, error: Infinity };
  for (let d = Math.max(minShift, coarse.shift - 4); d <= Math.min(maxShift, coarse.shift + 4); d++) {
    const error = errorAt(d, fineXs, 2);
    if (error !== null && error < best.error) best = { shift: d, error };
  }
  // Best error away from the winner, to tell a clear match from a guess.
  let runnerUp = Infinity;
  for (const [d, e] of errors) if (Math.abs(d - best.shift) > 8 && e < runnerUp) runnerUp = e;
  return { shift: best.shift, error: best.error, runnerUp, errorAtZero: errors.get(0) ?? null };
}

// Fraction of card pixels that changed between two grid grays (to see whether the grid stopped moving).
export function gridChangedFraction(a, b, layout, threshold = 40) {
  const xs = cardInteriorXs(layout, a, 2);
  let changed = 0;
  let n = 0;
  for (let y = 0; y < a.h; y += 2) {
    const r = y * a.w;
    for (const x of xs) {
      if (Math.abs(a.data[r + x] - b.data[r + x]) > threshold) changed++;
      n++;
    }
  }
  return changed / n;
}
