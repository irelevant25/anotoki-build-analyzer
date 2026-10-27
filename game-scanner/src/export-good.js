import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { CHARACTER_PASSES } from './config.js';
import { readCharacter, READ_VERSION as CHARACTER_READ_VERSION } from './good/character.js';
import { buildGood, characterItems, reportText } from './good/good-file.js';
import { createOcr } from './good/ocr.js';
import { readPanel } from './good/panel.js';
import { stamp } from './log.js';

// npm run good [-- <scan folder> ...] [--fresh]
// For development: makes a GOOD file again from scan folders (the newest artifacts, weapons and characters scans
// in scans/, or the given folders) - what the program read while scanning (each folder's ocr.json), or, with
// --fresh or after a reader changed, the screenshots kept by `npm start -- --keep-images`. The names the player
// gave characters come from the folders' scan.json. Writes exports/good_<date_time>.json and each folder's
// good-report.txt.

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const fresh = args.includes('--fresh');
const given = args.filter((a) => !a.startsWith('--')).map((a) => path.resolve(a));

const scans = given.length ? given.map(loadScan) : newestScans();
if (!scans.length) {
  console.error('No scan found in scans/. Run a scan first (npm start) or pass the scan folders.');
  process.exit(1);
}

const customNames = Object.assign({}, ...scans.map((s) => s.customNames ?? {}));
if (!Object.keys(customNames).length) console.log('The scans carry no names of named characters (Traveler, Wanderer, Manekins): their equipment gets no location.');
for (const scan of scans) {
  console.log(`\n${scan.kind}: ${scan.items.length} ${scan.kind === 'characters' ? 'characters' : 'panels'} in ${scan.dir}`);
  scan.readings = await readAll(scan);
}
const { good, results } = buildGood(Object.fromEntries(scans.map((s) => [s.kind, s])), { customNames });
for (const scan of scans) {
  const r = results[scan.kind];
  fs.writeFileSync(path.join(scan.dir, 'good-report.txt'), reportText(scan.kind, scan, r));
  if (scan.incomplete) console.log(scan.incomplete);
  const problems = scan.kind === 'characters' ? `${r.withProblems.length} left out (problems)` : `${r.withProblems.length} with problems`;
  console.log(
    `${r.list.length} ${scan.kind}, ${r.skipped.length} other items skipped, ${r.unread.length} not read, ${problems}, ` +
      `${r.corrected.length} names corrected, ${r.unknown.length} names not among the game's (good-report.txt)`,
  );
}
if (!good.weapons) console.log('\nNo weapons scan: importing this file into a site that replaces the whole inventory removes the weapons there.');
if (!good.artifacts) console.log('\nNo artifacts scan in this file.');
if (!good.characters) console.log('\nNo characters in this file.');

fs.mkdirSync(path.join(root, 'exports'), { recursive: true });
const out = path.join(root, 'exports', `good_${stamp()}.json`);
fs.writeFileSync(out, JSON.stringify(good, null, 2));
console.log(`\nGOOD file: ${out}`);

// The readings of a scan: its ocr.json, the screenshots read again for what it lacks (or everything, --fresh).
async function readAll(scan) {
  const cacheFile = path.join(scan.dir, 'ocr.json');
  const cache = !fresh && fs.existsSync(cacheFile) ? JSON.parse(fs.readFileSync(cacheFile, 'utf8')) : {};
  const image = (file) => (file && fs.existsSync(path.join(scan.dir, file)) ? fs.readFileSync(path.join(scan.dir, file)) : undefined);
  const stale = (e) => !cache[e.file] || (scan.kind === 'characters' && cache[e.file].version !== CHARACTER_READ_VERSION);
  const todo = scan.items.filter(stale).filter((e) => (e.files ? Object.values(e.files).some((f) => image(f)) : image(e.file)));
  if (todo.length) {
    const ocr = await createOcr();
    const started = Date.now();
    let done = 0;
    console.log(`OCR of ${todo.length} ${scan.kind === 'characters' ? 'characters' : 'panels'} with ${ocr.workers} workers...`);
    await Promise.all(
      todo.map(async (e) => {
        cache[e.file] =
          scan.kind === 'characters'
            ? await readCharacter({ attributes: image(e.files.attributes), constellation: image(e.files.constellation), talents: image(e.files.talents) }, ocr)
            : await readPanel(image(e.file), ocr, scan.kind);
        if (++done % 200 === 0) console.log(`  ${done}/${todo.length} (${((Date.now() - started) / 1000).toFixed(0)} s)`);
      }),
    );
    await ocr.terminate();
    fs.writeFileSync(cacheFile, JSON.stringify(cache));
  }
  return cache;
}

function loadScan(dir) {
  const json = JSON.parse(fs.readFileSync(path.join(dir, 'scan.json'), 'utf8'));
  if (json.kind === 'characters') return { dir, kind: 'characters', customNames: json.customNames, ...characterItems(json.passes ?? {}, CHARACTER_PASSES) };
  return { dir, kind: json.kind ?? 'artifacts', customNames: json.customNames, items: json.items ?? json.artifacts };
}

// The newest scan of each kind (folders are named by date and time, so the last one sorts last).
function newestScans() {
  const base = path.join(root, 'scans');
  if (!fs.existsSync(base)) return [];
  const all = fs
    .readdirSync(base)
    .sort()
    .filter((d) => fs.existsSync(path.join(base, d, 'scan.json')))
    .map((d) => loadScan(path.join(base, d)));
  return ['artifacts', 'weapons', 'characters'].map((kind) => all.filter((s) => s.kind === kind).at(-1)).filter(Boolean);
}
