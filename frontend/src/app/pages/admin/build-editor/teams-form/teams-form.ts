import { Component, ElementRef, Injector, afterNextRender, computed, inject, input, output, signal } from '@angular/core';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { ChipItem, ChipListComponent } from '@anotoki/lib/ui/chips';
import { ComboboxComponent, ComboboxOption } from '@anotoki/lib/ui/combobox';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SelectComponent, SelectOption } from '@anotoki/lib/ui/select';
import { EditorCharacter, EditorMember, EditorTeam, WaitingNote } from '../../../../core/models';
import { Avatar } from '../../../../shared/char-avatar';
import { roleName } from '../../builds/build-file';
import { EditorAnnouncer } from '../editor-announcer';
import { NoteDraft, TeamsChange, moved, noteDraft, noteOf, replaced, rowId, takerOf, waitingOf } from '../editor-draft';
import { MoveButtons } from '../move-buttons/move-buttons';
import { NoteFields } from '../note-fields/note-fields';

let forms = 0;

/** A note with nothing written. */
const EMPTY_NOTE: NoteDraft = { en: '', sk: '' };

/**
 * A build's teams in the builds editor, best first. A team is a box of its own: its name (as members read it), its
 * members - two to the game's team size, in the team's order -, and the site's note on it (English, Slovak optional).
 * A member is its character (picked by typing its name - the kit's combobox with portraits, none the team has twice),
 * the role it plays in the team, the constellation the team asks of it, and who can stand in for it (the kit's chips
 * and a picker, five at most, none of the team's members). The build's own character is in every team: it cannot be
 * swapped, removed or stood in for, only moved. A new team starts with it and one member to choose.
 *
 * A team's note stays with its team as members are changed - a save files it under the team's members. A team that
 * goes with a note asks first, and its note then waits below for a team of the same members (as a save keeps one
 * whose team is gone; editor-draft.ts: waitingOf()). A waiting note stays there while the teams are edited - its entry
 * names the team a save gives it to (exactly its members, no note of its own: takerOf()) -, and is moved to a team that
 * has none, or deleted, by hand. Every change comes out whole (`teamsChange`: the teams, their
 * notes in their order, the waiting notes), an edited team or member the same row (rowId()). English, as the admin
 * panel.
 */
@Component({
  selector: 'app-teams-form',
  imports: [AlertComponent, Avatar, BadgeComponent, ButtonComponent, ChipListComponent, ComboboxComponent, IconComponent, SelectComponent, TextFieldComponent, MoveButtons, NoteFields],
  templateUrl: './teams-form.html',
  styleUrl: './teams-form.scss',
})
export class TeamsForm {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);
  private readonly announcer = inject(EditorAnnouncer);
  private readonly confirm = inject(ConfirmService);

  readonly teams = input.required<readonly EditorTeam[]>();
  /** Each team's note, in the teams' order. */
  readonly notes = input.required<readonly NoteDraft[]>();
  /** The notes waiting for a team of their members. */
  readonly waiting = input.required<readonly WaitingNote[]>();
  /** The catalog's characters, to pick from. */
  readonly characters = input.required<readonly EditorCharacter[]>();
  /** The build's own character: in every team. */
  readonly own = input.required<EditorCharacter>();
  /** The role the build's own character plays in a new team: the build's. */
  readonly ownRole = input<string | null>(null);
  readonly roles = input.required<readonly string[]>();
  readonly maxRank = input(6);
  readonly rankPrefix = input('C');
  readonly teamSize = input(4);
  readonly maxTeams = input(20);
  readonly maxName = input(60);
  readonly maxStandIns = input(5);
  readonly maxNote = input(5000);
  /** The problems shown at the fields, by place ("teams[0].members[1].key", "notes.teams[0].en"). */
  readonly errors = input<Record<string, string>>({});

  readonly teamsChange = output<TeamsChange>();

  protected readonly rowId = rowId;
  protected readonly waitingTitleId = `waiting-title-${++forms}`;

  /** How many stand-ins each member has had added here, by its row: its picker is drawn anew with each, empty for the next. */
  private readonly added = signal<ReadonlyMap<string, number>>(new Map());
  /** The team each waiting note is to be moved to - by the team's row (rowId()), never its place -, by the note's key. */
  private readonly moveTo = signal<ReadonlyMap<string, string>>(new Map());
  /** Each character's option, made once: a picker given the same option again keeps what is typed in it. */
  private readonly made = new Map<string, ComboboxOption>();

  private readonly byKey = computed(() => new Map(this.characters().map((character) => [character.key, character])));

  protected readonly roleOptions = computed<SelectOption[]>(() => [{ value: '', label: 'Not said' }, ...this.roles().map((role) => ({ value: role, label: roleName(role) }))]);
  protected readonly rankOptions = computed<SelectOption[]>(() =>
    Array.from({ length: this.maxRank() + 1 }, (_, rank) => ({ value: String(rank), label: rank === 0 ? 'Any' : `${this.rankPrefix()}${rank}` })),
  );

  /** The teams a waiting note can be moved to: those with no note of their own, each by its row. */
  protected readonly moveTargets = computed<SelectOption[]>(() =>
    this.teams().flatMap((team, t) => (noteOf(this.notes()[t] ?? EMPTY_NOTE) === null ? [{ value: rowId(team), label: `Team ${t + 1}: ${team.name.trim() || 'not named yet'}` }] : [])),
  );

  private option(character: EditorCharacter): ComboboxOption {
    return this.once(`${character.key}\n${character.name}\n${character.icon_url ?? ''}`, () => ({ value: character.key, label: character.name, picture: character.icon_url }));
  }

  private once(key: string, make: () => ComboboxOption): ComboboxOption {
    let option = this.made.get(key);
    if (!option) {
      option = make();
      this.made.set(key, option);
    }
    return option;
  }

  /** The characters a member's picker offers: the catalog's but the team's others - and its own where the catalog lacks it. */
  protected memberOptions(team: EditorTeam, member: EditorMember): ComboboxOption[] {
    const taken = new Set(team.members.filter((other) => other !== member).map((other) => other.key));
    const options = this.characters()
      .filter((character) => !taken.has(character.key))
      .map((character) => this.option(character));
    if (member.key !== '' && !this.byKey().has(member.key)) {
      options.unshift(this.once(`${member.key}\n${member.name}\nunknown`, () => ({ value: member.key, label: `${member.name} (not in the catalog)` })));
    }
    return options;
  }

  /** Who can stand in for a member: the catalog's characters but the team's members and its stand-ins already. */
  protected standInOptions(team: EditorTeam, member: EditorMember): ComboboxOption[] {
    const taken = new Set([...team.members.map((one) => one.key), ...member.alternatives.map((one) => one.key)]);
    return this.characters()
      .filter((character) => !taken.has(character.key))
      .map((character) => this.option(character));
  }

  protected standInChips(member: EditorMember): ChipItem[] {
    return member.alternatives.map((one) => ({ value: one.key, label: this.byKey().get(one.key)?.name ?? one.name }));
  }

  /** How many stand-ins a member has had added here: its picker's turn. */
  protected addedTo(member: EditorMember): number {
    return this.added().get(rowId(member)) ?? 0;
  }

  protected isOwn(member: EditorMember): boolean {
    return member.key === this.own().key;
  }

  /** Whether the catalog knows a member's character (an empty member's is nobody's to know). */
  protected known(key: string): boolean {
    return key === '' || this.byKey().has(key);
  }

  protected icon(key: string): string | null {
    return this.byKey().get(key)?.icon_url ?? null;
  }

  /** A member's name, for its controls' names: its character's, or its place while it has none. */
  protected memberName(member: EditorMember, index: number): string {
    return this.byKey().get(member.key)?.name ?? (member.name || `member ${index + 1}`);
  }

  /** A team's name, for its controls' names: "team 2 (Freeze)". */
  protected teamName(team: EditorTeam, index: number): string {
    const name = team.name.trim();
    return name ? `team ${index + 1} (${name})` : `team ${index + 1}`;
  }

  /** The members a waiting note waits for: "Hu Tao, Xingqiu". */
  protected waitingMembers(entry: WaitingNote): string {
    return entry.members.map((member) => this.byKey().get(member.key)?.name ?? member.name).join(', ');
  }

  protected error(path: string): string | null {
    return this.errors()[path] ?? null;
  }

  /** A team's note: two empty boxes where it has none yet. */
  protected noteAt(t: number): NoteDraft {
    return this.notes()[t] ?? EMPTY_NOTE;
  }

  protected noteErrors(t: number): { en: string | null; sk: string | null } {
    return { en: this.error(`notes.teams[${t}].en`), sk: this.error(`notes.teams[${t}].sk`) };
  }

  /** The team chosen for a waiting note - while it can still take it. */
  protected chosenTarget(entry: WaitingNote): string {
    const chosen = this.moveTo().get(entry.key) ?? '';
    return this.moveTargets().some((target) => target.value === chosen) ? chosen : '';
  }

  /** The team a save gives a waiting note to (exactly its members, no note of its own), as the page says it: "Team 2 (Freeze)"; null for none. */
  protected takerName(entry: WaitingNote): string | null {
    const t = takerOf(entry, this.teams(), this.noteList());
    const team = this.teams()[t];
    return team ? `Team ${t + 1}${team.name.trim() ? ` (${team.name.trim()})` : ''}` : null;
  }

  /** A member's group's name: "Member 2: Xingqiu", or that no character is chosen yet. */
  protected memberLabel(member: EditorMember, index: number): string {
    const name = this.byKey().get(member.key)?.name ?? member.name;
    return `Member ${index + 1}: ${name || 'no character chosen yet'}`;
  }

  // ── what comes out ────────────────────────────────────────────────────────────────────────────

  /** The notes, one a team, in the teams' order: '' boxes for a team that has none yet. */
  private noteList(): NoteDraft[] {
    return this.teams().map((_, t) => this.notes()[t] ?? EMPTY_NOTE);
  }

  private emit(teams: EditorTeam[], notes: NoteDraft[] = this.noteList(), waiting: WaitingNote[] = [...this.waiting()]): void {
    this.teamsChange.emit({ teams, notes, waiting });
  }

  private changeTeam(t: number, update: (team: EditorTeam) => EditorTeam): void {
    this.emit(replaced(this.teams(), t, update));
  }

  private changeMember(t: number, m: number, update: (member: EditorMember) => EditorMember): void {
    this.changeTeam(t, (team) => ({ ...team, members: replaced(team.members, m, update) }));
  }

  private newMember(): EditorMember {
    return { key: '', name: '', role: null, min_rank: 0, alternatives: [] };
  }

  // ── teams ─────────────────────────────────────────────────────────────────────────────────────

  /** A new team, last: the build's own character and one member to choose - its name in hand. */
  protected addTeam(): void {
    const own = this.own();
    const team: EditorTeam = { name: '', members: [{ key: own.key, name: own.name, role: this.ownRole(), min_rank: 0, alternatives: [] }, this.newMember()] };
    this.emit([...this.teams(), team], [...this.noteList(), { ...EMPTY_NOTE }]);
    afterNextRender(() => this.focusTeam(this.teams().length - 1), { injector: this.injector });
  }

  protected setName(t: number, name: string): void {
    this.changeTeam(t, (team) => ({ ...team, name }));
  }

  /** A team moved, its note with it. */
  protected moveTeam(t: number, delta: -1 | 1): void {
    this.emit(moved(this.teams(), t, delta), moved(this.noteList(), t, delta));
  }

  /** A team taken away - asked first where it has a note, which then waits for a team of its members (or goes with it). */
  protected async removeTeam(t: number): Promise<void> {
    const team = this.teams()[t];
    if (!team) {
      return;
    }
    const note = this.noteList()[t];
    const waits = waitingOf(team, note, this.waiting());
    if (noteOf(note) !== null) {
      const sure = await this.confirm.ask({
        title: `Remove ${this.teamName(team, t)}?`,
        message: waits ? "Its note waits below for a team of the same members, and is that team's again once one is back. Delete it there to lose it." : 'Its note goes with it.',
        confirmLabel: 'Remove',
        tone: 'danger',
      });
      // The teams as they stand once answered: the same team still at its place, or nothing done.
      if (!sure || this.teams()[t] !== team) {
        return;
      }
    }
    this.emit(
      this.teams().filter((_, i) => i !== t),
      this.noteList().filter((_, i) => i !== t),
      waits ? [...this.waiting(), waits] : [...this.waiting()],
    );
    this.announcer.announce(waits ? `Team ${t + 1} removed: its note waits below.` : `Team ${t + 1} removed.`);
    afterNextRender(() => this.focusTeam(Math.min(t, this.teams().length - 1)), { injector: this.injector });
  }

  protected setNote(t: number, note: NoteDraft): void {
    this.emit(
      [...this.teams()],
      replaced(this.noteList(), t, () => note),
    );
  }

  // ── members ───────────────────────────────────────────────────────────────────────────────────

  /** A member added, last in its team: no character yet, its picker in hand. */
  protected addMember(t: number): void {
    this.changeTeam(t, (team) => ({ ...team, members: [...team.members, this.newMember()] }));
    afterNextRender(() => this.focusMember(t, (this.teams()[t]?.members.length ?? 1) - 1), { injector: this.injector });
  }

  /** A member's character picked by its name - or none (the field emptied). */
  protected pickMember(t: number, m: number, key: string | null): void {
    const member = this.teams()[t]?.members[m];
    if (!member || (key ?? '') === member.key) {
      return;
    }
    const known = key === null ? undefined : this.byKey().get(key);
    this.changeMember(t, m, (one) => ({ ...one, key: key ?? '', name: known?.name ?? '' }));
  }

  protected setRole(t: number, m: number, role: string): void {
    this.changeMember(t, m, (one) => ({ ...one, role: role === '' ? null : role }));
  }

  protected setRank(t: number, m: number, rank: string): void {
    this.changeMember(t, m, (one) => ({ ...one, min_rank: Number(rank) }));
  }

  protected moveMember(t: number, m: number, delta: -1 | 1): void {
    this.changeTeam(t, (team) => ({ ...team, members: moved(team.members, m, delta) }));
  }

  /** A member taken away (never the build's own character) - the focus on the picker of the one now in its place. */
  protected removeMember(t: number, m: number): void {
    const team = this.teams()[t];
    const member = team?.members[m];
    if (!team || !member || this.isOwn(member)) {
      return;
    }
    this.changeTeam(t, (one) => ({ ...one, members: one.members.filter((_, i) => i !== m) }));
    this.announcer.announce(`${this.memberName(member, m)} removed from ${this.teamName(team, t)}.`);
    afterNextRender(() => this.focusMember(t, Math.min(m, (this.teams()[t]?.members.length ?? 1) - 1)), { injector: this.injector });
  }

  /** A stand-in added, last - its picker drawn anew for the next one, the focus in it. */
  protected addStandIn(t: number, m: number, key: string | null): void {
    const member = this.teams()[t]?.members[m];
    if (key === null || !member || member.alternatives.some((one) => one.key === key)) {
      return;
    }
    const name = this.byKey().get(key)?.name ?? key;
    this.changeMember(t, m, (one) => ({ ...one, alternatives: [...one.alternatives, { key, name }] }));
    const row = rowId(member);
    this.added.update((added) => new Map(added).set(row, (added.get(row) ?? 0) + 1));
    afterNextRender(() => this.focusStandInPicker(t, m), { injector: this.injector });
  }

  protected removeStandIn(t: number, m: number, key: string): void {
    const member = this.teams()[t]?.members[m];
    this.changeMember(t, m, (one) => ({ ...one, alternatives: one.alternatives.filter((alternative) => alternative.key !== key) }));
    if (member?.alternatives.length === 1) {
      afterNextRender(() => this.focusStandInPicker(t, m), { injector: this.injector });
    }
  }

  // ── waiting notes ─────────────────────────────────────────────────────────────────────────────

  protected chooseTarget(entry: WaitingNote, value: string): void {
    this.moveTo.update((chosen) => new Map(chosen).set(entry.key, value));
  }

  /** A waiting note moved to the team chosen for it: that team's note now. */
  protected moveWaiting(w: number): void {
    const entry = this.waiting()[w];
    const chosen = entry ? this.chosenTarget(entry) : '';
    const t = this.teams().findIndex((team) => rowId(team) === chosen);
    const team = this.teams()[t];
    if (!entry || !team || noteOf(this.noteList()[t]) !== null) {
      return;
    }
    this.emit(
      [...this.teams()],
      replaced(this.noteList(), t, () => noteDraft(entry.note)),
      this.waiting().filter((_, i) => i !== w),
    );
    this.announcer.announce(`The note is ${this.teamName(team, t)}'s now.`);
    afterNextRender(() => this.focusNote(t), { injector: this.injector });
  }

  protected deleteWaiting(w: number): void {
    this.emit(
      [...this.teams()],
      this.noteList(),
      this.waiting().filter((_, i) => i !== w),
    );
    this.announcer.announce('The waiting note deleted.');
    afterNextRender(
      () => {
        const entries = this.host.nativeElement.querySelectorAll<HTMLElement>('.waiting-group');
        // The next one's Move to (where a team can take it), else Add team - never its Delete.
        const entry = entries[Math.min(w, entries.length - 1)];
        (entry?.querySelector<HTMLElement>('select:not(:disabled)') ?? entry ?? this.host.nativeElement.querySelector<HTMLElement>('button.add-team'))?.focus();
      },
      { injector: this.injector },
    );
  }

  // ── the focus ─────────────────────────────────────────────────────────────────────────────────

  /** The focus on a team's name - on Add team where no team is left. */
  private focusTeam(t: number): void {
    const team = t >= 0 ? this.host.nativeElement.querySelectorAll<HTMLElement>('.team')[t] : undefined;
    (team?.querySelector<HTMLElement>('.team-name input') ?? this.host.nativeElement.querySelector<HTMLElement>('button.add-team'))?.focus();
  }

  /** The focus on a member's picker - its role where it is the build's own character. */
  private focusMember(t: number, m: number): void {
    const member = this.host.nativeElement.querySelectorAll<HTMLElement>('.team')[t]?.querySelectorAll<HTMLElement>('.member')[m];
    member?.querySelector<HTMLElement>('.pick input, .role select')?.focus();
  }

  private focusStandInPicker(t: number, m: number): void {
    const member = this.host.nativeElement.querySelectorAll<HTMLElement>('.team')[t]?.querySelectorAll<HTMLElement>('.member')[m];
    (member?.querySelector<HTMLElement>('.add-stand-in input') ?? member?.querySelector<HTMLElement>('.stand-ins .full'))?.focus();
  }

  private focusNote(t: number): void {
    this.host.nativeElement.querySelectorAll<HTMLElement>('.team')[t]?.querySelector<HTMLElement>('.team-note textarea')?.focus();
  }
}
