import { binarize, gray, near, readPng, rgb } from './image.js';

// Reads one detail panel (the scanner's 'panel' capture: a PNG file's buffer, or the image itself) of the
// Artifacts or Weapons tab. Positions are measured on the 656 px wide panel of a 2560x1440 screen and scaled by
// the image width. Returns the texts as the OCR saw them plus what can be told from colours alone;
// { kind: 'other' } for anything else in the list (artifact EXP bottles, enhancement ores), which has no level
// box.

const BASE_WIDTH = 656;
const BADGE = [0x39, 0x44, 0x4f]; // dark box behind the level ("+20", "Lv. 90/90")
const BADGE_TOP = 412; // where that box starts when an artifact panel has no Sanctifying Elixir bar
const ELIXIR_BAR = [0xdc, 0xc0, 0xff]; // "Sanctifying Elixir Definition" bar above an artifact's level box
const EQUIP_BAR = [0xff, 0xe7, 0xbb]; // "Equipped: <name>" bar at the bottom

const luma = (r, g, b) => (r * 77 + g * 150 + b * 29) >> 8;
const isLight = (r, g, b) => luma(r, g, b) > 165;
const isDark = (limit) => (r, g, b) => luma(r, g, b) < limit;

export async function readPanel(source, ocr, kind = 'artifacts') {
  const png = Buffer.isBuffer(source) ? readPng(source) : source;
  const s = png.width / BASE_WIDTH;
  const at = (v) => Math.round(v * s);
  const badge = findBadge(png, s);
  if (!badge) return { kind: 'other' };
  return kind === 'weapons' ? readWeapon(png, ocr, at, badge) : readArtifact(png, ocr, at, badge);
}

async function readArtifact(png, ocr, at, badge) {
  // The body holds up to four substats (a long one wraps when "(unactivated)" follows it) and the set name.
  const [slot, mainStat, mainValue, level, body, equippedBy] = await Promise.all([
    ocr.recognize(binarize(png, rect(at, 30, 88, 420, 47), isLight)),
    ocr.recognize(binarize(png, rect(at, 30, 196, 370, 44), isLight)),
    ocr.recognize(binarize(png, rect(at, 30, 240, 340, 66), isLight)), // enlarged, "7.0%" reads as "71.0%"
    ocr.recognize(binarize(png, badgeText(badge, at), isLight, { scale: 3 })),
    ocr.recognize(binarize(png, { ...rect(at, 30, 0, 620, 330), y: badge.top + at(55) }, isDark(200))),
    readEquipped(png, ocr, at),
  ]);
  const buttonY = badge.top + Math.round(badge.height / 2);
  return {
    kind: 'artifact',
    text: { slot, mainStat, mainValue, level, body, equippedBy },
    rarity: countStars(png, at, { x: 57, step: 43, y: 338 }),
    locked: gray(png, at(497), buttonY) < 150,
    astralMark: gray(png, at(572), buttonY) < 150,
    elixirCrafted: near(rgb(png, at(600), at(408)), ELIXIR_BAR, 20),
  };
}

// Weapon panel: white name on the orange header (two lines when long), "Lv. 90/90" in the level box (the
// "/90" is grey), "Refinement Rank 1" in gold under it (missing on weapons that can't be refined).
async function readWeapon(png, ocr, at, badge) {
  const isWhite = (r, g, b) => r > 200 && g > 200 && b > 200;
  const [name, level, refinement, equippedBy] = await Promise.all([
    ocr.recognize(binarize(png, rect(at, 25, 0, 590, 82), isWhite)),
    ocr.recognize(binarize(png, badgeText(badge, at), (r, g, b) => luma(r, g, b) > 120, { scale: 3 })),
    ocr.recognize(binarize(png, { ...rect(at, 90, 0, 470, 44), y: badge.top + at(54) }, isDark(200))),
    readEquipped(png, ocr, at),
  ]);
  return {
    kind: 'weapon',
    text: { name, level, refinement, equippedBy },
    rarity: countStars(png, at, { x: 55, step: 45, y: 335 }),
    locked: gray(png, at(574), badge.top + Math.round(badge.height / 2)) < 150,
  };
}

function readEquipped(png, ocr, at) {
  if (!near(rgb(png, at(640), png.height - at(19)), EQUIP_BAR, 14)) return '';
  return ocr.recognize(binarize(png, { x: at(100), y: png.height - at(68), width: at(550), height: at(62) }, isDark(150), { scale: 2 }));
}

function rect(at, x, y, width, height) {
  return { x: at(x), y: at(y), width: at(width), height: at(height) };
}

function badgeText(badge, at) {
  return { x: badge.left + at(3), y: badge.top, width: badge.right - badge.left - at(6), height: badge.height };
}

// The level box: its top edge moves down when the Sanctifying Elixir bar is shown above it. The "+" of the
// level can touch the sampled column, so gaps of a few pixels don't end the box.
function findBadge(png, s) {
  const x = Math.round(45 * s);
  const maxGap = Math.round(3 * s);
  let top = -1;
  let last = -1;
  for (let y = Math.round(380 * s); y < Math.round(580 * s); y++) {
    if (!near(rgb(png, x, y), BADGE, 18)) {
      if (top >= 0 && y - last > maxGap) {
        if (last - top + 1 >= 20 * s) return withRight(png, top, last - top + 1, s);
        top = -1;
      }
      continue;
    }
    if (top < 0) top = y;
    last = y;
  }
  return null;
}

// The box is as wide as its text; its top border row (above the text) shows where it ends.
function withRight(png, top, height, s) {
  const y = top + Math.round(2 * s);
  const left = Math.round(40 * s);
  let right = left;
  for (let x = left; x < left + 200 * s; x++) if (near(rgb(png, x, y), BADGE, 18)) right = x;
  return { top, height, left, right };
}

// The gold rarity stars in the header (x of the first one, distance between them, y).
function countStars(png, at, { x, step, y }) {
  let n = 0;
  for (let i = 0; i < 5; i++) {
    const [r, g, b] = rgb(png, at(x + step * i), at(y));
    if (r > 220 && g > 170 && b < 120) n++;
  }
  return n;
}
