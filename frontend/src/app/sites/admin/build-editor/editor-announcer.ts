import { Injectable, signal } from '@angular/core';

/**
 * What the builds editor tells a screen reader that the eye sees for itself - a build or a row moved, and where to
 * (sites/admin/build-editor/move-buttons) -, said politely by the one live region the editor's page keeps for it
 * (always in the page: a region made together with its first message is one many screen readers never announce).
 */
@Injectable({ providedIn: 'root' })
export class EditorAnnouncer {
  readonly message = signal('');
  private timer: ReturnType<typeof setTimeout> | null = null;

  /** Says `text` - emptied first, so the same words twice in a row are said twice. */
  announce(text: string): void {
    this.message.set('');
    if (this.timer !== null) {
      clearTimeout(this.timer);
    }
    this.timer = setTimeout(() => {
      this.timer = null;
      this.message.set(text);
    }, 50);
  }
}
