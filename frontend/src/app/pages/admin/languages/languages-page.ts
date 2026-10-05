import { Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatSnackBar } from '@angular/material/snack-bar';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { Api } from '../../../core/api';
import { FALLBACK_LANGUAGE, TranslationService } from '../../../core/i18n/translation';
import { LanguageDraft, LocalizationDrafts } from '../../../core/localization-drafts';
import { AdminLanguage, LanguageChanges } from '../../../core/models';
import { HoldsUnsavedChanges } from '../../../core/unsaved-changes.guard';
import { askConfirm } from '../../../shared/confirm-dialog';
import { downloadName, saveFile } from '../../../core/download';
import { OwnerError, ownerError, ownerFileError } from '../translations/owner-error';

/** A language code as the database keeps it - the same expression as TranslationCheck::codeOk() in PHP. */
const LANGUAGE_CODE = /^[a-z]{2}(-[a-z]{2})?$/;
/** A place in the order: a whole number from 0 to 1000, as the server takes it. */
const SORT_ORDER = /^\d{1,4}$/;
const SORT_ORDER_MAX = 1000;

/** How long a snack bar that only says something went well stays. */
const SAID_FOR_MS = 4000;

/** The order the bar's menu shows them in - the server's: by their place, then by name, then by code. */
function inOrder(languages: AdminLanguage[]): AdminLanguage[] {
  return [...languages].sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name, 'en') || a.code.localeCompare(b.code, 'en'));
}

/** A row's fields as they are saved: what a draft is measured against. */
function savedDraft(language: AdminLanguage): LanguageDraft {
  return { name: language.name, native_name: language.native_name, sort_order: String(language.sort_order) };
}

function sameDraft(a: LanguageDraft, b: LanguageDraft): boolean {
  return a.name === b.name && a.native_name === b.native_name && a.sort_order === b.sort_order;
}

function strings(count: number): string {
  return count === 1 ? '1 string' : `${count} strings`;
}

/**
 * The languages the site can be read in: their names, their order in the bar's menu, and whether readers
 * are offered them. English page for the owner (admins).
 *
 * English is what every other language falls back to: it cannot be hidden or deleted. A language the
 * site is released in (a migration writes its strings) can be hidden but not deleted; any other can be
 * deleted, with its strings - the page offers to export them first. The page only hides what the server
 * refuses anyway, and shows the server's sentence when it does refuse.
 *
 * A new language starts hidden unless the owner says otherwise: with no string of its own yet it would
 * read in English throughout, under its own name in the menu.
 *
 * What is typed in a row and not saved is held in LocalizationDrafts, not here, so that it outlives the page.
 */
@Component({
  selector: 'app-languages-page',
  imports: [RouterLink, MatButtonModule, MatFormFieldModule, MatIconModule, MatInputModule, MatProgressSpinnerModule, MatSlideToggleModule],
  templateUrl: './languages-page.html',
  styleUrl: './languages-page.scss',
})
export class LanguagesPage implements HoldsUnsavedChanges {
  private readonly api = inject(Api);
  private readonly i18n = inject(TranslationService);
  private readonly dialog = inject(MatDialog);
  private readonly snackBar = inject(MatSnackBar);

  private readonly list = signal<AdminLanguage[] | null>(null);
  /** The rows whose fields do not say what is saved, by code. Kept outside the page. */
  private readonly drafts = inject(LocalizationDrafts).languages;
  /** What the server said when it refused a row's change, by code - its own sentence. */
  private readonly errors = signal<Record<string, string>>({});

  protected readonly loadError = signal<OwnerError | null>(null);
  /** The language whose names and order are being saved, whose switch is being saved, and the one being deleted. */
  protected readonly saving = signal<string | null>(null);
  protected readonly switching = signal<string | null>(null);
  protected readonly deleting = signal<string | null>(null);

  protected readonly newCode = signal('');
  protected readonly newName = signal('');
  protected readonly newNativeName = signal('');
  /** Offered to readers the moment it is added - off unless the owner says so: a new language has no strings yet. */
  protected readonly newOffered = signal(false);
  protected readonly adding = signal(false);
  protected readonly addError = signal<string | null>(null);

  protected readonly fallback = FALLBACK_LANGUAGE;
  protected readonly loaded = computed(() => this.list() !== null);
  protected readonly languages = computed(() => this.list() ?? []);
  protected readonly unsaved = computed(() => Object.keys(this.drafts()).length);
  protected readonly canAdd = computed(() => this.newCode().trim() !== '' && this.newName().trim() !== '' && this.newNativeName().trim() !== '');
  /** Said under the code as it is typed - the server's rule. */
  protected readonly newCodeError = computed(() => {
    const code = this.newCode().trim().toLowerCase();
    return code === '' || LANGUAGE_CODE.test(code) ? null : 'A language\'s code is two small letters, or two and two with a dash between: "cs", "pt-br".';
  });

  constructor() {
    // What was typed before the page went is in the drafts already; the languages that come say which
    // of it is still a change.
    void this.load();
  }

  /**
   * Leaving with a row changed and not saved asks first - and "Leave" is what gives the changes up: they
   * are kept outside the page, so they would be in their rows again the next time.
   */
  canLeave(): boolean | Promise<boolean> {
    const unsaved = this.unsaved();
    if (!unsaved) {
      return true;
    }
    return askConfirm(this.dialog, {
      title: 'Leave without saving?',
      message: `${unsaved === 1 ? '1 language has' : `${unsaved} languages have`} changes that are not saved yet, and leaving loses them.`,
      confirmLabel: 'Leave',
      cancelLabel: 'Stay here',
      danger: true,
    }).then((leave) => {
      if (leave) {
        this.drafts.set({});
      }
      return leave;
    });
  }

  protected async load(): Promise<void> {
    this.loadError.set(null);
    try {
      const languages = inOrder((await firstValueFrom(this.api.languages())).languages);
      this.list.set(languages);
      // Drafts kept from before: not for a language that is gone, and not one that says what is saved now.
      this.drafts.update((drafts) =>
        Object.fromEntries(
          Object.entries(drafts).filter(([code, draft]) => {
            const language = languages.find((other) => other.code === code);
            return language !== undefined && !sameDraft(draft, savedDraft(language));
          }),
        ),
      );
    } catch (error) {
      this.loadError.set(ownerError(error));
    }
  }

  // ---------------------------------------------------------------- a row

  /** What a row's fields show: what is typed, or what is saved. */
  protected draft(language: AdminLanguage): LanguageDraft {
    const drafts = this.drafts();
    return Object.hasOwn(drafts, language.code) ? drafts[language.code] : savedDraft(language);
  }

  protected isChanged(language: AdminLanguage): boolean {
    return Object.hasOwn(this.drafts(), language.code);
  }

  protected errorOf(language: AdminLanguage): string | null {
    const errors = this.errors();
    return Object.hasOwn(errors, language.code) ? errors[language.code] : null;
  }

  protected edit(language: AdminLanguage, change: Partial<LanguageDraft>): void {
    this.keep(language, { ...this.draft(language), ...change });
  }

  protected typed(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected undo(language: AdminLanguage): void {
    this.forget(language.code);
    this.setError(language.code, null);
  }

  /**
   * The names and the place in the order - what changed of them, in one request. The row's fields stay
   * open meanwhile: what is typed in them before the answer comes is a change to what the answer saved,
   * not lost to it.
   */
  protected async save(language: AdminLanguage): Promise<void> {
    if (this.saving() !== null) {
      return;
    }
    const draft = this.draft(language);
    const changes: LanguageChanges = {};
    if (draft.name !== language.name) {
      changes.name = draft.name;
    }
    if (draft.native_name !== language.native_name) {
      changes.native_name = draft.native_name;
    }
    if (draft.sort_order !== String(language.sort_order)) {
      const sortOrder = SORT_ORDER.test(draft.sort_order.trim()) ? Number(draft.sort_order.trim()) : -1;
      if (sortOrder < 0 || sortOrder > SORT_ORDER_MAX) {
        this.setError(language.code, 'The order is a whole number from 0 to 1000.');
        return;
      }
      changes.sort_order = sortOrder;
    }

    this.saving.set(language.code);
    this.setError(language.code, null);
    try {
      const saved = (await firstValueFrom(this.api.updateLanguage(language.code, changes))).language;
      // The fields as they stand now: as they were sent - or typed in since the request left.
      const now = this.draft(language);
      this.replace(saved);
      if (sameDraft(now, draft)) {
        this.forget(language.code);
      } else {
        // A change again - measured against what is saved now.
        this.keep(saved, now);
      }
      this.say(`${saved.name} is saved.`);
      void this.i18n.reload();
    } catch (error) {
      this.setError(language.code, ownerError(error).message);
    } finally {
      this.saving.set(null);
    }
  }

  /**
   * Offered or hidden, saved the moment the toggle is flipped. The row says the new state at once and
   * goes back to the old one if the server refuses - the toggle follows, because its state is the row's.
   */
  protected async setOffered(language: AdminLanguage, enabled: boolean): Promise<void> {
    if (enabled === language.enabled) {
      return;
    }
    this.switching.set(language.code);
    this.setError(language.code, null);
    this.patch(language.code, { enabled });
    try {
      const saved = (await firstValueFrom(this.api.updateLanguage(language.code, { enabled }))).language;
      this.patch(language.code, { enabled: saved.enabled });
      this.say(saved.enabled ? `${saved.name} is offered to readers now.` : `${saved.name} is hidden: readers are not offered it.`);
      void this.i18n.reload();
    } catch (error) {
      this.patch(language.code, { enabled: !enabled });
      this.setError(language.code, ownerError(error).message);
    } finally {
      this.switching.set(null);
    }
  }

  /**
   * Never for a language the site is released in. Its strings go with it, so a language that has some is
   * first offered as a file - one that can be imported again - and then the deletion is asked, naming
   * how many strings go.
   */
  protected async remove(language: AdminLanguage): Promise<void> {
    const count = language.strings;
    if (count > 0) {
      const exportFirst = await askConfirm(this.dialog, {
        title: `Export the ${strings(count)} of ${language.name} first?`,
        message: 'Deleting a language deletes its strings, and they cannot be brought back. An exported file can be imported again on the Translations page.',
        confirmLabel: 'Export first',
        cancelLabel: 'No, go on',
      });
      if (exportFirst && !(await this.exportStrings(language))) {
        return;
      }
    }
    const sure = await askConfirm(this.dialog, {
      title: `Delete ${language.name} (${language.code})?`,
      message:
        count > 0
          ? `Its ${strings(count)} ${count === 1 ? 'goes' : 'go'} with it, and none of ${count === 1 ? 'it' : 'them'} can be brought back.`
          : 'It has no strings of its own, so nothing else goes with it.',
      confirmLabel: 'Delete',
      cancelLabel: 'Cancel',
      danger: true,
    });
    if (!sure) {
      return;
    }
    this.deleting.set(language.code);
    this.setError(language.code, null);
    try {
      const gone = (await firstValueFrom(this.api.deleteLanguage(language.code))).strings;
      this.list.update((languages) => (languages ?? []).filter((other) => other.code !== language.code));
      this.forget(language.code);
      this.say(gone > 0 ? `${language.name} is deleted, and its ${strings(gone)} with it.` : `${language.name} is deleted.`);
      void this.i18n.reload();
    } catch (error) {
      this.setError(language.code, ownerError(error).message);
    } finally {
      this.deleting.set(null);
    }
  }

  /** The language's strings as the server's file, saved by the browser. Answers whether that went through. */
  private async exportStrings(language: AdminLanguage): Promise<boolean> {
    this.deleting.set(language.code);
    try {
      const response = await firstValueFrom(this.api.exportTranslations(language.code));
      saveFile(response.body ?? new Blob([]), downloadName(response.headers.get('Content-Disposition'), `translations-${language.code}.json`));
      return true;
    } catch (error) {
      this.setError(language.code, `The strings could not be exported, so nothing was deleted. ${(await ownerFileError(error)).message}`);
      return false;
    } finally {
      this.deleting.set(null);
    }
  }

  // ---------------------------------------------------------------- a new language

  protected async add(): Promise<void> {
    if (!this.canAdd() || this.newCodeError() !== null || this.adding()) {
      return;
    }
    this.adding.set(true);
    this.addError.set(null);
    try {
      // Said every time, on or off: whether readers are offered it is this form's to decide, not a default's.
      const added = (
        await firstValueFrom(
          this.api.createLanguage({
            code: this.newCode().trim().toLowerCase(),
            name: this.newName().trim(),
            native_name: this.newNativeName().trim(),
            enabled: this.newOffered(),
          }),
        )
      ).language;
      this.list.update((languages) => inOrder([...(languages ?? []), added]));
      this.newCode.set('');
      this.newName.set('');
      this.newNativeName.set('');
      this.newOffered.set(false);
      // By what the server made of it - the row says the same.
      this.say(
        added.enabled
          ? `${added.name} is added, and offered to readers. It reads in English until its strings are written.`
          : `${added.name} is added, hidden. Write its strings, then offer it.`,
      );
      void this.i18n.reload();
    } catch (error) {
      this.addError.set(ownerError(error).message);
    } finally {
      this.adding.set(false);
    }
  }

  private replace(saved: AdminLanguage): void {
    this.list.update((languages) => inOrder((languages ?? []).map((language) => (language.code === saved.code ? saved : language))));
  }

  private patch(code: string, change: Partial<AdminLanguage>): void {
    this.list.update((languages) => (languages ?? []).map((language) => (language.code === code ? { ...language, ...change } : language)));
  }

  private forget(code: string): void {
    this.drafts.update(({ [code]: _, ...others }) => others);
  }

  /** A row's fields measured against the row as it is saved: a draft while they differ, none when they say the same. */
  private keep(language: AdminLanguage, draft: LanguageDraft): void {
    const same = sameDraft(draft, savedDraft(language));
    this.drafts.update(({ [language.code]: _, ...others }) => (same ? others : { ...others, [language.code]: draft }));
  }

  private setError(code: string, message: string | null): void {
    this.errors.update(({ [code]: _, ...others }) => (message === null ? others : { ...others, [code]: message }));
  }

  private say(message: string): void {
    this.snackBar.open(message, undefined, { duration: SAID_FOR_MS });
  }
}
