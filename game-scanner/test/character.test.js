import assert from 'node:assert/strict';
import test from 'node:test';
import { parseCharacter, readCharacter } from '../src/good/character.js';
import { createOcr } from '../src/good/ocr.js';
import { characterImages } from './support/character-fixtures.js';

// Reading characters from the character screen into GOOD characters: parsing what was read (the texts are what
// the OCR read from real screenshots), then real screenshots read end to end.

const customNames = { ivantelle: 'Traveler', Ivantelle: 'Manekina', Taren: 'Manekin' };
const lv = (level, boosted = false) => ({ level, boosted });
const locked = [80, 70, 70, 76, 90, 99];
const read = (header, extra = {}) => ({
  version: 2,
  header,
  name: header.split('/').at(-1).trim(),
  level: 'Level 90 / 90',
  constellationHeader: header,
  talentsHeader: header,
  constellations: { none: false, rings: locked },
  talents: [lv(9), lv(8), lv(7), null, null, null, null],
  ...extra,
});

test('reads a C6 character whose skill and burst are raised by constellations', () => {
  const { character, problems } = parseCharacter(
    read('Hydro / Furina', {
      level: 'Jevel 90 / 90', // a bubble over the "L"
      constellations: { none: false, rings: [220, 221, 220, 223, 222, 223] },
      talents: [lv(10), lv(13, true), lv(13, true), null, null, null, null],
    }),
  );
  assert.deepEqual(problems, []);
  assert.deepEqual(character, { key: 'Furina', level: 90, constellation: 6, ascension: 6, talent: { auto: 10, skill: 10, burst: 10 } });
});

test('takes the first three talents with a level (an alternate sprint between them has none)', () => {
  const { character, problems } = parseCharacter(
    read('Cryo / Kamisato Ayaka', {
      constellations: { none: false, rings: [220, 221, 221, 83, 88, 97] },
      talents: [lv(10), lv(10), null, lv(13, true), null, null, null],
    }),
  );
  assert.deepEqual(problems, []);
  assert.deepEqual(character.talent, { auto: 10, skill: 10, burst: 10 });
  assert.equal(character.key, 'KamisatoAyaka');
});

test('counts constellations up to the first locked one; rings out of order or still fading in are reported', () => {
  const rings = (r) => ({ constellations: { none: false, rings: r } });
  assert.equal(parseCharacter(read('Geo / Chiori', rings([220, 65, 65, 76, 77, 78]))).character.constellation, 1);
  assert.equal(parseCharacter(read('Geo / Chiori', rings(locked))).character.constellation, 0);
  const odd = parseCharacter(read('Geo / Chiori', rings([220, 65, 220, 76, 77, 78])));
  assert.ok(
    odd.problems.some((p) => p.includes('out of order')),
    odd.problems.join('; '),
  );
  const fading = parseCharacter(read('Geo / Chiori', rings([154, 160, 164, 169, 174, 188])));
  assert.ok(
    fading.problems.some((p) => p.includes('neither lit nor dark')),
    fading.problems.join('; '),
  );
  const none = parseCharacter(read('Cryo / Taren', { constellations: { none: true, rings: [91, 90, 97, 95, 108, 143] } }), { customNames });
  assert.equal(none.character.constellation, 0);
});

test('names the characters you named yourself, telling apart names that differ only in case', () => {
  const traveler = parseCharacter(read('Cryo / ivantelle'), { customNames });
  assert.equal(traveler.character.key, 'TravelerCryo');
  assert.equal(parseCharacter(read('Cryo / Ivantelle', { level: 'Level 20 / 20', talents: [lv(1), lv(1), lv(1)] }), { customNames }).character.key, 'Manekina');
  const manekin = parseCharacter(read('Cryo / Taren', { name: 'Taren       o', level: 'Level 20 / 20', talents: [lv(1), lv(1), lv(1)] }), { customNames });
  assert.deepEqual(manekin.character, { key: 'Manekin', level: 20, constellation: 0, ascension: 0, talent: { auto: 1, skill: 1, burst: 1 } });
  assert.deepEqual(manekin.problems, []);
  // "IVANTELLE" could be either: not guessed (kept as read, so the report lists it)
  assert.equal(parseCharacter(read('Cryo / IVANTELLE'), { customNames }).names[0]?.known, false);
});

test("lowers Tartaglia's raised Normal Attack by 1, not 3", () => {
  const { character, problems } = parseCharacter(read('Hydro / Tartaglia', { level: 'Level 70 / 70', talents: [lv(2, true), lv(6), lv(6)] }));
  assert.deepEqual(problems, []);
  assert.deepEqual(character.talent, { auto: 1, skill: 6, burst: 6 });
});

test('reads a long name on two header lines and a header read slightly differently on another tab', () => {
  const header = 'Anemo YumemizuKi\n/               Mizuki';
  const { character, problems } = parseCharacter(
    read('Anemo / Yumemizuki Mizuki', {
      header,
      name: 'Yumemizuki Mizuki',
      level: 'Level 70 / 70',
      constellationHeader: 'Anemo Yumemizuki\n/ Mizuki',
      talentsHeader: header,
      talents: [lv(1), lv(1), lv(1)],
    }),
  );
  assert.deepEqual(problems, []);
  assert.equal(character.key, 'YumemizukiMizuki');
  assert.ok(parseCharacter(read('Cryo / Qiqgi', { talentsHeader: 'Cryo / Qiqi' })).problems.every((p) => !p.includes('screenshot shows')));
});

test('reads a level with a stray mark in it', () => {
  assert.equal(parseCharacter(read('Hydro / Xingqiu', { level: 'Level 70./ 70', talents: [lv(3), lv(6, true), lv(6, true)] })).character.level, 70);
});

test('takes the ascension from the stars: the max level only checks them', () => {
  const stars = (n) => [255, 255, 255, 255, 255, 255].map((b, i) => (i < n ? b : 127));
  const c6 = { constellations: { none: false, rings: [220, 221, 220, 223, 222, 223] }, talents: [lv(1), lv(4, true), lv(4, true)] };
  // a sparkle on the "/" made "/ 60" read "760"
  const gorou = parseCharacter(read('Geo / Gorou', { ...c6, level: 'Level 60 760', current: 'Level 60', ascension: stars(3) }));
  assert.deepEqual(gorou.problems, []);
  assert.deepEqual([gorou.character.level, gorou.character.ascension], [60, 3]);
  // bubbles by the "/" made "90 / 90" read "907/90": the level is read from the white "Level 90" alone
  const hutao = parseCharacter(read('Pyro / Hu Tao', { level: 'Level907/90', current: 'Level 90', ascension: stars(6) }));
  assert.deepEqual([hutao.character.level, hutao.problems], [90, []]);
  // without that reading, the whole line's level must fit the stars
  const misread = parseCharacter(read('Pyro / Hu Tao', { level: 'Level907/90', ascension: stars(6) }));
  assert.ok(
    misread.problems.some((p) => p.includes("level 907 doesn't fit ascension 6")),
    misread.problems.join('; '),
  );
  // no stars read (fading in): the max level's
  assert.equal(parseCharacter(read('Geo / Gorou', { ...c6, level: 'Level 60 / 60', ascension: [64, 64, 64, 64, 64, 64] })).character.ascension, 3);
  const disagree = parseCharacter(read('Geo / Gorou', { ...c6, level: 'Level 60 / 70', ascension: stars(3) }));
  assert.ok(
    disagree.problems.some((p) => p.includes("doesn't fit 3 ascension stars")),
    disagree.problems.join('; '),
  );
  const range = parseCharacter(read('Geo / Gorou', { ...c6, level: 'Level 40', ascension: stars(3) }));
  assert.ok(
    range.problems.some((p) => p.includes("level 40 doesn't fit ascension 3")),
    range.problems.join('; '),
  );
  assert.ok(parseCharacter(read('Geo / Gorou', { ...c6, level: '', ascension: [64, 64, 64, 64, 64, 64] })).problems.some((p) => p.includes('unreadable ascension')));
});

test('reports another character on a tab, unreadable texts and levels', () => {
  const { problems } = parseCharacter(read('Hydro / Furina', { talentsHeader: 'Hydro / Neuvillette', level: 'Level', talents: [lv(10), lv(null), null] }));
  assert.ok(
    problems.some((p) => p.includes('talents screenshot shows "Hydro / Neuvillette"')),
    problems.join('; '),
  );
  assert.ok(
    problems.some((p) => p.includes('unreadable level')),
    problems.join('; '),
  );
  assert.ok(
    problems.some((p) => p.includes('unreadable skill level')),
    problems.join('; '),
  );
  assert.ok(
    problems.some((p) => p.includes('no level found for burst')),
    problems.join('; '),
  );
});

test('checks the raised talents against the constellations, and the talents against the ascension', () => {
  const c3 = { constellations: { none: false, rings: [220, 221, 220, 70, 70, 70] } };
  assert.ok(parseCharacter(read('Pyro / Amber', { ...c3, talents: [lv(1), lv(1), lv(4, true)] })).problems.length === 0);
  const missing = parseCharacter(read('Pyro / Amber', { ...c3, talents: [lv(1), lv(1), lv(4)] }));
  assert.ok(
    missing.problems.some((p) => p.includes('0 talent level(s) shown raised, but C3 raises 1')),
    missing.problems.join('; '),
  );
  const capped = parseCharacter(read('Pyro / Amber', { level: 'Level 70 / 70', talents: [lv(8), lv(1), lv(1)] }));
  assert.ok(
    capped.problems.some((p) => p.includes('auto level 8 is above what ascension 4 allows (6)')),
    capped.problems.join('; '),
  );
});

test('reads real screenshots: names, levels, constellations and talents', async () => {
  const expected = {
    traveler: { key: 'TravelerCryo', level: 90, constellation: 1, ascension: 6, talent: { auto: 1, skill: 1, burst: 2 } },
    manekina: { key: 'Manekina', level: 20, constellation: 0, ascension: 0, talent: { auto: 1, skill: 1, burst: 1 } },
    manekin: { key: 'Manekin', level: 20, constellation: 0, ascension: 0, talent: { auto: 1, skill: 1, burst: 1 } },
    escoffier: { key: 'Escoffier', level: 90, constellation: 0, ascension: 6, talent: { auto: 1, skill: 9, burst: 6 } },
    skirk: { key: 'Skirk', level: 90, constellation: 0, ascension: 6, talent: { auto: 10, skill: 10, burst: 6 } },
    ayaka: { key: 'KamisatoAyaka', level: 90, constellation: 3, ascension: 6, talent: { auto: 10, skill: 10, burst: 10 } },
    raiden: { key: 'RaidenShogun', level: 90, constellation: 6, ascension: 6, talent: { auto: 10, skill: 10, burst: 10 } },
    xiangling: { key: 'Xiangling', level: 90, constellation: 6, ascension: 6, talent: { auto: 6, skill: 8, burst: 10 } },
    amber: { key: 'Amber', level: 80, constellation: 3, ascension: 5, talent: { auto: 1, skill: 1, burst: 1 } },
    mizuki: { key: 'YumemizukiMizuki', level: 70, constellation: 1, ascension: 4, talent: { auto: 1, skill: 1, burst: 1 } },
    kokomi: { key: 'SangonomiyaKokomi', level: 70, constellation: 0, ascension: 4, talent: { auto: 2, skill: 2, burst: 2 } },
    tartaglia: { key: 'Tartaglia', level: 70, constellation: 0, ascension: 4, talent: { auto: 1, skill: 6, burst: 6 } },
    lanyan: { key: 'LanYan', level: 70, constellation: 1, ascension: 4, talent: { auto: 1, skill: 1, burst: 1 } },
    xingqiu: { key: 'Xingqiu', level: 70, constellation: 6, ascension: 4, talent: { auto: 3, skill: 3, burst: 3 } },
    gorou: { key: 'Gorou', level: 60, constellation: 6, ascension: 3, talent: { auto: 1, skill: 1, burst: 1 } }, // a sparkle on the "/": "Level 60 760"
    hutao: { key: 'HuTao', level: 90, constellation: 0, ascension: 6, talent: { auto: 8, skill: 9, burst: 6 } }, // bubbles by the "/": "Level907/90"
    alyosha: { key: 'Alyosha', level: 40, constellation: 1, ascension: 1, talent: { auto: 1, skill: 1, burst: 1 } },
    freminet: { key: 'Freminet', level: 70, constellation: 6, ascension: 4, talent: { auto: 1, skill: 1, burst: 1 } }, // a line of specks above the name
    furina: { key: 'Furina', level: 90, constellation: 6, ascension: 6, talent: { auto: 10, skill: 10, burst: 10 } },
  };
  const ocr = await createOcr(4);
  try {
    for (const [name, character] of Object.entries(expected)) {
      const result = parseCharacter(await readCharacter(characterImages(name), ocr), { customNames });
      assert.deepEqual({ character: result.character, problems: result.problems }, { character, problems: [] }, name);
    }
    // taken while the tabs were still fading in: unreadable, reported
    const fading = parseCharacter(await readCharacter(characterImages('furina-fading'), ocr), { customNames });
    assert.ok(
      fading.problems.some((p) => p.includes('neither lit nor dark')),
      fading.problems.join('; '),
    );
    assert.ok(
      fading.problems.some((p) => p.includes('no level found')),
      fading.problems.join('; '),
    );
  } finally {
    await ocr.terminate();
  }
});
