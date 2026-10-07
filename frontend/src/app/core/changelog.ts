import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { firstValueFrom } from 'rxjs';
import { ChangelogDialog } from '../shared/changelog-dialog';

/** One release in public/changelog.json, newest first; the newest entry is the version shown in the app. */
export interface ChangelogEntry {
  /** MAJOR.MINOR.PATCH: big changes, new features, bug fixes ("0.4.0"). */
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
  /** "0.4.0", or null until the changelog has loaded. */
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
   * Opens what is new since the version this browser saw last: the releases after it. On a first visit - or
   * with a seen version that is not one - just the newest release, not the whole history. Nothing when this
   * version, or a later one, was seen already. Versions compare by their numbers (compareVersions()): a
   * browser that kept "0.3" before the versions had three numbers has seen 0.3.0, and is not shown it again.
   */
  showIfNew(): void {
    const current = this.current();
    const seen = read(SEEN_KEY);
    if (!current || (seen !== null && seenAlready(seen, current))) {
      return;
    }
    const unseen =
      seen === null
        ? []
        : this.entries().filter((entry) => (compareVersions(entry.version, seen) ?? 0) > 0);
    this.open(unseen.length > 0 ? unseen : this.entries().slice(0, 1));
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

/**
 * Two versions compared by their numbers - MAJOR.MINOR.PATCH, a missing part counting as 0: negative when `a`
 * is the older, 0 for the same release ("0.3", kept by a browser from before the versions had three numbers,
 * is 0.3.0), positive when `a` is the newer; null when either is not a version.
 */
export function compareVersions(a: string, b: string): number | null {
  const left = versionNumbers(a);
  const right = versionNumbers(b);
  if (left === null || right === null) {
    return null;
  }
  for (let i = 0; i < Math.max(left.length, right.length); i++) {
    const difference = (left[i] ?? 0) - (right[i] ?? 0);
    if (difference !== 0) {
      return Math.sign(difference);
    }
  }
  return 0;
}

/** "0.4.0" -> [0, 4, 0]; null for anything that is not a version. */
function versionNumbers(version: string): number[] | null {
  return /^\d+(\.\d+)*$/.test(version) ? version.split('.').map(Number) : null;
}

/** Whether a browser that saw `seen` has seen `version`: the same release, or a later one. */
function seenAlready(seen: string, version: string): boolean {
  return seen === version || (compareVersions(seen, version) ?? -1) >= 0;
}

function read(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}
