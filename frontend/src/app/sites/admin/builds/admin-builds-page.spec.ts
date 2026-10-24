import { HttpErrorResponse, HttpHeaders, HttpResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ToastService } from '@anotoki/lib/ui/toast';
import { Observable, Subject, of, throwError } from 'rxjs';
import { Api } from '../../../api/api';
import { AuthService } from '../../../shared/auth';
import { timeAgo } from '../../../shared/format';
import { provideKit } from '../../../shared/kit';
import { MetaStore } from '../../../shared/meta-store';
import { AdminBuildsGame, BuildImportChoices, BuildImportPlan, BuildQuestion, CatalogList, CatalogRefreshResult, EditorCatalog, GameKey } from '../../../api/models';
import { AdminBuildsPage, CATALOG_LISTS } from './admin-builds-page';
import { BUILD_FILE_MAX_BYTES, BUILD_FILE_TOO_LARGE, momentText } from './build-file';

/** Lets what a click or a file began finish, and the page be drawn. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const FILE_NAME = 'builds-genshin-2026-10-20.json';
const FILE_TEXT = JSON.stringify({ format: 'build-analyzer.builds', format_version: 3, game: 'genshin', exported_at: '2026-10-20T10:00:00Z', builds: [] });

/** The plan of a dry run's questions, which the import sends back with the answers. */
const PLAN = 'a'.repeat(64);

const CHECKED = '2026-10-19T10:00:00+00:00';
const CHANGED = '2026-10-18T10:00:00+00:00';
const IMPORTED = '2026-10-19T10:30:00+00:00';
const REFRESHED = '2026-10-19T08:00:00+00:00';

/** What GET /api/admin/builds says of Genshin Impact. */
function game(overrides: Partial<AdminBuildsGame> = {}): AdminBuildsGame {
  return {
    game: 'genshin',
    characters: 110,
    builds: 250,
    checked_at: CHECKED,
    changed_at: CHANGED,
    imports: [
      {
        id: 2,
        created_at: IMPORTED,
        file_exported_at: CHECKED,
        summary: { characters: 110, builds: 250, created: 2, updated: 1, unchanged: 247, removed: 3, skipped: 2 },
        imported_by: { id: 4, name: 'Mona' },
      },
      {
        id: 1,
        created_at: '2026-10-01T09:00:00+00:00',
        file_exported_at: null,
        summary: { characters: 100, builds: 240, created: 240, updated: 0, unchanged: 0, removed: 0, skipped: 0 },
        imported_by: null,
      },
    ],
    catalog: [
      { list: 'characters', entries: 120, refreshed_at: REFRESHED, problem: null },
      { list: 'weapons', entries: 230, refreshed_at: null, problem: null },
      { list: 'sets', entries: 60, refreshed_at: null, problem: null },
    ],
    editing: [],
    ...overrides,
  };
}

/** What the builds editor picks from (GET /api/admin/builds/genshin/catalog): three characters here, by name. */
const CATALOG: EditorCatalog = {
  game: 'genshin',
  facts: {
    roles: ['main_dps', 'sub_dps', 'support'],
    max_rank: 6,
    rank_prefix: 'C',
    refinement_prefix: 'R',
    slots: { sands: ['atk_'] },
    substats: ['critRate_'],
    stats: ['critRate_'],
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
  },
  characters: [
    { key: 'furina', name: 'Furina', element: 'hydro', weapon_type: 'sword', rarity: 5, icon_url: '/icons/furina.png' },
    { key: 'hutao', name: 'Hu Tao', element: 'pyro', weapon_type: 'polearm', rarity: 5, icon_url: '/icons/hutao.png' },
    { key: 'xiangling', name: 'Xiangling', element: 'pyro', weapon_type: 'polearm', rarity: 4, icon_url: null },
  ],
  weapons: [],
  sets: [],
};

/** What importing the file does (a dry run) or did. */
function plan(dryRun: boolean, overrides: Partial<BuildImportPlan> = {}): BuildImportPlan {
  return {
    dry_run: dryRun,
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
    plan: PLAN,
    merged: [],
    order_kept: [],
    notes_dormant: [],
    changes: [
      { change: 'created', character: { key: 'hutao', name: 'Hu Tao' }, role: 'main_dps', qualifier: 'Melt', min_rank: 2 },
      { change: 'updated', character: { key: 'furina', name: 'Furina' }, role: 'support', qualifier: '', min_rank: 0 },
      { change: 'removed', character: { key: 'xiangling', name: 'Xiangling' }, role: 'sub_dps', qualifier: '', min_rank: 6 },
    ],
    changes_listed: 3,
    ...overrides,
  };
}

function refused(status: number, message: string | null, asBlob = false): HttpErrorResponse {
  const body = message === null ? null : { message };
  return new HttpErrorResponse({ status, error: asBlob && body ? new Blob([JSON.stringify(body)], { type: 'application/json' }) : body });
}

describe('the Builds page (admin panel): the builds files and the catalog', () => {
  let fixture: ComponentFixture<AdminBuildsPage>;
  let host: HTMLElement;
  let api: {
    adminBuilds: ReturnType<typeof vi.fn<() => Observable<AdminBuildsGame[]>>>;
    exportBuilds: ReturnType<typeof vi.fn<(game: GameKey) => Observable<HttpResponse<Blob>>>>;
    importBuilds: ReturnType<typeof vi.fn<(game: GameKey, file: string, dryRun: boolean, choices?: BuildImportChoices | null) => Observable<BuildImportPlan>>>;
    refreshCatalog: ReturnType<typeof vi.fn<(game: GameKey, list: CatalogList) => Observable<CatalogRefreshResult>>>;
    editorCatalog: ReturnType<typeof vi.fn<(game: GameKey) => Observable<EditorCatalog>>>;
  };
  let toasts: { show: ReturnType<typeof vi.fn>; success: ReturnType<typeof vi.fn>; error: ReturnType<typeof vi.fn> };

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const texts = (selector: string) => [...host.querySelectorAll(selector)].map(text);
  const button = (selector: string) => host.querySelector<HTMLButtonElement>(`button.${selector}`)!;
  const problem = () => text(host.querySelector('.problem .anotoki-alert__text'));

  async function setup(answer: AdminBuildsGame[] = [game()], meta?: { game: () => { rank_prefix: string } | null }, catalog: () => Observable<EditorCatalog> = () => of(CATALOG)): Promise<void> {
    api = {
      adminBuilds: vi.fn(() => of(answer)),
      exportBuilds: vi.fn(),
      importBuilds: vi.fn((_game: GameKey, _file: string, dryRun: boolean) => of(plan(dryRun))),
      refreshCatalog: vi.fn(),
      editorCatalog: vi.fn(catalog),
    };
    toasts = { show: vi.fn(), success: vi.fn(), error: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations({}),
        provideKit(),
        provideRouter([]),
        { provide: Api, useValue: api },
        { provide: ToastService, useValue: toasts },
        { provide: AuthService, useValue: { preferences: signal(null) } },
        ...(meta ? [{ provide: MetaStore, useValue: meta }] : []),
      ],
    });
    fixture = TestBed.createComponent(AdminBuildsPage);
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  /** Chooses a file in the game's hidden input. */
  async function choose(file: { name?: string; size?: number; text?: () => Promise<string> } = {}): Promise<void> {
    const input = host.querySelector<HTMLInputElement>('.builds input[type="file"]')!;
    const chosen = {
      name: file.name ?? FILE_NAME,
      size: file.size ?? FILE_TEXT.length,
      text: file.text ?? (() => Promise.resolve(FILE_TEXT)),
    };
    Object.defineProperty(input, 'files', { value: [chosen], configurable: true });
    input.dispatchEvent(new Event('change'));
    await settle();
    await fixture.whenStable();
  }

  async function press(selector: string): Promise<void> {
    button(selector).click();
    await settle();
    await fixture.whenStable();
  }

  afterEach(() => vi.restoreAllMocks());

  it('says what it does, and per game the counts, the recent imports and the catalog', async () => {
    await setup();

    expect(text(host.querySelector('h1'))).toBe('Builds');
    const lead = text(host.querySelector('.anotoki-page-header__lead'));
    expect(lead).toContain('imported here, one file per game');
    expect(lead).toContain("Once a file is imported, the game's builds are the file's");
    expect(lead).toContain('an import keeps every edit, and asks where the file changes the same part of a build');
    expect(lead).toContain("characters this installation's catalog lacks, which are skipped");
    expect(lead).toContain("Export saves a game's builds as such a file: a backup.");

    expect(texts('section.game h2')).toEqual(['Genshin Impact']);
    expect(texts('.anotoki-card__title')).toEqual(['Builds', 'Edit builds', 'Recent imports', 'Catalog']);
    expect(texts('.builds anotoki-stat-tile')).toEqual([
      '110 Characters with builds',
      '250 Builds',
      `${timeAgo(CHECKED)} Newest file made ${momentText(CHECKED)}`,
      `${timeAgo(CHANGED)} Last change ${momentText(CHANGED)}`,
    ]);
    expect([text(button('export')), text(button('import')), text(button('refresh-catalog')), text(button('reload'))]).toEqual(['Export', 'Import a file', 'Refresh catalog', 'Reload']);
    expect(host.querySelector<HTMLInputElement>('.builds input[type="file"]')?.accept).toBe('.json,application/json');

    expect(texts('.import-list li')).toEqual([
      `${timeAgo(IMPORTED)} · file made ${momentText(CHECKED)} · 2 new, 1 updated, 3 removed, 2 skipped · by Mona`,
      `${timeAgo('2026-10-01T09:00:00+00:00')} · file of an unknown date · 240 new, 0 updated, 0 removed, 0 skipped · by a deleted person`,
    ]);
    expect(host.querySelector('.import-list time')?.getAttribute('datetime')).toBe(IMPORTED);

    expect(texts('.lists anotoki-stat-tile')).toEqual([`120 Characters refreshed ${timeAgo(REFRESHED)}`, '230 Weapons never refreshed', '60 Sets never refreshed']);
    expect(host.querySelector('.catalog-progress')).toBeNull();
    expect(host.querySelector('.list-problems')).toBeNull();

    // Nothing of the syncs, the sources or database updates: while one waits the site gate shows its own page.
    expect(host.textContent).not.toMatch(/\bsync|source \d|database update|migration/i);
  });

  it('says so when there is no build and no import yet', async () => {
    await setup([game({ characters: 0, builds: 0, checked_at: null, changed_at: null, imports: [] })]);

    expect(texts('.builds anotoki-stat-tile')).toEqual(['0 Characters with builds', '0 Builds', 'never Newest file made', 'never Last change']);
    expect(host.querySelector('.import-list')).toBeNull();
    expect(text(host.querySelector('.no-imports'))).toBe('No import yet.');
  });

  it("says why the page's data could not be read, and reads it again on Reload", async () => {
    await setup();
    api.adminBuilds.mockReturnValueOnce(throwError(() => refused(403, 'This is for editors.')));
    await press('reload');
    expect(text(host.querySelector('.load-error .anotoki-alert__text'))).toBe('This is for editors.');

    await press('reload');
    expect(host.querySelector('.load-error')).toBeNull();
    expect(api.adminBuilds).toHaveBeenCalledTimes(3);
  });

  it('exports: saves the file under the name the server gives', async () => {
    await setup();
    const created = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:file');
    let saved = '';
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      saved = this.download;
    });
    api.exportBuilds.mockReturnValue(of(new HttpResponse({ body: new Blob(['{}']), headers: new HttpHeaders({ 'Content-Disposition': `attachment; filename="${FILE_NAME}"` }) })));

    await press('export');

    expect(api.exportBuilds).toHaveBeenCalledWith('genshin');
    expect(created).toHaveBeenCalledTimes(1);
    expect(saved).toBe(FILE_NAME);
    expect(host.querySelector('.problem')).toBeNull();
  });

  it('keeps Export and Import waiting while a file is on its way, and says why an export was refused - its answer a file too', async () => {
    await setup();
    const exported = new Subject<HttpResponse<Blob>>();
    api.exportBuilds.mockReturnValue(exported);

    await press('export');
    expect([button('export').disabled, button('import').disabled]).toEqual([true, true]);

    exported.error(refused(403, 'This is for editors.', true));
    await vi.waitFor(async () => {
      await fixture.whenStable();
      expect(problem()).toBe('This is for editors.');
    });
    expect([button('export').disabled, button('import').disabled]).toEqual([false, false]);
  });

  it('imports in two steps: the dry run is the preview - what changes, one by one -, Import does it, and the page reads its data again', async () => {
    await setup();
    const skipped = [
      { key: 'aino', name: 'Aino', builds: 2 },
      { key: 'flins', name: 'Flins', builds: 1 },
    ];
    api.importBuilds.mockImplementation((_game, _file, dryRun) => of(plan(dryRun, { older_than_current: true, skipped, created: 2, updated: 1, removed: 3 })));
    await choose();

    expect(api.importBuilds.mock.calls).toEqual([['genshin', FILE_TEXT, true]]);
    expect(text(host.querySelector('.preview h4'))).toBe(`What importing ${FILE_NAME} does`);
    expect(text(host.querySelector('.preview .summary'))).toBe(
      `The file's 250 builds of 110 characters this installation knows, made ${momentText('2026-10-20T10:00:00+00:00')}: 2 new, 1 updated, 247 unchanged, 3 removed.`,
    );
    expect(text(host.querySelector('.preview .older .anotoki-alert__text'))).toBe('This file is older than the one the builds now come from.');
    expect(text(host.querySelector('.preview .skipped .anotoki-alert__heading'))).toBe('2 characters skipped');
    expect(text(host.querySelector('.preview .skipped .anotoki-alert__text'))).toContain("Not in this installation's catalog - refresh the catalog, then import again.");
    expect(texts('.skipped-list li')).toEqual(['Aino · 2 builds', 'Flins · 1 build']);

    // The changes: in the kit's disclosure, made only once it is opened.
    expect(text(host.querySelector('.changes .anotoki-disclosure__button'))).toBe('Changes (6)');
    expect(host.querySelector('.change-list')).toBeNull();
    host.querySelector<HTMLButtonElement>('.changes .anotoki-disclosure__button')!.click();
    await fixture.whenStable();
    expect(texts('.change-list li')).toEqual(['Hu Tao · Main DPS · Melt · C2+ — new', 'Furina · Support — changed', 'Xiangling · Sub DPS · C6+ — removed']);
    expect(text(host.querySelector('.changes .more'))).toBe('3 more not listed.');
    expect(api.adminBuilds).toHaveBeenCalledTimes(1);

    await press('confirm');

    expect(api.importBuilds.mock.calls).toEqual([
      ['genshin', FILE_TEXT, true],
      ['genshin', FILE_TEXT, false, { file: [], plan: PLAN }],
    ]);
    expect(host.querySelector('.preview')).toBeNull();
    // Skipped characters ask for something to be done: the message stays until it is closed.
    expect(toasts.show).toHaveBeenCalledWith(
      'Imported: 2 new, 1 updated, 247 unchanged, 3 removed. Skipped 2 characters the catalog lacks (Aino, Flins): refresh the catalog, then import again.',
      'warning',
      0,
    );
    expect(api.adminBuilds).toHaveBeenCalledTimes(2);
  });

  it('says what an import did as a message that goes by itself when nothing was skipped - and lists every change when they are all listed', async () => {
    await setup();
    await choose();
    expect(host.querySelector('.preview .older')).toBeNull();
    expect(host.querySelector('.preview .skipped')).toBeNull();
    expect(text(host.querySelector('.preview .summary'))).toBe(`The file's 250 builds of 110 characters, made ${momentText('2026-10-20T10:00:00+00:00')}: 2 new, 1 updated, 247 unchanged, 3 removed.`);

    api.importBuilds.mockReturnValueOnce(of(plan(false, { created: 1, updated: 1, removed: 1 })));
    await press('confirm');
    expect(toasts.show).toHaveBeenCalledWith('Imported: 1 new, 1 updated, 247 unchanged, 1 removed.', 'success', 8000);

    // A file whose changes are all listed says nothing more.
    api.importBuilds.mockReturnValueOnce(of(plan(true, { created: 1, updated: 1, removed: 1 })));
    await choose();
    host.querySelector<HTMLButtonElement>('.changes .anotoki-disclosure__button')!.click();
    await fixture.whenStable();
    expect(texts('.change-list li')).toHaveLength(3);
    expect(host.querySelector('.changes .more')).toBeNull();
  });

  it('says when a file changes nothing, and still imports it (the builds then carry its date)', async () => {
    await setup();
    api.importBuilds.mockReturnValueOnce(of(plan(true, { created: 0, updated: 0, removed: 0, unchanged: 250, changes: [], changes_listed: 0 })));
    await choose();

    expect(host.querySelector('.changes')).toBeNull();
    expect(text(host.querySelector('.no-changes'))).toBe("Nothing changes: the builds here are the file's already.");
    expect(button('confirm').disabled).toBe(false);
  });

  it('imports nothing on Cancel', async () => {
    await setup();
    await choose();
    await press('cancel');

    expect(host.querySelector('.preview')).toBeNull();
    expect(api.importBuilds).toHaveBeenCalledTimes(1);
    expect(toasts.show).not.toHaveBeenCalled();
  });

  it('refuses a file larger than the server takes before reading or sending it', async () => {
    await setup();
    const read = vi.fn(() => Promise.resolve(FILE_TEXT));
    await choose({ size: BUILD_FILE_MAX_BYTES + 1, text: read });

    expect(problem()).toBe(BUILD_FILE_TOO_LARGE);
    expect(read).not.toHaveBeenCalled();
    expect(api.importBuilds).not.toHaveBeenCalled();
  });

  it('says so when the browser cannot read the file', async () => {
    await setup();
    await choose({ text: () => Promise.reject(new Error('NotReadableError')) });

    expect(problem()).toBe('This file could not be read.');
    expect(api.importBuilds).not.toHaveBeenCalled();
  });

  it('says plainly what a file is not before sending it - a file of version 1 is from before builds had variations', async () => {
    await setup();
    const version1 = JSON.stringify({ format: 'build-analyzer.builds', format_version: 1, source: 'genshin-2', game: 'genshin', builds: [] });
    await choose({ text: () => Promise.resolve(version1) });

    expect(problem()).toBe("This builds file has format version 1, from before builds had variations. This installation reads version 3: a game's builds, each a variation of its character's.");
    expect(api.importBuilds).not.toHaveBeenCalled();

    await choose({ text: () => Promise.resolve('{"format": ') });
    expect(problem()).toBe('This is not a builds file: it is not JSON.');
    expect(api.importBuilds).not.toHaveBeenCalled();
  });

  it('says what the server refuses in the dry run - its own sentence - and shows no preview', async () => {
    await setup();
    const cases: [HttpErrorResponse, string][] = [
      [refused(422, 'builds[3] (hutao, main_dps C2+): weapons[0].key must be text.'), 'builds[3] (hutao, main_dps C2+): weapons[0].key must be text.'],
      [refused(409, 'Another import of builds is being written. Try again in a moment.'), 'Another import of builds is being written. Try again in a moment.'],
      [refused(0, null), 'Cannot reach the server. Check your connection and try again.'],
    ];
    for (const [answer, sentence] of cases) {
      api.importBuilds.mockReturnValueOnce(throwError(() => answer));
      await choose();
      expect(problem()).toBe(sentence);
      expect(host.querySelector('.preview')).toBeNull();
      expect([button('export').disabled, button('import').disabled]).toEqual([false, false]);
    }
  });

  it('keeps the preview when the import itself is refused, so it can be pressed again', async () => {
    await setup();
    await choose();
    api.importBuilds.mockReturnValueOnce(throwError(() => refused(409, 'Another import of builds is being written. Try again in a moment.')));
    await press('confirm');

    expect(problem()).toBe('Another import of builds is being written. Try again in a moment.');
    expect(host.querySelector('.preview')).not.toBeNull();
    expect(button('confirm').disabled).toBe(false);
    expect(toasts.show).not.toHaveBeenCalled();
    expect(api.adminBuilds).toHaveBeenCalledTimes(1);
  });

  describe("hand edits: what an import keeps of the editors' work, and what it asks", () => {
    const variation = (key: string, name: string, role: string, qualifier = '', minRank = 0) => ({ character: { key, name }, role, qualifier, min_rank: minRank });
    const QUESTIONS: BuildQuestion[] = [
      { id: 'b1', kind: 'edited', ...variation('hutao', 'Hu Tao', 'main_dps'), parts: ['weapons'], taken: ['substats', 'teams'], notes: false },
      { id: 'b2', kind: 'removed_by_file', ...variation('furina', 'Furina', 'support'), parts: ['stat_targets'], taken: [], notes: true },
      { id: 'r3', kind: 'removed_here', ...variation('xiangling', 'Xiangling', 'sub_dps', '', 6), parts: ['game_version'], taken: [], notes: false },
      { id: 'b4', kind: 'made_here', ...variation('nahida', 'Nahida', 'support', 'Spread'), parts: ['main_stats', 'sets'], taken: [], notes: false },
    ];
    const segment = (question: number, value: 'edit' | 'file') => host.querySelector<HTMLButtonElement>(`.question:nth-child(${question}) [role="radio"][data-value="${value}"]`)!;
    const chosen = () => [...host.querySelectorAll('.question')].map((question) => question.querySelector('[role="radio"][aria-checked="true"]')?.getAttribute('data-value'));

    async function click(element: HTMLElement): Promise<void> {
      element.click();
      await settle();
      await fixture.whenStable();
    }

    it("asks per build where the file and an editor's work disagree - the editor's work standing unless the file is chosen - and Import sends the answers with the plan", async () => {
      await setup();
      api.importBuilds.mockImplementation((_game, _file, dryRun) => of(plan(dryRun, { questions: QUESTIONS })));
      await choose();

      expect(text(host.querySelector('.questions h5'))).toBe('Your edits and the file (4)');
      expect(texts('.question .question-name')).toEqual(['Hu Tao · Main DPS', 'Furina · Support', 'Xiangling · Sub DPS · C6+', 'Nahida · Support · Spread']);
      expect(texts('.question .question-text .muted')).toEqual([
        "You edited its weapons, and the file changes them too. Its substats and teams, which only the file changes, are the file's either way.",
        'The file no longer has it; you edited its stat targets and wrote notes on it.',
        'You removed it; the file changes its game version.',
        'Made here; the file brings it too, with other main stats and artifact sets.',
      ]);
      expect([...host.querySelectorAll('.question')].map((question) => [...question.querySelectorAll('[role="radio"]')].map(text))).toEqual([
        ['Keep your edit', "Take the file's"],
        ['Keep it', 'Remove it'],
        ['Keep it removed', 'Bring it back'],
        ['Keep yours', "Take the file's"],
      ]);
      expect(host.querySelector('.question [role="radiogroup"]')?.getAttribute('aria-label')).toBe('What stands for Hu Tao · Main DPS');
      expect(chosen()).toEqual(['edit', 'edit', 'edit', 'edit']);

      await click(segment(1, 'file'));
      await click(segment(3, 'file'));
      expect(chosen()).toEqual(['file', 'edit', 'file', 'edit']);

      await press('confirm');
      expect(api.importBuilds.mock.calls.at(-1)).toEqual(['genshin', FILE_TEXT, false, { file: ['b1', 'r3'], plan: PLAN }]);
    });

    it('answers every question alike: "all" when the file is taken everywhere, none when every edit is kept', async () => {
      await setup();
      api.importBuilds.mockImplementation((_game, _file, dryRun) => of(plan(dryRun, { questions: QUESTIONS })));
      await choose();

      await press('take-all');
      expect(chosen()).toEqual(['file', 'file', 'file', 'file']);
      await press('confirm');
      expect(api.importBuilds.mock.calls.at(-1)).toEqual(['genshin', FILE_TEXT, false, { file: 'all', plan: PLAN }]);

      await choose();
      await press('take-all');
      await press('keep-all');
      expect(chosen()).toEqual(['edit', 'edit', 'edit', 'edit']);
      await press('confirm');
      expect(api.importBuilds.mock.calls.at(-1)).toEqual(['genshin', FILE_TEXT, false, { file: [], plan: PLAN }]);
    });

    it('checks the file again when its questions changed meanwhile - an edit saved, another import - and says so, every answer forgotten', async () => {
      await setup();
      api.importBuilds.mockImplementation((_game, _file, dryRun) => of(plan(dryRun, { questions: QUESTIONS })));
      await choose();
      await click(segment(1, 'file'));

      api.importBuilds.mockReturnValueOnce(throwError(() => new HttpErrorResponse({ status: 409, error: { message: 'The builds changed since this file was checked.', code: 'plan_changed' } })));
      await press('confirm');

      expect(api.importBuilds.mock.calls.slice(-2).map((call) => call[2])).toEqual([false, true]);
      expect(problem()).toBe('The builds changed since this preview - a build was edited, or another import came. The file was checked again: look at its questions before importing.');
      expect(host.querySelector('.preview')).not.toBeNull();
      expect(chosen()).toEqual(['edit', 'edit', 'edit', 'edit']);
      expect(toasts.show).not.toHaveBeenCalled();
    });

    it('says what is kept by hand, what it combines without asking, whose order stays and which team notes are left waiting', async () => {
      await setup();
      const answer = (dryRun: boolean) =>
        plan(dryRun, {
          edits_kept: 3,
          made_here: 2,
          kept_removed: 1,
          merged: [{ ...variation('hutao', 'Hu Tao', 'main_dps'), kept: ['weapons'], taken: ['substats', 'teams'] }],
          order_kept: [
            { key: 'hutao', name: 'Hu Tao' },
            { key: 'furina', name: 'Furina' },
          ],
          notes_dormant: [{ ...variation('xiangling', 'Xiangling', 'sub_dps'), notes: 2 }],
        });
      api.importBuilds.mockImplementation((_game, _file, dryRun) => of(answer(dryRun)));
      await choose();

      expect(text(host.querySelector('.preview .hand'))).toBe('Kept by hand: 3 builds with an edit, 2 made here, 1 removed.');
      expect(host.querySelector('.questions')).toBeNull();
      expect(text(host.querySelector('.merged .anotoki-disclosure__button'))).toBe('Combined without asking (1)');
      host.querySelector<HTMLButtonElement>('.merged .anotoki-disclosure__button')!.click();
      await fixture.whenStable();
      expect(texts('.merged-list li')).toEqual(["Hu Tao · Main DPS: your weapons kept, the file's substats and teams taken"]);
      expect(text(host.querySelector('.order-kept'))).toBe("Your order of builds stays for Hu Tao, Furina; the file's new builds come after it.");
      expect(text(host.querySelector('.notes-waiting'))).toBe('Team notes left without their team - kept, until a team of those members comes back: Xiangling · Sub DPS (2).');

      await press('confirm');
      expect(toasts.show).toHaveBeenCalledWith('Imported: 2 new, 1 updated, 247 unchanged, 3 removed. Kept by hand: 3 builds with an edit, 2 made here, 1 removed.', 'success', 8000);
    });

    it('says a file that changes nothing changes nothing but for what is kept by hand', async () => {
      await setup();
      api.importBuilds.mockReturnValueOnce(of(plan(true, { created: 0, updated: 0, removed: 0, unchanged: 250, changes: [], changes_listed: 0, edits_kept: 1 })));
      await choose();
      expect(text(host.querySelector('.no-changes'))).toBe("Nothing changes: the builds here are the file's already, but for what is kept by hand.");
    });
  });

  it("writes a constellation in the game's own prefix, from the game's metadata", async () => {
    await setup([game()], { game: () => ({ rank_prefix: 'E' }) });
    await choose();
    host.querySelector<HTMLButtonElement>('.changes .anotoki-disclosure__button')!.click();
    await fixture.whenStable();
    expect(texts('.change-list li')[0]).toBe('Hu Tao · Main DPS · Melt · E2+ — new');
  });

  describe('refreshing the catalog', () => {
    function result(list: CatalogList, entries: number, outcome: CatalogRefreshResult['outcome'] = 'changed'): CatalogRefreshResult {
      return { outcome, written: outcome === 'changed' ? entries : 0, list, entries, refreshed_at: '2026-10-20T11:59:00+00:00', problem: null };
    }

    it('says which lists failed the last time - here or after an answer - and why, until a refresh of them works', async () => {
      await setup([
        game({
          catalog: [
            { list: 'characters', entries: 120, refreshed_at: REFRESHED, problem: 'The game data provider is closed for now (HTTP 423).' },
            { list: 'weapons', entries: 230, refreshed_at: REFRESHED, problem: null },
            { list: 'sets', entries: 60, refreshed_at: null, problem: 'The game data provider answered HTTP 500.' },
          ],
        }),
      ]);
      expect(text(host.querySelector('.list-problems .anotoki-alert__heading'))).toBe('The last refresh failed');
      expect(texts('.list-problems .list-problem')).toEqual(['Characters: The game data provider is closed for now (HTTP 423).', 'Sets: The game data provider answered HTTP 500.']);
      // Part of the page, not news: not read out as it is drawn.
      expect(host.querySelector('.list-problems')?.getAttribute('role')).toBeNull();

      api.refreshCatalog.mockImplementation((_game, list) => of(result(list, list === 'characters' ? 120 : list === 'weapons' ? 230 : 61, list === 'sets' ? 'changed' : 'unchanged')));
      await press('refresh-catalog');
      await vi.waitFor(() => expect(toasts.success).toHaveBeenCalledWith('Catalog refreshed: 120 characters (as they were), 230 weapons (as they were), 61 sets.'));
      await fixture.whenStable();
      expect(host.querySelector('.list-problems')).toBeNull();
    });

    it("refreshes the three lists one after another - one request each, the kit's progress bar counting them - then shows their new state", async () => {
      await setup();
      const answers = new Map(CATALOG_LISTS.map((list) => [list, new Subject<CatalogRefreshResult>()]));
      api.refreshCatalog.mockImplementation((_game, list) => answers.get(list)!);
      const bar = () => host.querySelector('.catalog-progress [role="progressbar"]');
      const asked = () => api.refreshCatalog.mock.calls.map(([, list]) => list);

      await press('refresh-catalog');
      expect(asked()).toEqual(['characters']);
      expect(bar()?.getAttribute('aria-label')).toBeNull();
      expect(text(host.querySelector('.catalog-progress .anotoki-progress-bar__label'))).toBe('Refreshing the catalog');
      expect(bar()?.getAttribute('aria-valuetext')).toBe('0 of 3');
      expect(button('refresh-catalog').disabled).toBe(true);

      const steps: [CatalogList, number, string][] = [
        ['characters', 125, '1 of 3'],
        ['weapons', 231, '2 of 3'],
      ];
      for (const [list, entries, said] of steps) {
        answers.get(list)!.next(result(list, entries));
        await settle();
        await fixture.whenStable();
        expect(bar()?.getAttribute('aria-valuetext')).toBe(said);
      }
      expect(asked()).toEqual(['characters', 'weapons', 'sets']);
      expect(texts('.lists anotoki-stat-tile').slice(0, 2)).toEqual([
        `125 Characters refreshed ${timeAgo('2026-10-20T11:59:00+00:00')}`,
        `231 Weapons refreshed ${timeAgo('2026-10-20T11:59:00+00:00')}`,
      ]);

      answers.get('sets')!.next(result('sets', 61));
      await settle();
      await fixture.whenStable();
      expect(host.querySelector('.catalog-progress')).toBeNull();
      expect(texts('.lists anotoki-stat-tile')[2]).toBe(`61 Sets refreshed ${timeAgo('2026-10-20T11:59:00+00:00')}`);
      expect(button('refresh-catalog').disabled).toBe(false);
      expect(toasts.success).toHaveBeenCalledWith('Catalog refreshed: 125 characters, 231 weapons, 61 sets.');
      expect(api.refreshCatalog).toHaveBeenCalledTimes(3);
    });

    it("reads the builds editor's characters again once the catalog is refreshed: a character may have come", async () => {
      await setup();
      api.refreshCatalog.mockImplementation((_game, list) => of(result(list, 1)));
      expect(api.editorCatalog).toHaveBeenCalledTimes(1);

      await press('refresh-catalog');
      await vi.waitFor(() => expect(api.editorCatalog).toHaveBeenCalledTimes(2));
    });

    it('stops at the first error and says it - the lists refreshed before it keep their new state', async () => {
      await setup();
      api.refreshCatalog.mockImplementation((_game, list) =>
        list === 'characters' ? of(result('characters', 125)) : throwError(() => refused(502, 'The game data provider could not be read. Try again later.')),
      );

      await press('refresh-catalog');
      await vi.waitFor(async () => {
        await fixture.whenStable();
        expect(text(host.querySelector('.catalog-problem .anotoki-alert__text'))).toBe('Weapons could not be refreshed: The game data provider could not be read. Try again later.');
      });
      expect(api.refreshCatalog.mock.calls.map(([, list]) => list)).toEqual(['characters', 'weapons']);
      expect(host.querySelector('.catalog-progress')).toBeNull();
      expect(texts('.lists anotoki-stat-tile')).toEqual([`125 Characters refreshed ${timeAgo('2026-10-20T11:59:00+00:00')}`, '230 Weapons never refreshed', '60 Sets never refreshed']);
      expect(button('refresh-catalog').disabled).toBe(false);
      expect(toasts.success).not.toHaveBeenCalled();
    });
  });

  describe('Edit builds: the way into the builds editor', () => {
    const changedHere = game({
      editing: [
        { key: 'hutao', name: 'Hu Tao', icon_url: '/icons/hutao.png', builds: 2, edited: 1, made_here: 0, removed: 1, notes: 1, ordered: false },
        { key: 'xiangling', name: 'Xiangling', icon_url: null, builds: 1, edited: 0, made_here: 0, removed: 0, notes: 0, ordered: false },
      ],
    });
    // Each row's cells as read - the name by itself: a character with no picture draws its initials (hidden from a screen reader).
    const rows = () =>
      [...host.querySelectorAll<HTMLTableRowElement>('.editor-table tbody tr')].map((row) =>
        [...row.querySelectorAll('td')].map((cell, i) => text(i === 0 ? cell.querySelector('.editor-name') : cell)),
      );
    const links = () => [...host.querySelectorAll<HTMLAnchorElement>('.editor-table a.editor-character')].map((link) => link.getAttribute('href'));

    async function find(words: string): Promise<void> {
      const input = host.querySelector<HTMLInputElement>('.editor-find input')!;
      input.value = words;
      input.dispatchEvent(new Event('input'));
      await fixture.whenStable();
    }

    it('lists every character of the catalog in a table - its picture and name a link to its editor, its element, weapon, builds and what was done to them here', async () => {
      await setup([changedHere]);

      expect(text(host.querySelector('.editor .anotoki-card__title'))).toBe('Edit builds');
      expect([...host.querySelectorAll('.editor-table th')].map((cell) => text(cell))).toEqual(['Character', 'Element', 'Weapon', 'Builds', 'Changed here']);
      expect(rows()).toEqual([
        ['Furina', 'Hydro', 'Sword', '0', 'Nothing yet'],
        ['Hu Tao', 'Pyro', 'Polearm', '2', '1 edited · 1 removed · notes on 1'],
        ['Xiangling', 'Pyro', 'Polearm', '1', 'Nothing yet'],
      ]);
      expect(links()).toEqual(['/admin/builds/genshin/furina', '/admin/builds/genshin/hutao', '/admin/builds/genshin/xiangling']);
      expect(text(host.querySelector('.editor-count'))).toBe('3 of 3 characters');
      // On a phone each row a small card of its own, labelled from its cells (the kit's table-stack).
      expect(host.querySelector('.editor-table table')!.classList.contains('table-stack')).toBe(true);
      expect(host.querySelector('.editor-table td')!.getAttribute('data-label')).toBe('Character');
      expect(api.editorCatalog).toHaveBeenCalledWith('genshin');
    });

    it('narrows the table to the words typed - a name, an element, a weapon -, or to the characters changed here', async () => {
      await setup([changedHere]);

      await find('pyro');
      expect(rows().map((row) => row[0])).toEqual(['Hu Tao', 'Xiangling']);
      await find('hu   pyro');
      expect(rows().map((row) => row[0])).toEqual(['Hu Tao']);
      expect(text(host.querySelector('.editor-count'))).toBe('1 of 3 characters');
      // Without regard to case or accents.
      await find('FURÍNA');
      expect(rows().map((row) => row[0])).toEqual(['Furina']);
      await find('nobody');
      expect(host.querySelector('.editor-table')).toBeNull();
      expect(text(host.querySelector('.editor-none'))).toBe('No character matches.');

      await find('');
      host.querySelector<HTMLInputElement>('.editor-changed input')!.click();
      await fixture.whenStable();
      expect(rows().map((row) => row[0])).toEqual(['Hu Tao']);
    });

    it('says why the characters could not be read, in place of the table', async () => {
      await setup([game()], undefined, () => throwError(() => refused(500, 'Something broke.')));
      await settle();
      await fixture.whenStable();
      expect(text(host.querySelector('.editor-problem .anotoki-alert__text'))).toBe('The characters could not be read: Something broke.');
      expect(host.querySelector('.editor-table')).toBeNull();
    });
  });
});
