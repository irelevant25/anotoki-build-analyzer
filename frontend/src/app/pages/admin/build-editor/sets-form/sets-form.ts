import { Component, ElementRef, Injector, afterNextRender, computed, inject, input, output, signal } from '@angular/core';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { ChipItem, ChipListComponent } from '@anotoki/lib/ui/chips';
import { ComboboxComponent, ComboboxOption } from '@anotoki/lib/ui/combobox';
import { TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SegmentedComponent, SegmentedOption } from '@anotoki/lib/ui/segmented';
import { SelectComponent, SelectOption } from '@anotoki/lib/ui/select';
import { EditorCatalogSet, EditorLimits, EditorSetOption, EditorSetPiece } from '../../../../core/models';
import { EditorAnnouncer } from '../editor-announcer';
import { moved, replaced, rowId } from '../editor-draft';
import { MoveButtons } from '../move-buttons/move-buttons';

/**
 * A build's artifact sets in the builds editor, best option first. An option is a box of its pieces: each piece how
 * many pieces of a set it asks (2 or 4, as the game counts them), the sets that fill it - the kit's chips, each with
 * its remove button, in the order they were added, and the kit's combobox to add one by typing its name -, and a label:
 * the set's name where a piece takes one set (written for the editor), what the sets have in common where it takes
 * several ("any 18% ATK set": the editor's to write - a problem at Save while there is none). An option of several
 * pieces says how many of them to wear ("Wear: any 2 of them" - all by default). Options and pieces move and go with
 * the move buttons; an option keeps one piece at least. The kind of set shows only while the game has more than one.
 * Every change comes out as the whole list (`setsChange`), an edited entry the same row (rowId()). English, as the
 * admin panel.
 */
@Component({
  selector: 'app-sets-form',
  imports: [ButtonComponent, ChipListComponent, ComboboxComponent, IconComponent, SegmentedComponent, SelectComponent, TextFieldComponent, MoveButtons],
  templateUrl: './sets-form.html',
  styleUrl: './sets-form.scss',
})
export class SetsForm {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  private readonly announcer = inject(EditorAnnouncer);

  readonly sets = input.required<readonly EditorSetOption[]>();
  readonly catalog = input.required<readonly EditorCatalogSet[]>();
  /** Each kind of set the game has, with the piece counts it knows. */
  readonly kinds = input.required<Record<string, number[]>>();
  readonly kindLabels = input<Record<string, string>>({});
  readonly limits = input.required<EditorLimits>();
  /** The problems shown at the fields, by place ("sets[0].pieces[1].label"). */
  readonly errors = input<Record<string, string>>({});

  readonly setsChange = output<EditorSetOption[]>();

  protected readonly rowId = rowId;

  /**
   * How many sets each piece has had added here, by its row: its picker is drawn anew with each, empty for the next one
   * and the focus in it - the kit's field keeps the name of what was chosen until it is left.
   */
  private readonly added = signal<ReadonlyMap<string, number>>(new Map());
  /** Each set's option, made once. */
  private readonly made = new Map<string, ComboboxOption>();

  private readonly byKey = computed(() => new Map(this.catalog().map((set) => [set.key, set])));
  /** Whether the game has more than one kind of set: only then does an option say its kind. */
  protected readonly manyKinds = computed(() => Object.keys(this.kinds()).length > 1);
  protected readonly kindOptions = computed<SelectOption[]>(() => Object.keys(this.kinds()).map((kind) => ({ value: kind, label: this.kindLabels()[kind] ?? kind })));

  /** A kind's piece counts, in the game's notation: "2pc", "4pc". */
  protected countOptions(kind: string): SegmentedOption[] {
    return this.countsOf(kind).map((count) => ({ value: String(count), label: `${count}pc`, name: `${count}pc, ${count} pieces` }));
  }

  private countsOf(kind: string): number[] {
    return this.kinds()[kind] ?? [2, 4];
  }

  /** How many of an option's pieces to wear: all, or any of fewer. */
  protected chooseOptions(option: EditorSetOption): SelectOption[] {
    const count = option.pieces.length;
    const options: SelectOption[] = [{ value: '', label: `All ${count} of them` }];
    for (let k = 1; k < count; k++) {
      options.push({ value: String(k), label: `Any ${k} of them` });
    }
    // As a file wrote it: "any n" of n, which is all of them, kept until changed.
    if (option.choose !== null && option.choose >= count) {
      options.push({ value: String(option.choose), label: `Any ${option.choose} of them` });
    }
    return options;
  }

  /** The sets a piece's picker offers: the catalog's of the option's kind, but those the piece has. */
  protected setOptions(kind: string, piece: EditorSetPiece): ComboboxOption[] {
    const taken = new Set(piece.keys);
    return this.catalog()
      .filter((set) => set.kind === kind && !taken.has(set.key))
      .map((set) => {
        const key = `${set.key}\n${set.name}\n${set.icon_url ?? ''}`;
        let option = this.made.get(key);
        if (!option) {
          option = { value: set.key, label: set.name, picture: set.icon_url };
          this.made.set(key, option);
        }
        return option;
      });
  }

  /** How many sets a piece has had added here: its picker's turn. */
  protected addedTo(piece: EditorSetPiece): number {
    return this.added().get(rowId(piece)) ?? 0;
  }

  /** An option's name, for its buttons: its pieces, as the member pages write them - "4pc Crimson Witch of Flames". */
  protected optionName(option: EditorSetOption, index: number): string {
    const pieces = option.pieces.filter((piece) => piece.keys.length > 0).map((piece, p) => `${piece.count}pc ${this.pieceName(piece, p)}`);
    return pieces.length > 0 ? pieces.join(' + ') : `${index + 1}`;
  }

  /** A piece's sets as the kit's chips, each with its name. */
  protected chips(piece: EditorSetPiece): ChipItem[] {
    return piece.keys.map((key, k) => ({ value: key, label: piece.names[k] ?? this.byKey().get(key)?.name ?? key }));
  }

  /** A piece's name, for its controls' names: its label, its sets', or its place. */
  protected pieceName(piece: EditorSetPiece, index: number): string {
    return piece.label.trim() || piece.names.join(' / ') || `piece ${index + 1}`;
  }

  protected error(path: string): string | null {
    return this.errors()[path] ?? null;
  }

  private change(o: number, update: (option: EditorSetOption) => EditorSetOption): void {
    this.setsChange.emit(replaced(this.sets(), o, update));
  }

  private changePiece(o: number, p: number, update: (piece: EditorSetPiece) => EditorSetPiece): void {
    this.change(o, (option) => ({ ...option, pieces: replaced(option.pieces, p, update) }));
  }

  /** A new piece of a kind: no set yet, as many pieces as `count`. */
  private newPiece(count: number): EditorSetPiece {
    return { label: '', count, keys: [], names: [] };
  }

  // ── options ───────────────────────────────────────────────────────────────────────────────────

  /** A new option, last: one piece of the most pieces the kind knows (a 4pc set, mostly), its picker in hand. */
  protected addOption(): void {
    const kind = Object.keys(this.kinds())[0] ?? 'artifact';
    this.setsChange.emit([...this.sets(), { kind, choose: null, pieces: [this.newPiece(Math.max(...this.countsOf(kind)))] }]);
    afterNextRender(() => this.focusPicker(this.sets().length - 1, 0), { injector: this.injector });
  }

  protected moveOption(o: number, delta: -1 | 1): void {
    this.setsChange.emit(moved(this.sets(), o, delta));
  }

  protected removeOption(o: number): void {
    this.setsChange.emit(this.sets().filter((_, i) => i !== o));
    this.announcer.announce(`Option ${o + 1} removed.`);
    // The focus on the picker of the option now in its place (or before it) - never on a button a second press of
    // which would remove the next one -, on Add option where none is left.
    afterNextRender(
      () => {
        const left = this.sets().length;
        if (left === 0) {
          this.host.nativeElement.querySelector<HTMLElement>('button.add-option')?.focus();
        } else {
          this.focusPicker(Math.min(o, left - 1), 0);
        }
      },
      { injector: this.injector },
    );
  }

  /** Another kind: the pieces start again, a set of one kind filling no piece of another. */
  protected setKind(o: number, kind: string): void {
    this.change(o, (option) => (option.kind === kind ? option : { ...option, kind, choose: null, pieces: [this.newPiece(Math.max(...this.countsOf(kind)))] }));
  }

  protected setChoose(o: number, value: string): void {
    this.change(o, (option) => ({ ...option, choose: value === '' ? null : Number(value) }));
  }

  // ── pieces ────────────────────────────────────────────────────────────────────────────────────

  /** A new piece, last in its option: of the fewest pieces the kind knows (a 2pc set, mostly), its picker in hand. */
  protected addPiece(o: number): void {
    const option = this.sets()[o];
    this.change(o, (one) => ({ ...one, pieces: [...one.pieces, this.newPiece(Math.min(...this.countsOf(one.kind)))] }));
    afterNextRender(() => this.focusPicker(o, option.pieces.length), { injector: this.injector });
  }

  protected movePiece(o: number, p: number, delta: -1 | 1): void {
    this.change(o, (option) => ({ ...option, pieces: moved(option.pieces, p, delta) }));
  }

  /** A piece taken away - "wear any n" kept below the pieces left, all of them where it would not be. */
  protected removePiece(o: number, p: number): void {
    this.change(o, (option) => {
      const pieces = option.pieces.filter((_, i) => i !== p);
      return { ...option, pieces, choose: option.choose !== null && option.choose >= pieces.length ? null : option.choose };
    });
    this.announcer.announce(`Piece ${p + 1} of option ${o + 1} removed.`);
    // The focus on the picker of the piece now in its place (or before it): an option keeps one piece at least.
    afterNextRender(() => this.focusPicker(o, Math.min(p, (this.sets()[o]?.pieces.length ?? 1) - 1)), { injector: this.injector });
  }

  protected setCount(o: number, p: number, value: string): void {
    this.changePiece(o, p, (piece) => ({ ...piece, count: Number(value) }));
  }

  protected setLabel(o: number, p: number, label: string): void {
    this.changePiece(o, p, (piece) => ({ ...piece, label }));
  }

  /**
   * A set added to a piece, last - by the catalog's name. A piece of one set is labelled with its name; one that comes
   * to take several asks for a label of its own (the first set's name says no more what the piece takes). The picker is
   * drawn anew for the next one, the focus in it.
   */
  protected addSet(o: number, p: number, key: string | null): void {
    const piece = this.sets()[o]?.pieces[p];
    if (key === null || !piece) {
      return;
    }
    const set = this.byKey().get(key);
    this.changePiece(o, p, (piece) => {
      if (piece.keys.includes(key)) {
        return piece;
      }
      const keys = [...piece.keys, key];
      const names = [...piece.names, set?.name ?? key];
      const label = keys.length === 1 ? names[0] : keys.length === 2 && piece.label === piece.names[0] ? '' : piece.label;
      return { ...piece, keys, names, label };
    });
    const row = rowId(piece);
    this.added.update((added) => new Map(added).set(row, (added.get(row) ?? 0) + 1));
    afterNextRender(() => this.focusPicker(o, p), { injector: this.injector });
  }

  /** A set taken off a piece: one left labels the piece with its name, none leaves it unlabelled. */
  protected removeSet(o: number, p: number, key: string): void {
    this.changePiece(o, p, (piece) => {
      const at = piece.keys.indexOf(key);
      if (at < 0) {
        return piece;
      }
      const keys = piece.keys.filter((_, i) => i !== at);
      const names = piece.names.filter((_, i) => i !== at);
      return { ...piece, keys, names, label: keys.length === 1 ? names[0] : keys.length === 0 ? '' : piece.label };
    });
  }

  // ── the focus ─────────────────────────────────────────────────────────────────────────────────

  /** The focus on a piece's picker of sets. */
  private focusPicker(o: number, p: number): void {
    const option = this.host.nativeElement.querySelectorAll<HTMLElement>('.option')[o];
    option?.querySelectorAll<HTMLElement>('.piece')[p]?.querySelector<HTMLElement>('.add-set input')?.focus();
  }
}
