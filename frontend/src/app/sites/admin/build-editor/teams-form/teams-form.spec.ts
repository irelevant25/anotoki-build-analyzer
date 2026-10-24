import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ComboboxComponent } from '@anotoki/lib/ui/combobox';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { provideKit } from '../../../../shared/kit';
import { EditorCharacter, EditorTeam, WaitingNote } from '../../../../api/models';
import { NoteDraft, TeamsChange, rowId } from '../editor-draft';
import { TeamsForm } from './teams-form';

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const CHARACTERS: EditorCharacter[] = [
  { key: 'hutao', name: 'Hu Tao', element: 'pyro', weapon_type: 'polearm', rarity: 5, icon_url: '/icons/hutao.png' },
  { key: 'xingqiu', name: 'Xingqiu', element: 'hydro', weapon_type: 'sword', rarity: 4, icon_url: null },
  { key: 'yelan', name: 'Yelan', element: 'hydro', weapon_type: 'bow', rarity: 5, icon_url: null },
  { key: 'zhongli', name: 'Zhongli', element: 'geo', weapon_type: 'polearm', rarity: 5, icon_url: null },
  { key: 'bennett', name: 'Bennett', element: 'pyro', weapon_type: 'sword', rarity: 4, icon_url: null },
];

const VAPORIZE: EditorTeam = {
  name: 'Vaporize',
  members: [
    { key: 'hutao', name: 'Hu Tao', role: 'main_dps', min_rank: 0, alternatives: [] },
    { key: 'xingqiu', name: 'Xingqiu', role: 'sub_dps', min_rank: 6, alternatives: [{ key: 'yelan', name: 'Yelan' }] },
  ],
};

const DOUBLE: EditorTeam = {
  name: 'Double Hydro',
  members: [
    { key: 'xingqiu', name: 'Xingqiu', role: 'sub_dps', min_rank: 0, alternatives: [] },
    { key: 'hutao', name: 'Hu Tao', role: 'main_dps', min_rank: 1, alternatives: [] },
    { key: 'yelan', name: 'Yelan', role: null, min_rank: 0, alternatives: [] },
  ],
};

describe("the teams' form: a build's teams, each its members, their stand-ins and the site's note", () => {
  let fixture: ComponentFixture<TeamsForm>;
  let host: HTMLElement;
  let emitted: TeamsChange[];
  let ask: ReturnType<typeof vi.fn>;

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const field = (path: string) => host.querySelector<HTMLElement>(`[data-path="${path}"]`)!;
  const pickers = () => fixture.debugElement.queryAll(By.directive(ComboboxComponent)).map((found) => found.componentInstance as ComboboxComponent);
  const pickerAt = (path: string) =>
    fixture.debugElement.queryAll(By.directive(ComboboxComponent)).find((found) => found.nativeElement.getAttribute('data-path') === path)!.componentInstance as ComboboxComponent;
  const last = () => emitted.at(-1)!;

  async function setup(teams: EditorTeam[], notes: NoteDraft[] = teams.map(() => ({ en: '', sk: '' })), waiting: WaitingNote[] = [], confirm = true): Promise<void> {
    ask = vi.fn(() => Promise.resolve(confirm));
    TestBed.configureTestingModule({ providers: [provideTestTranslations({}), provideKit(), { provide: ConfirmService, useValue: { ask } }] });
    fixture = TestBed.createComponent(TeamsForm);
    fixture.componentRef.setInput('teams', teams);
    fixture.componentRef.setInput('notes', notes);
    fixture.componentRef.setInput('waiting', waiting);
    fixture.componentRef.setInput('characters', CHARACTERS);
    fixture.componentRef.setInput('own', CHARACTERS[0]);
    fixture.componentRef.setInput('ownRole', 'main_dps');
    fixture.componentRef.setInput('roles', ['main_dps', 'sub_dps', 'support']);
    emitted = [];
    // The page keeps the teams: what comes out goes back in.
    fixture.componentInstance.teamsChange.subscribe((change) => {
      emitted.push(change);
      fixture.componentRef.setInput('teams', change.teams);
      fixture.componentRef.setInput('notes', change.notes);
      fixture.componentRef.setInput('waiting', change.waiting);
    });
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  async function update(): Promise<void> {
    await settle();
    await fixture.whenStable();
  }

  async function choose(path: string, value: string): Promise<void> {
    const select = field(path).querySelector<HTMLSelectElement>('select')!;
    select.value = value;
    select.dispatchEvent(new Event('change'));
    await update();
  }

  it("shows each team - its name, its members (the build's own character as it is, the others to pick), roles, constellations, stand-ins - and its note", async () => {
    await setup([VAPORIZE], [{ en: 'Swirl first.', sk: 'Najprv rozvír.' }]);

    expect(text(host.querySelector('.team-title'))).toBe('Team 1');
    expect(field('teams[0].name').querySelector('input')!.value).toBe('Vaporize');
    // The build's own character: its name, no picker, no stand-ins, no remove.
    const members = host.querySelectorAll('.member');
    expect(text(members[0].querySelector('.own-name'))).toBe('Hu Tao');
    expect(members[0].querySelector('.pick')).toBeNull();
    expect(members[0].querySelector('.stand-ins')).toBeNull();
    expect(members[0].querySelector('button.remove')).toBeNull();
    expect(members[1].querySelector<HTMLInputElement>('.pick input')!.value).toBe('Xingqiu');
    expect(field('teams[0].members[1].role').querySelector('select')!.value).toBe('sub_dps');
    expect([...field('teams[0].members[1].role').querySelectorAll('option')].map((option) => option.text)).toEqual(['Not said', 'Main DPS', 'Sub DPS', 'Support']);
    expect(field('teams[0].members[1].min_rank').querySelector('select')!.value).toBe('6');
    expect(text(members[1].querySelector('.anotoki-chip-list__label'))).toBe('Yelan');
    // Each member a group of its own, inside its list item.
    expect([...host.querySelectorAll('.member-row')].map((row) => row.getAttribute('aria-label'))).toEqual(['Member 1: Hu Tao', 'Member 2: Xingqiu']);
    expect([...host.querySelectorAll('.members > li')].map((item) => item.getAttribute('role'))).toEqual([null, null]);
    // A member's picker offers the characters but the team's others; its stand-ins' picker, none of the team's nor its own.
    expect(
      pickerAt('teams[0].members[1].key')
        .options()
        .map((option) => option.value),
    ).toEqual(['xingqiu', 'yelan', 'zhongli', 'bennett']);
    expect(
      pickerAt('teams[0].members[1].alternatives')
        .options()
        .map((option) => option.value),
    ).toEqual(['zhongli', 'bennett']);
    expect(host.querySelector<HTMLTextAreaElement>('[data-path="notes.teams[0].en"] textarea')!.value).toBe('Swirl first.');
    expect(host.querySelector('[data-path="notes.teams[0].sk"]')!.closest('[lang="sk"]')).not.toBeNull();
  });

  it("adds a team - the build's own character and a member to choose -, picks the member, and gives it a role, a constellation and stand-ins", async () => {
    await setup([VAPORIZE]);

    host.querySelector<HTMLButtonElement>('button.add-team')!.click();
    await update();
    expect(last().teams[1]).toEqual({
      name: '',
      members: [
        { key: 'hutao', name: 'Hu Tao', role: 'main_dps', min_rank: 0, alternatives: [] },
        { key: '', name: '', role: null, min_rank: 0, alternatives: [] },
      ],
    });
    expect(last().notes).toEqual([
      { en: '', sk: '' },
      { en: '', sk: '' },
    ]);
    expect(document.activeElement).toBe(field('teams[1].name').querySelector('input'));

    pickerAt('teams[1].members[1].key').value.set('zhongli');
    await update();
    expect(last().teams[1].members[1]).toEqual({ key: 'zhongli', name: 'Zhongli', role: null, min_rank: 0, alternatives: [] });
    await choose('teams[1].members[1].role', 'support');
    await choose('teams[1].members[1].min_rank', '2');
    expect(last().teams[1].members[1]).toMatchObject({ role: 'support', min_rank: 2 });

    pickerAt('teams[1].members[1].alternatives').value.set('bennett');
    await update();
    expect(last().teams[1].members[1].alternatives).toEqual([{ key: 'bennett', name: 'Bennett' }]);
    // The stand-ins' picker drawn anew - empty, the focus in it.
    expect(pickerAt('teams[1].members[1].alternatives').value()).toBeNull();
    expect(document.activeElement).toBe(field('teams[1].members[1].alternatives').querySelector('input'));
    host.querySelectorAll<HTMLElement>('.team')[1].querySelector<HTMLButtonElement>('.stand-ins .chosen button')!.click();
    await update();
    expect(last().teams[1].members[1].alternatives).toEqual([]);

    // A member added, last, its picker in hand; a team of four takes no more.
    const second = () => host.querySelectorAll<HTMLElement>('.team')[1];
    second().querySelector<HTMLButtonElement>('button.add-member')!.click();
    await update();
    expect(last().teams[1].members.map((member) => member.key)).toEqual(['hutao', 'zhongli', '']);
    expect(document.activeElement).toBe(field('teams[1].members[2].key').querySelector('input'));
    second().querySelector<HTMLButtonElement>('button.add-member')!.click();
    await update();
    // Four, the most a team has: no more to add.
    expect(second().querySelector('button.add-member')).toBeNull();
  });

  it("moves and removes members - never the build's own character -, the focus on the picker of the one now in its place", async () => {
    await setup([DOUBLE]);
    const members = () => fixture.componentInstance.teams()[0].members;
    const own = rowId(members()[1]);
    // The members side by side: moved left and right.
    expect(host.querySelectorAll<HTMLElement>('.member')[1].querySelector('button.move-up')!.getAttribute('aria-label')).toBe('Move Hu Tao in team 1 (Double Hydro) left');

    host.querySelectorAll<HTMLButtonElement>('.member')[1].querySelector<HTMLButtonElement>('button.move-up')!.click();
    await update();
    expect(last().teams[0].members.map((member) => member.key)).toEqual(['hutao', 'xingqiu', 'yelan']);
    expect(rowId(members()[0])).toBe(own);

    host.querySelectorAll<HTMLElement>('.member')[1].querySelector<HTMLButtonElement>('button.remove')!.click();
    await update();
    expect(last().teams[0].members.map((member) => member.key)).toEqual(['hutao', 'yelan']);
    expect(document.activeElement).toBe(field('teams[0].members[1].key').querySelector('input'));
  });

  it('moves a team with its note; removes one with a note - asked first -, the note then waiting for a team of its members', async () => {
    await setup(
      [VAPORIZE, DOUBLE],
      [
        { en: '', sk: '' },
        { en: 'Yelan first.', sk: '' },
      ],
    );

    host.querySelectorAll<HTMLElement>('.team')[1].querySelector<HTMLButtonElement>('.team-head button.move-up')!.click();
    await update();
    expect(last().teams.map((team) => team.name)).toEqual(['Double Hydro', 'Vaporize']);
    expect(last().notes).toEqual([
      { en: 'Yelan first.', sk: '' },
      { en: '', sk: '' },
    ]);

    host.querySelectorAll<HTMLElement>('.team')[0].querySelector<HTMLButtonElement>('.team-head button.remove')!.click();
    await update();
    expect(ask).toHaveBeenCalledTimes(1);
    expect(last().teams.map((team) => team.name)).toEqual(['Vaporize']);
    expect(last().waiting).toEqual([
      {
        key: 'hutao xingqiu yelan',
        members: [
          { key: 'xingqiu', name: 'Xingqiu' },
          { key: 'hutao', name: 'Hu Tao' },
          { key: 'yelan', name: 'Yelan' },
        ],
        note: { en: 'Yelan first.' },
      },
    ]);
    expect(text(host.querySelector('.waiting-members'))).toBe('Xingqiu, Hu Tao, Yelan');
    expect(document.activeElement).toBe(field('teams[0].name').querySelector('input'));

    // A team with no note goes without a question.
    host.querySelector<HTMLButtonElement>('.team-head button.remove')!.click();
    await update();
    expect(ask).toHaveBeenCalledTimes(1);
    expect(last().teams).toEqual([]);
    expect(document.activeElement).toBe(host.querySelector('button.add-team'));
  });

  it('keeps a team with a note where the question is answered no', async () => {
    await setup([VAPORIZE], [{ en: 'Swirl first.', sk: '' }], [], false);
    host.querySelector<HTMLButtonElement>('.team-head button.remove')!.click();
    await update();
    expect(ask).toHaveBeenCalledTimes(1);
    expect(emitted).toEqual([]);
  });

  it('moves a waiting note to a team that has none, or deletes it', async () => {
    const waiting: WaitingNote = {
      key: 'hutao zhongli',
      members: [
        { key: 'hutao', name: 'Hu Tao' },
        { key: 'zhongli', name: 'Zhongli' },
      ],
      note: { en: 'Shield up.', sk: 'Štít hore.' },
    };
    await setup(
      [VAPORIZE, DOUBLE],
      [
        { en: '', sk: '' },
        { en: 'Yelan first.', sk: '' },
      ],
      [waiting],
    );

    // Only the teams with no note of their own are offered; no team has exactly its members, so none takes it at a save.
    const select = host.querySelector<HTMLSelectElement>('.move-to select')!;
    expect([...select.options].map((option) => option.text)).toEqual(['Choose a team', 'Team 1: Vaporize']);
    expect(host.querySelector<HTMLButtonElement>('button.move-note')!.disabled).toBe(true);
    expect(host.querySelector('.waiting-note[lang="sk"]')).not.toBeNull();
    expect(host.querySelector('.taker')).toBeNull();
    // Each entry a group named by its members; its buttons said with them.
    expect(host.querySelector('.waiting-group')!.getAttribute('aria-label')).toBe('Note waiting for Hu Tao, Zhongli');
    expect(host.querySelector('button.delete-note')!.getAttribute('aria-label')).toBe('Delete the note for Hu Tao, Zhongli');
    select.value = [...select.options].find((option) => option.text === 'Team 1: Vaporize')!.value;
    select.dispatchEvent(new Event('change'));
    await update();
    host.querySelector<HTMLButtonElement>('button.move-note')!.click();
    await update();
    expect(last().notes[0]).toEqual({ en: 'Shield up.', sk: 'Štít hore.' });
    expect(last().waiting).toEqual([]);
    expect(host.querySelector('.waiting')).toBeNull();
    expect(document.activeElement).toBe(host.querySelector('[data-path="notes.teams[0].en"] textarea'));
  });

  it('names the team a save gives a waiting note to - exactly its members, no note of its own -, and keeps a choice with its team', async () => {
    const waiting: WaitingNote = {
      key: 'hutao zhongli',
      members: [
        { key: 'hutao', name: 'Hu Tao' },
        { key: 'zhongli', name: 'Zhongli' },
      ],
      note: { en: 'Shield up.' },
    };
    const shield: EditorTeam = {
      name: 'Shield',
      members: [
        { key: 'hutao', name: 'Hu Tao', role: 'main_dps', min_rank: 0, alternatives: [] },
        { key: 'zhongli', name: 'Zhongli', role: 'support', min_rank: 0, alternatives: [] },
      ],
    };
    await setup([VAPORIZE, shield], undefined, [waiting]);
    expect(text(host.querySelector('.taker'))).toBe('Team 2 (Shield) has exactly these members and no note: saving gives it this one.');

    // Team 2 chosen to move it to; the teams then moved: the choice stays with the Shield team, now the first.
    const select = () => host.querySelector<HTMLSelectElement>('.move-to select')!;
    select().value = [...select().options].find((option) => option.text === 'Team 2: Shield')!.value;
    select().dispatchEvent(new Event('change'));
    await update();
    host.querySelectorAll<HTMLElement>('.team')[1].querySelector<HTMLButtonElement>('.team-head button.move-up')!.click();
    await update();
    expect(select().selectedOptions[0].text).toBe('Team 1: Shield');
    host.querySelector<HTMLButtonElement>('button.move-note')!.click();
    await update();
    expect(last().teams.map((team) => team.name)).toEqual(['Shield', 'Vaporize']);
    expect(last().notes[0]).toEqual({ en: 'Shield up.', sk: '' });
  });

  it('keeps the focus in the stand-ins: on the picker once the last chip goes, on the line saying the most once the fifth comes', async () => {
    const full: EditorTeam = {
      name: 'Full',
      members: [
        VAPORIZE.members[0],
        {
          ...VAPORIZE.members[1],
          alternatives: [
            { key: 'yelan', name: 'Yelan' },
            { key: 'zhongli', name: 'Zhongli' },
            { key: 'bennett', name: 'Bennett' },
          ],
        },
      ],
    };
    await setup([full]);
    fixture.componentRef.setInput('maxStandIns', 4);
    await update();
    // A fourth: the most - the picker gives way to the line saying so, the focus on it.
    host.querySelector<HTMLButtonElement>('.stand-ins .chosen button')!.click();
    await update();
    expect(last().teams[0].members[1].alternatives.map((one) => one.key)).toEqual(['zhongli', 'bennett']);
    fixture.componentRef.setInput('maxStandIns', 3);
    await update();
    pickerAt('teams[0].members[1].alternatives').value.set('yelan');
    await update();
    expect(last().teams[0].members[1].alternatives.map((one) => one.key)).toEqual(['zhongli', 'bennett', 'yelan']);
    expect(document.activeElement).toBe(host.querySelector('.stand-ins .full'));

    // Down to one, then none: the focus on the picker.
    fixture.componentRef.setInput('maxStandIns', 5);
    await update();
    for (let i = 0; i < 3; i++) {
      host.querySelector<HTMLButtonElement>('.stand-ins .chosen button')!.click();
      await update();
    }
    expect(last().teams[0].members[1].alternatives).toEqual([]);
    expect(document.activeElement).toBe(field('teams[0].members[1].alternatives').querySelector('input'));
  });

  it('deletes a waiting note, and says each problem at its field - a team-wide one under its members', async () => {
    const waiting: WaitingNote = {
      key: 'hutao zhongli',
      members: [
        { key: 'hutao', name: 'Hu Tao' },
        { key: 'zhongli', name: 'Zhongli' },
      ],
      note: { en: 'Shield up.' },
    };
    await setup([VAPORIZE], undefined, [waiting]);
    fixture.componentRef.setInput('errors', {
      'teams[0].name': 'Name the team.',
      'teams[0].members[1].key': 'Choose a character here.',
      'teams[0].members': 'Team 2 has the same members.',
      'notes.waiting[0]': 'Keep one of the two.',
    });
    await update();
    expect(text(field('teams[0].name'))).toContain('Name the team.');
    expect(text(field('teams[0].members[1].key'))).toContain('Choose a character here.');
    expect(text(field('teams[0].members'))).toContain('Team 2 has the same members.');
    expect(text(field('notes.waiting[0]'))).toContain('Keep one of the two.');

    host.querySelector<HTMLButtonElement>('button.delete-note')!.click();
    await update();
    expect(last().waiting).toEqual([]);
    expect(pickers().length).toBeGreaterThan(0);
  });
});
