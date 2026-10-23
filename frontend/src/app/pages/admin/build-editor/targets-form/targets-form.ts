import { Component, ElementRef, Injector, afterNextRender, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SelectComponent, SelectOption } from '@anotoki/lib/ui/select';
import { EditorTarget } from '../../../../core/models';
import { EditorAnnouncer } from '../editor-announcer';
import { moved, replaced, rowId } from '../editor-draft';
import { MoveButtons } from '../move-buttons/move-buttons';

type Bound = 'min' | 'max';

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
 * A build's stat targets in the builds editor: each row the stat, the constellation it holds from ("From the start",
 * "From C1"…), its minimum and its maximum - numbers typed, a percent stat's with its "%" (in its name too, and taken
 * where it is typed) - and the move buttons; Add target adds one. A target keeps its numbers alone (the text a file may
 * carry is shown nowhere). What is typed in a bound stays as typed while it is not a number yet - said wrong at the
 * field once the field is left, and named to the page at once (`unreadableChange`, which holds the save back
 * meanwhile) -, and becomes the target's number as soon as it is one.
 * Every change comes out as the whole list (`targetsChange`), an edited row the same row (rowId()). English, as the
 * admin panel.
 */
@Component({
  selector: 'app-targets-form',
  imports: [ButtonComponent, IconComponent, SelectComponent, TextFieldComponent, MoveButtons],
  templateUrl: './targets-form.html',
  styleUrl: './targets-form.scss',
})
export class TargetsForm {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  private readonly announcer = inject(EditorAnnouncer);

  readonly targets = input.required<readonly EditorTarget[]>();
  /** The stats a target can be of, with their names. */
  readonly stats = input.required<readonly SelectOption[]>();
  readonly maxRank = input(6);
  readonly rankPrefix = input('C');
  readonly maxTargets = input(30);
  /** The problems shown at the fields, by place ("stat_targets[0].min"). */
  readonly errors = input<Record<string, string>>({});

  readonly targetsChange = output<EditorTarget[]>();
  /** The places of the bounds typed that are no number yet ("stat_targets[1].max"), whenever they change. */
  readonly unreadableChange = output<string[]>();

  protected readonly rowId = rowId;

  /** What is typed in a bound's field, by its row and bound ("row7 min"). */
  private readonly typed = signal<ReadonlyMap<string, string>>(new Map());
  /** The bounds' fields left since something was typed in them, by row and bound. */
  private readonly left = signal<ReadonlySet<string>>(new Set());

  protected readonly fromOptions = computed<SelectOption[]>(() => [
    { value: '0', label: 'From the start' },
    ...Array.from({ length: this.maxRank() }, (_, i) => ({ value: String(i + 1), label: `From ${this.rankPrefix()}${i + 1}` })),
  ]);

  private readonly names = computed(() => new Map(this.stats().map((stat) => [stat.value, stat.label])));

  /** The bounds typed that are no number, by place. */
  private readonly unreadable = computed(() => {
    const typed = this.typed();
    const places: string[] = [];
    this.targets().forEach((target, i) => {
      for (const bound of ['min', 'max'] as const) {
        const text = typed.get(`${rowId(target)} ${bound}`);
        if (text !== undefined && readBound(text) === undefined) {
          places.push(`stat_targets[${i}].${bound}`);
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

  protected statName(target: EditorTarget): string {
    return this.names().get(target.stat) ?? target.stat;
  }

  /** A percent stat's target is a percentage: "70" is 70%. */
  protected percent(stat: string): boolean {
    return stat.endsWith('_');
  }

  /** What a bound's field shows: what is typed while it is no number or the target's number, else the target's number. */
  protected textOf(target: EditorTarget, bound: Bound): string {
    const typed = this.typed().get(`${rowId(target)} ${bound}`);
    if (typed !== undefined) {
      const value = readBound(typed);
      if (value === undefined || value === target[bound]) {
        return typed;
      }
    }
    const value = target[bound];
    return value === null ? '' : String(value);
  }

  protected error(index: number, field: string): string | null {
    return this.errors()[`stat_targets[${index}].${field}`] ?? null;
  }

  /** A bound's problem: the page's, else what is typed being no number - once the field is left, never while typing on the way to one ("-", "120."). */
  protected boundError(target: EditorTarget, index: number, bound: Bound): string | null {
    const key = `${rowId(target)} ${bound}`;
    const text = this.typed().get(key);
    if (text !== undefined && readBound(text) === undefined && this.left().has(key)) {
      return 'Write a number, such as 70 or 120.5 - or leave it empty.';
    }
    return this.error(index, bound);
  }

  /** A bound's field left: what is typed in it said wrong from now on where it is no number. */
  protected leave(target: EditorTarget, bound: Bound): void {
    const key = `${rowId(target)} ${bound}`;
    if (!this.left().has(key)) {
      this.left.update((left) => new Set(left).add(key));
    }
  }

  /** A bound's name: "Minimum", "Minimum (%)" for a percent stat's - the "%" beside the field said too. */
  protected boundName(target: EditorTarget, bound: Bound): string {
    const name = bound === 'min' ? 'Minimum' : 'Maximum';
    return this.percent(target.stat) ? `${name} (%)` : name;
  }

  /** A target's name among the others, for its row: "Target 2: Energy Recharge, from C1" - one stat can have several. */
  protected targetName(target: EditorTarget, index: number): string {
    const from = target.from_rank > 0 ? `, from ${this.rankPrefix()}${target.from_rank}` : '';
    return `Target ${index + 1}: ${this.statName(target)}${from}`;
  }

  /** A target as its buttons name it, in a sentence: "target 2 (Energy Recharge from C1)". */
  protected buttonName(target: EditorTarget, index: number): string {
    const from = target.from_rank > 0 ? ` from ${this.rankPrefix()}${target.from_rank}` : '';
    return `target ${index + 1} (${this.statName(target)}${from})`;
  }

  /** What is typed in a bound: kept as typed, and the target's number once it is one. */
  protected type(index: number, bound: Bound, text: string): void {
    const target = this.targets()[index];
    if (!target) {
      return;
    }
    const key = `${rowId(target)} ${bound}`;
    this.typed.update((typed) => new Map(typed).set(key, text));
    if (this.left().has(key)) {
      this.left.update((left) => {
        const next = new Set(left);
        next.delete(key);
        return next;
      });
    }
    const value = readBound(text);
    if (value !== undefined && value !== target[bound]) {
      this.targetsChange.emit(replaced(this.targets(), index, (one) => ({ ...one, [bound]: value })));
    }
  }

  protected setStat(index: number, stat: string): void {
    this.targetsChange.emit(replaced(this.targets(), index, (one) => ({ ...one, stat })));
  }

  protected setFrom(index: number, value: string): void {
    this.targetsChange.emit(replaced(this.targets(), index, (one) => ({ ...one, from_rank: Number(value) })));
  }

  protected move(index: number, delta: -1 | 1): void {
    this.targetsChange.emit(moved(this.targets(), index, delta));
  }

  protected remove(index: number): void {
    const target = this.targets()[index];
    this.targetsChange.emit(this.targets().filter((_, i) => i !== index));
    const name = target ? this.buttonName(target, index) : `target ${index + 1}`;
    this.announcer.announce(`${name.charAt(0).toUpperCase()}${name.slice(1)} removed.`);
    afterNextRender(() => this.focusRow(Math.min(index, this.targets().length - 1)), { injector: this.injector });
  }

  /** A new target, last: the first stat with none from the start, from the start, no bound yet - its stat in hand. */
  protected add(): void {
    const taken = new Set(
      this.targets()
        .filter((target) => target.from_rank === 0)
        .map((target) => target.stat),
    );
    const stat = this.stats().find((one) => !taken.has(one.value))?.value ?? this.stats()[0]?.value ?? '';
    this.targetsChange.emit([...this.targets(), { stat, min: null, max: null, from_rank: 0 }]);
    afterNextRender(() => this.focusRow(this.targets().length - 1), { injector: this.injector });
  }

  /** The focus on a row's stat - on Add target where there is no row left. */
  private focusRow(index: number): void {
    const row = index >= 0 ? this.host.nativeElement.querySelectorAll<HTMLElement>('.target-row')[index] : undefined;
    (row?.querySelector<HTMLElement>('select') ?? this.host.nativeElement.querySelector<HTMLElement>('button.add-target'))?.focus();
  }
}
