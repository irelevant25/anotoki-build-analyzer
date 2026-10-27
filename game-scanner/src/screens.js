import { CHARACTER_SCREEN } from './config.js';
import { contains, rgbAt } from './frame.js';

// Whether the character screen (C) is open, and on which tab: 'attributes', 'weapons', 'artifacts',
// 'constellation', 'talents', 'profile', or 'unknown' when no menu entry is lit; null when the screen isn't
// open. `frame` must hold the whole screen; `scale` is the screen height / 1440.
export function detectCharacterTab(frame, scale) {
  const at = (v) => Math.round(v * scale);
  const white = (x, y) => contains(frame, x, y) && rgbAt(frame, x, y).every((v) => v > 235);
  if (!CHARACTER_SCREEN.keyHints.every(([x, y]) => white(at(x), at(y)))) return null;
  const { menu } = CHARACTER_SCREEN;
  let open = 'unknown';
  let most = menu.minWhitePixels * scale * scale;
  for (const [tab, y] of Object.entries(menu.tabs)) {
    let count = 0;
    for (let yy = at(y - menu.halfHeight); yy <= at(y + menu.halfHeight); yy++) {
      for (let x = at(menu.x); x < at(menu.x + menu.width); x++) if (white(x, yy)) count++;
    }
    if (count >= most) [open, most] = [tab, count];
  }
  return open;
}
