import { CharacterEditing, EditorBuild, EditorFacts, EditorNotes } from '../../../api/models';
import {
  Draft,
  addCopy,
  addNew,
  bringBack,
  bringable,
  buildBody,
  carried,
  deleteBuild,
  differingParts,
  editedParts,
  emptyBuild,
  fieldFor,
  fieldsOf,
  fileOrder,
  fromServer,
  giveOrderBack,
  holdsNothing,
  identityOf,
  inFileOrder,
  moveBuild,
  moved,
  noteOf,
  orderOwner,
  partText,
  problems,
  putBack,
  renameBuild,
  renamedOriginal,
  replaced,
  rowId,
  saveBody,
  saveText,
  takerOf,
  updateBuild,
  useFilePart,
  withField,
  waitingFields,
  waitingOf,
  withPart,
  withTeams,
  withTeamsChange,
} from './editor-draft';

const FACTS: EditorFacts = {
  roles: ['main_dps', 'sub_dps', 'support'],
  max_rank: 6,
  rank_prefix: 'C',
  refinement_prefix: 'R',
  slots: { sands: ['hp_', 'atk_'], goblet: ['pyro_dmg_', 'elemental_dmg_'] },
  substats: ['critRate_', 'critDMG_'],
  stats: ['critRate_', 'critDMG_', 'hp_'],
  talents: ['auto', 'skill', 'burst'],
  set_kinds: { artifact: [2, 4] },
  team_size: 4,
  labels: { stats: {}, talents: {}, slots: {}, set_kinds: {} },
  limits: {
    qualifier: 40,
    weapons: 100,
    refinement: 5,
    sets: 50,
    pieces: 30,
    set_keys: 100,
    stats: 30,
    talents: 10,
    priority: 99,
    targets: 30,
    bound: 1000000,
    teams: 20,
    team_name: 60,
    alternatives: 5,
    name: 1000,
    game_version: 20,
    note_build: 20000,
    note_team: 5000,
    builds: 30,
  },
};

const VAPORIZE = {
  name: 'Vaporize',
  members: [
    { key: 'hutao', name: 'Hu Tao', role: 'main_dps', min_rank: 0, alternatives: [] },
    { key: 'xingqiu', name: 'Xingqiu', role: 'sub_dps', min_rank: 6, alternatives: [] },
  ],
};
const MELT = {
  name: 'Melt',
  members: [
    { key: 'bennett', name: 'Bennett', role: 'support', min_rank: 0, alternatives: [] },
    { key: 'hutao', name: 'Hu Tao', role: 'main_dps', min_rank: 0, alternatives: [] },
  ],
};

/** A build in the editor's shape, as the server sends it. */
function build(fields: Partial<EditorBuild> = {}): EditorBuild {
  return {
    role: 'main_dps',
    qualifier: '',
    min_rank: 0,
    weapons: [{ key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 }],
    sets: [{ kind: 'artifact', choose: null, pieces: [{ label: 'Crimson Witch of Flames', count: 4, keys: ['crimsonwitchofflames'], names: ['Crimson Witch of Flames'] }] }],
    main_stats: { sands: [{ stat: 'hp_', priority: 1 }] },
    substats: [{ stat: 'critRate_', priority: 1 }],
    talent_priority: null,
    stat_targets: [{ stat: 'critRate_', min: 70, max: null, from_rank: 0 }],
    teams: [VAPORIZE],
    game_version: '5.4',
    ...fields,
  };
}

const NO_NOTES: EditorNotes = { build: null, teams: [null], waiting: [] };

/** Hu Tao's builds: two of the file's (the support one edited), one made here; a sub DPS build of the file's removed. */
function loaded(fields: Partial<CharacterEditing> = {}): CharacterEditing {
  const support = build({ role: 'support', teams: [] });
  return {
    game: 'genshin',
    character: { key: 'hutao', name: 'Hu Tao', element: 'pyro', weapon_type: 'polearm', rarity: 5, icon_url: null },
    version: 'v'.repeat(64),
    order: 'file',
    builds: [
      {
        id: 1,
        origin: 'file',
        build: build(),
        file: build(),
        file_position: 0,
        edited: [],
        computed: null,
        notes: { build: { en: 'Keep her HP low.', sk: 'Drž jej HP nízko.' }, teams: [{ en: 'Swirl first.' }], waiting: [] },
        checked_at: '2026-10-20T10:00:00+00:00',
        changed_at: '2026-10-20T10:00:00+00:00',
        edited_at: null,
      },
      {
        id: 2,
        origin: 'file',
        build: { ...support, game_version: '5.5' },
        file: support,
        file_position: 2,
        edited: ['game_version'],
        computed: null,
        notes: { build: null, teams: [], waiting: [] },
        checked_at: null,
        changed_at: null,
        edited_at: '2026-10-21T10:00:00+00:00',
      },
      {
        id: 3,
        origin: 'made_here',
        build: build({ qualifier: 'Mine', teams: [] }),
        file: null,
        file_position: null,
        edited: [],
        computed: null,
        notes: { build: null, teams: [], waiting: [] },
        checked_at: null,
        changed_at: null,
        edited_at: null,
      },
    ],
    removed: [{ id: 7, build: build({ role: 'sub_dps', teams: [] }), file_position: 1, notes: { build: { en: 'Old words.' }, teams: [], waiting: [] }, computed: null, removed_at: null }],
    ...fields,
  };
}

const uids = (draft: Draft) => draft.builds.map((one) => one.uid);

describe("the builds editor's state: from what the server loaded to a save, and the checks before it", () => {
  it('makes a save of every build exactly as it was loaded - its parts the very objects, its notes as they came - so loading and saving writes nothing', () => {
    const answer = loaded();
    const draft = fromServer(answer);

    const body = saveBody(draft);
    expect(body.version).toBe(answer.version);
    expect(body.order).toBeUndefined();
    expect(body.builds.map((one) => [one.id, one.removal])).toEqual([
      [1, null],
      [2, null],
      [3, null],
    ]);
    expect(body.builds[0].build).toBe(answer.builds[0].build);
    expect(body.builds[0].notes).toEqual({ build: { en: 'Keep her HP low.', sk: 'Drž jej HP nízko.' }, teams: [{ en: 'Swirl first.' }], waiting: [] });
    expect(body.builds[2].notes).toEqual({ build: null, teams: [], waiting: [] });
    expect(saveText(fromServer(answer))).toBe(saveText(draft));
  });

  it('sends a note as written: none where nothing is, the Slovak only where it is written', () => {
    expect(noteOf({ en: '', sk: '  ' })).toBeNull();
    expect(noteOf({ en: 'Swirl.', sk: '' })).toEqual({ en: 'Swirl.' });
    expect(noteOf({ en: 'Swirl.', sk: 'Rozvír.' })).toEqual({ en: 'Swirl.', sk: 'Rozvír.' });
  });

  it('compares a part as an import does: the order of a list counts, the order of the slots does not, none and empty are alike', () => {
    const one = build();
    expect(partText(build({ main_stats: { goblet: [{ stat: 'pyro_dmg_', priority: 1 }], sands: [{ stat: 'hp_', priority: 1 }] } }), 'main_stats')).toBe(
      partText(build({ main_stats: { sands: [{ stat: 'hp_', priority: 1 }], goblet: [{ stat: 'pyro_dmg_', priority: 1 }] } }), 'main_stats'),
    );
    expect(partText(build({ talent_priority: null }), 'talent_priority')).toBe(partText(build({ talent_priority: [] }), 'talent_priority'));
    expect(differingParts(one, build({ substats: [{ stat: 'critRate_', priority: 2 }] }))).toEqual(['substats']);
    expect(
      differingParts(
        one,
        build({
          weapons: [
            { key: 'dragonsbane', name: "Dragon's Bane", refinement: 5 },
            { key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 },
          ],
          game_version: '5.5',
        }),
      ),
    ).toEqual(['weapons', 'game_version']);
    expect(differingParts(one, build())).toEqual([]);
  });

  it("tells a build of the file's edits from what the file says of it; one made here has none", () => {
    const draft = fromServer(loaded());
    expect(draft.builds.map(editedParts)).toEqual([[], ['game_version'], []]);
  });

  it("puts a part back as the file has it, and the team notes follow their teams' members", () => {
    let draft = fromServer(loaded());
    draft = updateBuild(draft, 'b2', (one) => useFilePart(one, 'game_version'));
    expect(draft.builds[1].build.game_version).toBe('5.4');
    expect(editedParts(draft.builds[1])).toEqual([]);

    // A made-here build has no file to go back to.
    expect(useFilePart(draft.builds[2], 'weapons')).toBe(draft.builds[2]);

    // Rows of its own, never the file's very ones - and the part as loaded where that is what the file says.
    const first = draft.builds[0];
    const filed = useFilePart(first, 'weapons');
    expect(filed.build.weapons).toEqual(first.file!.weapons);
    expect(filed.build.weapons).not.toBe(first.file!.weapons);
    expect(useFilePart(first, 'weapons', first).build.weapons).toBe(first.build.weapons);
  });

  it("keeps a team's note with its members: a team gone leaves it waiting, a team back takes it again", () => {
    const first = fromServer(loaded()).builds[0];
    const melted = withTeams(first, [MELT]);
    expect(melted.notes.teams).toEqual([{ en: '', sk: '' }]);
    expect(melted.notes.waiting).toEqual([
      {
        key: 'hutao xingqiu',
        members: [
          { key: 'hutao', name: 'Hu Tao' },
          { key: 'xingqiu', name: 'Xingqiu' },
        ],
        note: { en: 'Swirl first.' },
      },
    ]);

    // The same members in another order are the same team.
    const back = withTeams(melted, [MELT, { ...VAPORIZE, members: [...VAPORIZE.members].reverse() }]);
    expect(back.notes.teams).toEqual([
      { en: '', sk: '' },
      { en: 'Swirl first.', sk: '' },
    ]);
    expect(back.notes.waiting).toEqual([]);
  });

  describe('the order', () => {
    it("is the file's while the builds stand as an import orders them, the editor's once one is moved", () => {
      const draft = fromServer(loaded());
      expect(inFileOrder(draft.builds)).toBe(true);
      expect(orderOwner(draft)).toBe('file');

      const moved = moveBuild(draft, 'b3', -1);
      expect(uids(moved)).toEqual(['b1', 'b3', 'b2']);
      expect(orderOwner(moved)).toBe('editors');
      // At an end, nowhere further.
      expect(moveBuild(draft, 'b1', -1)).toBe(draft);
      expect(moveBuild(draft, 'b3', 1)).toBe(draft);

      // Back in the file's order by hand: the file's again (an editor's order is said, never guessed).
      expect(orderOwner(moveBuild(moved, 'b3', 1))).toBe('file');
    });

    it("is given back to the file - the builds in its order, and where the stored order is an editor's, said so in the save", () => {
      const editors = fromServer(loaded({ order: 'editors' }));
      expect(orderOwner(editors)).toBe('editors');
      const given = giveOrderBack(moveBuild(editors, 'b3', -1));
      expect(uids(given)).toEqual(['b1', 'b2', 'b3']);
      expect(given.orderToFile).toBe(true);
      expect(saveBody(given).order).toBe('file');
      expect(orderOwner(given)).toBe('file');
      // Moved again: the editor's once more, nothing given back.
      expect(moveBuild(given, 'b3', -1).orderToFile).toBe(false);

      // A stored order of the file's needs no word: the builds in its order are enough.
      const moved = moveBuild(fromServer(loaded()), 'b3', -1);
      expect(giveOrderBack(moved).orderToFile).toBe(false);
      expect(fileOrder(moved.builds).map((one) => one.uid)).toEqual(['b1', 'b2', 'b3']);
    });
  });

  describe("the stat targets of each kind: a requirement, the tiers set by hand, a stat switched off (the editor's round of 2026-10-26)", () => {
    const requirement = { stat: 'critRate_', min: 70, max: null, from_rank: 0 };
    const byHand = { stat: 'critDMG_', min: null, max: null, from_rank: 0, tiers: { basic: 180, intermediate: 210, advanced: 240 } };
    const off = { stat: 'hp', min: null, max: null, from_rank: 0, off: true };

    it('tells the kinds apart in a part text: the same stat with tiers, switched off or as a requirement are three parts', () => {
      const texts = [[requirement], [byHand], [off], [{ ...byHand, tiers: { ...byHand.tiers, advanced: 250 } }]].map((targets) => partText(build({ stat_targets: targets }), 'stat_targets'));
      expect(new Set(texts).size).toBe(4);
    });

    it('holds a save back for a tier still to be typed or out of order, never for a stat switched off', () => {
      const draft = fromServer(loaded());
      const uid = draft.builds[0].uid;
      const problemsOf = (targets: EditorBuild['stat_targets']) =>
        problems(
          updateBuild(draft, uid, (one) => withField(one, 'stat_targets', targets)),
          FACTS,
        ).map((problem) => [problem.path, problem.message]);
      expect(problemsOf([requirement, byHand, off])).toEqual([]);
      expect(problemsOf([{ ...byHand, tiers: { basic: null, intermediate: 210, advanced: 240 } }])).toEqual([['stat_targets[0].tiers.basic', 'Give all three tiers a number.']]);
      expect(problemsOf([{ ...byHand, tiers: { basic: 220, intermediate: 210, advanced: 200 } }])).toEqual([
        ['stat_targets[0].tiers.basic', 'No higher than the intermediate tier.'],
        ['stat_targets[0].tiers.intermediate', 'No higher than the advanced tier.'],
      ]);
      expect(problemsOf([{ ...byHand, tiers: { basic: -1, intermediate: 210, advanced: 240 } }])).toEqual([['stat_targets[0].tiers.basic', `A number from 0 to ${FACTS.limits.bound}.`]]);
      // One target a stat from each constellation, whatever their kinds.
      expect(problemsOf([byHand, { ...off, stat: 'critDMG_' }])).toEqual([
        ['stat_targets[1].stat', 'Another target is for this stat from the same constellation: one target a stat from each constellation.'],
      ]);
    });
  });

  describe('adding and removing builds', () => {
    it("adds a new, empty build last - of the first role with no build of its own -, a build of the editor's own", () => {
      const draft = fromServer(loaded());
      const added = addNew(draft, FACTS);
      const fresh = added.draft.builds[3];
      expect(fresh.uid).toBe(added.uid);
      expect([fresh.id, fresh.removal, fresh.file]).toEqual([null, null, null]);
      // Every role has a build at C0 here - the sub DPS one the removed build, which is brought back, never made anew.
      expect(fresh.build).toEqual({
        role: 'main_dps',
        qualifier: 'New',
        min_rank: 0,
        weapons: [],
        sets: [],
        main_stats: {},
        substats: [],
        talent_priority: null,
        stat_targets: null,
        teams: [],
        game_version: null,
      });
      // Saved as it is, members would see an empty build: held back until it holds something.
      expect(problems(added.draft, FACTS)).toEqual([
        { uid: fresh.uid, path: '', message: 'This build holds nothing yet - no weapon, artifact set, main stat or substat - and members would see it empty: fill it in before saving, or delete it.' },
      ]);
      const named = updateBuild(added.draft, added.uid, (one) => withField(withField(one, 'role', 'sub_dps'), 'qualifier', ''));
      expect(identityOf(named.builds[3].build)).toBe(identityOf(draft.removed[0].build));
      expect(problems(named, FACTS)).toEqual([
        {
          uid: fresh.uid,
          path: 'qualifier',
          message: "A removed build of the file's has this role, qualifier and constellation: bring that one back instead (Add build, then Bring back).",
        },
        { uid: fresh.uid, path: '', message: 'This build holds nothing yet - no weapon, artifact set, main stat or substat - and members would see it empty: fill it in before saving, or delete it.' },
      ]);

      // Every role taken: "New", to be renamed.
      const full = addNew(addNew(addNew(fromServer(loaded({ removed: [] })), FACTS).draft, FACTS).draft, FACTS).draft;
      expect(full.builds.slice(3).map((one) => [one.build.role, one.build.qualifier])).toEqual([
        ['sub_dps', ''],
        ['main_dps', 'New'],
        ['main_dps', 'New 2'],
      ]);
    });

    it('copies a build - every part and note of it - as one of the editor\'s own, last, its qualifier "… copy" until renamed', () => {
      const draft = fromServer(loaded());
      const copied = addCopy(draft, 'b1', FACTS)!;
      const copy = copied.draft.builds[3];
      expect(copy.uid).toBe(copied.uid);
      expect([copy.id, copy.file, copy.filePosition]).toEqual([null, null, null]);
      expect(copy.build).toEqual({ ...draft.builds[0].build, qualifier: 'Copy' });
      expect(copy.notes).toEqual(draft.builds[0].notes);
      expect(copy.notes).not.toBe(draft.builds[0].notes);
      expect(addCopy(copied.draft, 'b3', FACTS)!.draft.builds[4].build.qualifier).toBe('Mine copy');
      expect(addCopy(copied.draft, 'b1', FACTS)!.draft.builds[4].build.qualifier).toBe('Copy 2');
      // Its rows its own: the page draws them apart from the original's.
      const original = draft.builds[0].build;
      expect(rowId(copy.build.weapons[0])).not.toBe(rowId(original.weapons[0]));
      expect(rowId(copy.build.sets[0].pieces[0])).not.toBe(rowId(original.sets[0].pieces[0]));
    });

    it("brings back a removed build of the file's - what the file said of it, its notes - where the file puts it", () => {
      const draft = fromServer(loaded());
      expect(bringable(draft).map((removal) => removal.id)).toEqual([7]);
      const brought = bringBack(draft, 7)!;
      expect(uids(brought.draft)).toEqual(['b1', 'r7', 'b2', 'b3']);
      expect(inFileOrder(brought.draft.builds)).toBe(true);
      const back = brought.draft.builds[1];
      expect([back.id, back.removal, back.file, back.filePosition]).toEqual([null, 7, draft.removed[0].build, 1]);
      expect(back.notes.build).toEqual({ en: 'Old words.', sk: '' });
      expect(bringable(brought.draft)).toEqual([]);
      // Rows of its own: brought back again after a delete, nothing typed in it comes back with it.
      expect(back.build).not.toBe(draft.removed[0].build);
      expect(rowId(back.build.weapons[0])).not.toBe(rowId(draft.removed[0].build.weapons[0]));
      expect(saveBody(brought.draft).builds[1]).toEqual({ id: null, removal: 7, build: draft.removed[0].build, notes: { build: { en: 'Old words.' }, teams: [], waiting: [] } });

      // Deleted again before the save: it can be brought back again.
      expect(bringable(deleteBuild(brought.draft, 'r7')).map((removal) => removal.id)).toEqual([7]);
    });

    it('deletes a build from the page - a stored one kept to be put back until the save, which removes it -, a new one at once', () => {
      const draft = fromServer(loaded());
      const deleted = deleteBuild(draft, 'b2');
      expect(uids(deleted)).toEqual(['b1', 'b3']);
      expect(deleted.deleted.map((one) => [one.build.uid, one.index])).toEqual([['b2', 1]]);
      expect(saveBody(deleted).builds.map((one) => one.id)).toEqual([1, 3]);
      // The removed one's name is taken: no build of the editor's may take it.
      const renamed = updateBuild(deleted, 'b3', (one) => withField(withField(one, 'role', 'support'), 'qualifier', ''));
      expect(problems(renamed, FACTS).map((problem) => problem.path)).toEqual(['qualifier']);

      const back = putBack(deleted, 'b2');
      expect(uids(back)).toEqual(['b1', 'b2', 'b3']);
      expect(back.deleted).toEqual([]);
      expect(saveText(back)).toBe(saveText(draft));

      const added = addNew(draft, FACTS);
      expect(deleteBuild(added.draft, added.uid).deleted).toEqual([]);
    });

    it("puts a deleted build back in the place it had - a delete undone changes no order, an editor's neither", () => {
      // An editor's order: the support build first.
      const ordered = moveBuild(fromServer(loaded()), 'b2', -1);
      expect(uids(ordered)).toEqual(['b2', 'b1', 'b3']);
      const back = putBack(deleteBuild(ordered, 'b1'), 'b1');
      expect(uids(back)).toEqual(['b2', 'b1', 'b3']);
      expect(saveText(back)).toBe(saveText(ordered));

      // A build of the editor's own between others: back between them, never last.
      const own = putBack(deleteBuild(moveBuild(fromServer(loaded()), 'b3', -1), 'b3'), 'b3');
      expect(uids(own)).toEqual(['b1', 'b3', 'b2']);
    });
  });

  describe('the checks before a save', () => {
    it("finds two builds of one name - their qualifier compared without its case and blanks - at the later one's qualifier", () => {
      const draft = updateBuild(fromServer(loaded()), 'b3', (one) => withField(one, 'qualifier', ''));
      expect(problems(draft, FACTS)).toEqual([{ uid: 'b3', path: 'qualifier', message: 'Build 1 has this role, qualifier and constellation too: tell the two apart by their qualifier.' }]);
      expect(identityOf({ role: 'support', qualifier: ' Melt ', min_rank: 2 })).toBe(identityOf({ role: 'support', qualifier: 'melt', min_rank: 2 }));
    });

    it("says two builds of one name at the one whose name can change: a build of the file's keeps its own", () => {
      // The editor's own build first, then renamed as the file's main DPS build after it.
      const first = moveBuild(moveBuild(fromServer(loaded()), 'b3', -1), 'b3', -1);
      const draft = updateBuild(first, 'b3', (one) => withField(one, 'qualifier', ''));
      expect(problems(draft, FACTS)).toEqual([{ uid: 'b3', path: 'qualifier', message: 'Build 2 has this role, qualifier and constellation too: tell the two apart by their qualifier.' }]);
    });

    it('holds back a build that holds nothing a member could use - no weapon, set, main stat or substat -, and only that', () => {
      const draft = fromServer(loaded());
      expect(holdsNothing(emptyBuild('support', '', 0))).toBe(true);
      expect(holdsNothing({ ...emptyBuild('support', '', 0), main_stats: { sands: [] } })).toBe(true);
      expect(holdsNothing({ ...emptyBuild('support', '', 0), substats: [{ stat: 'critRate_', priority: 1 }] })).toBe(false);
      expect(holdsNothing({ ...emptyBuild('support', '', 0), main_stats: { sands: [{ stat: 'hp_', priority: 1 }] } })).toBe(false);
      expect(holdsNothing(draft.builds[0].build)).toBe(false);
      const emptied = updateBuild(draft, 'b2', (one) => ({ ...one, build: { ...emptyBuild('support', '', 0), game_version: '5.5' } }));
      expect(problems(emptied, FACTS)).toEqual([
        { uid: 'b2', path: '', message: 'This build holds nothing yet - no weapon, artifact set, main stat or substat - and members would see it empty: fill it in before saving, or delete it.' },
      ]);
    });

    it('asks a note with Slovak for its English, and holds every text to its cap', () => {
      let draft = updateBuild(fromServer(loaded()), 'b2', (one) => ({ ...one, notes: { ...one.notes, build: { en: ' ', sk: 'Len po slovensky.' } } }));
      draft = updateBuild(draft, 'b3', (one) => withField(withField(one, 'qualifier', 'x'.repeat(41)), 'game_version', '1'.repeat(21)));
      draft = updateBuild(draft, 'b1', (one) => ({ ...one, notes: { ...one.notes, teams: [{ en: 'y'.repeat(5001), sk: '' }] } }));
      expect(problems(draft, FACTS)).toEqual([
        { uid: 'b1', path: 'notes.teams[0].en', message: 'At most 5000 characters.' },
        { uid: 'b2', path: 'notes.build.en', message: 'Write the English first: the Slovak is optional, the English is not.' },
        { uid: 'b3', path: 'qualifier', message: 'At most 40 characters.' },
        { uid: 'b3', path: 'game_version', message: 'At most 20 characters.' },
      ]);
    });

    it('holds a character to its most builds', () => {
      let draft = fromServer(loaded({ removed: [] }));
      for (let i = 0; i < 28; i++) {
        draft = addNew(draft, FACTS).draft;
      }
      expect(draft.builds).toHaveLength(31);
      expect(problems(draft, FACTS).filter((problem) => problem.uid === '')).toEqual([{ uid: '', path: '', message: 'A character has at most 30 builds: delete one before saving.' }]);
    });

    it('finds the field a problem belongs to: the very one, the first inside it, the nearest around it - else none', () => {
      const fields = ['qualifier', 'notes.build.en', 'notes.build.sk', 'weapons[0]'];
      expect(fieldFor(fields, 'qualifier')).toBe('qualifier');
      expect(fieldFor(fields, 'notes.build')).toBe('notes.build.en');
      expect(fieldFor(fields, 'weapons[0].refinement')).toBe('weapons[0]');
      expect(fieldFor(fields, 'weapons[1].refinement')).toBeNull();
      expect(fieldFor(fields, 'teams[0].members')).toBeNull();
      expect(fieldFor(fields, '')).toBeNull();
    });

    it("finds what a part's form can leave wrong for a moment: a weapon unchosen or twice, a piece with no set or no label, too many to wear", () => {
      const parts = build({
        weapons: [
          { key: '', name: '', refinement: null },
          { key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 },
          { key: 'staffofhoma', name: 'Staff of Homa', refinement: null },
        ],
        sets: [
          {
            kind: 'artifact',
            choose: 3,
            pieces: [
              { label: '', count: 4, keys: [], names: [] },
              { label: ' ', count: 2, keys: ['a', 'b'], names: ['A', 'B'] },
            ],
          },
        ],
      });
      const draft = updateBuild(fromServer(loaded()), 'b1', (one) => ({ ...one, build: parts }));
      expect(problems(draft, FACTS).filter((problem) => problem.uid === 'b1')).toEqual([
        { uid: 'b1', path: 'weapons[0].key', message: 'Choose a weapon here, or remove the row.' },
        { uid: 'b1', path: 'weapons[2].key', message: 'This weapon is listed above already: remove one of the two.' },
        { uid: 'b1', path: 'sets[0].pieces[0].keys', message: 'Choose a set for this piece, or remove it.' },
        { uid: 'b1', path: 'sets[0].pieces[1].label', message: 'Name what these sets have in common, as members read it - such as "any 18% ATK set".' },
        { uid: 'b1', path: 'sets[0].choose', message: 'More than the option has pieces: choose again.' },
      ]);
    });

    it('finds a target with no bound, its minimum over its maximum, a bound out of range, and two of one stat from one constellation', () => {
      const parts = build({
        stat_targets: [
          { stat: 'critRate_', min: null, max: null, from_rank: 0 },
          { stat: 'eleMas', min: 300, max: 200, from_rank: 0 },
          { stat: 'enerRech_', min: 2000000, max: null, from_rank: 0 },
          { stat: 'eleMas', min: 100, max: null, from_rank: 0 },
          { stat: 'eleMas', min: 100, max: null, from_rank: 1 },
        ],
      });
      const draft = updateBuild(fromServer(loaded()), 'b1', (one) => ({ ...one, build: parts }));
      expect(problems(draft, FACTS).filter((problem) => problem.uid === 'b1')).toEqual([
        { uid: 'b1', path: 'stat_targets[0].min', message: 'Give it a minimum, a maximum or both.' },
        { uid: 'b1', path: 'stat_targets[1].min', message: 'No higher than the maximum.' },
        { uid: 'b1', path: 'stat_targets[2].min', message: 'A number from -1000000 to 1000000.' },
        { uid: 'b1', path: 'stat_targets[3].stat', message: 'Another target is for this stat from the same constellation: one target a stat from each constellation.' },
      ]);
    });

    it("asks the page's own rules - a weapon twice, sets with no label, a target with no bound - only of a part changed on the page", () => {
      // As a file brought them: read, and saved again as they are.
      const parts = build({
        weapons: [
          { key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 },
          { key: 'staffofhoma', name: 'Staff of Homa', refinement: null },
        ],
        sets: [{ kind: 'artifact', choose: null, pieces: [{ label: '', count: 2, keys: ['a', 'b'], names: ['A', 'B'] }] }],
        stat_targets: [{ stat: 'critRate_', min: null, max: null, from_rank: 0 }],
      });
      const before = updateBuild(fromServer(loaded()), 'b1', (one) => ({ ...one, build: parts }));
      const ofB1 = (draft: Draft, loadedDraft: Draft | null) =>
        problems(draft, FACTS, loadedDraft)
          .filter((problem) => problem.uid === 'b1')
          .map((problem) => problem.path);
      expect(ofB1(before, before)).toEqual([]);

      // The weapons changed: asked of them, never of the sets or the targets.
      const changed = updateBuild(before, 'b1', (one) =>
        withField(
          one,
          'weapons',
          replaced(one.build.weapons, 0, (weapon) => ({ ...weapon, refinement: 2 })),
        ),
      );
      expect(ofB1(changed, before)).toEqual(['weapons[1].key']);
      // A build new on the page: every rule (and with nothing loaded to compare with).
      expect(ofB1(before, null)).toEqual(['weapons[1].key', 'sets[0].pieces[0].label', 'stat_targets[0].min']);
      // The file's own rules, always.
      const over = updateBuild(before, 'b1', (one) => withField(one, 'stat_targets', [{ stat: 'critRate_', min: 80, max: 70, from_rank: 0 }]));
      expect(ofB1(over, over)).toEqual(['stat_targets[0].min']);

      // As the file has it is as a file brought it: a removed build brought back as it was...
      const removed = fromServer(
        loaded({ removed: [{ id: 7, build: { ...parts, role: 'sub_dps' }, file_position: 1, notes: { build: null, teams: [], waiting: [] }, computed: null, removed_at: null }] }),
      );
      const brought = bringBack(removed, 7)!;
      expect(problems(brought.draft, FACTS, removed).filter((problem) => problem.uid === brought.uid)).toEqual([]);
      // ...and a part the file's again ("Use the file's"): two sets with no label, as the file has them.
      const fileSets = build({ sets: [{ kind: 'artifact', choose: null, pieces: [{ label: '', count: 2, keys: ['a', 'b'], names: ['A', 'B'] }] }] });
      const labelled = build({ sets: [{ kind: 'artifact', choose: null, pieces: [{ label: 'any set', count: 2, keys: ['a', 'b'], names: ['A', 'B'] }] }] });
      const stored = fromServer(loaded({ builds: [{ ...loaded().builds[0], build: labelled, file: fileSets, edited: ['sets'] }] }));
      const filed = updateBuild(stored, 'b1', (one) => useFilePart(one, 'sets', stored.builds[0]));
      expect(editedParts(filed.builds[0])).toEqual([]);
      expect(ofB1(filed, stored)).toEqual([]);
    });

    it("holds back a build that holds nothing only where the page changed it: one a file brought empty is the file's to fill", () => {
      const empty = build({ weapons: [], sets: [], main_stats: {}, substats: [] });
      const stored = fromServer(loaded({ builds: [{ ...loaded().builds[0], build: empty, file: empty }] }));
      // A note written on it, nothing else: the save goes.
      const noted = updateBuild(stored, 'b1', (one) => ({ ...one, notes: { ...one.notes, build: { en: 'Soon.', sk: '' } } }));
      expect(problems(noted, FACTS, stored)).toEqual([]);
      // Changed on the page and still empty: held back.
      const changed = updateBuild(stored, 'b1', (one) => withField(one, 'game_version', '6.0'));
      expect(problems(changed, FACTS, stored).map((problem) => problem.path)).toEqual(['']);
    });

    it("lists a build's fields in the order the page shows them, each at its place in a save", () => {
      expect(fieldsOf(fromServer(loaded()).builds[0].build, 'hutao')).toEqual([
        'role',
        'qualifier',
        'min_rank',
        'game_version',
        'notes.build.en',
        'notes.build.sk',
        'weapons[0].key',
        'weapons[0].refinement',
        'sets[0].pieces[0].keys',
        'sets[0].pieces[0].count',
        'main_stats.sands[0].stat',
        'main_stats.sands[0].priority',
        'substats[0].stat',
        'substats[0].priority',
        'stat_targets[0].stat',
        'stat_targets[0].from_rank',
        'stat_targets[0].min',
        'stat_targets[0].max',
        // The build's own character has no picker and no stand-ins; a team's members as a whole, under them.
        'teams[0].name',
        'teams[0].members[0].role',
        'teams[0].members[0].min_rank',
        'teams[0].members[1].key',
        'teams[0].members[1].role',
        'teams[0].members[1].min_rank',
        'teams[0].members[1].alternatives',
        'teams[0].members',
        'notes.teams[0].en',
        'notes.teams[0].sk',
      ]);
      expect(waitingFields([{ key: 'hutao xingqiu', members: [], note: { en: 'x' } }])).toEqual(['notes.waiting[0]']);
      // A stand-in's problem goes to the member's stand-ins.
      expect(fieldFor(fieldsOf(build(), 'hutao'), 'teams[0].members[1].alternatives[0].key')).toBe('teams[0].members[1].alternatives');
      // A list-wide problem goes to the list's first field; a piece's set, to its picker.
      expect(fieldFor(fieldsOf(build()), 'weapons')).toBe('weapons[0].key');
      expect(fieldFor(fieldsOf(build()), 'sets[0].pieces[0].keys[1]')).toBe('sets[0].pieces[0].keys');
    });
  });

  describe("a list's rows", () => {
    it('keep their name through an edit, a move and the edit of another - a new entry a new name', () => {
      const list = [
        { stat: 'critRate_', priority: 1 },
        { stat: 'critDMG_', priority: 2 },
      ];
      const names = list.map(rowId);
      const edited = replaced(list, 0, (row) => ({ ...row, priority: 3 }));
      expect(edited[0]).toEqual({ stat: 'critRate_', priority: 3 });
      expect(edited.map(rowId)).toEqual(names);
      expect(edited[1]).toBe(list[1]);
      expect(moved(edited, 0, 1).map(rowId)).toEqual([names[1], names[0]]);
      expect(moved(edited, 1, 1)).toEqual(edited);
      expect(rowId(carried(list[1], { ...list[1] }))).toBe(names[1]);
      expect(rowId({ stat: 'critRate_', priority: 1 })).not.toBe(names[0]);
    });
  });

  describe("a build's name changed on the page", () => {
    it("makes a build of the file's renamed one of the editor's own in its place - the file's set aside, its notes with it", () => {
      const draft = fromServer(loaded());
      const renamed = renameBuild(draft, 'b1', { qualifier: 'Melt' });
      const copy = renamed.draft.builds[0];
      expect(renamed.uid).toBe(copy.uid);
      expect([copy.id, copy.file, copy.build.qualifier, copy.renamedFrom]).toEqual([null, null, 'Melt', 'b1']);
      expect(copy.notes).toBe(draft.builds[0].notes);
      expect(copy.build.weapons).toBe(draft.builds[0].build.weapons);
      expect(renamed.draft.deleted.map((entry) => entry.build.uid)).toEqual(['b1']);
      expect(renamedOriginal(renamed.draft, copy)?.uid).toBe('b1');
      expect(saveBody(renamed.draft).builds.map((one) => one.id)).toEqual([null, 2, 3]);
    });

    it("is the file's build again, named so - without regard to case, its spelling the file's -, with what it holds now", () => {
      const draft = fromServer(loaded());
      const renamed = renameBuild(draft, 'b1', { qualifier: 'Melt' });
      const edited = updateBuild(renamed.draft, renamed.uid, (one) => withField(one, 'game_version', '5.6'));
      const back = renameBuild(edited, renamed.uid, { qualifier: '' });
      expect(back.uid).toBe('b1');
      expect(back.draft.builds[0].build.game_version).toBe('5.6');
      expect(back.draft.deleted).toEqual([]);
      // A copy of the file's support build, renamed back as "SUPPORT" names it: the file's own spelling.
      const support = renameBuild(draft, 'b2', { role: 'sub_dps' });
      expect(renameBuild(support.draft, support.uid, { role: 'support' }).draft.builds[1].uid).toBe('b2');
    });

    it("changes a build of the editor's own in place, and a file build's case not at all", () => {
      const draft = fromServer(loaded());
      const mine = renameBuild(draft, 'b3', { qualifier: 'Ours' });
      expect([mine.uid, mine.draft.builds[2].build.qualifier, mine.draft.deleted]).toEqual(['b3', 'Ours', []]);
      // The file's spelling stays: the same name in another case is no other name.
      const melt = updateBuild(draft, 'b1', (one) => ({ ...one, build: { ...one.build, qualifier: 'Melt' }, file: { ...one.file!, qualifier: 'Melt' } }));
      expect(renameBuild(melt, 'b1', { qualifier: 'MELT' }).draft).toBe(melt);
    });
  });

  describe("a build's teams on the page", () => {
    const FREEZE = {
      name: 'Freeze',
      members: [
        { key: 'hutao', name: 'Hu Tao', role: 'main_dps', min_rank: 0, alternatives: [] },
        { key: 'ganyu', name: 'Ganyu', role: 'sub_dps', min_rank: 0, alternatives: [] },
      ],
    };
    const empty = { en: '', sk: '' };

    it("keeps a team's note with its team as its members change - a save files it under the members it has then", () => {
      const first = fromServer(loaded()).builds[0];
      const swapped = { ...VAPORIZE, members: [VAPORIZE.members[0], { ...VAPORIZE.members[1], key: 'yelan', name: 'Yelan' }] };
      const next = withTeamsChange(first, { teams: [swapped], notes: first.notes.teams, waiting: [] });
      expect(next.notes.teams).toEqual([{ en: 'Swirl first.', sk: '' }]);
      expect(next.notes.waiting).toEqual([]);
      expect(buildBody(next).notes.teams).toEqual([{ en: 'Swirl first.' }]);
    });

    it('leaves the note of a team that goes waiting for a team of its members - none where it has no note, two members or a twin waiting already', () => {
      const note = { en: 'Swirl first.', sk: '' };
      expect(waitingOf(VAPORIZE, note, [])).toEqual({
        key: 'hutao xingqiu',
        members: [
          { key: 'hutao', name: 'Hu Tao' },
          { key: 'xingqiu', name: 'Xingqiu' },
        ],
        note: { en: 'Swirl first.' },
      });
      expect(waitingOf(VAPORIZE, empty, [])).toBeNull();
      expect(waitingOf({ ...VAPORIZE, members: [VAPORIZE.members[0], { ...VAPORIZE.members[1], key: '', name: '' }] }, note, [])).toBeNull();
      expect(waitingOf(VAPORIZE, note, [{ key: 'hutao xingqiu', members: [], note: { en: 'Older.' } }])).toBeNull();
      // A note waits only with its English: a Slovak one alone goes with its team.
      expect(waitingOf(VAPORIZE, { en: ' ', sk: 'Len po slovensky.' }, [])).toBeNull();
    });

    it("files the notes under their teams' chosen members when the file's teams come back - a note waiting only where one can", () => {
      const first = fromServer(loaded()).builds[0];
      // A member added to the file's team, not chosen yet: the file's team takes its note back.
      const added = withTeamsChange(first, {
        teams: [{ ...VAPORIZE, members: [...VAPORIZE.members, { key: '', name: '', role: null, min_rank: 0, alternatives: [] }] }],
        notes: first.notes.teams,
        waiting: [],
      });
      const back = useFilePart(added, 'teams');
      expect(back.notes.teams).toEqual([{ en: 'Swirl first.', sk: '' }]);
      expect(back.notes.waiting).toEqual([]);
      // A team of the page's own, half made, with a note: no team of the file's has its members, and it cannot wait.
      const half = withTeamsChange(first, {
        teams: [VAPORIZE, { name: '', members: [VAPORIZE.members[0], { key: '', name: '', role: null, min_rank: 0, alternatives: [] }] }],
        notes: [first.notes.teams[0], { en: 'Half.', sk: '' }],
        waiting: [],
      });
      expect(useFilePart(half, 'teams').notes.waiting).toEqual([]);
    });

    it('holds back a waiting note with no English: a save keeps none so', () => {
      const draft = updateBuild(fromServer(loaded()), 'b1', (one) => ({ ...one, notes: { ...one.notes, waiting: [{ key: 'ganyu hutao', members: [], note: { en: '', sk: 'Len po slovensky.' } }] } }));
      expect(problems(draft, FACTS).filter((problem) => problem.uid === 'b1')).toEqual([
        { uid: 'b1', path: 'notes.waiting[0]', message: 'This note has no English: move it to a team and write its English there, or delete it.' },
      ]);
    });

    it('keeps a waiting note waiting while the teams are edited - naming the team a save gives it to - and says where that team has a note of its own', () => {
      const first = fromServer(loaded()).builds[0];
      const waiting = { key: 'ganyu hutao', members: [FREEZE.members[1], FREEZE.members[0]].map(({ key, name }) => ({ key, name })), note: { en: 'Freeze them.', sk: 'Zmraz ich.' } };
      // A team of exactly its members, with no note: the note still waits on the page - a save gives it that team.
      const kept = withTeamsChange(first, { teams: [VAPORIZE, FREEZE], notes: [first.notes.teams[0], empty], waiting: [waiting] });
      expect(kept.notes.teams[1]).toEqual(empty);
      expect(kept.notes.waiting).toEqual([waiting]);
      expect(takerOf(waiting, kept.build.teams, kept.notes.teams)).toBe(1);
      // A member more, or one not chosen yet: no team of exactly its members.
      const third = { key: 'xingqiu', name: 'Xingqiu', role: null, min_rank: 0, alternatives: [] };
      expect(takerOf(waiting, [VAPORIZE, { ...FREEZE, members: [...FREEZE.members, third] }], [empty, empty])).toBe(-1);
      expect(takerOf(waiting, [{ ...FREEZE, members: [...FREEZE.members, { ...third, key: '', name: '' }] }], [empty])).toBe(-1);

      const both = withTeamsChange(first, { teams: [VAPORIZE, FREEZE], notes: [first.notes.teams[0], { en: 'Its own.', sk: '' }], waiting: [waiting] });
      expect(both.notes.waiting).toEqual([waiting]);
      const draft = updateBuild(fromServer(loaded()), 'b1', () => both);
      expect(problems(draft, FACTS).filter((problem) => problem.uid === 'b1')).toEqual([
        { uid: 'b1', path: 'notes.waiting[0]', message: "Team 2 has these members and a note of its own: keep one of the two - delete this one, or empty that team's." },
      ]);
    });

    it('is as loaded again where the teams and their notes say the same again - nothing to save -, the rows on the page staying those rows', () => {
      const draft = fromServer(loaded());
      const first = draft.builds[0];
      const renamed = withTeamsChange(first, { teams: [{ ...VAPORIZE, name: 'Vape' }], notes: first.notes.teams, waiting: [] }, first);
      expect(renamed.build.teams).not.toBe(first.build.teams);
      const team = { ...VAPORIZE, members: VAPORIZE.members.map((member) => ({ ...member })) };
      const back = withTeamsChange(renamed, { teams: [team], notes: [{ en: 'Swirl first.', sk: '' }], waiting: [] }, first);
      expect(back.build.teams).toBe(first.build.teams);
      expect(rowId(back.build.teams[0])).toBe(rowId(team));
      expect(saveText(updateBuild(draft, 'b1', () => back))).toBe(saveText(draft));
      // The file's teams again, where they are what was loaded: as loaded.
      expect(useFilePart(first, 'teams', first).build.teams).toBe(first.build.teams);
    });

    it('holds back a team the builds file would refuse: no name or one too long, fewer than two members, one unchosen or twice, a stand-in of the team, two teams of the same members', () => {
      const teams = [
        { ...VAPORIZE, name: ' ' },
        { ...FREEZE, name: 'x'.repeat(61), members: [FREEZE.members[0]] },
        {
          name: 'Odd',
          members: [
            FREEZE.members[0],
            { ...FREEZE.members[1], key: '', name: '' },
            { key: 'xingqiu', name: 'Xingqiu', role: null, min_rank: 0, alternatives: [{ key: 'hutao', name: 'Hu Tao' }] },
            { key: 'xingqiu', name: 'Xingqiu', role: null, min_rank: 0, alternatives: [] },
          ],
        },
        { ...VAPORIZE, name: 'Vaporize again', members: [...VAPORIZE.members].reverse() },
      ];
      const draft = updateBuild(fromServer(loaded()), 'b1', (one) => ({ ...withField(one, 'teams', teams), notes: { ...one.notes, teams: teams.map(() => empty) } }));
      expect(problems(draft, FACTS).filter((problem) => problem.uid === 'b1')).toEqual([
        { uid: 'b1', path: 'teams[0].name', message: 'Name the team, as members read it - such as "Vaporize".' },
        { uid: 'b1', path: 'teams[1].name', message: 'At most 60 characters.' },
        { uid: 'b1', path: 'teams[1].members', message: 'A team has two members at least: add one.' },
        { uid: 'b1', path: 'teams[2].members[1].key', message: 'Choose a character here, or remove the member.' },
        { uid: 'b1', path: 'teams[2].members[3].key', message: 'This character is in the team already: remove one of the two.' },
        { uid: 'b1', path: 'teams[2].members[2].alternatives', message: 'Hu Tao: a member of the team, so no stand-in.' },
        { uid: 'b1', path: 'teams[3].members', message: "Team 1 has the same members: a team's members are its own." },
      ]);
    });
  });

  describe('a part set on the page', () => {
    it('is the part as it was loaded where it says the same again - a change undone holds nothing to save -, its rows the rows on the page', () => {
      const draft = fromServer(loaded());
      const was = draft.builds[0];
      // The sands emptied, then listed again - the page lists it last among the slots -: the slots as loaded, in their order.
      const goblet = [{ stat: 'pyro_dmg_', priority: 1 }];
      const two = withField(was, 'main_stats', { sands: [{ stat: 'hp_', priority: 1 }], goblet });
      const emptied = withPart(two, 'main_stats', { goblet }, two.build);
      expect(Object.keys(emptied.build.main_stats)).toEqual(['goblet']);
      const relisted = withPart(emptied, 'main_stats', { goblet, sands: [{ stat: 'hp_', priority: 1 }] }, two.build);
      expect(relisted.build.main_stats).toBe(two.build.main_stats);
      expect(Object.keys(relisted.build.main_stats)).toEqual(['sands', 'goblet']);

      // A refinement changed and changed back: the part loaded again - the save as it came.
      const changed = withPart(
        was,
        'weapons',
        replaced(was.build.weapons, 0, (weapon) => ({ ...weapon, refinement: 2 })),
        was.build,
      );
      expect(changed.build.weapons).not.toBe(was.build.weapons);
      const back = withPart(
        changed,
        'weapons',
        replaced(changed.build.weapons, 0, (weapon) => ({ ...weapon, refinement: 1 })),
        was.build,
      );
      expect(back.build.weapons).toBe(was.build.weapons);
      expect(saveText(updateBuild(draft, was.uid, () => back))).toBe(saveText(draft));
      // The weapon removed and chosen again: its row drawn as the one on the page - which the page keeps, and its focus.
      const again = { key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 };
      const drawn = rowId(again);
      const chosen = withPart(withPart(was, 'weapons', [], was.build), 'weapons', [again], was.build);
      expect(chosen.build.weapons).toBe(was.build.weapons);
      expect(rowId(chosen.build.weapons[0])).toBe(drawn);

      // The loaded rows take the page rows' very ids: the sands' ATK% removed, the HP% row made ATK% at priority 1,
      // and HP% added at priority 2 - as loaded again, and no two rows of the list one id.
      const sandsRows = [
        { stat: 'atk_', priority: 1 },
        { stat: 'hp_', priority: 2 },
      ];
      const sands = withField(was, 'main_stats', { sands: sandsRows });
      const kept = carried(sandsRows[1], { stat: 'atk_', priority: 1 });
      const added = { stat: 'hp_', priority: 2 };
      const listed = withPart(sands, 'main_stats', { sands: [kept, added] }, sands.build);
      expect(listed.build.main_stats).toBe(sands.build.main_stats);
      expect(listed.build.main_stats['sands'].map(rowId)).toEqual([rowId(kept), rowId(added)]);
      expect(rowId(kept)).not.toBe(rowId(added));

      // Something else: the part as set; none loaded to compare with (a build new on the page): as set too.
      expect(withPart(was, 'game_version', '5.6', was.build).build.game_version).toBe('5.6');
      const fresh = withPart(was, 'substats', [{ stat: 'critRate_', priority: 1 }], null);
      expect(fresh.build.substats).not.toBe(was.build.substats);
    });
  });
});
