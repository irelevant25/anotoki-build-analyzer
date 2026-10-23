import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideKit } from '../../../../core/kit';
import { EditorAnnouncer } from '../editor-announcer';
import { rowId } from '../editor-draft';
import { Ranked, RankedList } from './ranked-list';

const CHOICES = [
  { value: 'critRate_', label: 'CRIT Rate' },
  { value: 'critDMG_', label: 'CRIT DMG' },
  { value: 'atk_', label: 'ATK%' },
];

describe('a ranked list: stats or talents, each with its priority - a number, equal ones a tie', () => {
  let fixture: ComponentFixture<RankedList>;
  let host: HTMLElement;
  let emitted: Ranked[][];

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const choices = () => [...host.querySelectorAll<HTMLSelectElement>('.choice select')];
  const priorities = () => [...host.querySelectorAll<HTMLSelectElement>('.priority select')];
  const last = () => emitted.at(-1)!;

  async function setup(rows: Ranked[], fields: { field?: 'stat' | 'talent'; showLabel?: boolean } = {}): Promise<void> {
    TestBed.configureTestingModule({ providers: [provideTestTranslations({}), provideKit()] });
    fixture = TestBed.createComponent(RankedList);
    fixture.componentRef.setInput('label', 'Circlet of Logos');
    fixture.componentRef.setInput('noun', fields.field === 'talent' ? 'talent' : 'main stat');
    fixture.componentRef.setInput('rows', rows);
    fixture.componentRef.setInput('choices', CHOICES);
    fixture.componentRef.setInput('path', 'main_stats.circlet');
    fixture.componentRef.setInput('field', fields.field ?? 'stat');
    fixture.componentRef.setInput('showLabel', fields.showLabel ?? true);
    emitted = [];
    fixture.componentInstance.rowsChange.subscribe((next) => {
      emitted.push(next);
      fixture.componentRef.setInput('rows', next);
    });
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  it('shows each row - its choice among those no other row has, its priority - at its place, under its name', async () => {
    await setup([
      { stat: 'critRate_', priority: 1 },
      { stat: 'critDMG_', priority: 1 },
    ]);

    expect(text(host.querySelector('.ranked-label'))).toBe('Circlet of Logos');
    expect(host.querySelector('[role="group"]')!.getAttribute('aria-labelledby')).toBe(host.querySelector('.ranked-label')!.id);
    expect(choices().map((select) => select.value)).toEqual(['critRate_', 'critDMG_']);
    expect([...choices()[0].options].map((option) => option.value)).toEqual(['critRate_', 'atk_']);
    expect(priorities().map((select) => select.value)).toEqual(['1', '1']);
    // A priority's number under its column's name - for the eye; each field's own name says it to a screen reader.
    expect([...priorities()[0].options].map((option) => option.text)).toEqual(Array.from({ length: 10 }, (_, i) => `${i + 1}`));
    expect([...host.querySelectorAll('.row-head span')].map(text)).toEqual(['Main stat', 'Priority']);
    expect(host.querySelector('.row-head')!.getAttribute('aria-hidden')).toBe('true');
    expect(host.querySelectorAll('.priority label')[1].textContent?.trim()).toBe('Priority of CRIT DMG');
    expect([...host.querySelectorAll('[data-path]')].map((place) => place.getAttribute('data-path'))).toEqual([
      'main_stats.circlet[0].stat',
      'main_stats.circlet[0].priority',
      'main_stats.circlet[1].stat',
      'main_stats.circlet[1].priority',
    ]);
    expect([...host.querySelectorAll('button.remove')].map((button) => button.getAttribute('aria-label'))).toEqual(['Remove CRIT Rate', 'Remove CRIT DMG']);
  });

  it("changes a row's choice in place, and moves a row whose priority changes to its place, the focus with it", async () => {
    await setup([
      { stat: 'critRate_', priority: 1 },
      { stat: 'critDMG_', priority: 2 },
      { stat: 'atk_', priority: 3 },
    ]);
    const first = rowId(fixture.componentInstance.rows()[0]);
    const announcer = TestBed.inject(EditorAnnouncer);

    priorities()[0].value = '3';
    priorities()[0].dispatchEvent(new Event('change'));
    await fixture.whenStable();
    expect(last()).toEqual([
      { stat: 'critDMG_', priority: 2 },
      { stat: 'critRate_', priority: 3 },
      { stat: 'atk_', priority: 3 },
    ]);
    expect(rowId(last()[1])).toBe(first);
    expect(document.activeElement).toBe(priorities()[1]);
    // The row moved where the eye sees it: said to a screen reader too.
    await vi.waitFor(() => expect(announcer.message()).toBe('CRIT Rate, priority 3: now 2 of 3.'));

    choices()[2].value = 'critRate_';
    // CRIT Rate is the second row's: not offered to the third.
    expect([...choices()[2].options].map((option) => option.value)).toEqual(['atk_']);
  });

  it('adds the next choice after the last at the next priority, until none is left; removes a row, the focus kept in the list', async () => {
    await setup([{ stat: 'critRate_', priority: 1 }]);

    host.querySelector<HTMLButtonElement>('button.add')!.click();
    await fixture.whenStable();
    expect(last()).toEqual([
      { stat: 'critRate_', priority: 1 },
      { stat: 'critDMG_', priority: 2 },
    ]);
    expect(document.activeElement).toBe(choices()[1]);
    host.querySelector<HTMLButtonElement>('button.add')!.click();
    await fixture.whenStable();
    expect(host.querySelector<HTMLButtonElement>('button.add')!.disabled).toBe(true);

    host.querySelectorAll<HTMLButtonElement>('button.remove')[0].click();
    await fixture.whenStable();
    expect(last().map((row) => ('stat' in row ? row.stat : row.talent))).toEqual(['critDMG_', 'atk_']);
    expect(document.activeElement).toBe(choices()[0]);
  });

  it('says what an empty list means, ranks talents, and names itself to a screen reader alone where the card says it', async () => {
    await setup([], { field: 'talent', showLabel: false });
    expect(text(host.querySelector('.none'))).toBe('None listed.');
    expect(host.querySelector('.ranked-label')).toBeNull();
    expect(host.querySelector('[role="group"]')!.getAttribute('aria-label')).toBe('Circlet of Logos');
    expect(host.querySelector('.row-head')).toBeNull();

    host.querySelector<HTMLButtonElement>('button.add')!.click();
    await fixture.whenStable();
    expect(last()).toEqual([{ talent: 'critRate_', priority: 1 }]);
    expect(text(host.querySelector('button.add'))).toBe('Add talent');
    expect([...host.querySelectorAll('.row-head span')].map(text)).toEqual(['Talent', 'Priority']);
  });
});
