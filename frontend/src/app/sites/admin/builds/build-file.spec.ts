import { downloadName } from '../../../shared/download';
import { BuildChange, BuildImportEntry, BuildImportPlan, BuildQuestion } from '../../../api/models';
import {
  BUILD_FILE_FORMAT,
  BUILD_FILE_MAX_BYTES,
  BUILD_FILE_TOO_LARGE,
  changeCount,
  changeName,
  checkBuildFile,
  choiceOptions,
  count,
  handSummary,
  importLine,
  importSummary,
  mergedText,
  momentText,
  noChangesText,
  partList,
  planSummary,
  questionText,
  roleName,
  skippedHeading,
  unknownTeammates,
  variationName,
} from './build-file';

/** The text of a builds file of Genshin Impact, with $fields replaced. */
function file(fields: Record<string, unknown> = {}): string {
  return JSON.stringify({
    format: BUILD_FILE_FORMAT,
    format_version: 3,
    game: 'genshin',
    exported_at: '2026-10-20T10:00:00Z',
    builds: [
      { character: 'hutao', character_name: 'Hu Tao', role: 'main_dps', qualifier: '', min_rank: 0 },
      { character: 'hutao', character_name: 'Hu Tao', role: 'main_dps', qualifier: 'Melt', min_rank: 2 },
      { character: 'furina', character_name: 'Furina', role: 'support', qualifier: '', min_rank: 0 },
    ],
    ...fields,
  });
}

function plan(overrides: Partial<BuildImportPlan> = {}): BuildImportPlan {
  return {
    dry_run: true,
    game: 'genshin',
    exported_at: '2026-10-20T10:00:00+00:00',
    older_than_current: false,
    characters: 110,
    builds: 250,
    teams: 0,
    created: 2,
    updated: 1,
    unchanged: 247,
    removed: 3,
    skipped: [],
    teammates_unknown: [],
    teammates_unknown_total: 0,
    edits_kept: 0,
    made_here: 0,
    kept_removed: 0,
    file_taken: 0,
    questions: [],
    plan: 'a'.repeat(64),
    merged: [],
    order_kept: [],
    notes_dormant: [],
    changes: [],
    changes_listed: 6,
    ...overrides,
  };
}

/** A question of an import about Hu Tao's main DPS build. */
function question(overrides: Partial<BuildQuestion> = {}): BuildQuestion {
  return { id: 'b1', kind: 'edited', character: { key: 'hutao', name: 'Hu Tao' }, role: 'main_dps', qualifier: '', min_rank: 0, parts: ['weapons'], taken: [], notes: false, ...overrides };
}

/** In the browser's time zone; around noon UTC it is the 20th nearly everywhere. */
const NOON = '2026-10-20T12:03:00+00:00';
const AT_NOON = /^20 October 2026(,| at) \d\d:\d\d$/;

describe('checkBuildFile (is a chosen file a builds file of this game this installation reads?)', () => {
  it('takes a builds file of the game', () => {
    expect(checkBuildFile(file(), 'genshin')).toEqual({ ok: true });
    // The server checks every build; an empty list is a file too (it would remove every build).
    expect(checkBuildFile(file({ builds: [] }), 'genshin')).toEqual({ ok: true });
  });

  it('says plainly what else a file is', () => {
    const cases: [string, string][] = [
      ['{"format": ', 'This is not a builds file: it is not JSON.'],
      ['', 'This is not a builds file: it is not JSON.'],
      ['[1, 2]', 'This is not a builds file exported by the build analyzer.'],
      [file({ format: 'build-analyzer.accounts' }), 'This is not a builds file exported by the build analyzer.'],
      [file({ format_version: 4 }), 'This builds file has format version 4, and this installation reads version 3. Update this installation, then import again.'],
      [
        file({ format_version: 2 }),
        'This builds file has format version 2, from before builds had their teams. This installation reads version 3: make the file again, or export it again from this page.',
      ],
      [file({ format_version: '2' }), 'This builds file has no format version this installation reads.'],
      [file({ format_version: 2.5 }), 'This builds file has no format version this installation reads.'],
      [file({ format_version: null }), 'This builds file has no format version this installation reads.'],
      [file({ game: 'toString' }), 'This builds file names no game this installation knows.'],
      [file({ game: null }), 'This builds file names no game this installation knows.'],
      [file({ builds: { hutao: [] } }), 'This builds file holds no list of builds.'],
    ];
    for (const [text, message] of cases) {
      expect(checkBuildFile(text, 'genshin'), text).toEqual({ ok: false, message });
    }
  });

  it('says a file of version 1 is from before builds had variations', () => {
    expect(checkBuildFile(file({ format_version: 1, source: 'genshin-2' }), 'genshin')).toEqual({
      ok: false,
      message: "This builds file has format version 1, from before builds had variations. This installation reads version 3: a game's builds, each a variation of its character's.",
    });
  });

  it('never repeats other text of the file, which could name a site', () => {
    const check = checkBuildFile(file({ game: 'www.a-build-site.example' }), 'genshin');
    expect(check).toEqual({ ok: false, message: 'This builds file names no game this installation knows.' });
  });

  it('refuses a file larger than the server takes, saying how large that is', () => {
    expect(BUILD_FILE_MAX_BYTES).toBe(8 * 1024 * 1024);
    expect(BUILD_FILE_TOO_LARGE).toBe("This file is larger than 8 MB: more than the server accepts, and far more than a game's builds take. Is it the right file?");
  });
});

describe("a build's variation, in the admin panel's English", () => {
  it('names the roles - one a newer server added as it is', () => {
    expect(['main_dps', 'sub_dps', 'support', 'healer'].map(roleName)).toEqual(['Main DPS', 'Sub DPS', 'Support', 'healer']);
  });

  it('says the role, the qualifier and the constellation it needs - in the prefix the game gives', () => {
    expect(variationName({ role: 'main_dps', qualifier: '', min_rank: 0 }, 'C')).toBe('Main DPS');
    expect(variationName({ role: 'main_dps', qualifier: 'Melt', min_rank: 0 }, 'C')).toBe('Main DPS · Melt');
    expect(variationName({ role: 'support', qualifier: '', min_rank: 4 }, 'C')).toBe('Support · C4+');
    expect(variationName({ role: 'sub_dps', qualifier: 'Hyperbloom', min_rank: 6 }, 'E')).toBe('Sub DPS · Hyperbloom · E6+');
  });

  it('names a change by its character and variation, and counts the changes', () => {
    const change: BuildChange = { change: 'updated', character: { key: 'hutao', name: 'Hu Tao' }, role: 'main_dps', qualifier: 'Melt', min_rank: 2 };
    expect(changeName(change, 'C')).toBe('Hu Tao · Main DPS · Melt · C2+');
    expect(changeCount(plan())).toBe(6);
    expect(changeCount(plan({ created: 0, updated: 0, removed: 0 }))).toBe(0);
  });
});

describe('the words around an import', () => {
  it("says what importing a file does: the file's builds and when it was made, then what happens to them", () => {
    expect(planSummary(plan({ exported_at: NOON }))).toMatch(/^The file's 250 builds of 110 characters, made 20 October 2026(,| at) \d\d:\d\d: 2 new, 1 updated, 247 unchanged, 3 removed\.$/);
    // With characters skipped, the builds counted are the ones of the characters this installation knows.
    const skipped = plan({ exported_at: NOON, characters: 1, builds: 1, skipped: [{ key: 'aino', name: 'Aino', builds: 2 }] });
    expect(planSummary(skipped)).toMatch(/^The file's 1 build of 1 character this installation knows, made 20 October 2026/);
    expect(skippedHeading(skipped)).toBe('1 character skipped');
    expect(skippedHeading(plan({ skipped: [skipped.skipped[0], { key: 'flins', name: 'Flins', builds: 1 }] }))).toBe('2 characters skipped');
  });

  it("says the file's teams, and names the teammates the catalog lacks - how many more past those listed", () => {
    expect(planSummary(plan({ exported_at: NOON, teams: 809 }))).toMatch(/^The file's 250 builds of 110 characters, with 809 teams, made 20 October 2026/);
    expect(planSummary(plan({ exported_at: NOON, teams: 1 }))).toMatch(/, with 1 team, made /);
    expect(planSummary(plan({ exported_at: NOON }))).not.toContain('team');

    expect(unknownTeammates(plan())).toBeNull();
    const two = [
      { key: 'xingqiu', name: 'Xingqiu' },
      { key: 'yelan', name: 'Yelan' },
    ];
    expect(unknownTeammates(plan({ teammates_unknown: two, teammates_unknown_total: 2 }))).toBe(
      "Teammates not in this installation's catalog, shown by their names alone until a refresh brings them: Xingqiu, Yelan.",
    );
    expect(unknownTeammates(plan({ teammates_unknown: two, teammates_unknown_total: 14 }))).toMatch(/: Xingqiu, Yelan and 12 more\.$/);
  });

  it("writes a moment in the account's date order and clock, where the person has chosen them", () => {
    const at = new Date(NOON);
    const pad = (value: number) => String(value).padStart(2, '0');
    const day = `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`;
    const clock = `${pad(at.getHours())}:${pad(at.getMinutes())}`;

    expect(momentText(NOON)).toMatch(AT_NOON);
    expect(momentText(NOON, { date_format: 'ymd_dash', time_format: '24' })).toBe(`${day} ${clock}`);
    // Only the clock chosen: the page's own day beside it.
    expect(momentText(NOON, { date_format: null, time_format: '12' })).toMatch(/^20 October 2026 \d{1,2}:\d\d\s?[ap]m$/i);
    // Nothing chosen: the page's own way.
    expect(momentText(NOON, { date_format: null, time_format: null })).toBe(momentText(NOON));
    // A time with microseconds reads as one with milliseconds.
    expect(momentText('2026-10-20T12:03:00.123456+00:00')).toBe(momentText(NOON));
  });

  it('sums up what an import did, naming the characters the catalog lacks', () => {
    expect(importSummary(plan({ dry_run: false }))).toBe('Imported: 2 new, 1 updated, 247 unchanged, 3 removed.');

    const skipped = ['Aino', 'Bella', 'Cyno', 'Dahlia', 'Emilie', 'Flins', 'Gaming'].map((name) => ({ key: name.toLowerCase(), name, builds: 1 }));
    expect(importSummary(plan({ dry_run: false, skipped }))).toBe(
      'Imported: 2 new, 1 updated, 247 unchanged, 3 removed. Skipped 7 characters the catalog lacks ' + '(Aino, Bella, Cyno, Dahlia, Emilie and 2 more): refresh the catalog, then import again.',
    );
    expect(importSummary(plan({ dry_run: false, skipped: skipped.slice(0, 1) }))).toBe(
      'Imported: 2 new, 1 updated, 247 unchanged, 3 removed. Skipped 1 character the catalog lacks (Aino): refresh the catalog, then import again.',
    );
  });

  it("says each recent import's file, what it did and who imported it - a deleted person's as such", () => {
    const entry: BuildImportEntry = {
      id: 3,
      created_at: '2026-10-20T13:00:00+00:00',
      file_exported_at: NOON,
      summary: { characters: 110, builds: 250, created: 3, updated: 1, unchanged: 246, removed: 0, skipped: 2 },
      imported_by: { id: 4, name: 'Mona' },
    };
    expect(importLine(entry)).toMatch(/^file made 20 October 2026(,| at) \d\d:\d\d · 3 new, 1 updated, 0 removed, 2 skipped · by Mona$/);
    expect(importLine({ ...entry, file_exported_at: null, imported_by: null })).toBe('file of an unknown date · 3 new, 1 updated, 0 removed, 2 skipped · by a deleted person');
    // Since 0.7 what the editors' work came to: said only where an edit was kept.
    expect(importLine({ ...entry, file_exported_at: null, summary: { ...entry.summary, edits_kept: 4 } })).toBe(
      'file of an unknown date · 3 new, 1 updated, 0 removed, 2 skipped, 4 kept an edit · by Mona',
    );
    expect(importLine({ ...entry, file_exported_at: null, summary: { ...entry.summary, edits_kept: 0 } })).toBe('file of an unknown date · 3 new, 1 updated, 0 removed, 2 skipped · by Mona');
  });

  it("names a build's parts in a sentence", () => {
    expect([partList([]), partList(['weapons']), partList(['weapons', 'teams']), partList(['sets', 'main_stats', 'talent_priority', 'game_version'])]).toEqual([
      '',
      'weapons',
      'weapons and teams',
      'artifact sets, main stats, talents and game version',
    ]);
  });

  it("says what each kind of question is about, and offers its two answers - the editors' work first", () => {
    expect(questionText(question())).toBe('You edited its weapons, and the file changes them too.');
    expect(questionText(question({ parts: ['weapons', 'teams'], taken: ['substats'] }))).toBe(
      "You edited its weapons and teams, and the file changes them too. Its substats, which only the file changes, are the file's either way.",
    );
    expect(questionText(question({ parts: ['game_version'], taken: ['weapons'] }))).toBe(
      "You edited its game version, and the file changes it too. Its weapons, which only the file changes, are the file's either way.",
    );
    expect(questionText(question({ parts: ['teams'], taken: ['game_version'] }))).toBe(
      "You edited its teams, and the file changes them too. Its game version, which only the file changes, is the file's either way.",
    );
    expect(questionText(question({ kind: 'made_here', parts: ['stat_targets'] }))).toBe('Made here; the file brings it too, with other stat targets.');
    expect(questionText(question({ kind: 'removed_here', parts: ['game_version'] }))).toBe('You removed it; the file changes its game version.');
    expect(questionText(question({ kind: 'removed_by_file', parts: ['substats'] }))).toBe('The file no longer has it; you edited its substats.');
    expect(questionText(question({ kind: 'removed_by_file', parts: [], notes: true }))).toBe('The file no longer has it; you wrote notes on it.');
    expect(questionText(question({ kind: 'removed_by_file', parts: ['teams'], notes: true }))).toBe('The file no longer has it; you edited its teams and wrote notes on it.');

    expect((['edited', 'made_here', 'removed_here', 'removed_by_file'] as const).map((kind) => choiceOptions(question({ kind })).map((option) => `${option.value}: ${option.label}`))).toEqual([
      ['edit: Keep your edit', "file: Take the file's"],
      ['edit: Keep yours', "file: Take the file's"],
      ['edit: Keep it removed', 'file: Bring it back'],
      ['edit: Keep it', 'file: Remove it'],
    ]);
  });

  it('says a file that changes nothing changes nothing - unless the file is taken where it asks', () => {
    expect(noChangesText(plan())).toBe("Nothing changes: the builds here are the file's already.");
    expect(noChangesText(plan({ made_here: 1 }))).toBe("Nothing changes: the builds here are the file's already, but for what is kept by hand.");
    expect(noChangesText(plan({ edits_kept: 1, questions: [question()] }))).toBe("Nothing changes unless you take the file's above.");
  });

  it("says what an import keeps by hand and makes of an edit and the file's changes without asking", () => {
    expect(handSummary(plan())).toBeNull();
    expect(handSummary(plan({ edits_kept: 1 }))).toBe('Kept by hand: 1 build with an edit.');
    expect(handSummary(plan({ edits_kept: 3, made_here: 2, kept_removed: 1 }))).toBe('Kept by hand: 3 builds with an edit, 2 made here, 1 removed.');
    expect(handSummary(plan({ made_here: 2 }))).toBe('Kept by hand: 2 made here.');
    expect(importSummary(plan({ dry_run: false, kept_removed: 1 }))).toBe('Imported: 2 new, 1 updated, 247 unchanged, 3 removed. Kept by hand: 1 removed.');
    expect(mergedText({ character: { key: 'hutao', name: 'Hu Tao' }, role: 'main_dps', qualifier: '', min_rank: 0, kept: ['weapons', 'teams'], taken: ['substats'] })).toBe(
      "your weapons and teams kept, the file's substats taken",
    );
  });

  it('counts in words', () => {
    expect([count(0, 'build'), count(1, 'build'), count(2, 'build')]).toEqual(['0 builds', '1 build', '2 builds']);
  });

  it('saves an export under the name the server gave it', () => {
    expect(downloadName('attachment; filename="builds-genshin-2026-10-20.json"', 'fallback.json')).toBe('builds-genshin-2026-10-20.json');
    expect(downloadName(null, 'fallback.json')).toBe('fallback.json');
  });
});
