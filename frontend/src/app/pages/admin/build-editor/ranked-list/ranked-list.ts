import { Component, ElementRef, Injector, afterNextRender, computed, inject, input, output } from '@angular/core';
import { uniqueId } from '@anotoki/lib/ui/dom';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SelectComponent, SelectOption } from '@anotoki/lib/ui/select';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { StatPriority, TalentPriority } from '../../../../core/models';
import { EditorAnnouncer } from '../editor-announcer';
import { carried, rowId } from '../editor-draft';

/** A row of a ranked list: a stat or a talent, and its priority. */
export type Ranked = StatPriority | TalentPriority;

/** The priorities a row offers at least: 1 the most important; more than ten is no build's. */
const PRIORITIES = 10;

/**
 * A ranked list of the builds editor - a slot's main stats, the substats, the talents: each row a stat (or a talent) to
 * pick - none another row has -, its priority and its remove button; Add adds the next one after the last. Priorities
 * stay numbers, as the builds file writes them and the analysis weighs them (docs/decisions.md): equal ones are a tie,
 * nothing renumbers them, and the rows stand in their order - a row whose priority changes moves to its place among
 * them, the focus with it. Every change comes out as the whole list (`rowsChange`), an edited row the same row
 * (rowId()). English, as the admin panel.
 */
@Component({
  selector: 'app-ranked-list',
  imports: [ButtonComponent, IconComponent, SelectComponent, TooltipDirective],
  templateUrl: './ranked-list.html',
  styleUrl: './ranked-list.scss',
})
export class RankedList {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  private readonly announcer = inject(EditorAnnouncer);

  /** The list's name: "Sands of Eon", "Substats". */
  readonly label = input.required<string>();
  /** Whether the name is shown over the rows (a card that says it already: for screen readers alone). */
  readonly showLabel = input(true);
  /** What a row holds, in a sentence: "main stat", "substat", "talent". */
  readonly noun = input.required<string>();
  readonly rows = input.required<readonly Ranked[]>();
  /** The field of a row that holds its choice. */
  readonly field = input<'stat' | 'talent'>('stat');
  readonly choices = input.required<readonly SelectOption[]>();
  /** Where the list is in a save: "substats", "main_stats.sands", "talent_priority". */
  readonly path = input.required<string>();
  readonly maxRows = input(30);
  readonly maxPriority = input(99);
  /** What an empty list means, said where it is empty. */
  readonly emptyText = input('None listed.');
  /** The problems shown at the fields, by place ("substats[0].priority"). */
  readonly errors = input<Record<string, string>>({});

  readonly rowsChange = output<Ranked[]>();

  protected readonly labelId = uniqueId('ranked-label');
  protected readonly rowId = rowId;

  /** The priorities a row offers: one to ten, and any higher one a row has (as a file wrote it). */
  protected readonly priorities = computed<SelectOption[]>(() => {
    const top = Math.min(this.maxPriority(), Math.max(PRIORITIES, ...this.rows().map((row) => row.priority)));
    return Array.from({ length: top }, (_, i) => ({ value: String(i + 1), label: String(i + 1) }));
  });

  private readonly names = computed(() => new Map(this.choices().map((choice) => [choice.value, choice.label])));

  protected keyOf(row: Ranked): string {
    return 'stat' in row ? row.stat : row.talent;
  }

  protected nameOf(row: Ranked): string {
    return this.names().get(this.keyOf(row)) ?? this.keyOf(row);
  }

  /** A row's choices: those no other row has. */
  protected choicesFor(index: number): SelectOption[] {
    const taken = new Set(
      this.rows()
        .filter((_, i) => i !== index)
        .map((row) => this.keyOf(row)),
    );
    return this.choices().filter((choice) => !taken.has(choice.value));
  }

  protected readonly canAdd = computed(() => {
    const taken = new Set(this.rows().map((row) => this.keyOf(row)));
    return this.rows().length < this.maxRows() && this.choices().some((choice) => !taken.has(choice.value));
  });

  protected error(index: number, field: string): string | null {
    return this.errors()[`${this.path()}[${index}].${field}`] ?? null;
  }

  /** A row with another key, the same row. */
  private withKey(row: Ranked, key: string): Ranked {
    return carried(row, this.field() === 'stat' ? { stat: key, priority: row.priority } : { talent: key, priority: row.priority });
  }

  protected choose(index: number, key: string): void {
    this.rowsChange.emit(this.rows().map((row, i) => (i === index ? this.withKey(row, key) : row)));
  }

  /** A row's priority changed: the row in its place among the others (ties as they stood), the focus kept on it. */
  protected prioritize(index: number, value: string): void {
    const edited = this.rows().map((row, i) => (i === index ? carried(row, { ...row, priority: Number(value) }) : row));
    const id = rowId(edited[index]);
    const sorted = [...edited].sort((a, b) => a.priority - b.priority);
    this.rowsChange.emit(sorted);
    const now = sorted.findIndex((row) => rowId(row) === id);
    if (now !== index) {
      this.announcer.announce(`${this.nameOf(edited[index])}, priority ${value}: now ${now + 1} of ${sorted.length}.`);
    }
    afterNextRender(() => this.host.nativeElement.querySelector<HTMLElement>(`[data-row="${id}"] .priority select`)?.focus(), { injector: this.injector });
  }

  protected remove(index: number): void {
    const row = this.rows()[index];
    this.rowsChange.emit(this.rows().filter((_, i) => i !== index));
    this.announcer.announce(`${row ? this.nameOf(row) : this.noun()} removed.`);
    afterNextRender(
      () => {
        const rows = this.host.nativeElement.querySelectorAll<HTMLElement>('.ranked-row');
        const next = rows[Math.min(index, rows.length - 1)];
        (next?.querySelector<HTMLElement>('.choice select') ?? this.host.nativeElement.querySelector<HTMLElement>('button.add'))?.focus();
      },
      { injector: this.injector },
    );
  }

  /** The first choice no row has, after the last one: its priority the next number (a tie with it at ten and beyond). */
  protected add(): void {
    const taken = new Set(this.rows().map((row) => this.keyOf(row)));
    const choice = this.choices().find((one) => !taken.has(one.value));
    if (!choice || this.rows().length >= this.maxRows()) {
      return;
    }
    const last = this.rows().reduce((top, row) => Math.max(top, row.priority), 0);
    const priority = Math.max(1, Math.min(last + 1, Math.max(PRIORITIES, last), this.maxPriority()));
    const row: Ranked = this.field() === 'stat' ? { stat: choice.value, priority } : { talent: choice.value, priority };
    this.rowsChange.emit([...this.rows(), row]);
    afterNextRender(
      () => {
        const rows = this.host.nativeElement.querySelectorAll<HTMLElement>('.ranked-row');
        rows[rows.length - 1]?.querySelector<HTMLElement>('.choice select')?.focus();
      },
      { injector: this.injector },
    );
  }

  /** The choices' column, by its name: "Main stat", "Talent". */
  protected headChoice(): string {
    const noun = this.noun();
    return `${noun.charAt(0).toUpperCase()}${noun.slice(1)}`;
  }

  /** "Substat 2": a row's choice, by its place. */
  protected rowLabel(index: number): string {
    const noun = this.noun();
    return `${noun.charAt(0).toUpperCase()}${noun.slice(1)} ${index + 1}`;
  }
}
