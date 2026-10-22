import { Component, computed, inject, signal } from '@angular/core';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent, BadgeTone } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { DisclosureComponent, DisclosurePanelDirective } from '@anotoki/lib/ui/disclosure';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { ProgressBarComponent } from '@anotoki/lib/ui/progress';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { StatTileComponent, StatTilesComponent } from '@anotoki/lib/ui/stat-tiles';
import { ToastService } from '@anotoki/lib/ui/toast';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { firstValueFrom } from 'rxjs';
import { Api, errorMessage, fileErrorMessage } from '../../../core/api';
import { AuthService } from '../../../core/auth';
import { downloadName, saveFile } from '../../../core/download';
import { GAME_LABELS, GAMES } from '../../../core/format';
import { MetaStore } from '../../../core/meta-store';
import { AdminBuildsGame, BuildChange, BuildImportEntry, BuildImportPlan, CatalogList, CatalogListStatus, CatalogRefreshResult, GameKey } from '../../../core/models';
import { TimeAgoPipe } from '../../../shared/stat-pipes';
import {
  BUILD_FILE_MAX_BYTES,
  BUILD_FILE_TOO_LARGE,
  CHANGE_WORDS,
  changeCount,
  changeName,
  checkBuildFile,
  count,
  importLine,
  importSummary,
  momentText,
  planSummary,
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

/** The badge after a change's line: its colour repeats the word. */
const CHANGE_TONES: Record<BuildChange['change'], BadgeTone> = {
  created: 'success',
  updated: 'info',
  removed: 'danger',
};

/** A file chosen for an import, and what the server's dry run said importing it does. */
interface Preview {
  name: string;
  text: string;
  plan: BuildImportPlan;
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
 * whole truth for its game - after an import the game's builds are exactly the file's, but for characters this
 * installation's catalog lacks, which are skipped (an import never adds to the catalog).
 *
 * Per game:
 * - the counts - characters with builds, builds -, when the newest file carrying them was made and when one last
 *   changed;
 * - **Export**: the game's builds as a file (GET /api/admin/builds/{game}/export), saved under the server's name - a
 *   backup, and the start of a hand edit;
 * - **Import a file**: a file is chosen and checked here (build-file.ts: JSON, the format, its version, the game, at
 *   most 8 MB), then sent as a dry run, whose answer is the preview - what is new, updated, unchanged and removed,
 *   whether the file is older than the one the builds now come from, the characters skipped, and the changes one by
 *   one (the kit's disclosure); Import sends the same text for real, Cancel forgets it. The server's refusal (422:
 *   what does not fit; 409: another import writes) is said in the card, the preview kept when the import itself
 *   fails;
 * - the recent imports: when, the file's date, what each did, who imported it;
 * - the catalog's lists (characters, weapons, sets) and when each was refreshed; Refresh catalog refreshes the three
 *   one after another, with the kit's progress bar, and stops at the first error.
 *
 * Drawn with the anotoki family's kit: its page header, cards, stat tiles, buttons, alerts, badges, disclosure,
 * progress bar, spinner and messages. A time is relative ("3 days ago"), or a moment in the account's date order and
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
    SpinnerComponent,
    StatTileComponent,
    StatTilesComponent,
    TooltipDirective,
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
    this.api.importBuilds(game, text, true).subscribe({
      next: (plan) => this.patch(game, { busy: null, preview: { name: file.name, text, plan } }),
      error: (err: unknown) => this.patch(game, { busy: null, problem: errorMessage(err) }),
    });
  }

  /** The previewed file imported for real: what it did said, and the page's data read again. */
  protected confirmImport(game: GameKey): void {
    const { preview, busy } = this.states()[game];
    if (!preview || busy !== null) {
      return;
    }
    this.patch(game, { busy: 'import', problem: null });
    this.api.importBuilds(game, preview.text, false).subscribe({
      next: (result) => {
        this.patch(game, { busy: null, preview: null });
        // Skipped characters ask for something to be done: that message stays until it is closed.
        const skipped = result.skipped.length > 0;
        this.toasts.show(importSummary(result), skipped ? 'warning' : 'success', skipped ? 0 : 8000);
        this.load(false);
      },
      // The preview stays, so Import can be pressed again (after another import has finished, say).
      error: (err: unknown) => this.patch(game, { busy: null, problem: errorMessage(err) }),
    });
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

  protected changeName(change: BuildChange, game: GameKey): string {
    return changeName(change, this.rankPrefix(game));
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
