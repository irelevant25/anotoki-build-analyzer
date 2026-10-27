import { CHARACTER_SCREEN } from '../../src/config.js';
import { constellationCount, readConstellations, readTalentLevels } from '../../src/good/character-screen.js';
import { characterFrame } from './character-fixtures.js';

// A simulated character screen (C) on a 2560x1440 screen, to run the real scanner against:
// - the Q and E key hints, the menu on the left with the open tab lit (a click on an entry opens that tab a
//   little later);
// - a gold "Element / Name" header, drawn as blocks unique to the character - or the fixture's own (realHeaders;
//   then no two characters may show the same fixture: the scanner tells them apart by the header);
// - the open tab's data on the right, taken from real screenshots (test/fixtures/characters): the i-th character
//   shows characters[i % characters.length]. It fades in over fadeMs after a tab opens and after E, so a
//   screenshot taken too early shows it half drawn;
// - E shows the next character after a delay, with a blank moment in between; the data appears a little after
//   the header. At the end of the list E goes back to the first character (wrap) or does nothing;
// - small white sparkles drift over the data all the time.
// Time is virtual: sleeping advances it at once. Saved screenshots are checked when saved (frames are too big to
// keep): `saved` gets which character the header and the data show, how far the data had faded in (alpha 0-1)
// and what the readers make of it (Constellation: the count, Talents: "level+/level/level", + = raised).

export const FIXTURE_CHARACTERS = ['traveler', 'escoffier', 'skirk', 'ayaka', 'raiden', 'xiangling', 'amber', 'mizuki', 'kokomi', 'tartaglia', 'lanyan', 'xingqiu'];

const BACKGROUND = [0x20, 0x40, 0x90];

export class FakeCharacterScreen {
  constructor(count, { tab = 'attributes', wrap = true, start = 0, characters = FIXTURE_CHARACTERS, fadeMs = 250, realHeaders = false } = {}) {
    this.count = count;
    this.tab = tab;
    this.wrap = wrap;
    this.characters = characters;
    this.fadeMs = fadeMs;
    this.realHeaders = realHeaders;
    this.width = 2560;
    this.height = 1440;
    this.screen = Buffer.alloc(this.width * this.height * 4);
    this.header = start; // character whose header is shown (null: blank)
    this.content = start; // character whose data the tab shows (null: blank)
    this.shownAt = -fadeMs; // when the data started to fade in (drawn in full at the start)
    this.t = 0;
    this.events = [];
    this.presses = 0;
    this.saved = [];
  }

  // The fixture the i-th character shows.
  fixture(i) {
    return this.characters[i % this.characters.length];
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
        this.advance(3);
        return this.capture(rect ?? { x: 0, y: 0, width: this.width, height: this.height });
      },
      click: async (x, y) => {
        this.advance(60);
        for (const [tab, row] of Object.entries(CHARACTER_SCREEN.menu.tabs)) {
          if (Math.abs(y - row) < 20 && x > 150 && x < 480) {
            this.later(300, () => {
              this.tab = tab;
              this.shownAt = this.t;
            });
          }
        }
      },
      pressKey: async (name) => {
        this.advance(40);
        if (name !== 'E' || this.header === null) return;
        this.presses++;
        const next = this.header + 1 < this.count ? this.header + 1 : this.wrap ? 0 : this.header;
        if (next === this.header) return;
        const latency = 80 + ((this.presses * 53) % 170);
        this.later(latency, () => (this.header = this.content = null));
        this.later(latency + 50, () => (this.header = next));
        this.later(latency + 50 + ((this.presses * 37) % 160), () => {
          this.content = next;
          this.shownAt = this.t;
        });
      },
      createSaver: () => ({
        save: (file, frame) => {
          const marker = (x) => frame.data[((1430 - frame.top) * frame.width + x - frame.left) * 4 + 2];
          const tab = ['attributes', 'constellation', 'talents'].find((t) => file.includes(t));
          if (file.includes('images')) this.saved.push({ file, header: marker(3), content: marker(9), alpha: marker(15) / 255, reading: readingOf(tab, frame) });
          return Promise.resolve();
        },
        close: async () => [],
      }),
    };
  }

  later(ms, apply) {
    this.events.push({ at: this.t + ms, apply });
  }

  advance(ms) {
    this.t += ms;
    this.events.sort((a, b) => a.at - b.at);
    while (this.events.length && this.events[0].at <= this.t) this.events.shift().apply();
  }

  capture(rect) {
    this.render();
    const data = Buffer.alloc(rect.width * rect.height * 4);
    for (let y = 0; y < rect.height; y++) {
      const from = ((rect.y + y) * this.width + rect.x) * 4;
      this.screen.copy(data, y * rect.width * 4, from, from + rect.width * 4);
    }
    return { left: rect.x, top: rect.y, width: rect.width, height: rect.height, data, channels: 4, bgr: true };
  }

  render() {
    this.fill(0, 0, this.width, this.height, BACKGROUND);
    for (const [x, y] of CHARACTER_SCREEN.keyHints) this.fill(x - 4, y - 8, x + 5, y + 9, [255, 255, 255]);
    const row = CHARACTER_SCREEN.menu.tabs[this.tab];
    this.fill(200, row - 10, 460, row + 10, [255, 255, 255]);
    const h = CHARACTER_SCREEN.header;
    if (this.header !== null && this.realHeaders) {
      this.draw(fixturePixels(this.fixture(this.header), 'attributes', true), 1);
      this.fill(3, 1430, 4, 1431, [this.header, 0, 0]);
    } else if (this.header !== null) {
      for (let k = 0; k < 6; k++) {
        const x = h.x + 10 + ((this.header * 71 + k * 59) % (h.width - 40));
        this.fill(x, h.y + 5 + (k % 2) * 20, x + 26, h.y + 20 + (k % 2) * 20, [0xd0, 0xc0, 0x90]);
      }
      this.fill(3, 1430, 4, 1431, [this.header, 0, 0]);
    }
    const c = CHARACTER_SCREEN.content[this.tab];
    if (c && this.content !== null) {
      const alpha = Math.min(1, Math.max(0, (this.t - this.shownAt) / this.fadeMs));
      this.draw(fixturePixels(this.fixture(this.content), this.tab), alpha);
      this.fill(9, 1430, 10, 1431, [this.content, 0, 0]);
      this.fill(15, 1430, 16, 1431, [Math.round(alpha * 255), 0, 0]);
    }
    for (let k = 0; k < 4 && c; k++) {
      const x = c.x + ((this.t * 7 + k * 101) % (c.width - 4));
      const y = c.y + ((this.t * 3 + k * 23) % (c.height - 4));
      this.fill(x, y, x + 3, y + 3, [250, 250, 250]);
    }
  }

  // The fixture's pixels blended over the background: alpha 0 = not there yet, 1 = fully drawn.
  draw(pixels, alpha) {
    const s = this.screen;
    const [br, bg, bb] = BACKGROUND;
    for (const { at, r, g, b } of pixels) {
      s[at] = Math.round(bb + alpha * (b - bb));
      s[at + 1] = Math.round(bg + alpha * (g - bg));
      s[at + 2] = Math.round(br + alpha * (r - br));
    }
  }

  fill(x0, y0, x1, y1, [r, g, b]) {
    const pixel = Buffer.from([b, g, r, 255]);
    for (let y = Math.max(0, y0); y < Math.min(this.height, y1); y++) {
      this.screen.fill(pixel, (y * this.width + Math.max(0, x0)) * 4, (y * this.width + Math.min(this.width, x1)) * 4);
    }
  }
}

// The non-black pixels of a fixture right of the header (the tab's data) - or of the header alone - as screen
// byte offsets.
const pixelCache = new Map();
function fixturePixels(name, tab, header = false) {
  const key = `${name}-${tab}-${header}`;
  if (!pixelCache.has(key)) {
    const frame = characterFrame(name, tab);
    const pixels = [];
    for (let y = 0; y < frame.height; y++) {
      for (let x = 0; x < frame.width; x++) {
        if (y < 120 && x < 700 ? !header : header) continue;
        const i = (y * frame.width + x) * 4;
        const [r, g, b] = [frame.data[i], frame.data[i + 1], frame.data[i + 2]];
        if (r || g || b) pixels.push({ at: (y * 2560 + x) * 4, r, g, b });
      }
    }
    pixelCache.set(key, pixels);
  }
  return pixelCache.get(key);
}

// What the readers make of a tab: the scanner waits for exactly this, the export reads it.
export function readingOf(tab, frame) {
  if (tab === 'constellation') return constellationCount(readConstellations(frame));
  if (tab === 'talents') {
    return readTalentLevels(frame)
      .filter(Boolean)
      .slice(0, 3)
      .map((t) => `${t.level}${t.boosted ? '+' : ''}`)
      .join('/');
  }
  return null;
}
