import assert from 'node:assert/strict';
import test from 'node:test';
import { fetchNames, namesFromCatalog } from '../src/catalog.js';

// The game's names from genshin.anotoki.eu's catalog lists (rows as the lists send them, shortened).

const lists = {
  characters: [
    { game_id: 10000089, name: 'Furina', element: 'Hydro', is_traveler: false },
    { game_id: 10000005, name: 'Aether', element: 'Cryo', is_traveler: true },
    { game_id: 10000007, name: 'Lumine', element: 'Cryo', is_traveler: true },
    { game_id: 10000118, name: 'Manekina', element: 'Adaptive', is_traveler: false },
  ],
  weapons: [{ name: 'Splendor of Tranquil Waters' }, { name: 'Aqua Simulacra' }, { name: 'Aqua Simulacra' }, { name: '' }, { type: 'Bow' }],
  artifacts: [{ name: 'Golden Troupe', two_piece: '...' }],
};

test('takes the names of the three lists, leaving out the Travelers (the game shows the name the player chose)', () => {
  assert.deepEqual(namesFromCatalog(lists), {
    characters: ['Furina', 'Manekina'],
    weapons: ['Aqua Simulacra', 'Splendor of Tranquil Waters'],
    sets: ['Golden Troupe'],
  });
});

test('fetches the three lists, and fails as a whole when one cannot be had', async () => {
  const asked = [];
  const answer = (status, body) => ({ ok: status === 200, status, json: async () => body });
  const list = (url) => lists[url.split('/').pop()];
  const fetchImpl = async (url) => {
    asked.push(url);
    return answer(200, list(url));
  };
  assert.deepEqual((await fetchNames({ url: 'https://example.test/api/catalog', fetchImpl })).sets, ['Golden Troupe']);
  assert.deepEqual(asked.sort(), ['https://example.test/api/catalog/artifacts', 'https://example.test/api/catalog/characters', 'https://example.test/api/catalog/weapons']);
  const weaponsDown = async (url) => (url.endsWith('weapons') ? answer(503, null) : answer(200, list(url)));
  await assert.rejects(fetchNames({ url: 'https://example.test/api/catalog', fetchImpl: weaponsDown }), /weapons: HTTP 503/);
  await assert.rejects(fetchNames({ url: 'https://example.test/api/catalog', fetchImpl: async () => answer(200, { not: 'a list' }) }), /not a list/);
});
