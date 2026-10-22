import { HttpErrorResponse, HttpHeaders, HttpResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ToastService } from '@anotoki/lib/ui/toast';
import { Observable, Subject, of, throwError } from 'rxjs';
import { Api } from '../../../core/api';
import { AuthService } from '../../../core/auth';
import { timeAgo } from '../../../core/format';
import { provideKit } from '../../../core/kit';
import { MetaStore } from '../../../core/meta-store';
import { AdminBuildsGame, BuildImportPlan, CatalogList, CatalogRefreshResult, GameKey } from '../../../core/models';
import { AdminBuildsPage, CATALOG_LISTS } from './admin-builds-page';
import { BUILD_FILE_MAX_BYTES, BUILD_FILE_TOO_LARGE, momentText } from './build-file';

/** Lets what a click or a file began finish, and the page be drawn. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const FILE_NAME = 'builds-genshin-2026-10-20.json';
const FILE_TEXT = JSON.stringify({ format: 'build-analyzer.builds', format_version: 3, game: 'genshin', exported_at: '2026-10-20T10:00:00Z', builds: [] });

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
    ...overrides,
  };
}

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
    importBuilds: ReturnType<typeof vi.fn<(game: GameKey, file: string, dryRun: boolean) => Observable<BuildImportPlan>>>;
    refreshCatalog: ReturnType<typeof vi.fn<(game: GameKey, list: CatalogList) => Observable<CatalogRefreshResult>>>;
  };
  let toasts: { show: ReturnType<typeof vi.fn>; success: ReturnType<typeof vi.fn>; error: ReturnType<typeof vi.fn> };

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const texts = (selector: string) => [...host.querySelectorAll(selector)].map(text);
  const button = (selector: string) => host.querySelector<HTMLButtonElement>(`button.${selector}`)!;
  const problem = () => text(host.querySelector('.problem .anotoki-alert__text'));

  async function setup(answer: AdminBuildsGame[] = [game()], meta?: { game: () => { rank_prefix: string } | null }): Promise<void> {
    api = {
      adminBuilds: vi.fn(() => of(answer)),
      exportBuilds: vi.fn(),
      importBuilds: vi.fn((_game: GameKey, _file: string, dryRun: boolean) => of(plan(dryRun))),
      refreshCatalog: vi.fn(),
    };
    toasts = { show: vi.fn(), success: vi.fn(), error: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations({}),
        provideKit(),
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
    expect(lead).toContain('the whole truth for its game');
    expect(lead).toContain("characters this installation's catalog lacks, which are skipped");
    expect(lead).toContain('a backup, and the start of a hand edit');

    expect(texts('section.game h2')).toEqual(['Genshin Impact']);
    expect(texts('.anotoki-card__title')).toEqual(['Builds', 'Recent imports', 'Catalog']);
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
      ['genshin', FILE_TEXT, false],
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
});
