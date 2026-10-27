import assert from 'node:assert/strict';
import test from 'node:test';
import { matchName, parseArtifact, parseBody, parseWeapon, toKey } from '../src/good/parse.js';
import { levelFromMainValue, possibleRollCounts } from '../src/good/stats.js';

// OCR texts below are as the OCR returned them for real panels of the first scan.

const panel = (text, extra = {}) => ({
  kind: 'artifact',
  text: { slot: 'Sands of Eon', mainStat: 'ATK', mainValue: '7.0%', level: '+0', body: '', equippedBy: '', ...text },
  rarity: 5,
  locked: false,
  astralMark: false,
  elixirCrafted: false,
  ...extra,
});

test('reads a +0 artifact with an unactivated fourth substat', () => {
  const { artifact, problems } = parseArtifact(panel({ body: '+ DEF+5.1%\n\n* Energy Recharge+4.5%\n\n- DEF+23\n\n+ ATK+18 (unactivated)\nDisenchantment in Deep Shadow:' }));
  assert.deepEqual(problems, []);
  assert.equal(artifact.setKey, 'DisenchantmentInDeepShadow');
  assert.deepEqual(artifact.substats, [
    { key: 'def_', value: 5.1 },
    { key: 'enerRech_', value: 4.5 },
    { key: 'def', value: 23 },
  ]);
  assert.deepEqual(artifact.unactivatedSubstats, [{ key: 'atk', value: 18 }]);
});

test('joins a wrapped "(unactivated)" and a wrapped set name', () => {
  const unactivated = parseBody("+ CRIT DMG+6.2%\n\n+ ATK+14\n\n DEF+6.6%\n\n- Elemental Mastery+19\n(unactivated)\nGladiator's Finale:");
  assert.deepEqual(unactivated.unactivatedSubstats, [{ key: 'eleMas', value: 19 }]);
  assert.equal(unactivated.setName, "Gladiator's Finale");
  const longName = parseBody('+ DEF+42\n\n+ ATK+14\n\n+ Energy Recharge+23.3%\n\n+ HP+4.1%\n\nNighttime Whispers in the Echoing\nWoods:\n\nAND Dinan');
  assert.equal(longName.setName, 'Nighttime Whispers in the Echoing Woods');
  assert.deepEqual(longName.bodyProblems, []);
});

test('reads a misread decimal point in a percentage', () => {
  const { substats } = parseBody('+ CRIT Rate+2,7%\n+ HP+4.,1%\n+ HP+1,195\nX:');
  assert.deepEqual(substats, [
    { key: 'critRate_', value: 2.7 },
    { key: 'hp_', value: 4.1 },
    { key: 'hp', value: 1195 },
  ]);
});

test('ignores artwork read next to a name', () => {
  assert.equal(matchName('HP                 RB', ['HP', 'ATK', 'CRIT Rate']), 'HP');
  assert.equal(matchName('IN\nDEF                                     i', ['HP', 'DEF']), 'DEF');
  assert.equal(matchName('CRIT Rate                     RB', ['CRIT Rate', 'CRIT DMG']), 'CRIT Rate');
});

test('takes the level from the main stat value when the level box is misread', () => {
  assert.equal(levelFromMainValue('atk_', 28.8, 5), 11);
  assert.equal(levelFromMainValue('hp', 1530, 5), 4);
  assert.equal(levelFromMainValue('critDMG_', 62.2, 5), 20);
  const { artifact, problems } = parseArtifact(panel({ level: '+1', mainValue: '28.8%', body: '+ CRIT DMG+6.2%\n+ DEF+18.2%\n+ DEF+23\n+ Energy Recharge+4.5%\nX:' }));
  assert.equal(artifact.level, 11);
  assert.deepEqual(problems, []);
});

test('flags substat values the game cannot roll', () => {
  assert.deepEqual(possibleRollCounts('def', 63, 5), [3]);
  assert.deepEqual(possibleRollCounts('critRate_', 27, 5), []);
  const { problems } = parseArtifact(panel({ body: '+ CRIT Rate+27%\n+ HP+239\n+ HP+5.8%\n+ ATK+16 (unactivated)\nX:' }));
  assert.ok(
    problems.some((p) => p.includes("can't be rolled")),
    problems.join('; '),
  );
});

test('names the equipped character by GOOD key and the Traveler as Traveler', () => {
  assert.equal(parseArtifact(panel({ equippedBy: 'Equipped: Kaedehara Kazuha' })).artifact.location, 'KaedeharaKazuha');
  const customNames = { ivantelle: 'Traveler', Ivantelle: 'Manekina', Taren: 'Manekin' };
  assert.equal(parseArtifact(panel({ equippedBy: 'Equipped: ivantelle' }), { customNames }).artifact.location, 'Traveler');
  assert.equal(parseArtifact(panel({ equippedBy: 'Equipped: Ivantelle' }), { customNames }).artifact.location, 'Manekina');
  assert.equal(parseWeapon(weaponPanel({ equippedBy: 'Equipped: Taren' }), { customNames }).weapon.location, 'Manekin');
  assert.equal(parseWeapon(weaponPanel({ equippedBy: 'Equipped: TAREN' }), { customNames }).weapon.location, 'Manekin'); // one name fits
  assert.equal(toKey("Night of the Sky's Unveiling"), 'NightOfTheSkysUnveiling');
});

test('corrects names the OCR misread a letter of, and keeps names it does not know', () => {
  // Read from real panels: "Aqua" came out as "Agua", "Xingqiu" as "Xinggiu".
  const aqua = parseWeapon(weaponPanel({ name: 'Agua Simulacra', equippedBy: 'Equipped: Xinggiu' }));
  assert.equal(aqua.weapon.key, 'AquaSimulacra');
  assert.equal(aqua.weapon.location, 'Xingqiu');
  assert.deepEqual(
    aqua.names.map((n) => [n.kind, n.read, n.name, n.corrected]),
    [
      ['weapon', 'Agua Simulacra', 'Aqua Simulacra', true],
      ['character', 'Xinggiu', 'Xingqiu', true],
    ],
  );
  const set = parseArtifact(panel({ body: "+ DEF+5.1%\n+ ATK+18\n+ HP+209\nGladiator's Finalle:" }));
  assert.equal(set.artifact.setKey, 'GladiatorsFinale');
  // A name that isn't in data/names.json (new in the game) is kept as read.
  const unknown = parseWeapon(weaponPanel({ name: 'Some Future Polearm', equippedBy: '' }));
  assert.equal(unknown.weapon.key, 'SomeFuturePolearm');
  assert.equal(unknown.names[0].known, false);
});

const weaponPanel = (text, extra = {}) => ({
  kind: 'weapon',
  text: { name: 'Aqua Simulacra', level: 'Lv. 90/90', refinement: 'Refinement Rank 1', equippedBy: 'Equipped: Yelan', ...text },
  rarity: 5,
  locked: true,
  ...extra,
});

test('reads a weapon: key, level, ascension from the max level, refinement, owner', () => {
  const { weapon, problems } = parseWeapon(weaponPanel({}));
  assert.deepEqual(problems, []);
  assert.deepEqual(weapon, { key: 'AquaSimulacra', level: 90, ascension: 6, refinement: 1, location: 'Yelan', lock: true });
  assert.equal(parseWeapon(weaponPanel({ level: 'Lv. 40/50' })).weapon.ascension, 2);
  assert.equal(parseWeapon(weaponPanel({ level: 'Lv. 1/20', refinement: 'Refinement Rank 5' })).weapon.refinement, 5);
  assert.equal(parseWeapon(weaponPanel({ name: 'Wolf’s\nGravestone' })).weapon.key, 'WolfsGravestone');
  assert.equal(parseWeapon(weaponPanel({ name: '"The Catch"' })).weapon.key, 'TheCatch');
});

test('flags impossible weapon levels; 1★ and 2★ weapons have no refinement line', () => {
  assert.ok(parseWeapon(weaponPanel({ level: 'Lv. 30/50' })).problems.length);
  assert.ok(parseWeapon(weaponPanel({ level: 'Lv. 80/90' }, { rarity: 2 })).problems.length);
  const dull = parseWeapon(weaponPanel({ name: 'Dull Blade', level: 'Lv. 1/20', refinement: 'A blade', equippedBy: '' }, { rarity: 1 }));
  assert.deepEqual(dull.problems, []);
  assert.equal(dull.weapon.refinement, 1);
});
