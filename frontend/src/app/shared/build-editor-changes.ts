import { Injectable, signal } from '@angular/core';

/**
 * Whether the admin panel's builds editor holds changes that are not saved (sites/admin/build-editor) - outside the
 * page, so the site's Sign out can ask about them first (shared/sign-out.ts: the admin panel's frame's, the top
 * bar's). Nothing of them is kept anywhere but on the page: signed out, or the tab closed, they are gone.
 */
@Injectable({ providedIn: 'root' })
export class BuildEditorChanges {
  /** The editor's page holds unsaved changes; it says so itself. */
  readonly unsaved = signal(false);
  /** The changes were given up - a sign-out asked for and confirmed: the page lets the tab go without asking again. */
  readonly givenUp = signal(false);

  /** The changes given up: nothing asks about them any more. */
  giveUp(): void {
    this.givenUp.set(true);
    this.unsaved.set(false);
  }
}
