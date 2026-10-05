import { BuildImportResult, SourceStatus } from '../../../core/models';
import { downloadName } from '../../../core/download';
import { BUILD_FILE_FORMAT, BuildFileCheck, checkBuildFile, importQuestion, importSummary } from './build-file';

const source = { key: 'genshin-2', name: 'Source 2', game: 'genshin', builds: 240 } as const;

/** The text of a builds file of Source 2, with $fields replaced. */
function file(fields: Record<string, unknown> = {}): string {
  return JSON.stringify({
    format: BUILD_FILE_FORMAT,
    format_version: 1,
    source: 'genshin-2',
    game: 'genshin',
    exported_at: '2026-09-29T12:03:12.123456Z',
    synced_at: null,
    builds: [
      { character: 'hutao', variant: 'DPS' },
      { character: 'hutao', variant: 'Support' },
      { character: 'furina', variant: 'Main' },
    ],
    ...fields,
  });
}

describe('checkBuildFile (is a chosen file a builds file of this source?)', () => {
  it('takes a builds file of the source, counting its builds and characters', () => {
    expect(checkBuildFile(file(), source)).toEqual({ ok: true, builds: 3, characters: 2, exportedAt: '2026-09-29T12:03:12.123456Z' });
  });

  it('says plainly what else a file is', () => {
    const cases: [string, string][] = [
      ['{"format": ', 'This is not a builds file: it is not JSON.'],
      ['[1, 2]', 'This is not a builds file exported by the build analyzer.'],
      [file({ format: 'GOOD' }), 'This is not a builds file exported by the build analyzer.'],
      [file({ format_version: 2 }), 'This builds file has format version 2, and this installation reads version 1. Update this installation, then import again.'],
      [file({ format_version: '1' }), 'This builds file has no format version this installation reads.'],
      [file({ game: 'hsr', source: 'hsr-1' }), 'This file holds Honkai: Star Rail builds, and Source 2 is a Genshin Impact source.'],
      [file({ game: 'toString' }), 'This builds file names no game this installation knows.'],
      [file({ source: 'genshin-1' }), 'This file holds the builds of genshin-1, not of Source 2 (genshin-2).'],
      [file({ builds: { hutao: [] } }), 'This builds file holds no list of builds.'],
    ];
    for (const [text, message] of cases) {
      expect(checkBuildFile(text, source)).toEqual({ ok: false, message });
    }
  });

  it('never repeats other text of the file, which could name a site', () => {
    expect(checkBuildFile(file({ source: 'www.a-build-site.example' }), source)).toEqual({
      ok: false,
      message: 'This file holds the builds of another source, not of Source 2 (genshin-2).',
    });
  });

  it('leaves out an export time it cannot read', () => {
    expect(checkBuildFile(file({ exported_at: 'yesterday' }), source)).toMatchObject({ ok: true, exportedAt: null });
  });
});

describe('the words around an import', () => {
  const check: BuildFileCheck = { ok: true, builds: 250, characters: 110, exportedAt: null };

  it('asks before replacing the builds, saying what comes instead and when it was exported', () => {
    expect(importQuestion(source, check)).toBe("Replace the 240 builds of Source 2 (Genshin Impact) with the file's 250 builds of 110 characters?");
    expect(importQuestion({ ...source, builds: 0 }, { ...check, builds: 1, characters: 1 })).toBe(
      "Import into Source 2 (Genshin Impact) the file's 1 build of 1 character?",
    );
    // In the browser's time zone; around noon UTC it is the 29th nearly everywhere.
    expect(importQuestion(source, { ...check, exportedAt: '2026-09-29T12:03:12.123456Z' })).toMatch(
      /^Replace the 240 builds of Source 2 \(Genshin Impact\) with the file's 250 builds of 110 characters, exported 29 September 2026(,| at) \d\d:\d\d\?$/,
    );
  });

  it('sums up what an import did, naming the characters the catalog lacks', () => {
    const result: BuildImportResult = { created: 2, updated: 1, unchanged: 240, removed: 3, characters: 108, skipped: [], source: {} as SourceStatus };
    expect(importSummary(source, result)).toBe('Imported into Source 2: 2 new, 1 updated, 240 unchanged, 3 removed.');

    const skipped = ['Aino', 'Bella', 'Cyno', 'Dahlia', 'Emilie', 'Flins', 'Gaming'].map((name) => ({ key: name.toLowerCase(), name }));
    expect(importSummary(source, { ...result, skipped })).toBe(
      'Imported into Source 2: 2 new, 1 updated, 240 unchanged, 3 removed. Skipped 7 characters the catalog lacks ' +
        '(Aino, Bella, Cyno, Dahlia, Emilie and 2 more): refresh the catalog, then import again.',
    );
  });

  it('saves an export under the name the server gave it', () => {
    expect(downloadName('attachment; filename="builds-genshin-2-2026-09-29.json"', 'fallback.json')).toBe('builds-genshin-2-2026-09-29.json');
    expect(downloadName(null, 'fallback.json')).toBe('fallback.json');
  });
});
