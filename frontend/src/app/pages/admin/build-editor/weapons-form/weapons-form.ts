import { Component, ElementRef, Injector, afterNextRender, computed, inject, input, output, signal } from '@angular/core';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { ComboboxComponent, ComboboxOption } from '@anotoki/lib/ui/combobox';
import { CheckboxComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SelectComponent, SelectOption } from '@anotoki/lib/ui/select';
import { EditorCatalogWeapon, EditorWeapon } from '../../../../core/models';
import { Avatar } from '../../../../shared/char-avatar';
import { EditorAnnouncer } from '../editor-announcer';
import { moved, replaced, rowId } from '../editor-draft';
import { MoveButtons } from '../move-buttons/move-buttons';

/**
 * A build's weapons in the builds editor, best first: each row the weapon's picture, the kit's combobox to pick it by
 * typing its name (the catalog's weapons of the character's type - every type where the switch says so -, none another
 * row has), its refinement (any, or R1-R5) and the move buttons. A weapon the catalog does not know (yet) keeps its
 * stored name, marked; Add weapon adds an empty row, its picker in hand - a row left empty is a problem at Save
 * (editor-draft.ts). The page keeps the list: every change comes out as the whole list (`weaponsChange`), an edited row
 * the same row (rowId()). English, as the admin panel.
 */
@Component({
  selector: 'app-weapons-form',
  imports: [BadgeComponent, ButtonComponent, ComboboxComponent, CheckboxComponent, IconComponent, SelectComponent, Avatar, MoveButtons],
  templateUrl: './weapons-form.html',
  styleUrl: './weapons-form.scss',
})
export class WeaponsForm {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  private readonly announcer = inject(EditorAnnouncer);

  readonly weapons = input.required<readonly EditorWeapon[]>();
  readonly catalog = input.required<readonly EditorCatalogWeapon[]>();
  /** The character's weapon type: what the pickers offer; null for every type. */
  readonly weaponType = input<string | null>(null);
  readonly refinementPrefix = input('R');
  readonly maxRefinement = input(5);
  readonly maxWeapons = input(100);
  /** The problems shown at the fields, by place ("weapons[0].key"). */
  readonly errors = input<Record<string, string>>({});

  readonly weaponsChange = output<EditorWeapon[]>();

  /** Every weapon type offered, not only the character's. */
  protected readonly everyType = signal(false);
  protected readonly rowId = rowId;

  private readonly byKey = computed(() => new Map(this.catalog().map((weapon) => [weapon.key, weapon])));
  /** The weapons offered: the character's type, or every type. */
  private readonly offered = computed<ComboboxOption[]>(() => {
    const type = this.weaponType();
    return this.catalog()
      .filter((weapon) => this.everyType() || type === null || weapon.weapon_type === type)
      .map((weapon) => this.option(weapon));
  });
  protected readonly refinements = computed<SelectOption[]>(() => [
    { value: '', label: 'Any refinement' },
    ...Array.from({ length: this.maxRefinement() }, (_, i) => ({ value: String(i + 1), label: `${this.refinementPrefix()}${i + 1}` })),
  ]);

  /** Each weapon's option, made once: a picker given the same option again keeps what is typed in it (the kit's field
   * shows its chosen option's name anew whenever that option changes). */
  private readonly made = new Map<string, ComboboxOption>();

  private option(weapon: EditorCatalogWeapon): ComboboxOption {
    return this.once(`${weapon.key}\n${weapon.name}\n${weapon.icon_url ?? ''}\n${weapon.weapon_type ?? ''}`, () => ({
      value: weapon.key,
      label: weapon.name,
      picture: weapon.icon_url,
      keywords: weapon.weapon_type,
    }));
  }

  private once(key: string, make: () => ComboboxOption): ComboboxOption {
    let option = this.made.get(key);
    if (!option) {
      option = make();
      this.made.set(key, option);
    }
    return option;
  }

  /**
   * Each row's choices: the weapons offered but those the other rows have - and the row's own where the offer lacks it
   * (one the catalog lacks, of another type, another row's too) -, worked out once a change, not at every turn.
   */
  protected readonly rowOptions = computed<ComboboxOption[][]>(() => {
    const weapons = this.weapons();
    const offered = this.offered();
    return weapons.map((own, index) => {
      const taken = new Set(weapons.filter((_, i) => i !== index).map((weapon) => weapon.key));
      const options = offered.filter((option) => !taken.has(option.value));
      if (own.key !== '' && !options.some((option) => option.value === own.key)) {
        const known = this.byKey().get(own.key);
        options.unshift(known ? this.option(known) : this.once(`${own.key}\n${own.name}\nunknown`, () => ({ value: own.key, label: `${own.name} (not in the catalog)` })));
      }
      return options;
    });
  });

  /** Whether the catalog knows a row's weapon (an empty row's is nobody's to know). */
  protected known(key: string): boolean {
    return key === '' || this.byKey().has(key);
  }

  protected icon(key: string): string | null {
    return this.byKey().get(key)?.icon_url ?? null;
  }

  protected error(index: number, field: string): string | null {
    return this.errors()[`weapons[${index}].${field}`] ?? null;
  }

  /** A row's name, for its controls' names: the weapon's, or its place while it has none. */
  protected nameOf(weapon: EditorWeapon, index: number): string {
    return weapon.name || `weapon ${index + 1}`;
  }

  /** A weapon picked for a row - by the catalog's name -, or none (the field emptied). */
  protected pick(index: number, key: string | null): void {
    const weapon = this.weapons()[index];
    if (!weapon || (key ?? '') === weapon.key) {
      return;
    }
    const known = key === null ? undefined : this.byKey().get(key);
    this.weaponsChange.emit(replaced(this.weapons(), index, (one) => ({ ...one, key: key ?? '', name: known?.name ?? '' })));
  }

  protected refine(index: number, value: string): void {
    this.weaponsChange.emit(replaced(this.weapons(), index, (one) => ({ ...one, refinement: value === '' ? null : Number(value) })));
  }

  protected move(index: number, delta: -1 | 1): void {
    this.weaponsChange.emit(moved(this.weapons(), index, delta));
  }

  protected remove(index: number): void {
    const weapon = this.weapons()[index];
    this.weaponsChange.emit(this.weapons().filter((_, i) => i !== index));
    this.announcer.announce(`Weapon ${weapon ? this.nameOf(weapon, index) : index + 1} removed.`);
    afterNextRender(() => this.focusRow(Math.min(index, this.weapons().length - 1)), { injector: this.injector });
  }

  protected add(): void {
    this.weaponsChange.emit([...this.weapons(), { key: '', name: '', refinement: null }]);
    afterNextRender(() => this.focusRow(this.weapons().length - 1), { injector: this.injector });
  }

  /** The focus on a row's picker - on Add weapon where there is no row left. */
  private focusRow(index: number): void {
    const row = index >= 0 ? this.host.nativeElement.querySelectorAll<HTMLElement>('.weapon-row')[index] : undefined;
    (row?.querySelector<HTMLElement>('input') ?? this.host.nativeElement.querySelector<HTMLElement>('button.add-weapon'))?.focus();
  }
}
