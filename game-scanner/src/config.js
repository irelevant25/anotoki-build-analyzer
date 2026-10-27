// Everything that can be tuned lives here. Pixel values are measured on a 2560x1440 screen and scaled
// to the actual screen size (16:9 only), so they keep working at 1920x1080 or 3840x2160.

export const BASE_WIDTH = 2560;
export const BASE_HEIGHT = 1440;

// The inventory grid and the detail panel on a 2560x1440 screen (the same on the Artifacts and Weapons tabs,
// except where the grid starts: the Artifacts tab has the "Show 5-star Artifacts..." bar above it).
export const BASE_LAYOUT = {
  columns: 8,
  firstCardLeft: 158, // left edge of the first column's cards
  columnPitch: 195.3, // distance between the left edges of two neighbouring columns
  cardWidth: 162,
  cardHeight: 200, // top of a card to the last row of its beige label strip ("+20", "Lv. 90")
  rowPitch: 234, // distance between two rows of cards
  scrollNotch: 24, // how far one mouse wheel notch moves the grid
  labelStripHeight: 30,
  viewBottom: 1270, // last screen row where cards are fully drawn (the grid fades out below)
  panel: { x: 1744, y: 160, width: 656, height: 1119 }, // detail panel on the right
};

// The inventory tabs that can be scanned. The open tab has a gold line under its icon at y=125 (tabX = its
// middle); viewTop is the first screen row where cards are fully drawn.
export const TABS = {
  artifacts: { tabX: 900, viewTop: 214 },
  weapons: { tabX: 775, viewTop: 150 },
};
export const TAB_LINE = { y: 125, color: [0xd3, 0xbc, 0x8e], tolerance: 16 };
// The open tab's name at the top left of the inventory ("Weapons", "Artifacts", "Food", ...), up to the Q key
// hint: read to tell which tab is open and that the game is in English.
export const INVENTORY_TITLE = { x: 180, y: 32, width: 450, height: 64 };

// The character screen (C key): white Q and E key hints at the top (points inside the boxes, beside the
// letters); the menu on the left, where the open tab's entry is large and white (y = the entry's middle, clicked
// at clickX to open the tab); the gold "Element / Name" at the top left, on every tab; and per tab the text on
// the right that differs from character to character (name and level, the constellations' names right of their
// icons - light grey while locked -, the talents). Only pixels of the text's colour are compared, so the
// animated background is ignored.
export const CHARACTER_SCREEN = {
  keyHints: [
    [641, 63],
    [672, 63],
    [1886, 63],
    [1918, 63],
  ],
  menu: {
    x: 190,
    width: 290,
    clickX: 300,
    halfHeight: 16,
    minWhitePixels: 1000,
    tabs: { attributes: 205, weapons: 298, artifacts: 390, constellation: 484, talents: 576, profile: 668 },
  },
  header: { x: 190, y: 40, width: 450, height: 52, color: 'gold' },
  content: {
    attributes: { x: 1950, y: 168, width: 420, height: 150, color: 'white' },
    constellation: { x: 2200, y: 300, width: 340, height: 880, color: 'light' },
    talents: { x: 1900, y: 170, width: 380, height: 700, color: 'light' },
  },
};

// The tabs a character scan goes through, in this order, all from the same first character.
export const CHARACTER_PASSES = ['attributes', 'constellation', 'talents'];

// Colour of the beige label strip under every card (where "+0" / "+20" is written).
export const LABEL_COLOR = [0xe9, 0xe5, 0xdc];
export const LABEL_TOLERANCE = 10;

export const SETTINGS = {
  hotkeyLabel: 'Ctrl+S',

  // What to save for every item: 'panel' = only the detail panel on the right (small, everything needed to
  // read the item), 'full' = the whole screen.
  capture: 'panel',

  // Scrolling: every fully visible row is scanned, then the list is scrolled this many wheel notches (24 px
  // each at 2560x1440; a row is 234 px). Rows of identical items repeat every row, so a step can only be
  // measured when it moves clearly less than a row: 8 notches (192 px) is the most.
  scrollTicksPerStep: 8,
  wheelDelta: 120, // one wheel notch on Windows
  scrollTickDelayMs: 30, // every scroll so far moved exactly 24 px per notch at this pace
  // Scroll steps in a row without a new row to scan before the scan gives up (each step shows ~0.8 rows).
  maxStepsWithoutRow: 3,

  // Mouse.
  afterMoveDelayMs: 30,
  clickHoldMs: 30,

  // Waiting for the detail panel to show the clicked item.
  panelPollMs: 20,
  panelChangeTimeoutMs: 1200, // no change and no sign of the click this long: the card is clicked once more
  // An item identical to the previous one (dozens of the same weapon) leaves the panel unchanged. It is taken
  // as it is once the clicked card shows the white selection frame and this long has passed since the click
  // (the panel of a different item has always changed within ~230 ms)...
  panelSameItemMs: 250,
  // ...or after this long when a bright background behind the menu hides whether the frame is there.
  panelSameItemUnsureMs: 600,
  panelPixelThreshold: 40, // gray difference that counts a pixel as changed
  panelChangedPixels: 30, // at least this many changed pixels = the panel shows something new
  panelStablePixels: 3, // at most this many changed pixels between two polls = the panel stopped moving

  // Waiting for the grid to stop moving after a scroll: wait at least settleMinDelayMs, then until
  // settleStableCount polls in a row see (almost) no change.
  settleMinDelayMs: 150,
  settlePollMs: 30,
  settleStableCount: 2,
  settleTimeoutMs: 2500,
  settleMaxChangedFraction: 0.002,

  // Save a full screenshot whenever something unexpected happens (into the scan's debug folder).
  debugScreenshots: true,

  // Character screen: E shows the next character. Before the first E the scan waits for you to let go of
  // Ctrl+S; after each E (and after opening a tab) it waits until the header and the tab's data show the next
  // character and are ready: Attributes' name and level drawn and still and every ascension star clearly lit or
  // grey, every constellation ring clearly lit or dark, three talent levels readable - the same in
  // characterSteadyLooks looks in a row.
  keyHoldMs: 40,
  keysReleaseMs: 500,
  characterPollMs: 40,
  characterSteadyLooks: 2,
  characterSwitchTimeoutMs: 4000, // the header didn't change this long after E: the list has ended
  characterContentWaitMs: 1500, // the tab's data didn't change this long after the header did: taken as it is
  // Two names a capital letter apart ("ivantelle", "Ivantelle") differ by ~260-325 gold pixels; one header seen
  // twice in one tab by up to ~65, across tabs (another background behind it) by up to ~155.
  characterChangedPixels: 200, // the header changed at least this much: another character
  characterContentChangedPixels: 300, // the tab's data changed at least this much: it shows the new character
  characterMinHeaderPixels: 300, // gold text pixels a header has at least (blank while switching characters)
  characterMinContentPixels: 150, // text pixels the tab's data has at least once shown
  characterMinAttributesPixels: 1500, // white pixels of a drawn name and level (~3000-7000; fading in: fewer)
  characterStablePixels: 120, // at most this much change between two looks: it stopped moving
  characterSamePixels: 120, // the header at most this much different from the pass's first one: the list came round
  tabOpenTimeoutMs: 4000,
  maxCharacters: 400,
};

// The program around the scans: where the game's names come from, where the GOOD file is imported, and how
// many OCR workers read the screenshots while a scan runs (reading keeps well ahead of scanning with two).
export const APP = {
  title: 'Genshin Impact scanner for builder.anotoki.eu',
  catalogUrl: 'https://genshin.anotoki.eu/api/catalog',
  catalogTimeoutMs: 15000,
  importUrl: 'https://builder.anotoki.eu/import',
  ocrWorkers: 2,
};

// The characters the player names: shown under that name on the character screen and in "Equipped: <name>".
// The program asks for each name when it starts; the GOOD key is the character's ('Traveler' gets its element
// from the character scan, e.g. TravelerCryo, and equipment on it the location 'Traveler').
export const NAMED_CHARACTERS = [
  { key: 'Traveler', who: 'your Traveler (the name you gave yourself)', required: true },
  { key: 'Wanderer', who: 'the Wanderer', fallback: 'Wanderer' },
  { key: 'Manekin', who: 'the Manekin (the boy)', fallback: 'Manekin' },
  { key: 'Manekina', who: 'the Manekina (the girl)', fallback: 'Manekina' },
];
