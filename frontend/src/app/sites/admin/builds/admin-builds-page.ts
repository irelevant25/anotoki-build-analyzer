import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent, BadgeTone } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { DisclosureComponent, DisclosurePanelDirective } from '@anotoki/lib/ui/disclosure';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { CheckboxComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { ProgressBarComponent } from '@anotoki/lib/ui/progress';
import { SegmentedComponent } from '@anotoki/lib/ui/segmented';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { StatTileComponent, StatTilesComponent } from '@anotoki/lib/ui/stat-tiles';
import { ToastService } from '@anotoki/lib/ui/toast';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { firstValueFrom } from 'rxjs';
import { Api, errorMessage, fileErrorMessage } from '../../../api/api';
import { AuthService } from '../../../shared/auth';
import { downloadName, saveFile } from '../../../shared/download';
import { GAME_LABELS, GAMES } from '../../../shared/format';
import { MetaStore } from '../../../shared/meta-store';
import {
  AdminBuildsGame,
  BuildChange,
  BuildImportEntry,
  BuildImportPlan,
  BuildQuestion,
  BuildVariationRef,
  CatalogList,
  CatalogListStatus,
  CatalogRefreshResult,
  EditingOverview,
  EditorCharacter,
  GameKey,
} from '../../../api/models';
import { Avatar } from '../../../shared/char-avatar';
import { TimeAgoPipe } from '../../../shared/stat-pipes';
import { EditorCatalogs } from '../build-editor/editor-catalogs';
import {
  BUILD_FILE_MAX_BYTES,
  BUILD_FILE_TOO_LARGE,
  BuildChoice,
  CHANGE_WORDS,
  changeCount,
  changeName,
  checkBuildFile,
  choiceOptions,
  count,
  handSummary,
  importLine,
  importSummary,
  mergedText,
  momentText,
  noChangesText,
  planSummary,
  questionText,
  skippedHeading,
  unknownTeammates,
} from './build-file';

/** The catalog's lists, refreshed one after another in this order - one request each. */
export const CATALOG_LISTS: readonly CatalogList[] = ['characters', 'weapons', 'sets'];

/** A catalog list's name on the page. */
export const LIST_NAMES: Record<CatalogList, string> = {
  characters: 'Characters',
  weapons: 'Weapons',
  sets: 'Sets',
};

/** What a list holds one of: "120 characters". */
const LIST_NOUNS: Record<CatalogList, string> = {
  characters: 'character',
  weapons: 'weapon',
  sets: 'set',
};

/**
 * What was done to a character's builds here, a few words each: its builds (but where only what was done is asked
 * for), how many carry an edit, were made here, were removed, have notes, and whether their order is an editor's.
 */
export function editingFacts(character: EditingOverview | null, doneOnly: boolean): string[] {
  if (!character) {
    return doneOnly ? [] : ['no builds'];
  }
  const done = [
    character.edited > 0 ? `${character.edited} edited` : '',
    character.made_here > 0 ? `${character.made_here} made here` : '',
    character.removed > 0 ? `${character.removed} removed` : '',
    character.notes > 0 ? `notes on ${character.notes}` : '',
    character.ordered ? 'your order' : '',
  ].filter((fact) => fact !== '');
  return doneOnly ? done : [count(character.builds, 'build'), ...done];
}

/** A character in the Edit builds table: what the catalog says of it, and of its builds here. */
export interface CharacterRow {
  key: string;
  name: string;
  icon: string | null;
  element: string;
  weapon: string;
  builds: number;
  /** What was done to its builds here ("1 edited · notes on 1"); '' for nothing. */
  done: string;
}

/** A word of the game's as the admin panel writes it: "pyro" is "Pyro"; none is "—". */
function gameWord(value: string | null): string {
  return value ? `${value.charAt(0).toUpperCase()}${value.slice(1)}` : '—';
}

/** Text as the table's search compares it: without its case and its accents ("Fúrina" finds "furina"). */
function folded(text: string): string {
  return text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
}

/** The badge after a change's line: its colour repeats the word. */
const CHANGE_TONES: Record<BuildChange['change'], BadgeTone> = {
  created: 'success',
  updated: 'info',
  removed: 'danger',
};

/**
 * A file chosen for an import, what the server's dry run said importing it does, and the answers to its questions so
 * far - by question id; one not answered keeps the editors' work, as the server does unless told otherwise.
 */
interface Preview {
  name: string;
  text: string;
  plan: BuildImportPlan;
  choices: Record<string, BuildChoice>;
}

/** What a game's part of the page is doing. */
interface GameState {
  /** A builds file on its way: exported, checked (the dry run) or imported. */
  busy: 'export' | 'check' | 'import' | null;
  /** What went wrong with the file or its request, in a sentence. */
  problem: string | null;
  preview: Preview | null;
  /** While the catalog is being refreshed: how many of its lists are done. */
  refreshing: number | null;
  /** Why the refresh of the catalog stopped. */
  catalogProblem: string | null;
}

function idle(): GameState {
  return { busy: null, problem: null, preview: null, refreshing: null, catalogProblem: null };
}

/**
 * /admin/builds (editors and admins; English, as the whole admin panel): the builds and the game catalog. The site
 * never fetches builds itself: they are put together elsewhere and imported here as one file per game, which is the
 * truth for its game - after an import the game's builds are the file's, but for what editors did by hand (kept: an
 * import asks only where the file changes the same part of a build), and for characters this
 * installation's catalog lacks, which are skipped (an import never adds to the catalog).
 *
 * Per game:
 * - the counts - characters with builds, builds -, when the newest file carrying them was made and when one last
 *   changed;
 * - **Export**: the game's builds as a file (GET /api/admin/builds/{game}/export), saved under the server's name - a
 *   backup;
 * - **Import a file**: a file is chosen and checked here (build-file.ts: JSON, the format, its version, the game, at
 *   most 8 MB), then sent as a dry run, whose answer is the preview - what is new, updated, unchanged and removed,
 *   what is kept of the editors' hand edits, whether the file is older than the one the builds now come from, the
 *   characters skipped, the changes one by one (the kit's disclosure) - and the questions: each build where the file
 *   and an editor's work disagree (docs/builds.md, "Hand edits"), answered with the kit's segmented control, the
 *   editor's work standing unless the file is chosen, with the builds made of both without asking listed after. Import
 *   sends the same text for real with the answers and the preview's plan, Cancel forgets it. The server's refusal (422:
 *   what does not fit; 409: another import writes) is said in the card, the preview kept when the import itself
 *   fails; when the questions changed meanwhile (409 plan_changed: an edit saved, another import), the file is checked
 *   again and said so;
 * - **Edit builds**: the way into the builds editor (/admin/builds/:game/:key) - every character of the catalog in a
 *   table (the kit's: its picture and name a link to its editor, its element and weapon, its builds and what was done
 *   to them here), narrowed by the words typed - a name, an element, a weapon - or to the characters changed here;
 * - the recent imports: when, the file's date, what each did, who imported it;
 * - the catalog's lists (characters, weapons, sets) and when each was refreshed; Refresh catalog refreshes the three
 *   one after another, with the kit's progress bar, and stops at the first error.
 *
 * Drawn with the anotoki family's kit: its page header, cards, stat tiles, buttons, alerts, badges, disclosure,
 * text field, checkbox, table, progress bar, spinner and messages. A time is relative ("3 days ago"), or a moment in the account's date order and
 * clock where the person has chosen them (build-file.ts: momentText). The server checks the role (403 editor_only);
 * while a database update waits the site gate shows its own page instead of this one.
 */
@Component({
  selector: 'app-admin-builds-page',
  imports: [
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    DisclosureComponent,
    DisclosurePanelDirective,
    IconComponent,
    PageHeaderComponent,
    ProgressBarComponent,
    SegmentedComponent,
    SpinnerComponent,
    StatTileComponent,
    StatTilesComponent,
    TooltipDirective,
    CheckboxComponent,
    TextFieldComponent,
    RouterLink,
    Avatar,
    TimeAgoPipe,
  ],
  templateUrl: './admin-builds-page.html',
  styleUrl: './admin-builds-page.scss',
})
export class AdminBuildsPage {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastService);
  private readonly auth = inject(AuthService);
  private readonly meta = inject(MetaStore);
  private readonly catalogs = inject(EditorCatalogs);

  protected readonly games = GAMES;
  protected readonly gameLabels = GAME_LABELS;
  protected readonly listNames = LIST_NAMES;
  protected readonly listCount = CATALOG_LISTS.length;
  protected readonly changeWords = CHANGE_WORDS;
  protected readonly changeTones = CHANGE_TONES;

  protected readonly data = signal<AdminBuildsGame[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly states = signal<Record<GameKey, GameState>>(Object.fromEntries(GAMES.map((game) => [game, idle()])) as Record<GameKey, GameState>);

  /** Each game's catalog characters, to pick one to edit; why they could not be read, where they could not. */
  protected readonly characters = signal<Partial<Record<GameKey, EditorCharacter[]>>>({});
  protected readonly charactersProblem = signal<Partial<Record<GameKey, string>>>({});

  /** What each game's Edit builds table is narrowed to: the words typed; the characters changed here alone. */
  protected readonly find = signal<Partial<Record<GameKey, string>>>({});
  protected readonly changedOnly = signal<Partial<Record<GameKey, boolean>>>({});

  /**
   * Each game's Edit builds table: every character of the catalog, in its order, with its builds and what was done to
   * them here - those the words typed find (each word in its name, element or weapon), or those changed here.
   */
  protected readonly characterRows = computed(() => {
    const tables: Partial<Record<GameKey, { all: number; shown: CharacterRow[] }>> = {};
    for (const game of GAMES) {
      const overview = new Map((this.byGame()[game]?.editing ?? []).map((character) => [character.key, character]));
      const words = folded(this.find()[game] ?? '')
        .split(/\s+/)
        .filter((word) => word !== '');
      const changedOnly = this.changedOnly()[game] ?? false;
      const all = (this.characters()[game] ?? []).map((character): CharacterRow => {
        const editing = overview.get(character.key) ?? null;
        return {
          key: character.key,
          name: character.name,
          icon: character.icon_url,
          element: gameWord(character.element),
          weapon: gameWord(character.weapon_type),
          builds: editing?.builds ?? 0,
          done: editingFacts(editing, true).join(' · '),
        };
      });
      const shown = all.filter((row) => (!changedOnly || row.done !== '') && words.every((word) => folded(`${row.name} ${row.element} ${row.weapon}`).includes(word)));
      tables[game] = { all: all.length, shown };
    }
    return tables;
  });

  /** Each game's answer, by its key; a game the server did not answer for is not drawn. */
  protected readonly byGame = computed(() => {
    const games: Partial<Record<GameKey, AdminBuildsGame>> = {};
    for (const entry of this.data()) {
      games[entry.game] = entry;
    }
    return games;
  });

  constructor() {
    this.load();
    for (const game of GAMES) {
      this.loadCharacters(game);
    }
  }

  /** The game's catalog characters, for the editor's picker (the editor's page asks for the same, once). */
  private loadCharacters(game: GameKey): void {
    this.catalogs.get(game).then(
      (catalog) => {
        this.characters.update((all) => ({ ...all, [game]: catalog.characters }));
        this.charactersProblem.update((all) => ({ ...all, [game]: undefined }));
      },
      (err: unknown) => this.charactersProblem.update((all) => ({ ...all, [game]: errorMessage(err) })),
    );
  }

  protected setFind(game: GameKey, text: string): void {
    this.find.update((all) => ({ ...all, [game]: text }));
  }

  protected setChangedOnly(game: GameKey, on: boolean): void {
    this.changedOnly.update((all) => ({ ...all, [game]: on }));
  }

  /** The builds, the imports and the catalog of every game; `showSpinner` false after an import, the page kept. */
  protected load(showSpinner = true): void {
    if (showSpinner) {
      this.loading.set(true);
    }
    this.api.adminBuilds().subscribe({
      next: (games) => {
        this.data.set(games);
        this.error.set(null);
        this.loading.set(false);
      },
      error: (err: unknown) => {
        this.error.set(errorMessage(err));
        this.loading.set(false);
      },
    });
  }

  protected exportBuilds(game: GameKey): void {
    this.patch(game, { busy: 'export', problem: null });
    this.api.exportBuilds(game).subscribe({
      next: (response) => {
        this.patch(game, { busy: null });
        const today = new Date().toISOString().slice(0, 10);
        saveFile(response.body ?? new Blob([]), downloadName(response.headers.get('Content-Disposition'), `builds-${game}-${today}.json`));
      },
      error: async (err: unknown) => {
        // The answer is a file: the API's {message} arrives as one too.
        const message = await fileErrorMessage(err);
        this.patch(game, { busy: null, problem: message });
      },
    });
  }

  /** A file chosen: checked here, then a dry run - its answer is the preview. */
  protected async choose(game: GameKey, input: HTMLInputElement): Promise<void> {
    const file = input.files?.[0];
    // Cleared, so choosing the same file again (after fixing it) counts as a change too.
    input.value = '';
    if (!file || this.states()[game].busy !== null) {
      return;
    }
    this.patch(game, { problem: null, preview: null });
    if (file.size > BUILD_FILE_MAX_BYTES) {
      this.patch(game, { problem: BUILD_FILE_TOO_LARGE });
      return;
    }

    this.patch(game, { busy: 'check' });
    let text: string;
    try {
      text = await file.text();
    } catch {
      this.patch(game, { busy: null, problem: 'This file could not be read.' });
      return;
    }
    const check = checkBuildFile(text, game);
    if (!check.ok) {
      this.patch(game, { busy: null, problem: check.message });
      return;
    }
    this.preview(game, file.name, text);
  }

  /** The dry run of a file's text: its answer is the preview, every question unanswered - `note` said above it. */
  private preview(game: GameKey, name: string, text: string, note: string | null = null): void {
    this.patch(game, { busy: 'check' });
    this.api.importBuilds(game, text, true).subscribe({
      next: (plan) => this.patch(game, { busy: null, problem: note, preview: { name, text, plan, choices: {} } }),
      error: (err: unknown) => this.patch(game, { busy: null, problem: errorMessage(err) }),
    });
  }

  /** The previewed file imported for real, with the answers to its questions: what it did said, and the page's data read again. */
  protected confirmImport(game: GameKey): void {
    const { preview, busy } = this.states()[game];
    if (!preview || busy !== null) {
      return;
    }
    const questions = preview.plan.questions;
    const file = questions.filter((question) => preview.choices[question.id] === 'file').map((question) => question.id);
    this.patch(game, { busy: 'import', problem: null });
    this.api.importBuilds(game, preview.text, false, { file: file.length > 0 && file.length === questions.length ? 'all' : file, plan: preview.plan.plan }).subscribe({
      next: (result) => {
        this.patch(game, { busy: null, preview: null });
        // Skipped characters ask for something to be done: that message stays until it is closed.
        const skipped = result.skipped.length > 0;
        this.toasts.show(importSummary(result), skipped ? 'warning' : 'success', skipped ? 0 : 8000);
        this.load(false);
      },
      error: (err: unknown) => {
        if (err instanceof HttpErrorResponse && err.status === 409 && err.error?.code === 'plan_changed') {
          // The questions are not the ones answered: the file checked again, and said why.
          this.preview(
            game,
            preview.name,
            preview.text,
            'The builds changed since this preview - a build was edited, or another import came. The file was checked again: look at its questions before importing.',
          );
          return;
        }
        // The preview stays, so Import can be pressed again (after another import has finished, say).
        this.patch(game, { busy: null, problem: errorMessage(err) });
      },
    });
  }

  /** A question answered: the editors' work, or the file's. */
  protected answer(game: GameKey, id: string, choice: string): void {
    const preview = this.states()[game].preview;
    if (preview && (choice === 'edit' || choice === 'file')) {
      this.patch(game, { preview: { ...preview, choices: { ...preview.choices, [id]: choice } } });
    }
  }

  /** Every question answered alike. */
  protected answerAll(game: GameKey, choice: BuildChoice): void {
    const preview = this.states()[game].preview;
    if (preview) {
      this.patch(game, { preview: { ...preview, choices: Object.fromEntries(preview.plan.questions.map((question) => [question.id, choice])) } });
    }
  }

  protected cancel(game: GameKey): void {
    this.patch(game, { preview: null, problem: null });
  }

  /** The catalog's three lists, refreshed one after another; the first error stops the rest. */
  protected async refreshCatalog(game: GameKey): Promise<void> {
    if (this.states()[game].refreshing !== null) {
      return;
    }
    this.patch(game, { refreshing: 0, catalogProblem: null });
    const done: CatalogRefreshResult[] = [];
    for (const list of CATALOG_LISTS) {
      try {
        done.push(await firstValueFrom(this.api.refreshCatalog(game, list)));
      } catch (err: unknown) {
        this.patch(game, { refreshing: null, catalogProblem: `${LIST_NAMES[list]} could not be refreshed: ${errorMessage(err)}` });
        return;
      }
      this.showList(game, done[done.length - 1]);
      this.patch(game, { refreshing: done.length });
    }
    this.patch(game, { refreshing: null });
    // The editor's catalog is read again: a character or a weapon may have come.
    this.catalogs.forget(game);
    this.loadCharacters(game);
    this.toasts.success(`Catalog refreshed: ${done.map((result) => count(result.entries, LIST_NOUNS[result.list]) + (result.outcome === 'unchanged' ? ' (as they were)' : '')).join(', ')}.`);
  }

  /** The lists whose last refresh failed - here or by the upkeep after an answer - and have not been refreshed since. */
  protected failedLists(catalog: readonly CatalogListStatus[]): CatalogListStatus[] {
    return catalog.filter((list) => list.problem !== null);
  }

  /** The prefix of the game's constellations (or whatever the game calls them): "C" in "C2+". */
  protected rankPrefix(game: GameKey): string {
    return this.meta.game(game)?.rank_prefix || 'C';
  }

  /** A moment in the account's date order and clock where chosen; null for none. */
  protected moment(value: string | null): string | null {
    return value ? momentText(value, this.auth.preferences()) : null;
  }

  protected planSummary(plan: BuildImportPlan): string {
    return planSummary(plan, this.auth.preferences());
  }

  protected importLine(entry: BuildImportEntry): string {
    return importLine(entry, this.auth.preferences());
  }

  protected changeName(change: BuildVariationRef, game: GameKey): string {
    return changeName(change, this.rankPrefix(game));
  }

  protected questionText(question: BuildQuestion): string {
    return questionText(question);
  }

  protected choiceOptions(question: BuildQuestion): { value: BuildChoice; label: string }[] {
    return choiceOptions(question);
  }

  /** How a question is answered so far: the editors' work unless the file was chosen. */
  protected choiceOf(preview: Preview, question: BuildQuestion): BuildChoice {
    return preview.choices[question.id] ?? 'edit';
  }

  protected mergedText = mergedText;

  protected noChangesText = noChangesText;

  protected handSummary(plan: BuildImportPlan): string | null {
    return handSummary(plan);
  }

  /** The characters named in a list of them: "Hu Tao, Furina". */
  protected characterNames(characters: readonly { name: string }[]): string {
    return characters.map((character) => character.name).join(', ');
  }

  /** The builds whose team notes lose their team: "Hu Tao · Main DPS (1)". */
  protected waitingNotes(plan: BuildImportPlan, game: GameKey): string {
    return plan.notes_dormant.map((build) => `${changeName(build, this.rankPrefix(game))} (${build.notes})`).join(', ');
  }

  protected changeCount(plan: BuildImportPlan): number {
    return changeCount(plan);
  }

  protected skippedHeading(plan: BuildImportPlan): string {
    return skippedHeading(plan);
  }

  protected unknownTeammates(plan: BuildImportPlan): string | null {
    return unknownTeammates(plan);
  }

  protected count(value: number, noun: string): string {
    return count(value, noun);
  }

  private patch(game: GameKey, change: Partial<GameState>): void {
    this.states.update((states) => ({ ...states, [game]: { ...states[game], ...change } }));
  }

  /** A list as its refresh left it. */
  private showList(game: GameKey, result: CatalogRefreshResult): void {
    this.data.update((games) =>
      games.map((entry) =>
        entry.game !== game
          ? entry
          : {
              ...entry,
              catalog: entry.catalog.map((list) => (list.list === result.list ? { list: result.list, entries: result.entries, refreshed_at: result.refreshed_at, problem: result.problem } : list)),
            },
      ),
    );
  }
}
