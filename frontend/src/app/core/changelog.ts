import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { ChangelogDialog } from '../shared/changelog-dialog';

/** One release in public/changelog.json, newest first; the newest entry is the version shown in the app. */
export interface ChangelogEntry {
  version: string;
  /** YYYY-MM-DD */
  date: string;
  changes: {
    added?: string[];
    fixed?: string[];
    updated?: string[];
  };
}

/** The last version this browser was shown, so "what's new" opens once per release. */
const SEEN_KEY = 'build-analyzer-version-seen';

/**
 * The version and its changelog (public/changelog.json, shipped with the frontend). The version button in
 * the top bar opens the whole list; the first visit after a new release opens what changed since the version
 * this browser saw last. Kept per browser: the browser is what knows what it has shown.
 */
@Injectable({ providedIn: 'root' })
export class Changelog {
  private readonly http = inject(HttpClient);
  private readonly dialog = inject(MatDialog);

  readonly entries = signal<ChangelogEntry[]>([]);
  /** "0.1", or null until the changelog has loaded. */
  readonly current = computed(() => this.entries()[0]?.version ?? null);

  async load(): Promise<void> {
    try {
      const entries = await firstValueFrom(this.http.get<ChangelogEntry[]>('changelog.json'));
      this.entries.set(Array.isArray(entries) ? entries : []);
    } catch {
      // No changelog (a broken upload): the app works without the version button.
      this.entries.set([]);
    }
  }

  /** Every release, newest first. */
  open(entries: ChangelogEntry[] = this.entries()): void {
    if (entries.length === 0) {
      return;
    }
    this.remember();
    this.dialog.open(ChangelogDialog, { data: entries, width: '640px', maxWidth: 'calc(100vw - 32px)', autoFocus: 'dialog' });
  }

  /**
   * Opens what is new since the version this browser saw last; on a first visit just the newest release,
   * not the whole history. Nothing when this version was seen already.
   */
  showIfNew(): void {
    const current = this.current();
    const seen = read(SEEN_KEY);
    if (!current || seen === current) {
      return;
    }
    const seenIndex = this.entries().findIndex((entry) => entry.version === seen);
    this.open(seenIndex === -1 ? this.entries().slice(0, 1) : this.entries().slice(0, seenIndex));
  }

  private remember(): void {
    const current = this.current();
    if (current) {
      try {
        localStorage.setItem(SEEN_KEY, current);
      } catch {
        // Storage can be unavailable (private mode); the list then shows again on the next visit.
      }
    }
  }
}

function read(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}
