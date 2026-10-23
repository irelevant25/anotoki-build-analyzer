import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { ToastService } from '@anotoki/lib/ui/toast';
import { Observable, Subject, of, throwError } from 'rxjs';
import { Api } from '../../../core/api';
import { BuildEditorChanges } from '../../../core/build-editor-changes';
import { provideKit } from '../../../core/kit';
import { CharacterEditing, EditorBuild, EditorCatalog, EditorSave, GameKey } from '../../../core/models';
import { BuildEditorPage } from './build-editor-page';

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const CATALOG: EditorCatalog = {
  game: 'genshin',
  facts: {
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
    labels: {
      stats: { critRate_: 'CRIT Rate', critDMG_: 'CRIT DMG', hp_: 'HP%' },
      talents: { auto: 'Normal Attack', skill: 'Elemental Skill', burst: 'Elemental Burst' },
      slots: { sands: 'Sands', goblet: 'Goblet' },
      set_kinds: { artifact: 'Artifact set' },
    },
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
    { key: 'hutao', name: 'Hu Tao', element: 'pyro', weapon_type: 'polearm', rarity: 5, icon_url: null },
    { key: 'xingqiu', name: 'Xingqiu', element: 'hydro', weapon_type: 'sword', rarity: 4, icon_url: null },
  ],
  weapons: [{ key: 'staffofhoma', name: 'Staff of Homa', weapon_type: 'polearm', rarity: 5, icon_url: null }],
  sets: [],
};

function build(fields: Partial<EditorBuild> = {}): EditorBuild {
  return {
    role: 'main_dps',
    qualifier: '',
    min_rank: 0,
    weapons: [
      { key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 },
      { key: 'whiteflamebow', name: 'White Flame Bow', refinement: null },
    ],
    sets: [{ kind: 'artifact', choose: null, pieces: [{ label: 'Crimson Witch of Flames', count: 4, keys: ['crimsonwitchofflames'], names: ['Crimson Witch of Flames'] }] }],
    main_stats: { sands: [{ stat: 'hp_', priority: 1 }] },
    substats: [
      { stat: 'critRate_', priority: 1 },
      { stat: 'critDMG_', priority: 1 },
    ],
    talent_priority: [{ talent: 'skill', priority: 1 }],
    stat_targets: [{ stat: 'critRate_', min: 70, max: null, from_rank: 0 }],
    teams: [
      {
        name: 'Vaporize',
        members: [
          { key: 'hutao', name: 'Hu Tao', role: 'main_dps', min_rank: 0, alternatives: [] },
          { key: 'xingqiu', name: 'Xingqiu', role: 'sub_dps', min_rank: 6, alternatives: [] },
        ],
      },
    ],
    game_version: '5.4',
    ...fields,
  };
}

/** Hu Tao's builds: the file's main DPS one, the file's support one with an edit, one made here; a sub DPS one removed. */
function editing(fields: Partial<CharacterEditing> = {}): CharacterEditing {
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
        notes: { build: { en: 'Keep her HP low.' }, teams: [null], waiting: [] },
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
        notes: { build: null, teams: [], waiting: [] },
        checked_at: null,
        changed_at: null,
        edited_at: null,
      },
    ],
    removed: [{ id: 7, build: build({ role: 'sub_dps', teams: [] }), file_position: 1, notes: { build: null, teams: [], waiting: [] }, removed_at: null }],
    ...fields,
  };
}

function refused(status: number, body: object): HttpErrorResponse {
  return new HttpErrorResponse({ status, error: body });
}

describe("the builds editor: a character's builds changed by hand, saved in one go", () => {
  let fixture: ComponentFixture<BuildEditorPage>;
  let host: HTMLElement;
  let api: {
    editorCatalog: ReturnType<typeof vi.fn<(game: GameKey) => Observable<EditorCatalog>>>;
    characterEditing: ReturnType<typeof vi.fn<(game: GameKey, key: string) => Observable<CharacterEditing>>>;
    saveCharacterEditing: ReturnType<typeof vi.fn<(game: GameKey, key: string, save: EditorSave) => Observable<CharacterEditing>>>;
  };
  let ask: ReturnType<typeof vi.fn>;
  let toasts: { success: ReturnType<typeof vi.fn>; warning: ReturnType<typeof vi.fn>; error: ReturnType<typeof vi.fn>; show: ReturnType<typeof vi.fn> };

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const texts = (selector: string) => [...host.querySelectorAll(selector)].map(text);
  const button = (selector: string) => host.querySelector<HTMLButtonElement>(`button.${selector}`)!;
  /** Each tab as it is seen: its label, and its badge after it (heard after a comma, which the kit adds unseen). */
  const tabs = (selector = '[role="tab"]') =>
    [...host.querySelectorAll(selector)].map((tab) => [text(tab.querySelector('.anotoki-tabs__label')), text(tab.querySelector('.anotoki-tabs__badge'))].filter(Boolean).join(' '));
  const field = (path: string) => host.querySelector<HTMLElement>(`.build-panel [data-path="${path}"]`)!;
  const control = <T extends HTMLElement>(path: string) => field(path).querySelector<T>('input, textarea, select')!;
  /** The page itself, for what its menu does: the kit's menu opens in the browser's top layer. */
  const page = () => fixture.componentInstance as unknown as Record<string, () => unknown>;

  async function setup(answer: CharacterEditing = editing(), confirm = true): Promise<void> {
    api = {
      editorCatalog: vi.fn(() => of(CATALOG)),
      characterEditing: vi.fn(() => of(answer)),
      saveCharacterEditing: vi.fn(),
    };
    ask = vi.fn(() => Promise.resolve(confirm));
    toasts = { success: vi.fn(), warning: vi.fn(), error: vi.fn(), show: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations({}),
        provideKit(),
        provideRouter([]),
        { provide: Api, useValue: api },
        { provide: ConfirmService, useValue: { ask } },
        { provide: ToastService, useValue: toasts },
      ],
    });
    fixture = TestBed.createComponent(BuildEditorPage);
    fixture.componentRef.setInput('game', 'genshin');
    fixture.componentRef.setInput('key', 'hutao');
    host = fixture.nativeElement;
    await settle();
    await fixture.whenStable();
  }

  async function update(): Promise<void> {
    await settle();
    await fixture.whenStable();
  }

  async function type(path: string, value: string): Promise<void> {
    const input = control<HTMLInputElement | HTMLTextAreaElement>(path);
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await update();
  }

  async function choose(path: string, value: string): Promise<void> {
    const select = control<HTMLSelectElement>(path);
    select.value = value;
    select.dispatchEvent(new Event('change'));
    await update();
  }

  async function chooseTab(index: number): Promise<void> {
    host.querySelectorAll<HTMLButtonElement>('[role="tab"]')[index].click();
    await update();
  }

  afterEach(() => vi.restoreAllMocks());

  it("shows the character's builds - a tab each, named by its variation with what it is - and the open one's cards", async () => {
    await setup();

    expect(text(host.querySelector('h1'))).toBe('Builds of Hu Tao');
    expect(api.characterEditing).toHaveBeenCalledWith('genshin', 'hutao');
    expect(tabs()).toEqual(['Main DPS', 'Support Edited', 'Main DPS · Mine Made here']);
    expect(text(host.querySelector('.order'))).toBe("The builds file's order, builds made here after the file's.");
    expect(text(host.querySelector('.build-origin'))).toContain('From the builds file');

    // A build of the file's keeps its name there: its role, qualifier and constellation stay.
    expect(control<HTMLSelectElement>('role').value).toBe('main_dps');
    expect([control<HTMLSelectElement>('role').disabled, control<HTMLInputElement>('qualifier').disabled, control<HTMLSelectElement>('min_rank').disabled]).toEqual([true, true, true]);
    expect(control<HTMLInputElement>('game_version').disabled).toBe(false);
    expect(host.querySelector('.fixed-name')).not.toBeNull();
    expect(control<HTMLTextAreaElement>('notes.build.en').value).toBe('Keep her HP low.');

    expect(texts('app-part-card .anotoki-card__title')).toEqual(['Variation', 'Notes', 'Weapons', 'Artifact sets', 'Main stats', 'Substats', 'Talents', 'Stat targets', 'Teams']);
    // The parts' forms, each field at its place: the weapons (one the catalog lacks by its stored name, marked) ...
    expect(control<HTMLInputElement>('weapons[0].key').value).toBe('Staff of Homa');
    expect(control<HTMLInputElement>('weapons[1].key').value).toBe('White Flame Bow (not in the catalog)');
    expect([control<HTMLSelectElement>('weapons[0].refinement').value, control<HTMLSelectElement>('weapons[1].refinement').value]).toEqual(['1', '']);
    expect(texts('[data-part="weapons"] anotoki-badge')).toEqual(['Not in the catalog']);
    // ... the sets, a slot's main stats, the substats with their priorities, a talent, a target ...
    expect(texts('[data-part="sets"] .anotoki-chip-list__label')).toEqual(['Crimson Witch of Flames']);
    expect(control<HTMLSelectElement>('main_stats.sands[0].stat').value).toBe('hp_');
    expect([control<HTMLSelectElement>('substats[0].stat').value, control<HTMLSelectElement>('substats[1].stat').value]).toEqual(['critRate_', 'critDMG_']);
    expect([control<HTMLSelectElement>('substats[0].priority').value, control<HTMLSelectElement>('substats[1].priority').value]).toEqual(['1', '1']);
    expect(control<HTMLSelectElement>('talent_priority[0].talent').value).toBe('skill');
    expect([control<HTMLSelectElement>('stat_targets[0].stat').value, control<HTMLInputElement>('stat_targets[0].min').value]).toEqual(['critRate_', '70']);
    // ... and the teams': the build's own character as it is, the others to pick.
    expect(control<HTMLInputElement>('teams[0].name').value).toBe('Vaporize');
    expect(texts('[data-part="teams"] .own-name')).toEqual(['Hu Tao']);
    expect(control<HTMLInputElement>('teams[0].members[1].key').value).toBe('Xingqiu');
    expect([control<HTMLSelectElement>('teams[0].members[1].role').value, control<HTMLSelectElement>('teams[0].members[1].min_rank').value]).toEqual(['sub_dps', '6']);
    expect(host.querySelector('.save-bar')).toBeNull();

    // The edited one: its game version differs from the file's, which is there to go back to.
    await chooseTab(1);
    expect(text(host.querySelector('.variation .edited'))).toBe('Edited');
    expect(host.querySelector('.variation button.use-file')).not.toBeNull();
    // Made here: its name is the editor's to change.
    await chooseTab(2);
    expect(control<HTMLInputElement>('qualifier').disabled).toBe(false);
    expect(host.querySelector('.fixed-name')).toBeNull();
  });

  it('saves a change - the build as changed, every part nobody touched as it came - and takes the answer', async () => {
    await setup();
    await chooseTab(2);
    await type('qualifier', 'Melt');

    expect(tabs()[2]).toBe('Main DPS · Melt Unsaved');
    expect(text(host.querySelector('.save-text'))).toBe('Changes not saved yet.');
    expect(TestBed.inject(BuildEditorChanges).unsaved()).toBe(true);

    const saved = editing();
    saved.builds[2] = { ...saved.builds[2], build: { ...saved.builds[2].build, qualifier: 'Melt' } };
    api.saveCharacterEditing.mockReturnValue(of(saved));
    button('save').click();
    await update();

    const [game, key, body] = api.saveCharacterEditing.mock.calls[0];
    expect([game, key, body.version, body.order]).toEqual(['genshin', 'hutao', 'v'.repeat(64), undefined]);
    expect(body.builds.map((one) => [one.id, one.removal, one.build.qualifier])).toEqual([
      [1, null, ''],
      [2, null, ''],
      [3, null, 'Melt'],
    ]);
    expect(body.builds[2].build.weapons).toEqual(editing().builds[2].build.weapons);
    expect(body.builds[0].notes).toEqual({ build: { en: 'Keep her HP low.' }, teams: [null], waiting: [] });
    expect(toasts.success).toHaveBeenCalledWith('Saved: the builds of Hu Tao.');
    expect(host.querySelector('.save-bar')).toBeNull();
    expect(tabs()[2]).toBe('Main DPS · Melt Made here');
    expect(TestBed.inject(BuildEditorChanges).unsaved()).toBe(false);
  });

  it("writes the site's note in English and Slovak, and sends it with the build", async () => {
    await setup();
    await type('notes.build.sk', 'Drž jej HP nízko.');
    api.saveCharacterEditing.mockReturnValue(of(editing()));
    button('save').click();
    await update();
    expect(api.saveCharacterEditing.mock.calls[0][2].builds[0].notes.build).toEqual({ en: 'Keep her HP low.', sk: 'Drž jej HP nízko.' });
  });

  it('checks before saving, and shows a problem at its field - the build opened, the field focused', async () => {
    await setup();
    await chooseTab(2);
    await type('qualifier', '');
    await chooseTab(0);
    await type('notes.build.en', ' ');
    await type('notes.build.sk', 'Len po slovensky.');

    button('save').click();
    await update();
    expect(api.saveCharacterEditing).not.toHaveBeenCalled();
    // The first problem's build is the open one: its Slovak with no English.
    expect(text(field('notes.build.en'))).toContain('Write the English first: the Slovak is optional, the English is not.');
    expect(document.activeElement).toBe(control('notes.build.en'));
    await chooseTab(2);
    expect(text(field('qualifier'))).toContain('Build 1 has this role, qualifier and constellation too: tell the two apart by their qualifier.');

    // Changed, the problem shown there goes.
    await type('qualifier', 'Melt');
    expect(text(field('qualifier'))).not.toContain('Build 1 has');
  });

  it("shows the server's refusal at the field it names, or above the build's cards where it has none", async () => {
    await setup();
    await type('game_version', '5.6');
    api.saveCharacterEditing.mockReturnValue(
      throwError(() => refused(422, { message: 'Build 1: notes.build.en names another site ("Elsewhere").', code: 'names_another_site', build: 0, path: 'notes.build.en' })),
    );
    button('save').click();
    await update();
    expect(text(field('notes.build.en'))).toContain('Build 1: notes.build.en names another site ("Elsewhere").');
    expect(document.activeElement).toBe(control('notes.build.en'));

    api.saveCharacterEditing.mockReturnValue(
      throwError(() => refused(422, { message: 'Build 2: weapons[0].refinement must be a whole number from 1 to 5, or null.', code: 'invalid_build', build: 1, path: 'weapons[0].refinement' })),
    );
    button('save').click();
    await update();
    expect(tabs('[role="tab"][aria-selected="true"]')).toEqual(['Support Edited']);
    expect(text(field('weapons[0].refinement'))).toContain('Build 2: weapons[0].refinement must be a whole number from 1 to 5, or null.');
    expect(document.activeElement).toBe(control('weapons[0].refinement'));

    // A team's member: at its picker.
    api.saveCharacterEditing.mockReturnValue(
      throwError(() => refused(422, { message: 'Build 1: teams[0].members[1].key repeats an earlier entry of its list.', code: 'invalid_build', build: 0, path: 'teams[0].members[1].key' })),
    );
    button('save').click();
    await update();
    expect(tabs('[role="tab"][aria-selected="true"]')).toEqual(['Main DPS Unsaved']);
    expect(text(field('teams[0].members[1].key'))).toContain('Build 1: teams[0].members[1].key repeats an earlier entry of its list.');
    expect(document.activeElement).toBe(control('teams[0].members[1].key'));

    // A place no field has: said above the build's cards.
    api.saveCharacterEditing.mockReturnValue(throwError(() => refused(422, { message: 'Build 1: removal is no removed build of this character.', code: 'invalid_build', build: 0, path: 'removal' })));
    button('save').click();
    await update();
    expect(text(host.querySelector('.build-panel .problems'))).toContain('Build 1: removal is no removed build of this character.');
    expect(document.activeElement).toBe(host.querySelector('.build-panel .problems'));
  });

  it('says when the builds changed meanwhile, keeps what was typed, and reads them again on Reload - asked first', async () => {
    await setup();
    await type('game_version', '5.6');
    api.saveCharacterEditing.mockReturnValue(throwError(() => refused(409, { message: 'These builds changed since they were opened.', code: 'changed' })));
    button('save').click();
    await update();

    expect(text(host.querySelector('.conflict'))).toContain('These builds changed since they were opened.');
    expect(control<HTMLInputElement>('game_version').value).toBe('5.6');
    expect(host.querySelector('.save-bar')).not.toBeNull();

    api.characterEditing.mockReturnValue(of(editing({ version: 'w'.repeat(64) })));
    button('conflict-reload').click();
    await update();
    expect(ask).toHaveBeenCalledWith(expect.objectContaining({ title: 'Reload and lose your changes?' }));
    expect(api.characterEditing).toHaveBeenCalledTimes(2);
    expect(host.querySelector('.conflict')).toBeNull();
    expect(control<HTMLInputElement>('game_version').value).toBe('5.4');
  });

  it('says to try again while an import or another save is written, and says on the page why any other save failed', async () => {
    await setup();
    await type('game_version', '5.6');
    api.saveCharacterEditing.mockReturnValue(throwError(() => refused(409, { message: 'An import of builds or another save is being written. Try again in a moment.', code: 'busy' })));
    button('save').click();
    await update();
    expect(toasts.warning).toHaveBeenCalledWith('An import of builds or another save is being written. Try again in a moment.');

    api.saveCharacterEditing.mockReturnValue(throwError(() => refused(500, { message: 'Something went wrong on the server.' })));
    button('save').click();
    await update();
    expect(text(host.querySelector('.save-error'))).toBe('Not saved: Something went wrong on the server.');
  });

  it('discards every change - asked first - and asks before the page is left with changes, never without', async () => {
    await setup();
    expect(await fixture.componentInstance.canLeave()).toBe(true);
    expect(ask).not.toHaveBeenCalled();

    await type('game_version', '5.6');
    expect(await fixture.componentInstance.canLeave()).toBe(true);
    expect(ask).toHaveBeenLastCalledWith(expect.objectContaining({ title: 'Leave without saving?', confirmLabel: 'Leave', cancelLabel: 'Stay' }));

    button('discard').click();
    await update();
    expect(ask).toHaveBeenLastCalledWith(expect.objectContaining({ title: 'Discard your changes?' }));
    expect(control<HTMLInputElement>('game_version').value).toBe('5.4');
    expect(host.querySelector('.save-bar')).toBeNull();

    // Given up by a sign-out: the page goes without asking again.
    await type('game_version', '5.7');
    TestBed.inject(BuildEditorChanges).giveUp();
    ask.mockClear();
    expect(await fixture.componentInstance.canLeave()).toBe(true);
    expect(ask).not.toHaveBeenCalled();
  });

  it('adds a build - a new one, a copy, a removed one brought back where the file puts it - and moves and deletes one', async () => {
    await setup();

    page()['addNew']();
    await update();
    expect(tabs()).toEqual(['Main DPS', 'Support Edited', 'Main DPS · Mine Made here', 'Main DPS · New New']);
    expect(tabs('[role="tab"][aria-selected="true"]')).toEqual(['Main DPS · New New']);

    (fixture.componentInstance as unknown as { bringBack(id: number): void }).bringBack(7);
    await update();
    expect(tabs()).toEqual(['Main DPS', 'Sub DPS Brought back', 'Support Edited', 'Main DPS · Mine Made here', 'Main DPS · New New']);
    expect(text(host.querySelector('.order'))).toBe("The builds file's order, builds made here after the file's.");

    // The open one, moved earlier: the editor's order, which can be given back.
    await chooseTab(3);
    host.querySelector<HTMLButtonElement>('.build-actions button.move-up')!.click();
    await update();
    expect(tabs().slice(0, 4)).toEqual(['Main DPS', 'Sub DPS Brought back', 'Main DPS · Mine Made here', 'Support Edited']);
    expect(text(host.querySelector('.order'))).toBe("Your order: every import keeps it, the file's new builds after it.");
    button('order-back').click();
    await update();
    expect(tabs().slice(0, 4)).toEqual(['Main DPS', 'Sub DPS Brought back', 'Support Edited', 'Main DPS · Mine Made here']);

    // Deleted, asked first: a stored one can be put back until the save.
    await chooseTab(3);
    host.querySelector<HTMLButtonElement>('.build-actions button.remove')!.click();
    await update();
    expect(ask).toHaveBeenLastCalledWith(expect.objectContaining({ title: 'Delete Main DPS · Mine?', tone: 'danger' }));
    expect(tabs()).toEqual(['Main DPS', 'Sub DPS Brought back', 'Support Edited', 'Main DPS · New New']);

    // The new build holds nothing: held back, said above its cards - which the save opens, the focus on the words.
    button('save').click();
    await update();
    expect(api.saveCharacterEditing).not.toHaveBeenCalled();
    expect(tabs('[role="tab"][aria-selected="true"]')).toEqual(['Main DPS · New New']);
    expect(text(host.querySelector('.build-panel .problems'))).toContain('This build holds nothing yet');
    expect(document.activeElement).toBe(host.querySelector('.build-panel .problems'));
    host.querySelector<HTMLButtonElement>('.build-actions button.remove')!.click();
    await update();

    api.saveCharacterEditing.mockReturnValue(of(editing()));
    button('save').click();
    await update();
    expect(api.saveCharacterEditing.mock.calls[0][2].builds.map((one) => [one.id, one.removal, one.build.role, one.build.qualifier])).toEqual([
      [1, null, 'main_dps', ''],
      [null, 7, 'sub_dps', ''],
      [2, null, 'support', ''],
    ]);
  });

  it("saves the parts as their forms leave them - a refinement, a piece's count, a priority, a target - every other part as it came", async () => {
    await setup();
    await choose('weapons[0].refinement', '5');
    host.querySelectorAll<HTMLButtonElement>('[data-path="sets[0].pieces[0].count"] [role="radio"]')[0].click();
    await update();
    await choose('substats[1].priority', '2');
    host.querySelector<HTMLButtonElement>('[data-part="stat_targets"] button.add-target')!.click();
    await update();
    await type('stat_targets[1].min', '180');
    await choose('stat_targets[1].stat', 'hp_');

    // Each part's card says it is not saved.
    expect(texts('[data-part] .unsaved')).toEqual(['Unsaved', 'Unsaved', 'Unsaved', 'Unsaved']);
    api.saveCharacterEditing.mockReturnValue(of(editing()));
    button('save').click();
    await update();
    const sent = api.saveCharacterEditing.mock.calls[0][2].builds[0].build;
    expect(sent.weapons.map((weapon) => weapon.refinement)).toEqual([5, null]);
    expect(sent.sets[0].pieces[0].count).toBe(2);
    expect(sent.substats).toEqual([
      { stat: 'critRate_', priority: 1 },
      { stat: 'critDMG_', priority: 2 },
    ]);
    expect(sent.stat_targets).toEqual([
      { stat: 'critRate_', min: 70, max: null, from_rank: 0 },
      { stat: 'hp_', min: 180, max: null, from_rank: 0 },
    ]);
    expect([sent.main_stats, sent.talent_priority, sent.teams]).toEqual([editing().builds[0].build.main_stats, editing().builds[0].build.talent_priority, editing().builds[0].build.teams]);
  });

  it("saves a team as its form leaves it - its note with it, said unsaved on the teams' card alone - and holds back what a team may not be", async () => {
    await setup();
    const note = host.querySelector<HTMLTextAreaElement>('[data-path="notes.teams[0].en"] textarea')!;
    note.value = 'Swirl first.';
    note.dispatchEvent(new Event('input'));
    await update();
    expect(texts('[data-part="teams"] .unsaved')).toEqual(['Unsaved']);
    expect(host.querySelector('app-part-card.notes .unsaved')).toBeNull();

    // A team added and left with no name and no second member: held back, said at its fields.
    host.querySelector<HTMLButtonElement>('[data-part="teams"] button.add-team')!.click();
    await update();
    button('save').click();
    await update();
    expect(api.saveCharacterEditing).not.toHaveBeenCalled();
    expect(text(field('teams[1].name'))).toContain('Name the team');
    expect(text(field('teams[1].members[1].key'))).toContain('Choose a character here');
    expect(document.activeElement).toBe(control('teams[1].name'));

    // A weapon left unchosen too: the problem higher on the page is the one opened and focused.
    host.querySelector<HTMLButtonElement>('[data-part="weapons"] button.add-weapon')!.click();
    await update();
    button('save').click();
    await update();
    expect(document.activeElement).toBe(control('weapons[2].key'));
    host.querySelectorAll<HTMLElement>('[data-part="weapons"] .weapon-row')[2].querySelector<HTMLButtonElement>('button.remove')!.click();
    await update();

    // Taken away again (no note, no question): the save goes, the team's note with its team.
    host.querySelectorAll<HTMLElement>('[data-part="teams"] .team')[1].querySelector<HTMLButtonElement>('.team-head button.remove')!.click();
    await update();
    api.saveCharacterEditing.mockReturnValue(of(editing()));
    button('save').click();
    await update();
    const sent = api.saveCharacterEditing.mock.calls[0][2].builds[0];
    expect(sent.build.teams.map((team) => team.name)).toEqual(['Vaporize']);
    expect(sent.notes.teams).toEqual([{ en: 'Swirl first.' }]);
  });

  it('holds the save back while a bound typed is no number - a change in itself -, saying it at its field', async () => {
    const wrong = 'Write a number, such as 70 or 120.5 - or leave it empty.';
    await setup();
    // The maximum stays none while what is typed is no number: nothing a save would write - and yet the save bar, which says it.
    await type('stat_targets[0].max', '8x');
    expect(host.querySelector('.save-bar')).not.toBeNull();
    expect(text(field('stat_targets[0].max'))).not.toContain(wrong);
    button('save').click();
    await update();
    expect(api.saveCharacterEditing).not.toHaveBeenCalled();
    expect(text(field('stat_targets[0].max'))).toContain(wrong);
    expect(document.activeElement).toBe(control('stat_targets[0].max'));

    // Another build open and back: what is typed kept, and still holding the save back.
    await chooseTab(1);
    await chooseTab(0);
    expect(control<HTMLInputElement>('stat_targets[0].max').value).toBe('8x');
    expect(host.querySelector('.save-bar')).not.toBeNull();

    // Emptied: nothing said, nothing to save.
    await type('stat_targets[0].max', '');
    expect(text(field('stat_targets[0].max'))).not.toContain(wrong);
    expect(host.querySelector('.save-bar')).toBeNull();
  });

  it('holds no change once a part is changed back - the part as it came, nothing to save -, the field in hand kept', async () => {
    await setup();
    await choose('weapons[0].refinement', '5');
    expect(texts('[data-part] .unsaved')).toEqual(['Unsaved']);
    expect(host.querySelector('.save-bar')).not.toBeNull();
    await choose('weapons[0].refinement', '1');
    expect(texts('[data-part] .unsaved')).toEqual([]);
    expect(host.querySelector('.save-bar')).toBeNull();

    // The sands' main stat removed, then listed again as it was: as it came, its picker still in hand.
    const sands = () => host.querySelector('[data-part="main_stats"] app-ranked-list')!;
    sands().querySelector<HTMLButtonElement>('button.remove')!.click();
    await update();
    expect(host.querySelector('.save-bar')).not.toBeNull();
    sands().querySelector<HTMLButtonElement>('button.add')!.click();
    await update();
    expect(control<HTMLSelectElement>('main_stats.sands[0].stat').value).toBe('hp_');
    expect(host.querySelector('.save-bar')).toBeNull();
    expect(document.activeElement).toBe(control('main_stats.sands[0].stat'));

    // The file's weapons again, after a change: what was loaded - nothing to save.
    await choose('weapons[0].refinement', '5');
    host.querySelector<HTMLButtonElement>('[data-part="weapons"] button.use-file')!.click();
    await update();
    expect(control<HTMLSelectElement>('weapons[0].refinement').value).toBe('1');
    expect(host.querySelector('.save-bar')).toBeNull();
  });

  it("forgets what was said of a build deleted - its problems, its bounds typed - and asks a build as loaded nothing of the page's own rules", async () => {
    const twice = editing();
    // As a file brought it: the same weapon twice - read, and saved again as it is.
    twice.builds[2] = {
      ...twice.builds[2],
      build: build({
        qualifier: 'Mine',
        teams: [],
        weapons: [
          { key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 },
          { key: 'staffofhoma', name: 'Staff of Homa', refinement: null },
        ],
      }),
    };
    await setup(twice);
    await type('game_version', '5.6');
    api.saveCharacterEditing.mockReturnValue(of(twice));
    button('save').click();
    await update();
    expect(api.saveCharacterEditing).toHaveBeenCalledTimes(1);

    // A bound typed that is no number in the build made here, said at Save; then the build deleted: nothing holds the save back.
    await chooseTab(2);
    await type('stat_targets[0].max', '8x');
    button('save').click();
    await update();
    expect(text(field('stat_targets[0].max'))).toContain('Write a number');
    page()['deleteActive']();
    await update();
    expect(tabs()).toEqual(['Main DPS', 'Support Edited']);
    api.saveCharacterEditing.mockReturnValue(of(editing()));
    button('save').click();
    await update();
    expect(api.saveCharacterEditing).toHaveBeenCalledTimes(2);
  });

  it("takes no change while a save is on its way - its answer is the page's next state - and puts nothing back from before it", async () => {
    await setup();
    await type('game_version', '5.6');
    const answer = new Subject<CharacterEditing>();
    api.saveCharacterEditing.mockReturnValue(answer);
    button('save').click();
    await update();

    expect(host.querySelector('.editing')?.hasAttribute('inert')).toBe(true);
    expect(button('save').getAttribute('aria-busy')).toBe('true');
    expect(text(host.querySelector('.save-text'))).toBe('Saving…');

    const saved = editing();
    saved.builds[0] = { ...saved.builds[0], build: { ...saved.builds[0].build, game_version: '5.6' } };
    answer.next(saved);
    answer.complete();
    await update();
    expect(host.querySelector('.editing')?.hasAttribute('inert')).toBe(false);
    expect(control<HTMLInputElement>('game_version').value).toBe('5.6');
    expect(host.querySelector('.save-bar')).toBeNull();
  });
});
