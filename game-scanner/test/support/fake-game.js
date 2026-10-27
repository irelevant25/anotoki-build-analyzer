import { LABEL_COLOR, TAB_LINE, TABS } from '../../src/config.js';
import { cardLeft, scaleLayout } from '../../src/grid.js';

// A simulated inventory on a 2560x1440 screen, to run the real scanner against. It behaves like the game where
// the scanner relies on it:
// - cards with a beige label strip under them, 8 per row, a row every 234 px;
// - a white frame around the selected card, which stays on its card slot when the list scrolls (the game
//   reuses slots, so after a scroll the frame sits around another item);
// - a detail panel that shows the clicked item a little after the click;
// - 24 px of scrolling per wheel notch, until the end of the list (the last row stops ~100 px above the bottom).
// Time is virtual: sleeping advances it at once, so a scan of hundreds of items takes seconds.
//
// items: [{ look, panel, isNew }] - cards with the same look look the same, items with the same panel show the
// same panel (identical items have both the same).
export class FakeGame {
  constructor(items, { tab = 'weapons', dropClicks = [], panelLatencyMs = [60, 150] } = {}) {
    this.items = items;
    this.tab = tab;
    this.L = scaleLayout(2560, 1440, tab);
    this.width = 2560;
    this.height = 1440;
    this.screen = Buffer.alloc(this.width * this.height * 4);
    this.firstLabelBottom = tab === 'weapons' ? 362 : 441; // the first row's label, list at the top
    this.rows = Math.ceil(items.length / 8);
    this.maxOffset = Math.max(0, this.firstLabelBottom + (this.rows - 1) * 234 - 1170);
    this.offset = 0;
    this.panelItem = 0; // the first item is selected when the inventory opens
    this.selection = { slot: 0, column: 0 };
    this.t = 0;
    this.events = [];
    this.dropClicks = new Set(dropClicks);
    this.panelLatencyMs = panelLatencyMs;
    this.clicks = 0;
    this.saved = []; // { file, panel } for every image the scanner saved
    this.dirty = true;
  }

  io() {
    return {
      now: () => this.t,
      sleep: async (ms, signal) => {
        signal?.check();
        this.advance(ms);
        await Promise.resolve();
        signal?.check();
      },
      screenSize: async () => ({ width: this.width, height: this.height }),
      grab: async (rect) => {
        this.advance(2);
        return this.capture(rect ?? { x: 0, y: 0, width: this.width, height: this.height });
      },
      click: async (x, y) => this.click(x, y),
      scroll: async (notches) => {
        for (let i = 0; i < Math.abs(notches); i++) {
          this.later(40, () => (this.offset = Math.min(this.maxOffset, Math.max(0, this.offset + Math.sign(notches) * 24))));
          this.advance(30);
        }
      },
      createSaver: () => ({
        save: (file, frame) => {
          this.saved.push({ file, panel: panelIdIn(frame) });
          return Promise.resolve();
        },
        close: async () => [],
      }),
    };
  }

  // The real click takes ~60 ms (move, press, release); the card is selected right after, its panel shows a
  // little later.
  click(x, y) {
    this.clicks++;
    this.advance(60);
    if (this.dropClicks.has(this.clicks)) return;
    const hit = this.itemAt(x, y);
    if (hit === null) return;
    const [lo, hi] = this.panelLatencyMs;
    const latency = lo + ((this.clicks * 37) % (hi - lo + 1)); // varies from click to click
    const slot = Math.floor(hit / 8) - Math.floor(this.offset / 234);
    this.later(20, () => (this.selection = { slot, column: hit % 8 }));
    this.later(latency, () => (this.panelItem = hit));
  }

  itemAt(x, y) {
    const L = this.L;
    for (let row = 0; row < this.rows; row++) {
      const bottom = this.labelBottomOf(row);
      if (y < bottom - 199 || y > bottom || y < L.viewTop || y > L.viewBottom) continue;
      for (let column = 0; column < 8; column++) {
        const left = cardLeft(L, column);
        const index = row * 8 + column;
        if (x >= left && x < left + L.cardWidth && index < this.items.length) return index;
      }
    }
    return null;
  }

  labelBottomOf(row) {
    return this.firstLabelBottom + row * 234 - this.offset;
  }

  later(ms, apply) {
    this.events.push({ at: this.t + ms, apply });
  }

  advance(ms) {
    this.t += ms;
    this.events.sort((a, b) => a.at - b.at);
    while (this.events.length && this.events[0].at <= this.t) {
      this.events.shift().apply();
      this.dirty = true;
    }
  }

  capture(rect) {
    if (this.dirty) this.render();
    const data = Buffer.alloc(rect.width * rect.height * 4);
    for (let y = 0; y < rect.height; y++) {
      const from = ((rect.y + y) * this.width + rect.x) * 4;
      this.screen.copy(data, y * rect.width * 4, from, from + rect.width * 4);
    }
    return { left: rect.x, top: rect.y, width: rect.width, height: rect.height, data, channels: 4, bgr: true };
  }

  render() {
    const L = this.L;
    this.dirty = false;
    this.fill(0, 0, this.width, this.height, [0x40, 0x3a, 0x48]);
    const tabX = TABS[this.tab].tabX;
    this.fill(tabX - 60, TAB_LINE.y - 2, tabX + 60, TAB_LINE.y + 3, TAB_LINE.color);
    const clip = [L.viewTop - 8, L.viewBottom + 8];
    this.items.forEach((item, index) => {
      const bottom = this.labelBottomOf(Math.floor(index / 8));
      if (bottom < clip[0] || bottom - 199 > clip[1]) return;
      this.card(cardLeft(L, index % 8), bottom, item, clip);
    });
    // The selection frame sits on a card slot: slots stay put while the list scrolls by less than a row.
    const { slot, column } = this.selection;
    const top = this.firstLabelBottom - 199 + slot * 234 - (this.offset % 234);
    const left = cardLeft(L, column);
    const right = left + L.cardWidth;
    const white = [255, 255, 255];
    this.fill(left - 9, top - 9, left - 5, top + 209, white, clip);
    this.fill(right + 5, top - 9, right + 9, top + 209, white, clip);
    this.fill(left - 9, top - 9, right + 9, top - 5, white, clip);
    this.fill(left - 9, top + 205, right + 9, top + 209, white, clip);
    // A "New" badge is drawn over the frame, as in the game.
    this.items.forEach((item, index) => {
      if (!item.isNew) return;
      const bottom = this.labelBottomOf(Math.floor(index / 8));
      const l = cardLeft(L, index % 8);
      this.fill(l + 80, bottom - 205, l + L.cardWidth + 4, bottom - 167, [0xf0, 0xc0, 0x40], clip);
    });
    // The grid fades out above viewTop and below viewBottom: nothing there is bright white.
    for (const [from, to] of [
      [L.viewTop - 12, L.viewTop - 1],
      [L.viewBottom + 1, L.viewBottom + 12],
    ]) {
      for (let y = from; y <= to; y++) {
        for (let i = (y * this.width + L.gridLeft - 24) * 4, end = (y * this.width + L.gridRight + 24) * 4; i < end; i += 4) {
          for (let c = 0; c < 3; c++) this.screen[i + c] = Math.round(this.screen[i + c] * 0.75);
        }
      }
    }
    this.panel(this.items[this.panelItem].panel);
  }

  // A card: a body whose bars depend on its look, and the beige label strip with a dark "Lv. 90" block.
  card(left, bottom, item, clip) {
    const right = left + this.L.cardWidth;
    const top = bottom - 199;
    const look = item.look;
    this.fill(left, top, right, bottom - 30, [((look * 53) % 200) + 30, ((look * 97) % 200) + 30, ((look * 31) % 200) + 30], clip);
    for (let k = 0; k < 4; k++) {
      const y = top + 12 + ((look * 37 + k * 41) % 140);
      this.fill(left + 4, y, right - 4, y + 8, [(look * 11 + k * 60) % 255, 40, 200 - k * 40], clip);
      const x = left + 14 + ((look * 53 + k * 29) % 120);
      this.fill(x, top + 6, x + 6, bottom - 34, [200 - k * 40, (look * 13) % 255, 90], clip);
    }
    this.fill(left, bottom - 30, right, bottom + 1, LABEL_COLOR, clip);
    this.fill(left + 55, bottom - 24, left + 105, bottom - 6, [0x50, 0x48, 0x40], clip);
  }

  // The detail panel: beige with dark blocks placed by the panel id; the id itself is in pixel (1750, 170).
  panel(id) {
    this.fill(1744, 160, 2400, 1279, [0xec, 0xe5, 0xd8]);
    for (let k = 0; k < 8; k++) {
      const x = 1790 + ((id * 71 + k * 113) % 520);
      const y = 300 + k * 110;
      this.fill(x, y, x + 60, y + 30, [0x3c, 0x3c, 0x46]);
    }
    this.fill(1750, 170, 1751, 171, [id & 255, (id >> 8) & 255, 77]);
  }

  fill(x0, y0, x1, y1, [r, g, b], clip) {
    const xa = Math.max(0, Math.round(x0));
    const xb = Math.min(this.width, Math.round(x1));
    let ya = Math.max(0, Math.round(y0));
    let yb = Math.min(this.height, Math.round(y1));
    if (clip) {
      ya = Math.max(ya, clip[0]);
      yb = Math.min(yb, clip[1]);
    }
    if (xa >= xb || ya >= yb) return;
    const pixel = Buffer.from([b, g, r, 255]);
    for (let y = ya; y < yb; y++) this.screen.fill(pixel, (y * this.width + xa) * 4, (y * this.width + xb) * 4);
  }
}

// The panel id the fake drew into a captured frame (a panel or a whole screen).
function panelIdIn(frame) {
  const i = ((170 - frame.top) * frame.width + (1750 - frame.left)) * 4;
  return frame.data[i + 2] + (frame.data[i + 1] << 8);
}
