import { WritableSignal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ComboboxComponent } from '@anotoki/lib/ui/combobox';
import { provideKit } from '../../../../core/kit';
import { EditorCatalogWeapon, EditorWeapon } from '../../../../core/models';
import { rowId } from '../editor-draft';
import { WeaponsForm } from './weapons-form';

const CATALOG: EditorCatalogWeapon[] = [
  { key: 'deathmatch', name: 'Deathmatch', weapon_type: 'polearm', rarity: 4, icon_url: '/icons/deathmatch.png' },
  { key: 'dragonsbane', name: "Dragon's Bane", weapon_type: 'polearm', rarity: 4, icon_url: null },
  { key: 'mistsplitter', name: 'Mistsplitter Reforged', weapon_type: 'sword', rarity: 5, icon_url: null },
  { key: 'staffofhoma', name: 'Staff of Homa', weapon_type: 'polearm', rarity: 5, icon_url: '/icons/homa.png' },
];

describe("the weapons' form: a build's weapons picked by name, refined, moved and removed", () => {
  let fixture: ComponentFixture<WeaponsForm>;
  let host: HTMLElement;
  let emitted: EditorWeapon[][];

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const pickers = () => fixture.debugElement.queryAll(By.directive(ComboboxComponent)).map((found) => found.componentInstance as ComboboxComponent);
  const field = (path: string) => host.querySelector<HTMLElement>(`[data-path="${path}"]`)!;

  async function setup(weapons: EditorWeapon[], weaponType: string | null = 'polearm'): Promise<void> {
    TestBed.configureTestingModule({ providers: [provideTestTranslations({}), provideKit()] });
    fixture = TestBed.createComponent(WeaponsForm);
    fixture.componentRef.setInput('weapons', weapons);
    fixture.componentRef.setInput('catalog', CATALOG);
    fixture.componentRef.setInput('weaponType', weaponType);
    emitted = [];
    // The page keeps the list: what comes out goes back in.
    fixture.componentInstance.weaponsChange.subscribe((next) => {
      emitted.push(next);
      fixture.componentRef.setInput('weapons', next);
    });
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  it("shows each weapon by its name and refinement - one the catalog lacks by its stored name, marked - and offers the character's type", async () => {
    await setup([
      { key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 },
      { key: 'whiteflamebow', name: 'White Flame Bow', refinement: null },
    ]);

    expect([...host.querySelectorAll<HTMLInputElement>('.pick input')].map((input) => input.value)).toEqual(['Staff of Homa', 'White Flame Bow (not in the catalog)']);
    expect([...host.querySelectorAll<HTMLSelectElement>('.refinement select')].map((select) => select.value)).toEqual(['1', '']);
    expect([...host.querySelectorAll<HTMLSelectElement>('.refinement select')][0].options[0].text).toBe('Any refinement');
    expect(text(host.querySelector('.unknown'))).toBe('Not in the catalog');
    expect([...host.querySelectorAll('.rank')].map(text)).toEqual(['1', '2']);
    // A row offers the character's type, but the weapon another row has.
    expect(
      pickers()[0]
        .options()
        .map((option) => option.value),
    ).toEqual(['deathmatch', 'dragonsbane', 'staffofhoma']);
    expect(
      pickers()[1]
        .options()
        .map((option) => option.value),
    ).toEqual(['whiteflamebow', 'deathmatch', 'dragonsbane']);

    // Every type, asked for: the swords too.
    host.querySelector<HTMLInputElement>('.every-type input')!.click();
    await fixture.whenStable();
    expect(
      pickers()[1]
        .options()
        .map((option) => option.value),
    ).toEqual(['whiteflamebow', 'deathmatch', 'dragonsbane', 'mistsplitter']);
  });

  it("picks a weapon by the catalog's name, keeping the row; refines it; empties a row whose field is emptied", async () => {
    await setup([{ key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 }]);
    const row = rowId(fixture.componentInstance.weapons()[0]);

    pickers()[0].value.set('deathmatch');
    await fixture.whenStable();
    expect(emitted.at(-1)).toEqual([{ key: 'deathmatch', name: 'Deathmatch', refinement: 1 }]);
    expect(rowId(emitted.at(-1)![0])).toBe(row);

    const refinement = host.querySelector<HTMLSelectElement>('.refinement select')!;
    refinement.value = '5';
    refinement.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    expect(emitted.at(-1)).toEqual([{ key: 'deathmatch', name: 'Deathmatch', refinement: 5 }]);
    refinement.value = '';
    refinement.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    expect(emitted.at(-1)![0].refinement).toBeNull();

    pickers()[0].value.set(null);
    await fixture.whenStable();
    expect(emitted.at(-1)).toEqual([{ key: '', name: '', refinement: null }]);
  });

  it('keeps what is typed in a picker while another row changes - its options the very ones, a weapon the catalog lacks too', async () => {
    await setup([
      { key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 },
      { key: 'whiteflamebow', name: 'White Flame Bow', refinement: null },
    ]);
    // Typed in the second row's picker, nothing chosen yet (the kit's field keeps it until its option changes).
    const typed = (i: number) => (pickers()[i] as unknown as { text: WritableSignal<string> }).text;
    typed(1).set('Drag');
    await fixture.whenStable();

    const refinement = host.querySelector<HTMLSelectElement>('.refinement select')!;
    refinement.value = '5';
    refinement.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    expect(emitted.at(-1)![0].refinement).toBe(5);
    expect(typed(1)()).toBe('Drag');

    host.querySelector<HTMLInputElement>('.every-type input')!.click();
    await fixture.whenStable();
    expect(typed(1)()).toBe('Drag');
  });

  it('adds an empty row with its picker in hand, moves a weapon, removes one with the focus kept in the list', async () => {
    await setup([
      { key: 'staffofhoma', name: 'Staff of Homa', refinement: 1 },
      { key: 'deathmatch', name: 'Deathmatch', refinement: null },
    ]);

    host.querySelector<HTMLButtonElement>('button.add-weapon')!.click();
    await fixture.whenStable();
    expect(emitted.at(-1)!.map((weapon) => weapon.key)).toEqual(['staffofhoma', 'deathmatch', '']);
    expect(document.activeElement).toBe(host.querySelectorAll<HTMLInputElement>('.pick input')[2]);

    host.querySelectorAll<HTMLButtonElement>('button.move-down')[0].click();
    await fixture.whenStable();
    expect(emitted.at(-1)!.map((weapon) => weapon.key)).toEqual(['deathmatch', 'staffofhoma', '']);

    host.querySelectorAll<HTMLButtonElement>('button.remove')[0].click();
    await fixture.whenStable();
    expect(emitted.at(-1)!.map((weapon) => weapon.key)).toEqual(['staffofhoma', '']);
    expect(document.activeElement).toBe(host.querySelectorAll<HTMLInputElement>('.pick input')[0]);
  });

  it("says a problem at its field, and offers every type where the character's is not known", async () => {
    await setup([{ key: '', name: '', refinement: null }], null);
    fixture.componentRef.setInput('errors', { 'weapons[0].key': 'Choose a weapon here, or remove the row.' });
    await fixture.whenStable();
    expect(text(field('weapons[0].key'))).toContain('Choose a weapon here, or remove the row.');
    expect(host.querySelector('.every-type')).toBeNull();
    expect(pickers()[0].options()).toHaveLength(4);
  });
});
