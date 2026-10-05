import { Injectable, signal } from '@angular/core';

/** The longest a sign-in waits for the saves still on their way before it leaves for the IAM all the same. */
export const SAVES_WAIT_MS = 30_000;

/**
 * The site's own API writes (anything but GET and HEAD) still on their way, counted by authInterceptor.
 *
 * A sign-in waits for them before the browser leaves for the IAM (AuthService.login()), as it would have to
 * for any page it leaves: a visitor's first import brings the new visitor key in its answer, and a page left
 * before that answer arrives never stores the key - what was imported would be out of reach, and the move
 * after the sign-in would find nothing. A save that hangs is waited for SAVES_WAIT_MS at most.
 */
@Injectable({ providedIn: 'root' })
export class SavesInFlight {
  private readonly _count = signal(0);
  private waiting: (() => void)[] = [];

  /** How many are on their way now. */
  readonly count = this._count.asReadonly();

  /** One save leaves; the function it answers is called once it has an answer (or failed, or was dropped). */
  begin(): () => void {
    let ended = false;
    this._count.update((count) => count + 1);
    return () => {
      if (ended) {
        return;
      }
      ended = true;
      this._count.update((count) => count - 1);
      if (this._count() === 0) {
        const waiting = this.waiting;
        this.waiting = [];
        waiting.forEach((resolve) => resolve());
      }
    };
  }

  /** Resolves once no save is on its way - at once when none is, and after `maxMs` at the latest. */
  settled(maxMs = SAVES_WAIT_MS): Promise<void> {
    if (this._count() === 0) {
      return Promise.resolve();
    }
    return new Promise((resolve) => {
      const done = () => {
        clearTimeout(timer);
        this.waiting = this.waiting.filter((waiting) => waiting !== done);
        resolve();
      };
      const timer = setTimeout(done, maxMs);
      this.waiting.push(done);
    });
  }
}
