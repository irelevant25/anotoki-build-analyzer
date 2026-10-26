import {
  BuildPart,
  CharacterEditing,
  ComputedTargets,
  EditorBuild,
  EditorEntry,
  EditorFacts,
  EditorNotes,
  EditorSave,
  EditorSaveBuild,
  EditorTeam,
  RemovedBuild,
  SiteNote,
  WaitingNote,
} from '../../../api/models';

/*
 * The builds editor's state (docs/builds.md, "The editor"): a character's builds as the page holds them while an editor
 * changes them - made from what the server loaded (GET /api/admin/builds/{game}/characters/{key}) and made back into a
 * save (PUT, the same address) -, and the checks a save gets here first, each tied to the place it is about.
 *
 * Plain functions over plain data. The page keeps the state in a signal and replaces it with each change, every object
 * along the way copied, never changed in place: a part nobody touched is the very object the server sent, and a save
 * sends it back exactly as it came - which is how the server tells an edit from a part sent back as it was loaded.
 * English, as the whole admin panel: the messages are written here.
 */

/** The parts of a build, in the order an import names them (backend: BuildParts::PARTS). */
export const BUILD_PARTS: readonly BuildPart[] = ['weapons', 'sets', 'main_stats', 'substats', 'talent_priority', 'stat_targets', 'teams', 'game_version'];

/** A note as the editor's two boxes hold it: '' where nothing is written. */
export interface NoteDraft {
  en: string;
  sk: string;
}

/** A build's notes on the page: the build's, each team's in the teams' order, and the ones waiting for a team. */
export interface DraftNotes {
  build: NoteDraft;
  teams: NoteDraft[];
  waiting: WaitingNote[];
}

/** A build on the editor's page. */
export interface DraftBuild {
  /** The page's own name for it - its tab's id -, the same while it is on the page. */
  uid: string;
  /** The stored build it is; null for one new on this page. */
  id: number | null;
  /** The removed build of the file's it brings back; null otherwise. */
  removal: number | null;
  build: EditorBuild;
  /** What the last builds file said of it; null for one made here. */
  file: EditorBuild | null;
  /** Where the file puts it among the character's builds; null for one made here. */
  filePosition: number | null;
  notes: DraftNotes;
  /** As loaded: when a file last carried it, when what members see last changed, when an editor last saved it. */
  checkedAt: string | null;
  changedAt: string | null;
  editedAt: string | null;
  /** The build of the file's this one was renamed from on this page (its uid, set aside in the draft's `deleted`); null otherwise. */
  renamedFrom: string | null;
  /** The stat targets the site works out for it, as loaded (what the targets form's Auto gives); null while the catalog lacks the stats. */
  computed: ComputedTargets | null;
}

/** A stored build deleted on this page, and the place it had: put back there. */
export interface DeletedBuild {
  build: DraftBuild;
  index: number;
}

/** A character's builds on the editor's page. */
export interface Draft {
  /** The version a save is checked against. */
  version: string;
  /** Whose order the stored builds are in. */
  order: 'file' | 'editors';
  /** The order given back to the file at the next save (it was an editor's). */
  orderToFile: boolean;
  builds: DraftBuild[];
  /** The removed builds of the file's, as loaded: those not brought back on this page can be. */
  removed: RemovedBuild[];
  /** The stored builds deleted on this page and not saved yet: they can be put back. */
  deleted: DeletedBuild[];
}

/** A change of a build's teams on the page: the teams, each one's note in their order, the notes waiting for a team. */
export interface TeamsChange {
  teams: EditorTeam[];
  notes: NoteDraft[];
  waiting: WaitingNote[];
}

/** Something a save would be refused for: the build it is in (its uid; '' for the builds as a whole), the place in it, what is wrong. */
export interface Problem {
  uid: string;
  path: string;
  message: string;
}

let made = 0;

/** A name for a build new on this page, unlike any other while the page lives. */
function newUid(): string {
  made += 1;
  return `n${made}`;
}

export function noteDraft(note: SiteNote | null | undefined): NoteDraft {
  return { en: note?.en ?? '', sk: note?.sk ?? '' };
}

/** A note as a save sends it: null where nothing is written, the Slovak only where it is. */
export function noteOf(note: NoteDraft): SiteNote | null {
  if (note.en.trim() === '' && note.sk.trim() === '') {
    return null;
  }
  return note.sk.trim() === '' ? { en: note.en } : { en: note.en, sk: note.sk };
}

function notesDraft(notes: EditorNotes): DraftNotes {
  return { build: noteDraft(notes.build), teams: notes.teams.map(noteDraft), waiting: notes.waiting };
}

function entryDraft(entry: EditorEntry): DraftBuild {
  return {
    uid: `b${entry.id}`,
    id: entry.id,
    removal: null,
    build: entry.build,
    file: entry.file,
    filePosition: entry.file_position,
    notes: notesDraft(entry.notes),
    checkedAt: entry.checked_at,
    changedAt: entry.changed_at,
    editedAt: entry.edited_at,
    renamedFrom: null,
    computed: entry.computed,
  };
}

/** The page's state from what the server loaded (or answered a save with). */
export function fromServer(answer: CharacterEditing): Draft {
  return { version: answer.version, order: answer.order, orderToFile: false, builds: answer.builds.map(entryDraft), removed: answer.removed, deleted: [] };
}

/** One build as a save sends it. */
export function buildBody(build: DraftBuild): EditorSaveBuild {
  return {
    id: build.id,
    removal: build.removal,
    build: build.build,
    notes: {
      build: noteOf(build.notes.build),
      teams: build.notes.teams.map(noteOf),
      waiting: build.notes.waiting.map((waiting) => ({ key: waiting.key, note: waiting.note })),
    },
  };
}

/** The save of the page's state: the character's builds as they stand, in their order. */
export function saveBody(draft: Draft): EditorSave {
  const body: EditorSave = { version: draft.version, builds: draft.builds.map(buildBody) };
  if (draft.orderToFile) {
    body.order = 'file';
  }
  return body;
}

/** The save as one text: two states that would save the same are the same text (unsaved changes, a build's own). */
export function saveText(draft: Draft): string {
  return JSON.stringify(saveBody(draft));
}

/** [{stat|talent, priority}] as pairs; none as []. */
function ranked<T extends { priority: number }>(list: readonly T[] | null, name: keyof T): unknown[] {
  return (list ?? []).map((entry) => [entry[name], entry.priority]);
}

/**
 * A part of a build in one form, as an import compares it (backend: BuildParts::of()): what the file says of it, the
 * order of every list as it is, a main stat's slots in one order, none (null) as an empty list.
 */
export function partText(build: EditorBuild, part: BuildPart): string {
  switch (part) {
    case 'weapons':
      return JSON.stringify(build.weapons.map((weapon) => [weapon.key, weapon.name, weapon.refinement ?? null]));
    case 'sets':
      return JSON.stringify(build.sets.map((option) => [option.kind, option.choose ?? null, option.pieces.map((piece) => [piece.label, piece.count, piece.keys, piece.names])]));
    case 'main_stats':
      return JSON.stringify(
        Object.keys(build.main_stats)
          .sort()
          .map((slot) => [slot, ranked(build.main_stats[slot], 'stat')]),
      );
    case 'substats':
      return JSON.stringify(ranked(build.substats, 'stat'));
    case 'talent_priority':
      return JSON.stringify(ranked(build.talent_priority, 'talent'));
    case 'stat_targets':
      return JSON.stringify(
        (build.stat_targets ?? []).map((target) => [
          target.stat,
          target.min,
          target.max,
          target.from_rank,
          target.tiers ? [target.tiers.basic, target.tiers.intermediate, target.tiers.advanced] : null,
          target.off === true,
        ]),
      );
    case 'teams':
      return JSON.stringify(
        build.teams.map((team) => [team.name, team.members.map((member) => [member.key, member.name, member.role, member.min_rank, member.alternatives.map((one) => [one.key, one.name])])]),
      );
    case 'game_version':
      return JSON.stringify(build.game_version ?? null);
  }
}

/** The parts in which two builds differ, in the parts' order. */
export function differingParts(a: EditorBuild, b: EditorBuild): BuildPart[] {
  return BUILD_PARTS.filter((part) => partText(a, part) !== partText(b, part));
}

/** The parts of a build of the file's that differ from what the file says of it - its edits; none for one made here. */
export function editedParts(build: DraftBuild): BuildPart[] {
  return build.file ? differingParts(build.build, build.file) : [];
}

/** A build's name among its character's: its role, its qualifier without regard to case, its constellation (BuildFile::identity()). */
export function identityOf(build: Pick<EditorBuild, 'role' | 'qualifier' | 'min_rank'>): string {
  return `${build.role}\n${build.qualifier.trim().toLowerCase()}\n${build.min_rank}`;
}

/** The removed builds of the file's that can be brought back: the ones not brought back on this page. */
export function bringable(draft: Draft): RemovedBuild[] {
  return draft.removed.filter((removal) => !draft.builds.some((build) => build.removal === removal.id));
}

/** The names of the file's builds that are removed - before, or on this page: no other build may take one. */
function removedNames(draft: Draft): Set<string> {
  return new Set([...bringable(draft).map((removal) => identityOf(removal.build)), ...draft.deleted.filter((entry) => entry.build.file !== null).map((entry) => identityOf(entry.build.build))]);
}

/** A build of the file's - or brought back - keeps its role, qualifier and constellation: its name in the file. */
export function nameFixed(build: DraftBuild): boolean {
  return build.file !== null;
}

// ── the order ─────────────────────────────────────────────────────────────────────────────────────

/** The order an import would give the builds: the file's in the order the file gives them, the others after them as they stand. */
export function fileOrder(builds: readonly DraftBuild[]): DraftBuild[] {
  const fromFile = builds.filter((build) => build.filePosition !== null).sort((a, b) => (a.filePosition ?? 0) - (b.filePosition ?? 0));
  return [...fromFile, ...builds.filter((build) => build.filePosition === null)];
}

/** Whether the builds stand as an import would order them. */
export function inFileOrder(builds: readonly DraftBuild[]): boolean {
  return fileOrder(builds).every((build, i) => build.uid === builds[i].uid);
}

/** Whose order the builds will be in once saved: the file's, or an editor's - said by moving one, never guessed by an import. */
export function orderOwner(draft: Draft): 'file' | 'editors' {
  if (draft.orderToFile) {
    return 'file';
  }
  return draft.order === 'editors' || !inFileOrder(draft.builds) ? 'editors' : 'file';
}

/** A build moved one place earlier (-1) or later (1): the order is then the editor's. */
export function moveBuild(draft: Draft, uid: string, delta: -1 | 1): Draft {
  const from = draft.builds.findIndex((build) => build.uid === uid);
  const to = from + delta;
  if (from < 0 || to < 0 || to >= draft.builds.length) {
    return draft;
  }
  const builds = [...draft.builds];
  [builds[from], builds[to]] = [builds[to], builds[from]];
  return { ...draft, builds, orderToFile: false };
}

/** The builds in the file's order again - and, where the stored order is an editor's, the order given back to the file. */
export function giveOrderBack(draft: Draft): Draft {
  return { ...draft, builds: fileOrder(draft.builds), orderToFile: draft.order === 'editors' };
}

/** A build put among the others: one of the file's where the file puts it among the file's, any other last. */
function placed(builds: readonly DraftBuild[], build: DraftBuild): DraftBuild[] {
  const position = build.filePosition;
  if (position === null) {
    return [...builds, build];
  }
  let at = builds.findIndex((other) => other.filePosition !== null && other.filePosition > position);
  if (at < 0) {
    // After the last of the file's: before the editors' own, as an import orders them.
    at = 0;
    builds.forEach((other, i) => {
      if (other.filePosition !== null) {
        at = i + 1;
      }
    });
  }
  return [...builds.slice(0, at), build, ...builds.slice(at)];
}

// ── adding and removing builds ────────────────────────────────────────────────────────────────────

/** A build with nothing in it but its variation. */
export function emptyBuild(role: string, qualifier: string, minRank: number): EditorBuild {
  return { role, qualifier, min_rank: minRank, weapons: [], sets: [], main_stats: {}, substats: [], talent_priority: null, stat_targets: null, teams: [], game_version: null };
}

/** `base`, or "base 2", "base 3"... - the first no build here, nor a removed one of the file's, has with this role and constellation. */
function freeQualifier(draft: Draft, role: string, minRank: number, base: string, max: number): string {
  const taken = new Set([...draft.builds.map((build) => identityOf(build.build)), ...removedNames(draft)]);
  for (let n = 1; ; n++) {
    const suffix = n === 1 ? '' : ` ${n}`;
    const qualifier = `${base.slice(0, Math.max(0, max - suffix.length)).trim()}${suffix}`;
    if (!taken.has(identityOf({ role, qualifier, min_rank: minRank }))) {
      return qualifier;
    }
  }
}

function newDraftBuild(build: EditorBuild, notes: DraftNotes, computed: ComputedTargets | null = null): DraftBuild {
  return { uid: newUid(), id: null, removal: null, build, file: null, filePosition: null, notes, checkedAt: null, changedAt: null, editedAt: null, renamedFrom: null, computed };
}

/** A new, empty build of the editor's own, last: the first role with no build of its own yet, else "New" (to be renamed). */
export function addNew(draft: Draft, facts: EditorFacts): { draft: Draft; uid: string } {
  const taken = new Set([...draft.builds.map((build) => identityOf(build.build)), ...removedNames(draft)]);
  const free = facts.roles.find((role) => !taken.has(identityOf({ role, qualifier: '', min_rank: 0 })));
  const role = free ?? facts.roles[0];
  const qualifier = free === undefined ? freeQualifier(draft, role, 0, 'New', facts.limits.qualifier) : '';
  const build = newDraftBuild(emptyBuild(role, qualifier, 0), { build: noteDraft(null), teams: [], waiting: [] });
  return { draft: { ...draft, builds: [...draft.builds, build] }, uid: build.uid };
}

/** A copy of a build - every part and note of it -, as a build of the editor's own, last; its qualifier "… copy" until renamed. */
export function addCopy(draft: Draft, uid: string, facts: EditorFacts): { draft: Draft; uid: string } | null {
  const source = draft.builds.find((build) => build.uid === uid);
  if (!source) {
    return null;
  }
  const own = source.build.qualifier.trim();
  const qualifier = freeQualifier(draft, source.build.role, source.build.min_rank, own === '' ? 'Copy' : `${own} copy`, facts.limits.qualifier);
  // Rows of its own: a copy's row is no row of the original's (rowId()).
  // What the site works out for the original holds for its copy until the copy's parts change and it is saved.
  const build = newDraftBuild({ ...structuredClone(source.build), qualifier }, structuredClone(source.notes), source.computed);
  return { draft: { ...draft, builds: [...draft.builds, build] }, uid: build.uid };
}

/** A removed build of the file's brought back - what the file said of it, its notes -, where the file puts it. */
export function bringBack(draft: Draft, removalId: number): { draft: Draft; uid: string } | null {
  const removal = bringable(draft).find((removed) => removed.id === removalId);
  if (!removal) {
    return null;
  }
  const build: DraftBuild = {
    uid: `r${removal.id}`,
    id: null,
    removal: removal.id,
    // Rows of its own: brought back again after a delete, nothing typed in it before comes back with it.
    build: structuredClone(removal.build),
    file: removal.build,
    filePosition: removal.file_position,
    notes: notesDraft(removal.notes),
    checkedAt: null,
    changedAt: null,
    editedAt: null,
    renamedFrom: null,
    computed: removal.computed,
  };
  return { draft: { ...draft, builds: placed(draft.builds, build) }, uid: build.uid };
}

/** A build taken off the page: a stored one kept to be put back until the save (the save removes it), any other gone. */
export function deleteBuild(draft: Draft, uid: string): Draft {
  const build = draft.builds.find((one) => one.uid === uid);
  if (!build) {
    return draft;
  }
  const index = draft.builds.indexOf(build);
  return { ...draft, builds: draft.builds.filter((one) => one.uid !== uid), deleted: build.id !== null ? [...draft.deleted, { build, index }] : draft.deleted };
}

/** A stored build deleted on this page put back as it was, in the place it had - a delete undone changes nothing else. */
export function putBack(draft: Draft, uid: string): Draft {
  const entry = draft.deleted.find((one) => one.build.uid === uid);
  if (!entry) {
    return draft;
  }
  const at = Math.min(entry.index, draft.builds.length);
  return { ...draft, builds: [...draft.builds.slice(0, at), entry.build, ...draft.builds.slice(at)], deleted: draft.deleted.filter((one) => one.build.uid !== uid) };
}

// ── a list's rows ─────────────────────────────────────────────────────────────────────────────────

const rowIds = new WeakMap<object, string>();
let rowsMade = 0;

/**
 * The page's name for an entry of a build's list - a weapon, a set option or piece, a priority, a target -, which the
 * page tracks the list's rows by: kept by the copy an edit makes of the entry (carried()), so the row being edited stays
 * the same row on the page and its field keeps the focus, and a row moved keeps its name.
 */
export function rowId(entry: object): string {
  let id = rowIds.get(entry);
  if (id === undefined) {
    rowsMade += 1;
    id = `row${rowsMade}`;
    rowIds.set(entry, id);
  }
  return id;
}

/** An entry's copy with a change: the same row on the page as the entry. */
export function carried<T extends object>(entry: T, copy: T): T {
  rowIds.set(copy, rowId(entry));
  return copy;
}

/** A list with one entry moved one place earlier (-1) or later (1); the list as it is where it can go no further. */
export function moved<T>(list: readonly T[], index: number, delta: -1 | 1): T[] {
  const to = index + delta;
  const copy = [...list];
  if (index < 0 || index >= list.length || to < 0 || to >= list.length) {
    return copy;
  }
  [copy[index], copy[to]] = [copy[to], copy[index]];
  return copy;
}

/** A list with one entry replaced by its copy with a change (the same row: carried()). */
export function replaced<T extends object>(list: readonly T[], index: number, change: (entry: T) => T): T[] {
  return list.map((entry, i) => (i === index ? carried(entry, change(entry)) : entry));
}

// ── changing a build ──────────────────────────────────────────────────────────────────────────────

/** A build's name: its role, its qualifier, the constellation it needs. */
export type BuildName = Pick<EditorBuild, 'role' | 'qualifier' | 'min_rank'>;

/** The build of the file's a build was renamed from on this page, set aside - or none (never renamed, or put back since). */
export function renamedOriginal(draft: Draft, build: DraftBuild): DraftBuild | null {
  return build.renamedFrom === null ? null : (draft.deleted.find((entry) => entry.build.uid === build.renamedFrom)?.build ?? null);
}

/**
 * A build's name changed on the page - its role, qualifier or constellation. A build of the file's keeps its name
 * (the server keeps it, its spelling too): changed, it becomes a build of the editor's own in its place - its parts and
 * its notes with it -, the file's set aside to be removed at the save (remembered there: no import brings it back
 * unasked; until then Add build puts it back). Named as that build of the file's again (without regard to case), it
 * is that build again - in its place, with the file's spelling and what it holds now. A name with no other identity
 * than it had (the case of a file build's qualifier) changes nothing: the file's spelling stays.
 */
export function renameBuild(draft: Draft, uid: string, name: Partial<BuildName>): { draft: Draft; uid: string } {
  const index = draft.builds.findIndex((one) => one.uid === uid);
  const build = draft.builds[index];
  if (!build) {
    return { draft, uid };
  }
  const next: EditorBuild = { ...build.build, ...name };
  const replace = (builds: readonly DraftBuild[], one: DraftBuild) => builds.map((each, i) => (i === index ? one : each));
  // Back to the file's build it was renamed from: that one, holding what this one holds now.
  const original = renamedOriginal(draft, build);
  if (original && identityOf(next) === identityOf(original.build)) {
    const named: BuildName = { role: original.build.role, qualifier: original.build.qualifier, min_rank: original.build.min_rank };
    const back: DraftBuild = { ...original, build: { ...next, ...named }, notes: build.notes };
    return { draft: { ...draft, builds: replace(draft.builds, back), deleted: draft.deleted.filter((entry) => entry.build !== original) }, uid: back.uid };
  }
  if (!nameFixed(build)) {
    return { draft: { ...draft, builds: replace(draft.builds, { ...build, build: next }) }, uid };
  }
  if (identityOf(next) === identityOf(build.build)) {
    return { draft, uid };
  }
  // A build of the file's renamed: one of the editor's own in its place, the file's set aside (a stored one, to come back to).
  const copy: DraftBuild = { ...newDraftBuild(next, build.notes), renamedFrom: build.id !== null ? build.uid : null };
  const without = deleteBuild(draft, uid);
  return { draft: { ...without, builds: [...without.builds.slice(0, index), copy, ...without.builds.slice(index)] }, uid: copy.uid };
}

/** The draft with one build changed. */
export function updateBuild(draft: Draft, uid: string, change: (build: DraftBuild) => DraftBuild): Draft {
  return { ...draft, builds: draft.builds.map((build) => (build.uid === uid ? change(build) : build)) };
}

/** A build with one field of it set. */
export function withField<K extends keyof EditorBuild>(build: DraftBuild, field: K, value: EditorBuild[K]): DraftBuild {
  return { ...build, build: { ...build.build, [field]: value } };
}

/**
 * The rows of one list drawn as the same rows of another of the same shape (rowId()): the page keeps them, and its
 * focus. Each takes the very id of the row on the page - one it is given now where it had none -, so no two rows of a
 * list ever share one.
 */
function sameRows(from: unknown, to: unknown): void {
  if (Array.isArray(from) && Array.isArray(to)) {
    from.forEach((entry, i) => sameRows(entry, to[i]));
  } else if (from !== null && to !== null && typeof from === 'object' && typeof to === 'object') {
    rowIds.set(to, rowId(from));
    for (const [key, value] of Object.entries(from)) {
      sameRows(value, (to as Record<string, unknown>)[key]);
    }
  }
}

/**
 * A build with one of its parts set - the part as it was loaded where it says the same again (partText()): a part
 * changed back saves as it came, a main stat's slots in their order too, and the page holds no change nobody sees; its
 * rows stay the rows on the page (rowId()).
 */
export function withPart<K extends Exclude<BuildPart, 'teams'>>(build: DraftBuild, part: K, value: EditorBuild[K], loaded: EditorBuild | null): DraftBuild {
  const next = withField(build, part, value);
  if (loaded === null || partText(next.build, part) !== partText(loaded, part)) {
    return next;
  }
  sameRows(value, loaded[part]);
  return withField(build, part, loaded[part]);
}

/** What a team's note goes by: its members' keys, sorted, one space apart (BuildNotes::teamKey()). */
export function teamKey(team: EditorTeam): string {
  return team.members
    .map((member) => member.key)
    .sort()
    .join(' ');
}

/**
 * A build with other teams ("Use the file's"), each team's note going with its chosen members (backend: BuildNotes): a
 * team of the same members keeps its note; a note whose team is gone waits for a team of those members where a note
 * can wait (waitingOf()), else it goes; a waiting note whose team is back is that team's again, where the team has none
 * of its own.
 */
export function withTeams(build: DraftBuild, teams: EditorTeam[]): DraftBuild {
  const byKey = new Map<string, { team: EditorTeam; note: NoteDraft }>();
  build.build.teams.forEach((team, i) => {
    const note = build.notes.teams[i];
    if (note && noteOf(note) !== null) {
      byKey.set(chosenKey(team), { team, note });
    }
  });
  const waiting = new Map(build.notes.waiting.map((entry) => [entry.key, entry]));
  const notes = teams.map((team) => {
    const key = chosenKey(team);
    const own = byKey.get(key);
    if (own) {
      byKey.delete(key);
      return own.note;
    }
    const waited = waiting.get(key);
    if (waited) {
      waiting.delete(key);
      return noteDraft(waited.note);
    }
    return noteDraft(null);
  });
  // The notes whose team is gone wait for a team of their members - where a note can wait (waitingOf()).
  for (const { team, note } of byKey.values()) {
    const entry = waitingOf(team, note, [...waiting.values()]);
    if (entry) {
      waiting.set(entry.key, entry);
    }
  }
  return { ...build, build: { ...build.build, teams }, notes: { ...build.notes, teams: notes, waiting: [...waiting.values()] } };
}

/** A team's chosen members' keys, sorted, one space apart: what its note is filed under (a member not chosen yet is nobody). */
function chosenKey(team: EditorTeam): string {
  return teamKey({ ...team, members: team.members.filter((member) => member.key !== '') });
}

/**
 * A part of a build as the file has it again ("Use the file's") - rows of its own, never the file's very ones (what is
 * typed in a row stays with that row), and the part as it was loaded where that is what the file says (withPart()); a
 * build the file has nothing of stays as it is.
 */
export function useFilePart(build: DraftBuild, part: BuildPart, loaded: DraftBuild | null = null): DraftBuild {
  if (!build.file) {
    return build;
  }
  if (part === 'teams') {
    const teams = structuredClone(build.file.teams);
    return asLoadedTeams(withTeams(build, teams), teams, loaded);
  }
  return withPart(build, part, structuredClone(build.file[part]), loaded?.build ?? null);
}

/** A build's teams' notes in one text, as a save sends them: two that would save the same are the same text. */
function teamNotesText(build: DraftBuild): string {
  return JSON.stringify([build.notes.teams.map(noteOf), build.notes.waiting.map((entry) => [entry.key, entry.note])]);
}

/**
 * A build's teams and their notes as loaded where they say the same again (as withPart()): a change undone holds
 * nothing to save, the rows on the page (`teams`, as the page has them) staying those rows.
 */
function asLoadedTeams(build: DraftBuild, teams: EditorTeam[], loaded: DraftBuild | null): DraftBuild {
  if (loaded === null || partText(build.build, 'teams') !== partText(loaded.build, 'teams') || teamNotesText(build) !== teamNotesText(loaded)) {
    return build;
  }
  sameRows(teams, loaded.build.teams);
  return { ...build, build: { ...build.build, teams: loaded.build.teams }, notes: { ...build.notes, teams: loaded.notes.teams, waiting: loaded.notes.waiting } };
}

/**
 * A build with its teams as the teams' form leaves them: each note with its team, as the form keeps them (a team's
 * members changed, its note stays its own), and the notes waiting for a team as they are - one goes to a team only when
 * it is moved there by hand (a save gives one to a team of exactly its members with no note of its own: takerOf()). As
 * loaded where all of it says the same again.
 */
export function withTeamsChange(build: DraftBuild, change: TeamsChange, loaded: DraftBuild | null = null): DraftBuild {
  const next: DraftBuild = { ...build, build: { ...build.build, teams: change.teams }, notes: { ...build.notes, teams: change.notes, waiting: change.waiting } };
  return asLoadedTeams(next, change.teams, loaded);
}

/** The team a save gives a waiting note to (BuildNotes::fromEditor()): one of exactly its members, with no note of its own; -1 for none. */
export function takerOf(entry: WaitingNote, teams: readonly EditorTeam[], notes: readonly NoteDraft[]): number {
  return teams.findIndex((team, t) => team.members.every((member) => member.key !== '') && teamKey(team) === entry.key && noteOf(notes[t] ?? noteDraft(null)) === null);
}

/**
 * The note a team leaves when it goes, waiting for a team of its members (as a save keeps one whose team is gone):
 * null where it has none, or no English (a note waits only with it), where its members are not two chosen characters
 * at least, or where a note waits for them already - the note then goes with the team.
 */
export function waitingOf(team: EditorTeam, note: NoteDraft, waiting: readonly WaitingNote[]): WaitingNote | null {
  const written = noteOf(note);
  const members = team.members.filter((member) => member.key !== '');
  if (written === null || written.en.trim() === '' || members.length < 2) {
    return null;
  }
  const key = teamKey({ ...team, members });
  return waiting.some((entry) => entry.key === key) ? null : { key, members: members.map((member) => ({ key: member.key, name: member.name })), note: written };
}

// ── the checks before a save ──────────────────────────────────────────────────────────────────────

/** What is wrong with a note's two boxes: English first, each within its cap. */
function noteProblems(uid: string, note: NoteDraft, path: string, max: number): Problem[] {
  const found: Problem[] = [];
  if (note.sk.trim() !== '' && note.en.trim() === '') {
    found.push({ uid, path: `${path}.en`, message: 'Write the English first: the Slovak is optional, the English is not.' });
  }
  for (const language of ['en', 'sk'] as const) {
    if (note[language].trim().length > max) {
      found.push({ uid, path: `${path}.${language}`, message: `At most ${max} characters.` });
    }
  }
  return found;
}

/**
 * What is wrong with a build's teams, as the builds file's reader would say it (BuildFile::teams()): a team with no name
 * or one too long, fewer than two members, a member with no character chosen or one twice, a stand-in who is a member
 * of the team, two teams of the same members. (The forms keep the build's own character in every team, without a
 * stand-in, and no more members or stand-ins than a team takes.)
 */
function teamProblems(uid: string, teams: readonly EditorTeam[], facts: EditorFacts): Problem[] {
  const found: Problem[] = [];
  const sets = new Map<string, number>();
  teams.forEach((team, t) => {
    const at = `teams[${t}]`;
    if (team.name.trim() === '') {
      found.push({ uid, path: `${at}.name`, message: 'Name the team, as members read it - such as "Vaporize".' });
    } else if (team.name.trim().length > facts.limits.team_name) {
      found.push({ uid, path: `${at}.name`, message: `At most ${facts.limits.team_name} characters.` });
    }
    if (team.members.length < 2) {
      found.push({ uid, path: `${at}.members`, message: 'A team has two members at least: add one.' });
    }
    const keys = new Set<string>();
    team.members.forEach((member, m) => {
      if (member.key === '') {
        found.push({ uid, path: `${at}.members[${m}].key`, message: 'Choose a character here, or remove the member.' });
      } else if (keys.has(member.key)) {
        found.push({ uid, path: `${at}.members[${m}].key`, message: 'This character is in the team already: remove one of the two.' });
      }
      keys.add(member.key);
    });
    team.members.forEach((member, m) => {
      const inTeam = member.alternatives.filter((alternative) => keys.has(alternative.key));
      if (inTeam.length > 0) {
        found.push({ uid, path: `${at}.members[${m}].alternatives`, message: `${inTeam.map((one) => one.name).join(', ')}: a member of the team, so no stand-in.` });
      }
    });
    if (team.members.length >= 2 && team.members.every((member) => member.key !== '')) {
      const set = teamKey(team);
      const other = sets.get(set);
      if (other !== undefined) {
        found.push({ uid, path: `${at}.members`, message: `Team ${other + 1} has the same members: a team's members are its own.` });
      } else {
        sets.set(set, t);
      }
    }
  });
  return found;
}

/** A number a target's bound can be: within the format's bound, either way. */
function withinBound(value: number | null, bound: number): boolean {
  return value === null || (Number.isFinite(value) && Math.abs(value) <= bound);
}

/**
 * What is wrong with a build's parts that the page's forms can leave wrong for a moment: a weapon's row with no weapon
 * chosen; a set piece with no set, a label over its cap, more pieces a "wear any" than there are; a target's minimum
 * over its maximum, a bound out of range, two targets of one stat from one constellation - the builds file's own rules,
 * which the server holds every build to. And the page's own, which a file's build may break and still be read - a
 * weapon twice, one of several sets with no label, a target with no bound -, asked only of the parts `changed` (the
 * parts changed on the page, changedParts()): a build as a file brought it never holds its character's save back. (A
 * ranked list's forms let nothing wrong be chosen.)
 */
function partProblems(uid: string, build: EditorBuild, facts: EditorFacts, changed: ReadonlySet<BuildPart>): Problem[] {
  const limits = facts.limits;
  const found: Problem[] = [];
  const weapons = new Set<string>();
  build.weapons.forEach((weapon, i) => {
    if (weapon.key === '') {
      found.push({ uid, path: `weapons[${i}].key`, message: 'Choose a weapon here, or remove the row.' });
    } else if (changed.has('weapons') && weapons.has(weapon.key)) {
      found.push({ uid, path: `weapons[${i}].key`, message: 'This weapon is listed above already: remove one of the two.' });
    }
    weapons.add(weapon.key);
  });
  build.sets.forEach((option, o) => {
    option.pieces.forEach((piece, p) => {
      const at = `sets[${o}].pieces[${p}]`;
      if (piece.keys.length === 0) {
        found.push({ uid, path: `${at}.keys`, message: 'Choose a set for this piece, or remove it.' });
      } else if (changed.has('sets') && piece.keys.length > 1 && piece.label.trim() === '') {
        found.push({ uid, path: `${at}.label`, message: 'Name what these sets have in common, as members read it - such as "any 18% ATK set".' });
      }
      if (piece.label.length > limits.name) {
        found.push({ uid, path: `${at}.label`, message: `At most ${limits.name} characters.` });
      }
    });
    if (option.choose !== null && option.choose > option.pieces.length) {
      found.push({ uid, path: `sets[${o}].choose`, message: 'More than the option has pieces: choose again.' });
    }
  });
  const targets = new Map<string, number>();
  (build.stat_targets ?? []).forEach((target, i) => {
    const at = `stat_targets[${i}]`;
    if (target.off === true) {
      // A stat switched off: nothing of it to check.
    } else if (target.tiers) {
      // The three tiers set by hand: each a number, nought or more, none above the next.
      const tiers = target.tiers;
      for (const tier of ['basic', 'intermediate', 'advanced'] as const) {
        const value = tiers[tier];
        if (value === null) {
          found.push({ uid, path: `${at}.tiers.${tier}`, message: 'Give all three tiers a number.' });
        } else if (!Number.isFinite(value) || value < 0 || value > limits.bound) {
          found.push({ uid, path: `${at}.tiers.${tier}`, message: `A number from 0 to ${limits.bound}.` });
        }
      }
      if (tiers.basic !== null && tiers.intermediate !== null && tiers.basic > tiers.intermediate) {
        found.push({ uid, path: `${at}.tiers.basic`, message: 'No higher than the intermediate tier.' });
      }
      if (tiers.intermediate !== null && tiers.advanced !== null && tiers.intermediate > tiers.advanced) {
        found.push({ uid, path: `${at}.tiers.intermediate`, message: 'No higher than the advanced tier.' });
      }
    } else {
      if (changed.has('stat_targets') && target.min === null && target.max === null) {
        found.push({ uid, path: `${at}.min`, message: 'Give it a minimum, a maximum or both.' });
      }
      for (const bound of ['min', 'max'] as const) {
        if (!withinBound(target[bound], limits.bound)) {
          found.push({ uid, path: `${at}.${bound}`, message: `A number from -${limits.bound} to ${limits.bound}.` });
        }
      }
      if (target.min !== null && target.max !== null && target.min > target.max) {
        found.push({ uid, path: `${at}.min`, message: 'No higher than the maximum.' });
      }
    }
    const key = `${target.stat}\n${target.from_rank}`;
    const other = targets.get(key);
    if (other !== undefined) {
      found.push({ uid, path: `${at}.stat`, message: 'Another target is for this stat from the same constellation: one target a stat from each constellation.' });
    } else {
      targets.set(key, i);
    }
  });
  found.push(...teamProblems(uid, build.teams, facts));
  return found;
}

/**
 * The places of a build that have a field on the page - each field carries its place as `data-path`, the path a save's
 * problem names (the server's: "qualifier", "notes.build.en", "weapons[0].refinement", "main_stats.sands[1].priority"),
 * in the order the page shows them. A problem at a place with no field of its own is shown at the first field inside
 * it, or the nearest around it, or above the build's cards (fieldFor()).
 */
export function fieldsOf(build: EditorBuild, own: string | null = null): string[] {
  const fields = ['role', 'qualifier', 'min_rank', 'game_version', 'notes.build.en', 'notes.build.sk'];
  build.weapons.forEach((_, i) => fields.push(`weapons[${i}].key`, `weapons[${i}].refinement`));
  build.sets.forEach((option, o) => {
    option.pieces.forEach((piece, p) => {
      // Its picker first: a problem of the piece's is shown there (its count has no words of its own).
      fields.push(`sets[${o}].pieces[${p}].keys`, `sets[${o}].pieces[${p}].count`);
      if (piece.keys.length > 1) {
        fields.push(`sets[${o}].pieces[${p}].label`);
      }
    });
    if (option.pieces.length > 1) {
      fields.push(`sets[${o}].choose`);
    }
  });
  for (const [slot, stats] of Object.entries(build.main_stats)) {
    stats.forEach((_, i) => fields.push(`main_stats.${slot}[${i}].stat`, `main_stats.${slot}[${i}].priority`));
  }
  build.substats.forEach((_, i) => fields.push(`substats[${i}].stat`, `substats[${i}].priority`));
  (build.talent_priority ?? []).forEach((_, i) => fields.push(`talent_priority[${i}].talent`, `talent_priority[${i}].priority`));
  (build.stat_targets ?? []).forEach((target, i) =>
    fields.push(
      `stat_targets[${i}].stat`,
      // A stat switched off has no field but its stat's place (the form says a problem there); the others theirs.
      ...(target.off === true
        ? []
        : [
            `stat_targets[${i}].from_rank`,
            ...(target.tiers ? [`stat_targets[${i}].tiers.basic`, `stat_targets[${i}].tiers.intermediate`, `stat_targets[${i}].tiers.advanced`] : [`stat_targets[${i}].min`, `stat_targets[${i}].max`]),
          ]),
    ),
  );
  build.teams.forEach((team, t) => {
    fields.push(`teams[${t}].name`);
    team.members.forEach((member, m) => {
      const at = `teams[${t}].members[${m}]`;
      if (member.key !== own) {
        fields.push(`${at}.key`);
      }
      fields.push(`${at}.role`, `${at}.min_rank`);
      if (member.key !== own) {
        fields.push(`${at}.alternatives`);
      }
    });
    // What is wrong with its members as a whole, under them.
    fields.push(`teams[${t}].members`, `notes.teams[${t}].en`, `notes.teams[${t}].sk`);
  });
  return fields;
}

/** The places of a build's notes waiting for a team: after its teams. */
export function waitingFields(waiting: readonly WaitingNote[]): string[] {
  return waiting.map((_, w) => `notes.waiting[${w}]`);
}

/** A build that holds nothing a member could use: no weapon, artifact set, main stat or substat. */
export function holdsNothing(build: EditorBuild): boolean {
  return build.weapons.length === 0 && build.sets.length === 0 && build.substats.length === 0 && Object.values(build.main_stats).every((stats) => stats.length === 0);
}

/**
 * What a save of the page's state would be refused for, as far as the page can tell: two builds of one name (said at the
 * one whose name can change), a build of the editor's taking a removed build's name, a build changed on the page that
 * holds nothing (the server would take it, and members would see it empty - and the analysis judge characters by it;
 * one a file brought that way is the file's to fill), what a part's form leaves wrong for a moment (partProblems(): the
 * page's own rules on the parts changed on the page, changedParts()), a text over its cap, a note with Slovak but no
 * English, more builds than a character can have. The server checks it all again, and more. (A text's length is counted
 * as the browser counts it, a little more than the server for a few characters: on the safe side.)
 */
/**
 * The parts of a build changed on the page: those that differ from what was loaded (`was`; every part of a build new
 * on the page) and from what the file says of it - a part as the file has it, a build brought back or "Use the
 * file's", is as a file brought it.
 */
export function changedParts(build: DraftBuild, was: DraftBuild | undefined): ReadonlySet<BuildPart> {
  return new Set(
    BUILD_PARTS.filter(
      (part) => (was === undefined || partText(build.build, part) !== partText(was.build, part)) && (build.file === null || partText(build.build, part) !== partText(build.file, part)),
    ),
  );
}

export function problems(draft: Draft, facts: EditorFacts, loaded: Draft | null = null): Problem[] {
  const limits = facts.limits;
  const before = new Map((loaded?.builds ?? []).map((build) => [build.uid, build]));
  const found: Problem[] = [];
  const seen = new Map<string, number>();
  const removed = removedNames(draft);
  draft.builds.forEach((build, i) => {
    const uid = build.uid;
    if (build.build.qualifier.trim().length > limits.qualifier) {
      found.push({ uid, path: 'qualifier', message: `At most ${limits.qualifier} characters.` });
    }
    if ((build.build.game_version ?? '').trim().length > limits.game_version) {
      found.push({ uid, path: 'game_version', message: `At most ${limits.game_version} characters.` });
    }
    const identity = identityOf(build.build);
    const other = seen.get(identity);
    if (other !== undefined) {
      // Said at the build whose name can change: a build of the file's keeps its own.
      const [at, twin] = nameFixed(build) && !nameFixed(draft.builds[other]) ? [draft.builds[other].uid, i] : [uid, other];
      found.push({ uid: at, path: 'qualifier', message: `Build ${twin + 1} has this role, qualifier and constellation too: tell the two apart by their qualifier.` });
    } else {
      seen.set(identity, i);
    }
    if (!nameFixed(build) && removed.has(identity)) {
      found.push({
        uid,
        path: 'qualifier',
        message: "A removed build of the file's has this role, qualifier and constellation: bring that one back instead (Add build, then Bring back).",
      });
    }
    const changed = changedParts(build, before.get(uid));
    if (changed.size > 0 && holdsNothing(build.build)) {
      found.push({
        uid,
        path: '',
        message: 'This build holds nothing yet - no weapon, artifact set, main stat or substat - and members would see it empty: fill it in before saving, or delete it.',
      });
    }
    found.push(...partProblems(uid, build.build, facts, changed));
    found.push(...noteProblems(uid, build.notes.build, 'notes.build', limits.note_build));
    build.notes.teams.forEach((note, t) => found.push(...noteProblems(uid, note, `notes.teams[${t}]`, limits.note_team)));
    // A note waiting for a team of the page's that has a note of its own: a save keeps one of the two, never both. And
    // one with no English (a save keeps none so).
    build.notes.waiting.forEach((entry, w) => {
      if (entry.note.en.trim() === '') {
        found.push({ uid, path: `notes.waiting[${w}]`, message: 'This note has no English: move it to a team and write its English there, or delete it.' });
      }
      const t = build.build.teams.findIndex((team) => teamKey(team) === entry.key);
      if (t >= 0 && noteOf(build.notes.teams[t] ?? noteDraft(null)) !== null) {
        found.push({ uid, path: `notes.waiting[${w}]`, message: `Team ${t + 1} has these members and a note of its own: keep one of the two - delete this one, or empty that team's.` });
      }
    });
  });
  if (draft.builds.length > limits.builds) {
    found.push({ uid: '', path: '', message: `A character has at most ${limits.builds} builds: delete one before saving.` });
  }
  return found;
}

/**
 * The field of the page a problem at `path` belongs to: the very one, else the first field inside it, else the nearest
 * field around it - "weapons[0].refinement" to "weapons[0]" where the refinement has no field of its own -, else none.
 */
export function fieldFor(fields: readonly string[], path: string): string | null {
  if (path === '') {
    return null;
  }
  if (fields.includes(path)) {
    return path;
  }
  const inside = fields.find((field) => field.startsWith(`${path}.`) || field.startsWith(`${path}[`));
  if (inside) {
    return inside;
  }
  let around: string | null = null;
  for (const field of fields) {
    if ((path.startsWith(`${field}.`) || path.startsWith(`${field}[`)) && (around === null || field.length > around.length)) {
      around = field;
    }
  }
  return around;
}
