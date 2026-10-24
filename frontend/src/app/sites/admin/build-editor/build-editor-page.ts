import { HttpErrorResponse } from '@angular/common/http';
import { DOCUMENT } from '@angular/common';
import { Component, DestroyRef, ElementRef, Injector, afterNextRender, computed, effect, inject, input, signal, untracked, viewChild } from '@angular/core';
import { RouterLink } from '@angular/router';
import { HoldsUnsavedChanges } from '@anotoki/lib/translations';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import type { BadgeTone } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { MenuComponent, MenuItemComponent, MenuSeparatorComponent, MenuTriggerDirective } from '@anotoki/lib/ui/menu';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { SelectComponent, SelectOption } from '@anotoki/lib/ui/select';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { TabItem, TabsComponent } from '@anotoki/lib/ui/tabs';
import { ToastService } from '@anotoki/lib/ui/toast';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { firstValueFrom } from 'rxjs';
import { Api, errorMessage } from '../../../api/api';
import { BuildEditorChanges } from '../../../shared/build-editor-changes';
import { GAMES } from '../../../shared/format';
import {
  BuildPart,
  CharacterEditing,
  EditorBuild,
  EditorCatalog,
  EditorCharacter,
  EditorRefusal,
  EditorSetOption,
  EditorTarget,
  EditorWeapon,
  GameKey,
  StatPriority,
  TalentPriority,
} from '../../../api/models';
import { TimeAgoPipe } from '../../../shared/stat-pipes';
import { roleName, variationName } from '../builds/build-file';
import {
  BuildName,
  Draft,
  DraftBuild,
  NoteDraft,
  Problem,
  TeamsChange,
  addCopy,
  addNew,
  bringBack,
  bringable,
  buildBody,
  deleteBuild,
  editedParts,
  fieldFor,
  fieldsOf,
  fromServer,
  giveOrderBack,
  inFileOrder,
  moveBuild,
  nameFixed,
  orderOwner,
  partText,
  problems,
  putBack,
  renameBuild,
  renamedOriginal,
  saveBody,
  saveText,
  updateBuild,
  useFilePart,
  waitingFields,
  withField,
  withPart,
  withTeamsChange,
} from './editor-draft';
import { EditorAnnouncer } from './editor-announcer';
import { EditorCatalogs } from './editor-catalogs';
import { MoveButtons } from './move-buttons/move-buttons';
import { NoteFields } from './note-fields/note-fields';
import { PartCard } from './part-card/part-card';
import { Ranked, RankedList } from './ranked-list/ranked-list';
import { SetsForm } from './sets-form/sets-form';
import { TargetsForm } from './targets-form/targets-form';
import { TeamsForm } from './teams-form/teams-form';
import { WeaponsForm } from './weapons-form/weapons-form';

/** A part of a build, as a card's heading and a sentence name it (in the order an import names them). */
const PART_NAMES: Record<BuildPart, { heading: string; name: string }> = {
  weapons: { heading: 'Weapons', name: 'weapons' },
  sets: { heading: 'Artifact sets', name: 'artifact sets' },
  main_stats: { heading: 'Main stats', name: 'main stats' },
  substats: { heading: 'Substats', name: 'substats' },
  talent_priority: { heading: 'Talents', name: 'talents' },
  stat_targets: { heading: 'Stat targets', name: 'stat targets' },
  teams: { heading: 'Teams', name: 'teams' },
  game_version: { heading: 'Game version', name: 'game version' },
};

/** The parts shown as cards of their own, in this order; the game version is the Variation card's. */
const PART_CARDS: readonly BuildPart[] = ['weapons', 'sets', 'main_stats', 'substats', 'talent_priority', 'stat_targets', 'teams'];

/** What a build's tab says of it beside its variation. */
interface BuildState {
  label: string;
  tone: BadgeTone;
}

/** A part's state on its card. */
interface PartState {
  edited: boolean;
  unsaved: boolean;
}

/** No rows: one list for every empty one, so a form is not handed a new one at every turn. */
const NONE: readonly never[] = [];

/** What a bound typed that is no number is, at Save. */
const UNREADABLE = 'Write a number, such as 70 or 120.5 - or leave it empty.';

/**
 * /admin/builds/:game/:key (editors and admins; English, as the whole admin panel) - the builds editor (docs/builds.md,
 * "The editor"): one character's builds changed by hand, in one go. A tab a build (the kit's tabs), named by its
 * variation, with what it is - "Edited" (it differs from what the builds file says of it), "Made here", "New", "Brought
 * back", "Unsaved". Add build makes a new one, a copy of the one open, or brings back one an editor removed (the file's,
 * remembered with its notes) or one deleted on this page; a build is moved earlier or later (the order then the
 * editor's - said, kept by every import; "Give the order back to the file" undoes it) or deleted, which asks first.
 *
 * A build's cards: its Variation - role, qualifier, constellation (a build of the file's renamed becomes one of the
 * editor's own in its place, the file's set aside to be removed at the save - said so, with the way back) and game
 * version -, its Notes - the site's own, English and Slovak (shown to members in
 * their language where written, else in English) -, and its parts, each with "Edited" / "Unsaved" and "Use the
 * file's" - a form each, the teams' with each team's note (teams-form). A part changed
 * back to what was loaded is what was loaded again (editor-draft.ts: withPart()), so the page holds no change nobody
 * sees.
 *
 * Nothing is written until Save, which sends the character's builds whole (PUT /api/admin/builds/{game}/characters/{key}:
 * a part nobody touched goes back as it came - editor-draft.ts). The page checks what it can first (editor-draft.ts:
 * problems()), the server the rest: a problem is shown at its field - the build's tab opened, the field scrolled to and
 * focused -, or above the build's cards where it has none. When the builds changed since they were loaded (409, an
 * import or another editor's save) the page says so and keeps what was typed until Reload; while an import is being
 * written (409 busy) a message says to try again. Leaving the page with changes not saved asks first (the route's
 * guard asks canLeave()), closing the tab too (the browser's own question), and so does the top bar's Sign out
 * (shared/build-editor-changes.ts). Drawn with the anotoki family's kit.
 */
@Component({
  selector: 'app-build-editor-page',
  imports: [
    RouterLink,
    AlertComponent,
    ButtonComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    IconComponent,
    MenuComponent,
    MenuItemComponent,
    MenuSeparatorComponent,
    MenuTriggerDirective,
    PageHeaderComponent,
    SelectComponent,
    SpinnerComponent,
    TabsComponent,
    TextFieldComponent,
    TooltipDirective,
    TimeAgoPipe,
    MoveButtons,
    NoteFields,
    PartCard,
    RankedList,
    SetsForm,
    TargetsForm,
    TeamsForm,
    WeaponsForm,
  ],
  templateUrl: './build-editor-page.html',
  styleUrl: './build-editor-page.scss',
  host: { '(window:beforeunload)': 'beforeUnload($event)' },
})
export class BuildEditorPage implements HoldsUnsavedChanges {
  private readonly api = inject(Api);
  private readonly catalogs = inject(EditorCatalogs);
  private readonly confirm = inject(ConfirmService);
  private readonly toasts = inject(ToastService);
  private readonly changes = inject(BuildEditorChanges);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly document = inject(DOCUMENT);
  /** The bar of Save and Discard, while it is there. */
  private readonly saveBar = viewChild<ElementRef<HTMLElement>>('saveBar');
  private readonly injector = inject(Injector);
  protected readonly announcer = inject(EditorAnnouncer);

  readonly game = input.required<string>();
  readonly key = input.required<string>();

  protected readonly partCards = PART_CARDS;
  protected readonly partNames = PART_NAMES;

  protected readonly gameKey = computed<GameKey | null>(() => GAMES.find((game) => game === this.game()) ?? null);
  protected readonly catalog = signal<EditorCatalog | null>(null);
  protected readonly who = signal<EditorCharacter | null>(null);
  /** The builds as the server last gave them. */
  private readonly loaded = signal<Draft | null>(null);
  /** The builds as they stand on the page. */
  protected readonly draft = signal<Draft | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly saving = signal(false);
  /** Why the last save failed, where it was none of the answers below: on the page, never only in a passing message. */
  protected readonly saveError = signal<string | null>(null);
  /** The builds changed since they were loaded (409 changed): the server's sentence, until they are loaded again. */
  protected readonly conflict = signal<string | null>(null);
  /** What a save would be refused for, by "uid path" of its field. */
  protected readonly fieldErrors = signal<Record<string, string>>({});
  /** What a save would be refused for where no field shows it: above the build's cards, or above the tabs. */
  protected readonly otherErrors = signal<{ uid: string; message: string }[]>([]);
  /** The open build's uid. */
  protected readonly active = signal<string | null>(null);

  protected readonly facts = computed(() => this.catalog()?.facts ?? null);
  protected readonly rankPrefix = computed(() => this.facts()?.rank_prefix ?? 'C');
  protected readonly refinementPrefix = computed(() => this.facts()?.refinement_prefix ?? 'R');
  private readonly loadedText = computed(() => {
    const loaded = this.loaded();
    return loaded ? saveText(loaded) : null;
  });
  /** The page holds changes a save would write - or a bound typed that is no number yet, which a save would say. */
  protected readonly dirty = computed(() => {
    const draft = this.draft();
    return draft !== null && (saveText(draft) !== this.loadedText() || this.unreadableIn(draft).length > 0);
  });
  private readonly loadedBuilds = computed(() => new Map((this.loaded()?.builds ?? []).map((build) => [build.uid, build])));
  protected readonly activeBuild = computed<DraftBuild | null>(() => {
    const builds = this.draft()?.builds ?? [];
    return builds.find((build) => build.uid === this.active()) ?? builds.at(0) ?? null;
  });
  protected readonly activeIndex = computed(() => {
    const active = this.activeBuild();
    return active ? (this.draft()?.builds ?? []).indexOf(active) : -1;
  });
  protected readonly tabItems = computed<TabItem[]>(() =>
    (this.draft()?.builds ?? []).map((build) => {
      const state = this.stateOf(build);
      return { id: build.uid, label: variationName(build.build, this.rankPrefix()), badge: state?.label ?? null, badgeTone: state?.tone ?? null };
    }),
  );
  /** Whose order the builds will be in once saved. */
  protected readonly owner = computed(() => {
    const draft = this.draft();
    return draft ? orderOwner(draft) : 'file';
  });
  protected readonly canGiveOrderBack = computed(() => {
    const draft = this.draft();
    return draft !== null && (!inFileOrder(draft.builds) || (draft.order === 'editors' && !draft.orderToFile));
  });
  protected readonly orderToFile = computed(() => this.draft()?.orderToFile ?? false);
  protected readonly bringable = computed(() => {
    const draft = this.draft();
    return draft ? bringable(draft) : [];
  });
  protected readonly deleted = computed(() => this.draft()?.deleted ?? []);
  protected readonly roleOptions = computed<SelectOption[]>(() => (this.facts()?.roles ?? []).map((role) => ({ value: role, label: roleName(role) })));
  protected readonly rankOptions = computed<SelectOption[]>(() =>
    Array.from({ length: (this.facts()?.max_rank ?? 6) + 1 }, (_, rank) => ({ value: String(rank), label: rank === 0 ? 'None needed' : `${this.rankPrefix()}${rank}` })),
  );
  /** The open build's parts: which differ from the file's, which from what was loaded. */
  protected readonly parts = computed<Record<BuildPart, PartState>>(() => {
    const build = this.activeBuild();
    const loaded = build ? this.loadedBuilds().get(build.uid) : undefined;
    const states = {} as Record<BuildPart, PartState>;
    for (const part of [...PART_CARDS, 'game_version' as const]) {
      states[part] = {
        edited: build !== null && build.file !== null && partText(build.build, part) !== partText(build.file, part),
        unsaved:
          build !== null &&
          loaded !== undefined &&
          (partText(build.build, part) !== partText(loaded.build, part) ||
            // A team's note, or one waiting for a team, is the teams' card's.
            (part === 'teams' && JSON.stringify([buildBody(build).notes.teams, buildBody(build).notes.waiting]) !== JSON.stringify([buildBody(loaded).notes.teams, buildBody(loaded).notes.waiting]))),
      };
    }
    return states;
  });
  /** The open build's variation - its name, its game version - changed since it was loaded. */
  protected readonly variationUnsaved = computed(() => {
    const build = this.activeBuild();
    const loaded = build ? this.loadedBuilds().get(build.uid) : undefined;
    if (!build || !loaded) {
      return false;
    }
    const [now, was] = [build.build, loaded.build];
    return now.role !== was.role || now.qualifier !== was.qualifier || now.min_rank !== was.min_rank || this.parts().game_version.unsaved;
  });
  /** The open build's own note changed since it was loaded (its teams' are the teams' card's). */
  protected readonly notesUnsaved = computed(() => {
    const build = this.activeBuild();
    const loaded = build ? this.loadedBuilds().get(build.uid) : undefined;
    return build !== null && loaded !== undefined && JSON.stringify(buildBody(build).notes.build) !== JSON.stringify(buildBody(loaded).notes.build);
  });
  protected readonly none = NONE;
  /** The bounds typed that are no number yet, by build: the save waits for them (the targets' form says them). */
  private readonly unreadable = signal<Record<string, readonly string[]>>({});

  /** The bounds typed that are no number yet in the builds on the page, as what a save would say of them. */
  private unreadableIn(draft: Draft): Problem[] {
    return Object.entries(this.unreadable()).flatMap(([uid, places]) => (draft.builds.some((build) => build.uid === uid) ? places.map((path) => ({ uid, path, message: UNREADABLE })) : []));
  }
  /** Each load and each Discard draws the parts' forms anew: nothing typed there outlives what it was typed into. */
  protected readonly formGeneration = signal(0);
  /** The open build's problems, by place: what its forms show at their fields. */
  protected readonly buildErrors = computed(() => {
    const uid = this.activeBuild()?.uid;
    const errors: Record<string, string> = {};
    for (const [key, message] of Object.entries(this.fieldErrors())) {
      const at = key.indexOf(' ');
      if (key.slice(0, at) === uid) {
        errors[key.slice(at + 1)] = message;
      }
    }
    return errors;
  });
  /** What the forms pick from, each with its English name (the admin panel's). */
  protected readonly slotKeys = computed(() => Object.keys(this.facts()?.slots ?? {}));
  protected readonly slotChoices = computed(() => {
    const facts = this.facts();
    const choices: Record<string, SelectOption[]> = {};
    for (const [slot, stats] of Object.entries(facts?.slots ?? {})) {
      choices[slot] = stats.map((stat) => ({ value: stat, label: facts?.labels.stats[stat] ?? stat }));
    }
    return choices;
  });
  protected readonly substatChoices = computed<SelectOption[]>(() => {
    const facts = this.facts();
    return (facts?.substats ?? []).map((stat) => ({ value: stat, label: facts?.labels.stats[stat] ?? stat }));
  });
  protected readonly talentChoices = computed<SelectOption[]>(() => {
    const facts = this.facts();
    return (facts?.talents ?? []).map((talent) => ({ value: talent, label: facts?.labels.talents[talent] ?? talent }));
  });
  /** A slot's main stats as the build lists them; none where it does not. */
  protected slotRows(build: DraftBuild, slot: string): readonly StatPriority[] {
    return Object.hasOwn(build.build.main_stats, slot) ? build.build.main_stats[slot] : NONE;
  }

  /** What a slot's main stats can be. */
  protected slotOptions(slot: string): readonly SelectOption[] {
    return Object.hasOwn(this.slotChoices(), slot) ? this.slotChoices()[slot] : NONE;
  }

  /** A slot's name: "Sands of Eon". */
  protected slotName(slot: string): string {
    const names = this.facts()?.labels.slots ?? {};
    return Object.hasOwn(names, slot) ? names[slot] : slot;
  }

  protected readonly statChoices = computed<SelectOption[]>(() => {
    const facts = this.facts();
    return (facts?.stats ?? []).map((stat) => ({ value: stat, label: facts?.labels.stats[stat] ?? stat }));
  });

  constructor() {
    this.changes.givenUp.set(false);
    effect(() => this.changes.unsaved.set(this.dirty()));
    inject(DestroyRef).onDestroy(() => this.changes.unsaved.set(false));
    // While the bar of Save and Discard stands at the foot of the window, the page scrolls a field it moves to above it,
    // never under it (WCAG 2.4.11): its height, measured, kept clear at the foot of the window.
    effect((onCleanup) => {
      const bar = this.saveBar()?.nativeElement;
      const root = this.document.documentElement;
      if (!bar) {
        return;
      }
      const keepClear = () => root.style.setProperty('scroll-padding-bottom', `${bar.offsetHeight + 16}px`);
      keepClear();
      const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(keepClear);
      observer?.observe(bar);
      onCleanup(() => {
        observer?.disconnect();
        root.style.removeProperty('scroll-padding-bottom');
      });
    });
    effect(() => {
      const game = this.gameKey();
      const key = this.key();
      untracked(() => void this.load(game, key));
    });
  }

  // ── loading ───────────────────────────────────────────────────────────────────────────────────

  /** The game's catalog (once) and the character's builds; a later load of another address wins. */
  private async load(game: GameKey | null, key: string): Promise<void> {
    this.loading.set(true);
    this.loadError.set(null);
    const current = () => game === this.gameKey() && key === this.key();
    try {
      if (game === null) {
        throw new Error('There is no such game.');
      }
      const [catalog, editing] = await Promise.all([this.catalogs.get(game), firstValueFrom(this.api.characterEditing(game, key))]);
      if (current()) {
        this.catalog.set(catalog);
        this.take(editing, null);
      }
    } catch (err: unknown) {
      if (current()) {
        this.loadError.set(errorMessage(err));
      }
    } finally {
      if (current()) {
        this.loading.set(false);
      }
    }
  }

  /** The server's state, loaded and on the page, every problem forgotten; the open build's place kept where there still is one. */
  private take(answer: CharacterEditing, keepIndex: number | null): void {
    const draft = fromServer(answer);
    this.who.set(answer.character);
    this.loaded.set(draft);
    this.draft.set(draft);
    this.fieldErrors.set({});
    this.otherErrors.set([]);
    this.conflict.set(null);
    this.saveError.set(null);
    this.unreadable.set({});
    this.formGeneration.update((generation) => generation + 1);
    const builds = draft.builds;
    this.active.set(builds.length === 0 ? null : builds[Math.min(Math.max(keepIndex ?? 0, 0), builds.length - 1)].uid);
  }

  /** The builds read again as saved now - what was changed here given up, asked first. */
  protected async reload(): Promise<void> {
    if (this.dirty()) {
      const sure = await this.confirm.ask({
        title: 'Reload and lose your changes?',
        message: 'The builds are read again as they are saved now: what was changed here and not saved is lost.',
        confirmLabel: 'Reload',
        tone: 'danger',
      });
      if (!sure) {
        return;
      }
    }
    await this.load(this.gameKey(), this.key());
  }

  // ── what the page says ────────────────────────────────────────────────────────────────────────

  /** "Builds of Hu Tao". */
  protected heading(): string {
    const who = this.who();
    return who ? `Builds of ${who.name}` : 'Builds editor';
  }

  /** A build's variation: "Main DPS · Melt · C2+". */
  protected variation(build: Pick<EditorBuild, 'role' | 'qualifier' | 'min_rank'>): string {
    return variationName(build, this.rankPrefix());
  }

  /** What a build's tab says of it: new, brought back, unsaved, made here, edited - or nothing. */
  private stateOf(build: DraftBuild): BuildState | null {
    const loaded = this.loadedBuilds().get(build.uid);
    if (!loaded) {
      const draft = this.draft();
      if (draft && renamedOriginal(draft, build)) {
        return { label: 'Renamed', tone: 'warning' };
      }
      return build.removal !== null ? { label: 'Brought back', tone: 'success' } : { label: 'New', tone: 'success' };
    }
    if (JSON.stringify(buildBody(build)) !== JSON.stringify(buildBody(loaded))) {
      return { label: 'Unsaved', tone: 'warning' };
    }
    if (build.file === null) {
      return { label: 'Made here', tone: 'neutral' };
    }
    return editedParts(build).length > 0 ? { label: 'Edited', tone: 'info' } : null;
  }

  /** Where a build comes from, in a line over its cards. */
  protected origin(build: DraftBuild): string {
    if (this.renamedName(build) !== null) {
      return 'Renamed from the builds file: a build of your own once saved';
    }
    if (build.removal !== null) {
      return 'Brought back: from the builds file, removed by an editor';
    }
    if (build.id === null) {
      return 'Made here: not saved yet';
    }
    return build.file !== null ? 'From the builds file' : 'Made here: no builds file has it';
  }

  /** The name of the build of the file's a build was renamed from on this page ("Main DPS · Vape"); null for none. */
  protected renamedName(build: DraftBuild): string | null {
    const draft = this.draft();
    const original = draft ? renamedOriginal(draft, build) : null;
    return original ? this.variation(original.build) : null;
  }

  /** The problem shown at a field of a build, or none. */
  protected error(build: DraftBuild, path: string): string | null {
    return this.fieldErrors()[`${build.uid} ${path}`] ?? null;
  }

  protected noteErrors(build: DraftBuild, path: string): { en: string | null; sk: string | null } {
    return { en: this.error(build, `${path}.en`), sk: this.error(build, `${path}.sk`) };
  }

  /** The problems of a build - or of the builds as a whole ('') - that no field shows. */
  protected othersOf(uid: string): string[] {
    return this.otherErrors()
      .filter((problem) => problem.uid === uid)
      .map((problem) => problem.message);
  }

  // ── changing the builds ───────────────────────────────────────────────────────────────────────

  /**
   * A build changed at some places: the problems shown there forgotten - at each place, and inside it ("weapons" takes
   * "weapons[2].key" with it: a list's rows move, and what was said of them with the rows they were).
   */
  private edit(uid: string, places: readonly string[], change: (build: DraftBuild) => DraftBuild): void {
    this.draft.update((draft) => draft && updateBuild(draft, uid, change));
    this.forget(uid, places);
  }

  /** The problems shown at some places of a build forgotten - at each place, and inside it. */
  private forget(uid: string, places: readonly string[]): void {
    const within = (key: string) => places.some((place) => key === `${uid} ${place}` || key.startsWith(`${uid} ${place}.`) || key.startsWith(`${uid} ${place}[`));
    const errors = this.fieldErrors();
    if (Object.keys(errors).some(within)) {
      this.fieldErrors.set(Object.fromEntries(Object.entries(errors).filter(([key]) => !within(key))));
    }
  }

  /**
   * A build's name changed - its role, qualifier or constellation (renameBuild()): a build of the file's becomes one of
   * the editor's own in its place, or one renamed so is the file's again - its tab open, what was typed in its targets
   * with it, said to a screen reader.
   */
  private rename(build: DraftBuild, name: Partial<BuildName>, places: readonly string[]): void {
    const draft = this.draft();
    if (!draft || this.saving()) {
      return;
    }
    const result = renameBuild(draft, build.uid, name);
    this.draft.set(result.draft);
    this.forget(build.uid, places);
    if (result.uid === build.uid) {
      return;
    }
    const typed = this.unreadable()[build.uid];
    if (typed) {
      this.unreadable.update(({ [build.uid]: _moved, ...rest }) => ({ ...rest, [result.uid]: typed }));
    }
    this.active.set(result.uid);
    const now = result.draft.builds.find((one) => one.uid === result.uid);
    this.announcer.announce(now?.file === null ? "Renamed: a build of your own now - the builds file's is removed when you save." : "Back to the builds file's build, with what it holds now.");
  }

  /** A renamed build named as the file's again: that build again. */
  protected undoRename(build: DraftBuild): void {
    const draft = this.draft();
    const original = draft ? renamedOriginal(draft, build) : null;
    if (original) {
      this.rename(build, { role: original.build.role, qualifier: original.build.qualifier, min_rank: original.build.min_rank }, ['role', 'qualifier', 'min_rank']);
    }
  }

  /** A part of a build set - as it was loaded where it says the same again (withPart()) -, the problems shown in it forgotten. */
  private setPart<K extends Exclude<BuildPart, 'teams'>>(build: DraftBuild, part: K, places: readonly string[], value: (now: EditorBuild) => EditorBuild[K]): void {
    const loaded = this.loadedBuilds().get(build.uid)?.build ?? null;
    this.edit(build.uid, places, (one) => withPart(one, part, value(one.build), loaded));
  }

  protected setWeapons(build: DraftBuild, weapons: EditorWeapon[]): void {
    this.setPart(build, 'weapons', ['weapons'], () => weapons);
  }

  protected setSets(build: DraftBuild, sets: EditorSetOption[]): void {
    this.setPart(build, 'sets', ['sets'], () => sets);
  }

  /** A slot's main stats: listed while it has one at least, left out of the build when it has none - any main stat then. */
  protected setMainStats(build: DraftBuild, slot: string, rows: Ranked[]): void {
    this.setPart(build, 'main_stats', [`main_stats.${slot}`], (now) => {
      const mainStats: Record<string, StatPriority[]> = {};
      for (const [key, stats] of Object.entries(now.main_stats)) {
        if (key !== slot) {
          mainStats[key] = stats;
        } else if (rows.length > 0) {
          mainStats[key] = rows as StatPriority[];
        }
      }
      if (rows.length > 0 && !(slot in mainStats)) {
        mainStats[slot] = rows as StatPriority[];
      }
      return mainStats;
    });
  }

  protected setSubstats(build: DraftBuild, rows: Ranked[]): void {
    this.setPart(build, 'substats', ['substats'], () => rows as StatPriority[]);
  }

  /** The talents in their priority; none, as a file says it, where the list is empty. */
  protected setTalents(build: DraftBuild, rows: Ranked[]): void {
    this.setPart(build, 'talent_priority', ['talent_priority'], () => (rows.length > 0 ? (rows as TalentPriority[]) : null));
  }

  /** The stat targets; none, as a file says it, where the list is empty. */
  protected setTargets(build: DraftBuild, targets: EditorTarget[]): void {
    this.setPart(build, 'stat_targets', ['stat_targets'], () => (targets.length > 0 ? targets : null));
  }

  /** A build's teams as their form leaves them - their notes with them -, as loaded where all of it says the same again. */
  protected setTeams(build: DraftBuild, change: TeamsChange): void {
    const loaded = this.loadedBuilds().get(build.uid) ?? null;
    this.edit(build.uid, ['teams', 'notes.teams', 'notes.waiting'], (one) => withTeamsChange(one, change, loaded));
  }

  /**
   * The bounds typed in a build's targets that are no number yet: the save waits for them. What a save said of a bound
   * that is a number now - or nothing - goes with it.
   */
  protected setUnreadable(build: DraftBuild, places: string[]): void {
    const now = this.unreadable()[build.uid] ?? NONE;
    if (now.join('|') === places.join('|')) {
      return;
    }
    this.unreadable.update((all) => ({ ...all, [build.uid]: places }));
    const gone = new Set(now.filter((place) => !places.includes(place)).map((place) => `${build.uid} ${place}`));
    const errors = this.fieldErrors();
    if (Object.entries(errors).some(([key, message]) => gone.has(key) && message === UNREADABLE)) {
      this.fieldErrors.set(Object.fromEntries(Object.entries(errors).filter(([key, message]) => !(gone.has(key) && message === UNREADABLE))));
    }
  }

  protected setRole(build: DraftBuild, role: string): void {
    this.rename(build, { role }, ['role', 'qualifier']);
  }

  protected setQualifier(build: DraftBuild, qualifier: string): void {
    this.rename(build, { qualifier }, ['qualifier']);
  }

  protected setRank(build: DraftBuild, rank: string): void {
    this.rename(build, { min_rank: Number(rank) }, ['min_rank', 'qualifier']);
  }

  /** The game version as typed; none where nothing is. */
  protected setVersion(build: DraftBuild, version: string): void {
    this.setPart(build, 'game_version', ['game_version'], () => (version.trim() === '' ? null : version));
  }

  /** The game version without the blanks around it, once its field is left. */
  protected tidyVersion(build: DraftBuild): void {
    const version = build.build.game_version;
    if (version !== null && version !== version.trim()) {
      this.setPart(build, 'game_version', [], () => (version.trim() === '' ? null : version.trim()));
    }
  }

  protected setBuildNote(build: DraftBuild, note: NoteDraft): void {
    this.edit(build.uid, ['notes.build.en', 'notes.build.sk'], (one) => ({ ...one, notes: { ...one.notes, build: note } }));
  }

  /** A part as the file has it again - as it was loaded where that is what the file says -, what was said in it forgotten. */
  protected useFile(build: DraftBuild, part: BuildPart): void {
    const loaded = this.loadedBuilds().get(build.uid) ?? null;
    this.edit(build.uid, part === 'teams' ? ['teams', 'notes.teams', 'notes.waiting'] : [part], (one) => useFilePart(one, part, loaded));
    this.announcer.announce(`The builds file's ${PART_NAMES[part].name} again.`);
  }

  protected choose(uid: string): void {
    this.active.set(uid);
  }

  protected addNew(): void {
    const draft = this.draft();
    const facts = this.facts();
    if (draft && facts) {
      const added = addNew(draft, facts);
      this.draft.set(added.draft);
      this.active.set(added.uid);
      this.announcer.announce(`A new build added, last: ${this.variation(added.draft.builds[added.draft.builds.length - 1].build)}.`);
    }
  }

  protected addCopy(): void {
    const draft = this.draft();
    const facts = this.facts();
    const source = this.activeBuild();
    const added = draft && facts && source ? addCopy(draft, source.uid, facts) : null;
    if (added) {
      this.draft.set(added.draft);
      this.active.set(added.uid);
      this.announcer.announce(`A copy added, last: ${this.variation(added.draft.builds[added.draft.builds.length - 1].build)}.`);
    }
  }

  protected bringBack(id: number): void {
    const draft = this.draft();
    const brought = draft ? bringBack(draft, id) : null;
    if (brought) {
      this.draft.set(brought.draft);
      this.active.set(brought.uid);
      this.announcer.announce('The build is back, where the builds file puts it.');
    }
  }

  protected putBack(uid: string): void {
    this.draft.update((draft) => draft && putBack(draft, uid));
    this.active.set(uid);
    this.announcer.announce('The build is back.');
  }

  protected moveActive(delta: -1 | 1): void {
    const build = this.activeBuild();
    if (build) {
      this.draft.update((draft) => draft && moveBuild(draft, build.uid, delta));
    }
  }

  protected giveOrderBack(): void {
    this.draft.update((draft) => draft && giveOrderBack(draft));
    this.announcer.announce("The builds are in the builds file's order.");
  }

  /** The open build deleted from the page - asked first; the save removes it (a build of the file's is remembered). */
  protected async deleteActive(): Promise<void> {
    const build = this.activeBuild();
    if (!build || this.saving()) {
      return;
    }
    const sure = await this.confirm.ask({
      title: `Delete ${this.variation(build.build)}?`,
      message:
        build.file !== null
          ? 'It comes from the builds file: once saved, it is remembered among the removed builds - no import brings it back unasked, and Add build, then Bring back, restores it with its notes.'
          : build.id !== null
            ? 'It was made here: once saved, it is gone. Until then, Add build puts it back.'
            : 'It is not saved yet: it is gone at once.',
      confirmLabel: 'Delete',
      tone: 'danger',
    });
    // The builds as they stand once answered - read now, never from before the question.
    const draft = this.draft();
    if (!sure || !draft || this.saving()) {
      return;
    }
    const index = draft.builds.findIndex((one) => one.uid === build.uid);
    const next = deleteBuild(draft, build.uid);
    this.draft.set(next);
    // What the page said of it goes with it: put back, its targets' form says again what is typed there.
    const errors = this.fieldErrors();
    if (Object.keys(errors).some((key) => key.startsWith(`${build.uid} `))) {
      this.fieldErrors.set(Object.fromEntries(Object.entries(errors).filter(([key]) => !key.startsWith(`${build.uid} `))));
    }
    if (Object.hasOwn(this.unreadable(), build.uid)) {
      this.unreadable.update(({ [build.uid]: _gone, ...rest }) => rest);
    }
    this.active.set(next.builds.at(Math.min(index, next.builds.length - 1))?.uid ?? null);
    this.announcer.announce(`Build ${this.variation(build.build)} deleted.`);
  }

  // ── saving ────────────────────────────────────────────────────────────────────────────────────

  protected async save(): Promise<void> {
    const draft = this.draft();
    const facts = this.facts();
    const game = this.gameKey();
    if (!draft || !facts || !game || this.saving()) {
      return;
    }
    this.saveError.set(null);
    const found = [...problems(draft, facts, this.loaded()), ...this.unreadableIn(draft)];
    if (found.length > 0) {
      this.show(found);
      return;
    }
    this.fieldErrors.set({});
    this.otherErrors.set([]);
    const sent = draft.builds.map((build) => build.uid);
    const keep = this.activeIndex();
    this.saving.set(true);
    try {
      const answer = await firstValueFrom(this.api.saveCharacterEditing(game, this.key(), saveBody(draft)));
      this.take(answer, keep);
      this.toasts.success(`Saved: the builds of ${answer.character.name}.`);
    } catch (err: unknown) {
      this.refused(err, sent);
    } finally {
      this.saving.set(false);
    }
  }

  /** A save refused: the builds changed meanwhile, an import writes, a problem at its place - or the reason, on the page. */
  private refused(err: unknown, sent: readonly string[]): void {
    const answer = err instanceof HttpErrorResponse ? err : null;
    const body = (answer?.error ?? null) as EditorRefusal | null;
    if (answer?.status === 409 && body?.code === 'changed') {
      this.conflict.set(body.message);
      return;
    }
    if (answer?.status === 409) {
      this.toasts.warning(errorMessage(err));
      return;
    }
    if (answer?.status === 422 && body?.message) {
      const uid = typeof body.build === 'number' ? (sent[body.build] ?? '') : '';
      this.show([{ uid, path: body.path ?? '', message: body.message }]);
      return;
    }
    this.saveError.set(`Not saved: ${errorMessage(err)}`);
  }

  /** Problems shown each at its field, or above the cards - the first one's build opened, its field scrolled to and focused. */
  private show(found: readonly Problem[]): void {
    const draft = this.draft();
    const fields: Record<string, string> = {};
    const others: { uid: string; message: string }[] = [];
    // The one opened and focused is the first on the page: those of the builds as a whole, then the builds in their
    // order - in a build those above its cards, then by its fields' order.
    let first: { uid: string; field: string | null; rank: [number, number] } | null = null;
    for (const problem of found) {
      const index = draft?.builds.findIndex((one) => one.uid === problem.uid) ?? -1;
      const build = index >= 0 ? draft?.builds[index] : undefined;
      const places = build ? [...fieldsOf(build.build, this.who()?.key ?? null), ...waitingFields(build.notes.waiting)] : [];
      const field = build ? fieldFor(places, problem.path) : null;
      if (build && field) {
        fields[`${build.uid} ${field}`] ??= problem.message;
      } else {
        others.push({ uid: build ? problem.uid : '', message: problem.message });
      }
      const rank: [number, number] = [index, field ? places.indexOf(field) : -1];
      if (first === null || rank[0] < first.rank[0] || (rank[0] === first.rank[0] && rank[1] < first.rank[1])) {
        first = { uid: build ? problem.uid : '', field, rank };
      }
    }
    this.fieldErrors.set(fields);
    this.otherErrors.set(others);
    if (first?.uid) {
      this.active.set(first.uid);
    }
    const target = first;
    afterNextRender(() => this.focusProblem(target?.uid ? target.field : null, !!target?.uid), { injector: this.injector });
  }

  /** The field a problem is at - or the problems above the build's cards, or above its tabs - scrolled to, and the focus on it. */
  private focusProblem(field: string | null, ofBuild: boolean): void {
    const page = this.host.nativeElement;
    const place = field ? page.querySelector<HTMLElement>(`.build-panel [data-path="${field}"]`) : page.querySelector<HTMLElement>(ofBuild ? '.build-panel .problems' : '.problems.of-all');
    if (!place) {
      return;
    }
    place.scrollIntoView?.({ block: 'center' });
    const control =
      place.matches('input, textarea, select') || place.hasAttribute('tabindex')
        ? place
        : place.querySelector<HTMLElement>('input:not(:disabled), textarea:not(:disabled), select:not(:disabled), button:not(:disabled)');
    (control ?? place).focus();
  }

  /** Every change since the last save given up - asked first. */
  protected async discard(): Promise<void> {
    if (!this.dirty()) {
      return;
    }
    const sure = await this.confirm.ask({
      title: 'Discard your changes?',
      message: 'The builds go back to what was last saved.',
      confirmLabel: 'Discard',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }
    const keep = this.activeIndex();
    this.draft.set(this.loaded());
    this.fieldErrors.set({});
    this.otherErrors.set([]);
    this.saveError.set(null);
    this.unreadable.set({});
    this.formGeneration.update((generation) => generation + 1);
    const builds = this.loaded()?.builds ?? [];
    const open = builds.find((build) => build.uid === this.active());
    this.active.set(open?.uid ?? builds.at(Math.min(Math.max(keep, 0), builds.length - 1))?.uid ?? null);
  }

  // ── leaving ───────────────────────────────────────────────────────────────────────────────────

  /** Whether the page may be left: at once without changes, else when the person says so. */
  canLeave(): boolean | Promise<boolean> {
    if (!this.dirty() || this.changes.givenUp()) {
      return true;
    }
    return this.confirm.ask({
      title: 'Leave without saving?',
      message: `Your changes to the builds of ${this.who()?.name ?? 'this character'} are not saved: leaving, they are lost.`,
      confirmLabel: 'Leave',
      cancelLabel: 'Stay',
      tone: 'danger',
    });
  }

  /** Closing the tab, or reloading it, with changes not saved: the browser asks first. */
  protected beforeUnload(event: BeforeUnloadEvent): void {
    if (this.dirty() && !this.changes.givenUp()) {
      event.preventDefault();
      // What older browsers ask by, as the library's drafts do.
      event.returnValue = '';
    }
  }

  /** Whether a build's role, qualifier and constellation stay as they are: its name in the builds file. */
  protected nameFixed(build: DraftBuild): boolean {
    return nameFixed(build);
  }
}
