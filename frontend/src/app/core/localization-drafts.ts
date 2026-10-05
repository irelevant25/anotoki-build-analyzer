import { DestroyRef, Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { AuthService } from './auth';
import { TranslationChanges } from './models';

/** A language's row as it stands in its fields - text, the order too, until it is saved. */
export interface LanguageDraft {
  name: string;
  native_name: string;
  sort_order: string;
}

/** Where the drafts are kept for the tab: whose they are, the strings' cells and the languages' rows. */
export const LOCALIZATION_DRAFTS_KEY = 'build-analyzer-localization-drafts';

/** The drafts as the tab keeps them. */
interface StoredDrafts {
  owner: string;
  strings: TranslationChanges;
  languages: Record<string, LanguageDraft>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** One language's row out of what was stored - or null when it is not one. */
function languageDraft(value: unknown): LanguageDraft | null {
  if (!isRecord(value)) {
    return null;
  }
  const { name, native_name, sort_order } = value;
  return typeof name === 'string' && typeof native_name === 'string' && typeof sort_order === 'string' ? { name, native_name, sort_order } : null;
}

/**
 * What the tab has kept, read as nothing is trusted to be: only a value of
 * exactly the shape written here is drafts - anything else (not JSON, another
 * shape, a text where a map should be) is none.
 */
function storedDrafts(text: string | null): StoredDrafts | null {
  if (text === null) {
    return null;
  }
  let parsed: unknown;
  try {
    parsed = JSON.parse(text);
  } catch {
    return null;
  }
  if (!isRecord(parsed) || typeof parsed['owner'] !== 'string' || parsed['owner'] === '' || !isRecord(parsed['strings']) || !isRecord(parsed['languages'])) {
    return null;
  }
  const strings: [string, Record<string, string>][] = [];
  for (const [key, cells] of Object.entries(parsed['strings'])) {
    if (!isRecord(cells) || !Object.values(cells).every((value) => typeof value === 'string')) {
      return null;
    }
    // fromEntries, so a name like "__proto__" is a key and nothing else.
    strings.push([key, Object.fromEntries(Object.entries(cells as Record<string, string>))]);
  }
  const languages: [string, LanguageDraft][] = [];
  for (const [code, value] of Object.entries(parsed['languages'])) {
    const draft = languageDraft(value);
    if (draft === null) {
      return null;
    }
    languages.push([code, draft]);
  }
  return { owner: parsed['owner'], strings: Object.fromEntries(strings), languages: Object.fromEntries(languages) };
}

/** sessionStorage that never throws: it can be missing, switched off or full, and a page is not broken over it. */
function readTab(): string | null {
  try {
    return globalThis.sessionStorage?.getItem(LOCALIZATION_DRAFTS_KEY) ?? null;
  } catch {
    return null;
  }
}

/**
 * Writes the tab's storage - or, with null, takes the drafts out of it - and
 * answers whether the tab now holds `value`: true only when a write went
 * through. No storage, a storage that refused (switched off, full) and a
 * removal all answer false: nothing is held then, and whoever asks must not
 * count on a page load bringing the drafts back.
 */
function writeTab(value: string | null): boolean {
  try {
    // Read inside the try: where storage is switched off, asking for it is what throws.
    const storage = globalThis.sessionStorage;
    if (!storage) {
      return false;
    }
    if (value === null) {
      storage.removeItem(LOCALIZATION_DRAFTS_KEY);
      return false;
    }
    storage.setItem(LOCALIZATION_DRAFTS_KEY, value);
    return true;
  } catch {
    // Nothing more to do: the drafts are in memory, and just will not survive a page load.
    return false;
  }
}

/**
 * What is typed on the owner's Translations and Languages pages and not saved
 * yet: the strings' changed cells, and the languages' changed rows.
 *
 * It is kept here, not in the pages, so that it outlives them: a page can go
 * with no question asked (a sign-in that ends and is begun again is a trip to
 * the IAM and back), and what was typed is in its boxes again when the page is
 * back.
 *
 * Signing in again is a page load, so the drafts are mirrored to
 * sessionStorage: for this tab only, and gone with it.
 *
 * Only ANOTHER person signing in lets go of them. A sign-out - here, in
 * another tab, or the session ending - keeps them, and signing in again as the
 * same person finds them.
 *
 * The one `beforeunload` question for both pages is asked here: closing the
 * tab loses the drafts, whichever page is showing - or none. It is not asked
 * while nobody is signed in and the tab's storage holds the drafts: the way on
 * from there is the sign-in, which they survive (see the listener).
 *
 * The app's shell asks for it, so after the sign-in has settled: who is signed
 * in is known when it reads what the tab has kept.
 */
@Injectable({ providedIn: 'root' })
export class LocalizationDrafts {
  private readonly auth = inject(AuthService);

  /** The boxes of the Translations page that do not say what the site has: key -> language -> text. */
  readonly strings = signal<TranslationChanges>({});
  /** The rows of the Languages page whose fields do not say what is saved, by code. */
  readonly languages = signal<Record<string, LanguageDraft>>({});
  /** How many cells and rows are changed and not saved. */
  readonly count = computed(() => Object.values(this.strings()).reduce((cells, row) => cells + Object.keys(row).length, 0) + Object.keys(this.languages()).length);

  /** Who is signed in now (the IAM's id), or null. */
  private readonly person = computed(() => this.auth.user()?.sub ?? null);
  /** Whose drafts these are: the person who typed them, signed in or not just now. */
  private readonly owner = signal<string | null>(null);

  constructor() {
    const person = untracked(this.person);
    const stored = storedDrafts(readTab());
    // Kept for the person who is signed in - or for whoever signs in next,
    // when nobody is: the page was loaded again after the session ended, and
    // the effect below lets go of them if it is somebody else who comes.
    if (stored && (person === null || person === stored.owner)) {
      this.owner.set(stored.owner);
      this.strings.set(stored.strings);
      this.languages.set(stored.languages);
    } else {
      this.owner.set(person);
      // Broken, or somebody else's: no drafts, and not read again.
      writeTab(null);
    }

    // Only somebody else signing in lets go of what is here. Signing out -
    // here, in another tab, or the IAM ending the session - keeps it all.
    effect(() => {
      const person = this.person();
      untracked(() => {
        const owner = this.owner();
        if (person !== null && person !== owner) {
          this.owner.set(person);
          if (owner !== null) {
            this.clear();
          }
        }
      });
    });

    // Kept for the tab as the page next draws, whoever changed them.
    effect(() => {
      this.mirror();
    });

    // And as they are the moment the page goes - the way to the IAM's sign-in
    // and back is an unload too, and may come before the page has drawn again.
    const leaving = (): void => {
      this.mirror();
    };
    // The browser's own "Leave site?" while something is not saved - but not
    // on the one trip the drafts are kept for. With nobody signed in the way on
    // is the sign-in: a page load to the IAM and back, which the mirror
    // survives, so "changes you made may not be saved" would be untrue there.
    // So: signed out AND the write just went through - no question. Signed in,
    // or where the storage refused the write (the drafts are in memory only,
    // and any page load loses them), it is asked.
    //
    // The cost: closing the tab while signed out is not asked about either,
    // and that does lose the drafts - sessionStorage goes with its tab.
    const warn = (event: BeforeUnloadEvent): void => {
      const kept = this.mirror();
      const onlyTheSignInAhead = kept && this.person() === null;
      if (this.count() > 0 && !onlyTheSignInAhead) {
        event.preventDefault();
        event.returnValue = '';
      }
    };
    globalThis.addEventListener?.('beforeunload', warn);
    globalThis.addEventListener?.('pagehide', leaving);
    inject(DestroyRef).onDestroy(() => {
      globalThis.removeEventListener?.('beforeunload', warn);
      globalThis.removeEventListener?.('pagehide', leaving);
    });
  }

  /**
   * Every draft let go: given up, or somebody else's. Out of the tab's storage
   * at once, not when the page next draws: what was given up must not be there
   * for whoever loads the page next.
   */
  clear(): void {
    this.strings.set({});
    this.languages.set({});
    this.mirror();
  }

  /**
   * The drafts as they are now, into the tab's storage - or nothing there,
   * when there are none or nobody to keep them for. Answers whether the tab's
   * storage holds them now: false with none to hold, and where it refused.
   */
  private mirror(): boolean {
    const owner = this.owner();
    const strings = this.strings();
    const languages = this.languages();
    return writeTab(owner !== null && this.count() > 0 ? JSON.stringify({ owner, strings, languages } satisfies StoredDrafts) : null);
  }
}
