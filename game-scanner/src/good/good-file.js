import { parseCharacter } from './character.js';
import { parseArtifact, parseWeapon } from './parse.js';

// Turns the readings of the scans into a GOOD file (format version 2, or 3 when a character is above level 90).
// scans: { artifacts, weapons, characters } (any may be missing), each { dir, items, readings } -
//   items: the scan's items in order ({ file } for a panel, { file, files } for a character);
//   readings: what was read, by the item's `file` (readPanel() / readCharacter() results).
// A character scan whose three tabs don't hold the same characters (`incomplete`) gives no characters: the
// file's constellations and talents replace what a site knows, so guessing them is worse than leaving them out.
export function buildGood(scans, { customNames = {}, source = 'anotoki-scanner' } = {}) {
  const good = { format: 'GOOD', version: 2, source };
  const results = {};
  for (const kind of ['artifacts', 'weapons', 'characters']) {
    const scan = scans[kind];
    if (!scan) continue;
    const result = kind === 'characters' ? parseCharacters(scan, customNames) : parseItems(scan, kind, customNames);
    results[kind] = result;
    if (!scan.incomplete) good[kind] = result.list;
  }
  if (good.characters?.some((c) => c.level > 90)) good.version = 3; // levels above 90 came with version 3
  return { good, results };
}

function parseItems(scan, kind, customNames) {
  const weapons = kind === 'weapons';
  const parsed = scan.items.map((entry) => ({ entry, panel: scan.readings[entry.file] })).filter((p) => p.panel);
  const items = parsed
    .filter((p) => p.panel.kind === (weapons ? 'weapon' : 'artifact'))
    .map((p) => ({ ...p, ...(weapons ? parseWeapon(p.panel, { customNames }) : parseArtifact(p.panel, { customNames })) }));
  return {
    items,
    list: items.map((item) => (weapons ? item.weapon : item.artifact)),
    skipped: parsed.filter((p) => !items.some((item) => item.entry === p.entry)),
    unread: scan.items.filter((entry) => !scan.readings[entry.file]),
    withProblems: items.filter((item) => item.problems.length),
    ...nameTallies(items),
  };
}

// Characters: the Attributes, Constellation and Talents screenshots of each, read together. One that didn't
// read cleanly is left out of the file: an import keeps what it knows of a character the file doesn't have
// (and its gear still finds it), while a wrong level, constellation or talent would replace the right one.
function parseCharacters(scan, customNames) {
  const items = scan.items
    .filter((entry) => scan.readings[entry.file])
    .map((entry) => ({ entry, panel: { text: scan.readings[entry.file] }, ...parseCharacter(scan.readings[entry.file], { customNames }) }));
  return {
    items,
    list: items.filter((item) => !item.problems.length).map((item) => item.character),
    skipped: [],
    unread: scan.items.filter((entry) => !scan.readings[entry.file]),
    withProblems: items.filter((item) => item.problems.length),
    ...nameTallies(items),
  };
}

function nameTallies(items) {
  const names = items.flatMap((item) => item.names.map((n) => ({ ...n, file: item.entry.file })));
  return {
    corrected: tallyNames(
      names.filter((n) => n.corrected),
      (n) => `${n.kind} "${n.read}" → ${n.name}`,
    ),
    unknown: tallyNames(
      names.filter((n) => !n.known),
      (n) => `${n.kind} "${n.read}"`,
    ),
  };
}

// The same name read over and over is listed once, with how often and where first.
function tallyNames(names, describe) {
  const seen = new Map();
  for (const n of names) {
    const key = describe(n);
    const entry = seen.get(key) ?? { key, count: 0, file: n.file };
    entry.count++;
    seen.set(key, entry);
  }
  return [...seen.values()].map((e) => `${e.key} (${e.count}×, first ${e.file})`);
}

// The good-report.txt of a scan: what didn't read cleanly (with what the OCR saw), the names corrected to the
// game's names and the ones not among them, the other items skipped.
export function reportText(kind, scan, r) {
  const lines = [`GOOD export of ${scan.dir}`, `${r.list.length} ${kind}, ${r.skipped.length} other items skipped`, ''];
  if (scan.incomplete) lines.push(scan.incomplete, '');
  if (r.unread.length) lines.push(`Not read (${r.unread.length}): ${r.unread.map((e) => e.file).join(', ')}`, '');
  lines.push(kind === 'characters' ? `Problems - left out of the GOOD file (${r.withProblems.length}):` : `Problems (${r.withProblems.length}):`);
  for (const item of r.withProblems) {
    const where = item.entry.files ? Object.values(item.entry.files).filter(Boolean).join(', ') : `row ${item.entry.row}, column ${item.entry.column}`;
    const name = item.character?.key ? ` ${item.character.key}` : '';
    lines.push(`  ${item.entry.file}${name} (${where}): ${item.problems.join('; ')}`);
    lines.push(`    read: ${JSON.stringify(item.panel.text)}`);
  }
  lines.push('', `Names corrected to the game's names (${r.corrected.length}):`, ...r.corrected.map((c) => `  ${c}`));
  lines.push('', `Names not among the game's names, kept as read - check the spelling (${r.unknown.length}):`, ...r.unknown.map((c) => `  ${c}`));
  lines.push('', 'Skipped items:', ...r.skipped.map((p) => `  ${p.entry.file}`));
  return lines.join('\n') + '\n';
}

// The items of a character scan (scan.json's passes): the nth screenshot of each tab shows the nth character.
// `incomplete` says why the characters can't go into a GOOD file, when the tabs don't hold the same number.
export function characterItems(passes, tabs) {
  const counts = tabs.map((tab) => passes[tab]?.length ?? 0);
  const items = Array.from({ length: Math.max(0, ...counts) }, (_, i) => ({
    index: i + 1,
    file: `character-${i + 1}`,
    files: Object.fromEntries(tabs.map((tab) => [tab, passes[tab]?.[i]?.file])),
  }));
  const incomplete =
    new Set(counts).size !== 1 || counts[0] === 0 ? `Characters left out of the GOOD file: the tabs have ${tabs.map((t, i) => `${counts[i]} (${t})`).join(', ')} characters - scan them again.` : null;
  return { items, incomplete };
}
