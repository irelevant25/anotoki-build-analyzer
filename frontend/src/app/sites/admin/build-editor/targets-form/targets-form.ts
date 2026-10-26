import { Component, ElementRef, Injector, afterNextRender, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CheckboxComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SelectComponent, SelectOption } from '@anotoki/lib/ui/select';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { EditorTarget, StatTarget, TARGET_TIERS, TargetTier } from '../../../../api/models';
import { EditorAnnouncer } from '../editor-announcer';
import { replaced, rowId } from '../editor-draft';
import { MoveButtons } from '../move-buttons/move-buttons';

type Bound = 'min' | 'max';

/** A field of a target that takes a number: a requirement's bound, or a tier set by hand. */
type Numeric = Bound | TargetTier;

/** A requirement as the form lists it: the stored entry and its place among every target. */
interface RequirementRow {
  target: EditorTarget;
  index: number;
}

/**
 * A row of the tiered targets: one the site works out (Auto - nothing stored), one set by hand (a stored entry with
 * its tiers) or a stat switched off (a stored entry with `off`).
 */
export interface TierRow {
  kind: 'auto' | 'hand' | 'off';
  stat: string;
  /** The stored entry's place among every target; null for one worked out. */
  index: number | null;
  from_rank: number;
  /** The three tiers shown: the hand-set ones, or the ones worked out. */
  tiers: Record<TargetTier, number | null>;
  /** What the site works out for the stat - what Auto gives -, where it does. */
  computed: Record<TargetTier, number> | null;
  /** A requirement for the stat holds from the start: no second target of it from there - Auto and Switch off wait until that one goes. */
  locked: boolean;
}

/** The flat stat a percent substat moves - HP% the sheet's HP -, as the pages key the row: `hp_` → `hp`. */
function flatOf(stat: string): string {
  return ({ hp_: 'hp', atk_: 'atk', def_: 'def' } as Record<string, string>)[stat] ?? stat;
}

/** A control that can take the focus: never a disabled one, whose focus() does nothing. */
const FOCUSABLE = 'select:not(:disabled), input:not(:disabled), button:not(:disabled)';

/** What a bound's field holds as a number - a percent sign after it taken as the field's own -: null where it is empty; undefined where it is no number (yet). */
export function readBound(text: string): number | null | undefined {
  const trimmed = text.trim().replace(/\s*%$/, '').replace(',', '.');
  if (trimmed === '') {
    return null;
  }
  const value = Number(trimmed);
  return Number.isFinite(value) ? value : undefined;
}

/**
 * A build's stat targets in the builds editor, in two lists. The **requirements**: each row the stat, the constellation
 * it holds from ("From the start", "From C1"…), its minimum and its maximum - numbers typed, a percent stat's with its
 * "%" (in its name too, and taken where it is typed) - and the move buttons; Add requirement adds one. A target keeps
 * its numbers alone (the text a file may carry is shown nowhere). The **tiered targets** (the editor's round of
 * 2026-10-26): what the site works out for the build's substats - a basic, an intermediate and an advanced tier for its
 * first weapon, as last saved (`computed`) - each a row with Auto ticked and its three values read-only; Auto unticked
 * makes the row a target set by hand (a stored entry with the three values, typed as the bounds are; the same for every
 * weapon then) and ticked again lets the site work it out; Remove switches the stat off (a stored entry: nothing is
 * worked out or shown for it; Switch on undoes it) - or takes a hand-set stat with nothing worked out behind it off the
 * list -; Add tiered target adds a hand-set row for a stat with none, its three values to type. What is typed in a
 * number's field stays as typed while it is not a number yet - said wrong at the field once the field is left, and named
 * to the page at once (`unreadableChange`, which holds the save back meanwhile) -, and becomes the target's number as
 * soon as it is one. Every change comes out as the whole list of stored targets (`targetsChange`), an edited row the
 * same row (rowId()). English, as the admin panel.
 */
@Component({
  selector: 'app-targets-form',
  imports: [ButtonComponent, CheckboxComponent, IconComponent, SelectComponent, TextFieldComponent, TooltipDirective, MoveButtons],
  templateUrl: './targets-form.html',
  styleUrl: './targets-form.scss',
})
export class TargetsForm {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  private readonly announcer = inject(EditorAnnouncer);

  readonly targets = input.required<readonly EditorTarget[]>();
  /** The targets the site works out for the build as loaded - what Auto gives each stat -; null while the catalog lacks the stats. */
  readonly computed = input<readonly StatTarget[] | null>(null);
  /** The stats a target can be of, with their names. */
  readonly stats = input.required<readonly SelectOption[]>();
  readonly maxRank = input(6);
  readonly rankPrefix = input('C');
  readonly maxTargets = input(30);
  /** The problems shown at the fields, by place ("stat_targets[0].min", "stat_targets[2].tiers.basic"). */
  readonly errors = input<Record<string, string>>({});

  readonly targetsChange = output<EditorTarget[]>();
  /** The places of the numbers typed that are no number yet ("stat_targets[1].max"), whenever they change. */
  readonly unreadableChange = output<string[]>();

  protected readonly rowId = rowId;
  protected readonly tierNames = TARGET_TIERS;

  /** What is typed in a number's field, by its row and field ("row7 min", "row9 basic"). */
  private readonly typed = signal<ReadonlyMap<string, string>>(new Map());
  /** The fields left since something was typed in them, by row and field. */
  private readonly left = signal<ReadonlySet<string>>(new Set());

  protected readonly fromOptions = computed<SelectOption[]>(() => [
    { value: '0', label: 'From the start' },
    ...Array.from({ length: this.maxRank() }, (_, i) => ({ value: String(i + 1), label: `From ${this.rankPrefix()}${i + 1}` })),
  ]);

  private readonly names = computed(() => new Map(this.stats().map((stat) => [stat.value, stat.label])));

  /** The requirements: the stored targets with bounds, each with its place among every target. */
  protected readonly requirements = computed<RequirementRow[]>(() =>
    this.targets()
      .map((target, index) => ({ target, index }))
      .filter(({ target }) => !target.tiers && target.off !== true),
  );

  /**
   * The tiered targets in the order the pages show them: each stat the site works out - set by hand or switched off
   * where a stored entry says so, else Auto -, then the stats set by hand that it works nothing out for, as stored.
   */
  protected readonly tierRows = computed<TierRow[]>(() => {
    const worked = new Map<string, Record<TargetTier, number>>();
    for (const row of this.computed() ?? []) {
      if (row.tiers) {
        worked.set(row.stat, row.tiers);
      }
    }
    // The stored entries by the stat the pages key the row with (HP% set by hand stands where the sheet's HP is
    // worked out, as TargetTiers reads it); the requirements from the start, which no second target of a stat may join.
    const stored = new Map<string, RequirementRow[]>();
    const required = new Set<string>();
    this.targets().forEach((target, index) => {
      if (target.tiers || target.off === true) {
        stored.set(flatOf(target.stat), [...(stored.get(flatOf(target.stat)) ?? []), { target, index }]);
      } else if (target.from_rank === 0) {
        required.add(flatOf(target.stat));
      }
    });
    const rowOf = ({ target, index }: RequirementRow): TierRow => ({
      kind: target.off === true ? 'off' : 'hand',
      stat: target.stat,
      index,
      from_rank: target.from_rank,
      tiers: { basic: null, intermediate: null, advanced: null, ...(target.off === true ? {} : target.tiers) },
      computed: worked.get(flatOf(target.stat)) ?? null,
      locked: false,
    });
    const rows: TierRow[] = [];
    for (const [stat, tiers] of worked) {
      const own = stored.get(stat);
      rows.push(...(own ? own.map(rowOf) : [{ kind: 'auto' as const, stat, index: null, from_rank: 0, tiers, computed: tiers, locked: required.has(stat) }]));
    }
    for (const [stat, own] of stored) {
      if (!worked.has(stat)) {
        rows.push(...own.map(rowOf));
      }
    }
    return rows;
  });

  /** Why Auto cannot be unticked on a row, or ticked: said under the box; null where it can. */
  protected autoHint(row: TierRow): string | null {
    if (row.locked) {
      return 'A requirement for this stat holds from the start: remove it to set the tiers by hand, or to switch the stat off.';
    }
    if (row.computed !== null) {
      return null;
    }
    return this.computed() === null
      ? 'Nothing is worked out yet: the catalog has no stats for this character or its weapons, or the build is not saved yet.'
      : 'Nothing is worked out for this stat: no substat of the build rolls it.';
  }

  /** The numbers typed that are no number, by place. */
  private readonly unreadable = computed(() => {
    const typed = this.typed();
    const places: string[] = [];
    this.targets().forEach((target, i) => {
      for (const field of this.numericFields(target)) {
        const text = typed.get(`${rowId(target)} ${field}`);
        if (text !== undefined && readBound(text) === undefined) {
          places.push(this.path(i, field));
        }
      }
    });
    return places;
  });

  constructor() {
    // Said to the page each time they are worked out again - a form drawn anew, another build's targets given to it:
    // the page keeps them by build, and takes a list it has already.
    effect(() => {
      const places = this.unreadable();
      untracked(() => this.unreadableChange.emit(places));
    });
  }

  protected statName(stat: string): string {
    return this.names().get(stat) ?? stat;
  }

  /** A percent stat's target is a percentage: "70" is 70%. */
  protected percent(stat: string): boolean {
    return stat.endsWith('_');
  }

  /** A number's place among the targets' fields: "stat_targets[1].max", "stat_targets[2].tiers.basic". */
  protected path(index: number, field: Numeric | 'stat' | 'from_rank'): string {
    return field === 'basic' || field === 'intermediate' || field === 'advanced' ? `stat_targets[${index}].tiers.${field}` : `stat_targets[${index}].${field}`;
  }

  /** A tier row's place for a field; none for a row worked out, which is no stored entry. */
  protected rowPath(row: TierRow, field: Numeric | 'stat' | 'from_rank'): string | null {
    return row.index === null ? null : this.path(row.index, field);
  }

  /** The fields of a target that take a number: a requirement's bounds, a hand-set target's tiers, none of a stat switched off. */
  private numericFields(target: EditorTarget): Numeric[] {
    return target.off === true ? [] : target.tiers ? [...TARGET_TIERS] : ['min', 'max'];
  }

  /** The target's own number in a field: a bound, or a tier set by hand. */
  private valueOf(target: EditorTarget, field: Numeric): number | null {
    return field === 'min' || field === 'max' ? target[field] : (target.tiers?.[field] ?? null);
  }

  /** What a number's field shows: what is typed while it is no number or the target's number, else the target's number. */
  protected textOf(target: EditorTarget, field: Numeric): string {
    const typed = this.typed().get(`${rowId(target)} ${field}`);
    if (typed !== undefined) {
      const value = readBound(typed);
      if (value === undefined || value === this.valueOf(target, field)) {
        return typed;
      }
    }
    const value = this.valueOf(target, field);
    return value === null ? '' : String(value);
  }

  /** What a tier row's field shows: the value worked out on a row the site works out, else as textOf(). */
  protected tierText(row: TierRow, tier: TargetTier): string {
    if (row.kind === 'auto' || row.index === null) {
      const value = row.tiers[tier];
      return value === null ? '' : String(value);
    }
    const target = this.targets()[row.index];
    return target ? this.textOf(target, tier) : '';
  }

  protected error(index: number | null, field: Numeric | 'stat' | 'from_rank'): string | null {
    return index === null ? null : (this.errors()[this.path(index, field)] ?? null);
  }

  /** A number's problem: the page's, else what is typed being no number - once the field is left, never while typing on the way to one ("-", "120."). */
  protected numberError(target: EditorTarget, index: number, field: Numeric): string | null {
    const key = `${rowId(target)} ${field}`;
    const text = this.typed().get(key);
    if (text !== undefined && readBound(text) === undefined && this.left().has(key)) {
      return 'Write a number, such as 70 or 120.5 - or leave it empty.';
    }
    return this.error(index, field);
  }

  protected tierError(row: TierRow, tier: TargetTier): string | null {
    const target = row.index === null ? undefined : this.targets()[row.index];
    return target && row.index !== null ? this.numberError(target, row.index, tier) : null;
  }

  /** A number's field left: what is typed in it said wrong from now on where it is no number. */
  protected leave(target: EditorTarget, field: Numeric): void {
    const key = `${rowId(target)} ${field}`;
    if (!this.left().has(key)) {
      this.left.update((left) => new Set(left).add(key));
    }
  }

  protected leaveTier(row: TierRow, tier: TargetTier): void {
    const target = row.index === null ? undefined : this.targets()[row.index];
    if (target) {
      this.leave(target, tier);
    }
  }

  /** A number's name: "Minimum", "Basic", "Minimum (%)" for a percent stat's - the "%" beside the field said too. */
  protected fieldName(stat: string, field: Numeric): string {
    const name = field === 'min' ? 'Minimum' : field === 'max' ? 'Maximum' : field.charAt(0).toUpperCase() + field.slice(1);
    return this.percent(stat) ? `${name} (%)` : name;
  }

  /** A requirement's name among the others, for its row: "Target 2: Energy Recharge, from C1" - one stat can have several. */
  protected targetName(row: RequirementRow, place: number): string {
    const from = row.target.from_rank > 0 ? `, from ${this.rankPrefix()}${row.target.from_rank}` : '';
    return `Target ${place + 1}: ${this.statName(row.target.stat)}${from}`;
  }

  /** A requirement as its buttons name it, in a sentence: "target 2 (Energy Recharge from C1)". */
  protected buttonName(row: RequirementRow, place: number): string {
    const from = row.target.from_rank > 0 ? ` from ${this.rankPrefix()}${row.target.from_rank}` : '';
    return `target ${place + 1} (${this.statName(row.target.stat)}${from})`;
  }

  /** A tier row's name: "Tiered target 2: CRIT DMG (worked out)", "... (set by hand, from C1)", "... (switched off)". */
  protected tierName(row: TierRow, place: number): string {
    const from = row.from_rank > 0 ? `, from ${this.rankPrefix()}${row.from_rank}` : '';
    const how = row.kind === 'auto' ? 'worked out' : row.kind === 'off' ? 'switched off' : `set by hand${from}`;
    return `Tiered target ${place + 1}: ${this.statName(row.stat)} (${how})`;
  }

  /** One row of the tiered list, kept by its kind and stat (a stored entry's row by the entry). */
  protected trackTier(row: TierRow): string {
    return row.index === null ? `${row.kind} ${row.stat}` : rowId(this.targets()[row.index] ?? row);
  }

  /** What is typed in a number: kept as typed, and the target's number once it is one. */
  protected type(index: number, field: Numeric, text: string): void {
    const target = this.targets()[index];
    if (!target) {
      return;
    }
    const key = `${rowId(target)} ${field}`;
    this.typed.update((typed) => new Map(typed).set(key, text));
    if (this.left().has(key)) {
      this.left.update((left) => {
        const next = new Set(left);
        next.delete(key);
        return next;
      });
    }
    const value = readBound(text);
    if (value !== undefined && value !== this.valueOf(target, field)) {
      this.targetsChange.emit(
        replaced(this.targets(), index, (one) =>
          field === 'min' || field === 'max' ? { ...one, [field]: value } : { ...one, tiers: { basic: null, intermediate: null, advanced: null, ...one.tiers, [field]: value } },
        ),
      );
    }
  }

  protected typeTier(row: TierRow, tier: TargetTier, text: string): void {
    if (row.index !== null) {
      this.type(row.index, tier, text);
    }
  }

  protected setStat(index: number, stat: string): void {
    this.targetsChange.emit(replaced(this.targets(), index, (one) => ({ ...one, stat })));
  }

  protected setFrom(index: number, value: string): void {
    this.targetsChange.emit(replaced(this.targets(), index, (one) => ({ ...one, from_rank: Number(value) })));
  }

  protected setTierStat(row: TierRow, stat: string): void {
    if (row.index !== null) {
      this.setStat(row.index, stat);
    }
  }

  protected setTierFrom(row: TierRow, value: string): void {
    if (row.index !== null) {
      this.setFrom(row.index, value);
    }
  }

  /** A requirement moved among the requirements: the two stored entries trade places. */
  protected move(place: number, delta: -1 | 1): void {
    const rows = this.requirements();
    const from = rows[place];
    const to = rows[place + delta];
    if (!from || !to) {
      return;
    }
    const next = [...this.targets()];
    [next[from.index], next[to.index]] = [next[to.index], next[from.index]];
    this.targetsChange.emit(next);
  }

  protected remove(row: RequirementRow, place: number): void {
    this.targetsChange.emit(this.targets().filter((_, i) => i !== row.index));
    const name = this.buttonName(row, place);
    this.announcer.announce(`${name.charAt(0).toUpperCase()}${name.slice(1)} removed.`);
    afterNextRender(() => this.focusRow(Math.min(place, this.requirements().length - 1)), { injector: this.injector });
  }

  /** The stats with a stored target of any kind from the start - no second one of a stat may join it there. */
  private takenFromStart(): Set<string> {
    return new Set(
      this.targets()
        .filter((target) => target.from_rank === 0)
        .map((target) => flatOf(target.stat)),
    );
  }

  /** A new requirement, last: the first stat with no target from the start, from the start, no bound yet - its stat in hand. */
  protected add(): void {
    const taken = this.takenFromStart();
    const stat = this.stats().find((one) => !taken.has(flatOf(one.value)))?.value ?? this.stats()[0]?.value ?? '';
    this.targetsChange.emit([...this.targets(), { stat, min: null, max: null, from_rank: 0 }]);
    afterNextRender(() => this.focusRow(this.requirements().length - 1), { injector: this.injector });
  }

  /**
   * Auto unticked on a row worked out: the stat's tiers set by hand, from the values worked out (a stored entry);
   * ticked again on a row set by hand: the entry goes, and the site works the stat out again.
   */
  protected setAuto(row: TierRow, auto: boolean): void {
    if (row.locked) {
      return;
    }
    if (auto && row.kind === 'hand' && row.index !== null && row.computed !== null) {
      this.targetsChange.emit(this.targets().filter((_, i) => i !== row.index));
      this.announcer.announce(`${this.statName(row.stat)}: worked out again.`);
      afterNextRender(() => this.focusTier(row.stat, '.auto input'), { injector: this.injector });
    } else if (!auto && row.kind === 'auto' && row.computed !== null) {
      this.targetsChange.emit([...this.targets(), { stat: row.stat, min: null, max: null, from_rank: 0, tiers: { ...row.computed } }]);
      this.announcer.announce(`${this.statName(row.stat)}: set by hand from now on.`);
      afterNextRender(() => this.focusTier(row.stat, '[data-tier="basic"] input'), { injector: this.injector });
    }
  }

  /**
   * A tiered target removed: a stat the site works out is switched off (a stored entry says so - from the constellation
   * the row held from -, so nothing shows for it until Switch on) - set by hand or not -; one set by hand with nothing
   * worked out behind it is gone.
   */
  protected removeTier(row: TierRow, place: number): void {
    if (row.locked) {
      return;
    }
    const off: EditorTarget = { stat: row.stat, min: null, max: null, from_rank: row.from_rank, off: true };
    if (row.kind === 'auto') {
      this.targetsChange.emit([...this.targets(), off]);
    } else if (row.index !== null && row.computed !== null) {
      this.targetsChange.emit(replaced(this.targets(), row.index, () => off));
    } else if (row.index !== null) {
      this.targetsChange.emit(this.targets().filter((_, i) => i !== row.index));
    }
    this.announcer.announce(`${this.statName(row.stat)} ${row.computed !== null ? 'switched off' : 'removed'}.`);
    afterNextRender(() => this.focusTierRow(Math.min(place, this.tierRows().length - 1)), { injector: this.injector });
  }

  /** A stat switched off shown again: the entry goes, and the site works the stat out. */
  protected switchOn(row: TierRow): void {
    if (row.index !== null) {
      this.targetsChange.emit(this.targets().filter((_, i) => i !== row.index));
      this.announcer.announce(`${this.statName(row.stat)} switched on.`);
      afterNextRender(() => this.focusTier(row.stat, '.auto input'), { injector: this.injector });
    }
  }

  /** A new target set by hand, last among the tiered: the first stat with no row and no target from the start, its three values to type. */
  protected addTier(): void {
    const taken = this.takenFromStart();
    for (const row of this.tierRows()) {
      taken.add(flatOf(row.stat));
    }
    const stat = this.stats().find((one) => !taken.has(flatOf(one.value)))?.value ?? this.stats()[0]?.value ?? '';
    this.targetsChange.emit([...this.targets(), { stat, min: null, max: null, from_rank: 0, tiers: { basic: null, intermediate: null, advanced: null } }]);
    afterNextRender(() => this.focusTier(stat, 'select'), { injector: this.injector });
  }

  /** The focus on a requirement's stat - on Add requirement where there is no row left. */
  private focusRow(index: number): void {
    const row = index >= 0 ? this.host.nativeElement.querySelectorAll<HTMLElement>('.target-row')[index] : undefined;
    (row?.querySelector<HTMLElement>('select') ?? this.host.nativeElement.querySelector<HTMLElement>('button.add-target'))?.focus();
  }

  /** The focus on a tier row's first control that takes it - or on Add tiered target where there is no row left. */
  private focusTierRow(index: number): void {
    const row = index >= 0 ? this.host.nativeElement.querySelectorAll<HTMLElement>('.tier-row')[index] : undefined;
    (row?.querySelector<HTMLElement>(FOCUSABLE) ?? this.host.nativeElement.querySelector<HTMLElement>('button.add-tiered'))?.focus();
  }

  private focusTier(stat: string, selector: string): void {
    const rows = [...this.host.nativeElement.querySelectorAll<HTMLElement>('.tier-row')];
    const row = rows.find((one) => one.getAttribute('data-stat') === stat);
    (row?.querySelector<HTMLElement>(selector) ?? row?.querySelector<HTMLElement>(FOCUSABLE))?.focus();
  }
}
