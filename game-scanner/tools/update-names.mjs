import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { fetchNames } from '../src/catalog.js';
import { APP } from '../src/config.js';

// npm run names - refreshes data/names.json, the game's names the program falls back on when it can't fetch the
// current ones when it starts: the same lists, from genshin.anotoki.eu's catalog.

const file = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'data', 'names.json');
const names = await fetchNames();
const out = {
  about: `The game's names (English) from ${APP.catalogUrl}/{characters,weapons,artifacts} - the program fetches them when it starts and uses this copy when it can't. The Travelers are left out: the game shows the name the player chose. Refresh with npm run names.`,
  updated: new Date().toISOString().slice(0, 10),
  ...names,
};
fs.writeFileSync(file, `${JSON.stringify(out, null, 2)}\n`);
console.log(`${file}: ${names.characters.length} characters, ${names.weapons.length} weapons, ${names.sets.length} sets`);
