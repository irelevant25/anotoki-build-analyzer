import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideKit } from '../../../../shared/kit';
import { EditorTarget } from '../../../../api/models';
import { TargetsForm, readBound } from './targets-form';

const STATS = [
  { value: 'critRate_', label: 'CRIT Rate' },
  { value: 'eleMas', label: 'Elemental Mastery' },
  { value: 'enerRech_', label: 'Energy Recharge' },
];

describe("the targets' form: a stat's minimum and maximum, from a constellation on", () => {
  let fixture: ComponentFixture<TargetsForm>;
  let host: HTMLElement;
  let emitted: EditorTarget[][];
  let unreadable: string[][];

  const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const field = (path: string) => host.querySelector<HTMLElement>(`[data-path="${path}"]`)!;
  const control = <T extends HTMLElement>(path: string) => field(path).querySelector<T>('input, select')!;
  const last = () => emitted.at(-1)!;

  async function setup(targets: EditorTarget[]): Promise<void> {
    TestBed.configureTestingModule({ providers: [provideTestTranslations({}), provideKit()] });
    fixture = TestBed.createComponent(TargetsForm);
    fixture.componentRef.setInput('targets', targets);
    fixture.componentRef.setInput('stats', STATS);
    emitted = [];
    unreadable = [];
    fixture.componentInstance.targetsChange.subscribe((next) => {
      emitted.push(next);
      fixture.componentRef.setInput('targets', next);
    });
    fixture.componentInstance.unreadableChange.subscribe((places) => unreadable.push(places));
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  async function type(path: string, words: string): Promise<void> {
    const input = control<HTMLInputElement>(path);
    input.value = words;
    input.dispatchEvent(new Event('input'));
    await fixture.whenStable();
  }

  async function leave(path: string): Promise<void> {
    control<HTMLInputElement>(path).dispatchEvent(new Event('blur'));
    await fixture.whenStable();
  }

  it("reads a bound as a number - a comma for the point too, a percent sign after it the field's own -, none where it is empty, and no number otherwise", () => {
    expect([readBound('70'), readBound(' 120.5 '), readBound('120,5'), readBound(''), readBound('-3')]).toEqual([70, 120.5, 120.5, null, -3]);
    expect([readBound('75%'), readBound('75 %'), readBound(' 62,4% ')]).toEqual([75, 75, 62.4]);
    expect([readBound('abc'), readBound('7e'), readBound('1.2.3'), readBound('%'), readBound('%75')]).toEqual([undefined, undefined, undefined, null, undefined]);
  });

  it('shows each target - its stat, where it holds from, its bounds, a percent stat with its "%" -', async () => {
    await setup([
      { stat: 'critRate_', min: 70, max: null, from_rank: 0 },
      { stat: 'eleMas', min: 200, max: 300, from_rank: 2 },
    ]);

    expect([control<HTMLSelectElement>('stat_targets[0].stat').value, control<HTMLSelectElement>('stat_targets[1].from_rank').value]).toEqual(['critRate_', '2']);
    expect([...control<HTMLSelectElement>('stat_targets[1].from_rank').options].map((option) => option.text)).toEqual([
      'From the start',
      'From C1',
      'From C2',
      'From C3',
      'From C4',
      'From C5',
      'From C6',
    ]);
    expect(['stat_targets[0].min', 'stat_targets[0].max', 'stat_targets[1].min', 'stat_targets[1].max'].map((path) => control<HTMLInputElement>(path).value)).toEqual(['70', '', '200', '300']);
    expect(text(field('stat_targets[0].min'))).toContain('%');
    expect(text(field('stat_targets[1].min'))).not.toContain('%');
    // The "%" beside a percent stat's field said in its name too; each target a group of its own, by its place.
    expect(field('stat_targets[0].min').querySelector('label')!.textContent?.trim()).toBe('Minimum (%)');
    expect(field('stat_targets[1].max').querySelector('label')!.textContent?.trim()).toBe('Maximum');
    // The list's items stay its items: the group inside each.
    expect([...host.querySelectorAll('ol > li')].map((item) => item.getAttribute('role'))).toEqual([null, null]);
    expect([...host.querySelectorAll('.target-row')].map((row) => [row.getAttribute('role'), row.getAttribute('aria-label')])).toEqual([
      ['group', 'Target 1: CRIT Rate'],
      ['group', 'Target 2: Elemental Mastery, from C2'],
    ]);
    expect(host.querySelectorAll('.target-row')[1].querySelector('button.remove')!.getAttribute('aria-label')).toBe('Remove target 2 (Elemental Mastery from C2)');
    // A form drawn anew tells the page it holds nothing typed that is no number.
    expect(unreadable).toEqual([[]]);
  });

  it('takes a bound as soon as it is a number, keeps what is typed while it is none - said wrong, and to the page -, and takes it once fixed', async () => {
    await setup([{ stat: 'critRate_', min: 70, max: null, from_rank: 0 }]);

    await type('stat_targets[0].max', '80.5');
    expect(last()).toEqual([{ stat: 'critRate_', min: 70, max: 80.5, from_rank: 0 }]);

    const before = emitted.length;
    const wrong = 'Write a number, such as 70 or 120.5 - or leave it empty.';
    await type('stat_targets[0].min', '7x');
    expect(emitted).toHaveLength(before);
    expect(control<HTMLInputElement>('stat_targets[0].min').value).toBe('7x');
    // Told to the page at once; said at the field once it is left - never while typing on the way to a number.
    expect(unreadable.at(-1)).toEqual(['stat_targets[0].min']);
    expect(text(field('stat_targets[0].min'))).not.toContain(wrong);
    await leave('stat_targets[0].min');
    expect(text(field('stat_targets[0].min'))).toContain(wrong);
    await type('stat_targets[0].min', '7xy');
    expect(text(field('stat_targets[0].min'))).not.toContain(wrong);

    await type('stat_targets[0].min', '75%');
    expect(last()).toEqual([{ stat: 'critRate_', min: 75, max: 80.5, from_rank: 0 }]);
    expect(unreadable.at(-1)).toEqual([]);
    expect(control<HTMLInputElement>('stat_targets[0].min').value).toBe('75%');

    await type('stat_targets[0].max', '');
    expect(last()[0].max).toBeNull();
  });

  it("tells the page again for another build's targets - the page keeps them by build", async () => {
    await setup([{ stat: 'critRate_', min: 70, max: null, from_rank: 0 }]);
    await type('stat_targets[0].min', '7x');
    expect(unreadable.at(-1)).toEqual(['stat_targets[0].min']);

    // Another build open: its targets have nothing typed.
    fixture.componentRef.setInput('targets', [{ stat: 'eleMas', min: 200, max: null, from_rank: 0 }]);
    await fixture.whenStable();
    expect(unreadable.at(-1)).toEqual([]);
    expect(control<HTMLInputElement>('stat_targets[0].min').value).toBe('200');
  });

  it('changes a stat and where it holds from, adds the first stat with no target from the start, moves and removes one', async () => {
    await setup([{ stat: 'critRate_', min: 70, max: null, from_rank: 0 }]);

    const from = control<HTMLSelectElement>('stat_targets[0].from_rank');
    from.value = '1';
    from.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    expect(last()[0].from_rank).toBe(1);

    host.querySelector<HTMLButtonElement>('button.add-target')!.click();
    await fixture.whenStable();
    expect(last()[1]).toEqual({ stat: 'critRate_', min: null, max: null, from_rank: 0 });
    expect(document.activeElement).toBe(control('stat_targets[1].stat'));

    const stat = control<HTMLSelectElement>('stat_targets[1].stat');
    stat.value = 'enerRech_';
    stat.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    expect(last()[1].stat).toBe('enerRech_');

    host.querySelectorAll<HTMLButtonElement>('button.move-up')[1].click();
    await fixture.whenStable();
    expect(last().map((target) => target.stat)).toEqual(['enerRech_', 'critRate_']);

    host.querySelectorAll<HTMLButtonElement>('button.remove')[0].click();
    await fixture.whenStable();
    expect(last().map((target) => target.stat)).toEqual(['critRate_']);
  });
});
