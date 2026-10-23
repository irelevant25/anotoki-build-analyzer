import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ComboboxComponent } from '@anotoki/lib/ui/combobox';
import { provideKit } from '../../../../core/kit';
import { EditorCatalogSet, EditorLimits, EditorSetOption } from '../../../../core/models';
import { rowId } from '../editor-draft';
import { SetsForm } from './sets-form';

const CATALOG: EditorCatalogSet[] = [
  { key: 'crimsonwitchofflames', name: 'Crimson Witch of Flames', kind: 'artifact', icon_url: '/icons/cw.png' },
  { key: 'gladiatorsfinale', name: "Gladiator's Finale", kind: 'artifact', icon_url: null },
  { key: 'shimenawasreminiscence', name: "Shimenawa's Reminiscence", kind: 'artifact', icon_url: null },
];

const LIMITS = { sets: 50, pieces: 30, set_keys: 100, name: 1000 } as EditorLimits;

const CRIMSON: EditorSetOption = {
  kind: 'artifact',
  choose: null,
  pieces: [{ label: 'Crimson Witch of Flames', count: 4, keys: ['crimsonwitchofflames'], names: ['Crimson Witch of Flames'] }],
};

const MIXED: EditorSetOption = {
  kind: 'artifact',
  choose: 1,
  pieces: [
    { label: 'Crimson Witch of Flames', count: 2, keys: ['crimsonwitchofflames'], names: ['Crimson Witch of Flames'] },
    { label: 'any 18% ATK set', count: 2, keys: ['gladiatorsfinale', 'shimenawasreminiscence'], names: ["Gladiator's Finale", "Shimenawa's Reminiscence"] },
  ],
};

describe("the sets' form: a build's options of set pieces, each piece's sets picked by name", () => {
  let fixture: ComponentFixture<SetsForm>;
  let host: HTMLElement;
  let emitted: EditorSetOption[][];

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const texts = (selector: string) => [...host.querySelectorAll(selector)].map(text);
  const pickers = () => fixture.debugElement.queryAll(By.directive(ComboboxComponent)).map((found) => found.componentInstance as ComboboxComponent);
  const last = () => emitted.at(-1)!;

  async function setup(sets: EditorSetOption[]): Promise<void> {
    TestBed.configureTestingModule({ providers: [provideTestTranslations({}), provideKit()] });
    fixture = TestBed.createComponent(SetsForm);
    fixture.componentRef.setInput('sets', sets);
    fixture.componentRef.setInput('catalog', CATALOG);
    fixture.componentRef.setInput('kinds', { artifact: [2, 4] });
    fixture.componentRef.setInput('limits', LIMITS);
    emitted = [];
    fixture.componentInstance.setsChange.subscribe((next) => {
      emitted.push(next);
      fixture.componentRef.setInput('sets', next);
    });
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  async function settle(): Promise<void> {
    await fixture.whenStable();
  }

  it("shows each option's pieces - a count, the sets as chips, a label where a piece takes several - and how many to wear", async () => {
    await setup([CRIMSON, MIXED]);

    expect(texts('.option-title')).toEqual(['Option 1', 'Option 2']);
    expect(host.querySelectorAll('.option:nth-child(1) .piece')).toHaveLength(1);
    // The game's notation on the count; the kind not shown, the game having one.
    expect(texts('.option:nth-child(2) .count [role="radio"][aria-checked="true"]')).toEqual(['2pc', '2pc']);
    expect(host.querySelector('.kind')).toBeNull();
    expect(texts('.option:nth-child(2) .piece:nth-child(2) .anotoki-chip-list__label')).toEqual(["Gladiator's Finale", "Shimenawa's Reminiscence"]);
    // A label only where a piece takes several sets; "wear" only where an option has several pieces.
    expect(host.querySelectorAll('.option:nth-child(1) .label')).toHaveLength(0);
    expect(host.querySelector<HTMLInputElement>('.option:nth-child(2) .label input')!.value).toBe('any 18% ATK set');
    expect(host.querySelector('.option:nth-child(1) .choose')).toBeNull();
    const wear = host.querySelector<HTMLSelectElement>('.option:nth-child(2) .choose select')!;
    expect([wear.value, [...wear.options].map((option) => option.text)]).toEqual(['1', ['All 2 of them', 'Any 1 of them']]);
    // A piece's picker offers the sets of its kind it has not.
    expect(
      pickers()[0]
        .options()
        .map((option) => option.value),
    ).toEqual(['gladiatorsfinale', 'shimenawasreminiscence']);
  });

  it('adds a set to a piece by its name - labelled by it alone, asking for a label once it takes two - and takes one off', async () => {
    await setup([CRIMSON]);
    const option = rowId(fixture.componentInstance.sets()[0]);

    pickers()[0].value.set('gladiatorsfinale');
    await settle();
    expect(last()[0].pieces[0]).toEqual({ label: '', count: 4, keys: ['crimsonwitchofflames', 'gladiatorsfinale'], names: ['Crimson Witch of Flames', "Gladiator's Finale"] });
    expect(rowId(last()[0])).toBe(option);
    // The picker is emptied for the next one - drawn anew, its field empty, the focus in it.
    expect(pickers()[0].value()).toBeNull();
    expect(host.querySelector<HTMLInputElement>('.add-set input')!.value).toBe('');
    expect(document.activeElement).toBe(host.querySelector('.add-set input'));
    expect(host.querySelector<HTMLInputElement>('.label input')!.value).toBe('');

    host.querySelector<HTMLInputElement>('.label input')!.value = 'any set';
    host.querySelector<HTMLInputElement>('.label input')!.dispatchEvent(new Event('input'));
    await settle();
    expect(last()[0].pieces[0].label).toBe('any set');

    // Back to one set: labelled with its name.
    host.querySelectorAll<HTMLButtonElement>('.chosen button')[0].click();
    await settle();
    expect(last()[0].pieces[0]).toEqual({ label: "Gladiator's Finale", count: 4, keys: ['gladiatorsfinale'], names: ["Gladiator's Finale"] });
  });

  it('changes a count, adds a piece - "wear" offered then -, removes one with "wear" kept below the pieces left', async () => {
    await setup([MIXED]);

    host.querySelectorAll<HTMLButtonElement>('.piece:nth-child(1) .count [role="radio"]')[1].click();
    await settle();
    expect(last()[0].pieces[0].count).toBe(4);

    host.querySelector<HTMLButtonElement>('button.add-piece')!.click();
    await settle();
    expect(last()[0].pieces.map((piece) => [piece.count, piece.keys.length])).toEqual([
      [4, 1],
      [2, 2],
      [2, 0],
    ]);
    expect(document.activeElement).toBe(host.querySelectorAll<HTMLInputElement>('.add-set input')[2]);

    const wear = host.querySelector<HTMLSelectElement>('.choose select')!;
    wear.value = '2';
    wear.dispatchEvent(new Event('change'));
    await settle();
    expect(last()[0].choose).toBe(2);

    host.querySelectorAll<HTMLButtonElement>('.piece button.remove')[2].click();
    await settle();
    expect(last()[0].pieces).toHaveLength(2);
    expect(last()[0].choose).toBeNull();
    // The focus on the picker of the piece before it, the last one now.
    expect(document.activeElement).toBe(host.querySelectorAll('.piece')[1].querySelector('.add-set input'));
    // An option keeps one piece at least: its last has no remove.
    expect(host.querySelectorAll('.piece button.remove')).toHaveLength(2);
  });

  it('adds an option of one 4pc piece with its picker in hand, moves options and removes one', async () => {
    await setup([CRIMSON]);

    host.querySelector<HTMLButtonElement>('button.add-option')!.click();
    await settle();
    expect(last()).toHaveLength(2);
    expect(last()[1]).toEqual({ kind: 'artifact', choose: null, pieces: [{ label: '', count: 4, keys: [], names: [] }] });
    expect(document.activeElement).toBe(host.querySelectorAll<HTMLInputElement>('.add-set input')[1]);

    host.querySelectorAll<HTMLButtonElement>('.option-head button.move-up')[1].click();
    await settle();
    expect(last()[1]).toBe(CRIMSON);

    host.querySelectorAll<HTMLButtonElement>('.option-head button.remove')[0].click();
    await settle();
    expect(last()).toEqual([CRIMSON]);
    // The focus on the picker of the option now in its place - never its Remove, which a second press would use.
    expect(document.activeElement).toBe(host.querySelector('.option .add-set input'));
  });

  it('says a problem at its field', async () => {
    await setup([{ kind: 'artifact', choose: null, pieces: [{ label: '', count: 4, keys: [], names: [] }] }]);
    fixture.componentRef.setInput('errors', { 'sets[0].pieces[0].keys': 'Choose a set for this piece, or remove it.' });
    await settle();
    expect(text(host.querySelector('[data-path="sets[0].pieces[0].keys"]'))).toContain('Choose a set for this piece, or remove it.');
  });
});
