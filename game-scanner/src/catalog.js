import { APP } from './config.js';

// The game's current names - characters, weapons, artifact sets - from genshin.anotoki.eu's catalog (its public
// lists, the same ones builder.anotoki.eu reads), so a character or weapon newer than this program is still
// matched. Each list is a JSON array of rows with a `name`; the Travelers come as a row per twin and element
// (`is_traveler`), which the game never shows by name (the player named them), so they are left out.

export async function fetchNames({ url = APP.catalogUrl, timeoutMs = APP.catalogTimeoutMs, fetchImpl = fetch } = {}) {
  const get = async (list) => {
    const response = await fetchImpl(`${url}/${list}`, { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(timeoutMs) });
    if (!response.ok) throw new Error(`${list}: HTTP ${response.status}`);
    return response.json();
  };
  const [characters, weapons, artifacts] = await Promise.all(['characters', 'weapons', 'artifacts'].map(get));
  return namesFromCatalog({ characters, weapons, artifacts });
}

// { characters, weapons, sets } - sorted names, each once - from the catalog's three lists.
export function namesFromCatalog({ characters, weapons, artifacts }) {
  const names = (rows, keep = () => true) => {
    if (!Array.isArray(rows)) throw new Error('a catalog list is not a list');
    const list = [...new Set(rows.filter((row) => typeof row?.name === 'string' && row.name.trim() && keep(row)).map((row) => row.name.trim()))].sort();
    if (!list.length) throw new Error('a catalog list is empty');
    return list;
  };
  return { characters: names(characters, (row) => !row.is_traveler), weapons: names(weapons), sets: names(artifacts) };
}
