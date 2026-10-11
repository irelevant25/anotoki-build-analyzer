import { HttpErrorResponse } from '@angular/common/http';
import { DestroyRef, Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import type { VisitorKeyStore } from '@anotoki/lib/auth';
import { firstValueFrom } from 'rxjs';
import { Api } from './api';
import { AuthService } from './auth';
import { VisitorMove } from './models';
import { SignInKind } from './sign-in-kind';
import { readStorage, storageUsable, writeStorage } from './storage';

/** Where this browser keeps its visitor key (localStorage: every tab of the site shares it). */
export const VISITOR_STORAGE_KEY = 'build-analyzer-visitor';

/** A key as the server makes them: 32 random bytes, base64url without padding. Anything else is no key. */
const KEY_SHAPE = /^[A-Za-z0-9_-]{43}$/;

/**
 * How long to wait before asking for the move again in this page load, after a network error or a 5xx that
 * says nothing of its own (a 503's Retry-After wins). After the last one, the next page load asks again.
 */
export const MOVE_RETRY_MS: readonly number[] = [2_000, 10_000, 30_000];

/**
 * The visitor key: what lets somebody who is not signed in keep what they import. The server makes it on
 * the first import of somebody with neither a token nor a key and sends it once, in the `X-Visitor-Key`
 * header of that answer; it keeps only its hash. This browser keeps the key in localStorage - the site's
 * visitor key store (the anotoki family's VisitorKeyStore: current(), remember(), forget()), which the
 * family's interceptor (@anotoki/lib/auth) reads to send it on the site's own API requests issued as a
 * visitor's, and hands a key an answer brought, or one the server no longer knows.
 *
 * - **Kept, not sent, while the site requires a sign-in** (AuthService.loginRequired: the IAM's switch). The
 *   server would refuse it (401 sign_in_required) and nobody visits then; the next sign-in moves what it
 *   holds into the account, and switching the requirement off again within the days a visitor is kept
 *   brings it back.
 *
 * - **Read at send time.** `current()` reads storage on every request, and a `storage` event keeps the
 *   `key` signal in step, so every tab sends the key the browser holds now - not one it read at its start.
 * - **The first key stays.** Two tabs that each import first both get a key; the second never overwrites
 *   the stored one (`remember()`). Its one import is left behind on the server, which deletes it with the
 *   unvisited visitors.
 * - **Forgotten** when the server no longer knows it (401 `visitor_unknown`: moved by another tab, or
 *   swept) - `lost` says so, for the page to word once - and after the move.
 * - **The move, after a sign-in** (whether or not the site requires one). Signed in with a key held:
 *   `POST /api/visitor/move` with the token and the key, once at a time - and `silent` when the sign-in was the
 *   IAM's hint's silent hop (SignInKind). On a 200 (found or not) the key is forgotten, `moves` counts up (the pages that
 *   list accounts load them again) and `moved` holds what it did, for the shell's snack bar. On a network
 *   error or a 5xx (`visitor_move_retry`, `visitors_unavailable`) the key stays and the move is asked again
 *   later: the data is out of sight meanwhile, never lost.
 * - **Held, after a silent sign-in into an account that has data here** (the owner's decision of 2026-10-11):
 *   the server moves nothing (`held`), and the key stays this browser's - signed out, its visitor's data is
 *   there again; the next sign-in the person clicks moves it. Nothing is said, nothing reloaded.
 *
 * A private window that refuses storage: the key lives in memory for the tab.
 *
 * This service reads AuthService, and never the other way round (the auth module is handed it as the site's
 * `visitors`, app.config.ts): the move is started here, by an effect on `auth.signedIn()`.
 */
@Injectable({ providedIn: 'root' })
export class VisitorKey implements VisitorKeyStore {
  private readonly auth = inject(AuthService);
  private readonly api = inject(Api);
  private readonly signInKind = inject(SignInKind);

  /** The key, when storage cannot keep it (or refused to). */
  private memory: string | null = null;
  private inMemory = false;
  private readonly _key = signal<string | null>(null);

  /** The key this browser holds, or null. */
  readonly key = this._key.asReadonly();
  /** A visitor: nobody is signed in, the site does not require a sign-in, and this browser holds a key. */
  readonly active = computed(
    () => !this.auth.signedIn() && !this.auth.loginRequired() && this._key() !== null,
  );
  /** The key was forgotten because the server no longer knew it: the dashboard says so, once. */
  readonly lost = signal(false);
  /** The last move that moved or kept anything - for the snack bar (worded where it is shown). */
  readonly moved = signal<VisitorMove | null>(null);
  /** Counts the moves that finished: a page that lists accounts loads them again when it changes. */
  readonly moves = signal(0);

  private moving = false;
  private retries = 0;
  private retryTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    this._key.set(this.read());

    // Another tab stored or forgot the key.
    const onStorage = (event: StorageEvent) => {
      if (event.key === VISITOR_STORAGE_KEY || event.key === null) {
        this._key.set(this.read());
      }
    };
    globalThis.addEventListener?.('storage', onStorage);
    inject(DestroyRef).onDestroy(() => {
      globalThis.removeEventListener?.('storage', onStorage);
      if (this.retryTimer !== null) {
        clearTimeout(this.retryTimer);
      }
    });

    // Signed in, with a key held: what the visitor imported becomes the person's.
    effect(() => {
      if (this.auth.signedIn() && this._key() !== null) {
        untracked(() => void this.moveIntoAccount());
      }
    });
  }

  /** The key held now, read from storage - what a request sends. */
  current(): string | null {
    const key = this.read();
    if (key !== untracked(this._key)) {
      this._key.set(key);
    }
    return key;
  }

  /**
   * A key the server just made. Kept only when this browser holds none: a key already stored (another tab's,
   * made a moment earlier) stays, and this one is dropped.
   */
  remember(key: string): void {
    if (!KEY_SHAPE.test(key)) {
      return;
    }
    if (this.read() === null) {
      this.write(key);
      this.lost.set(false);
    }
    this._key.set(this.read());
  }

  /**
   * Forgets `key` - when it is still the one held (another tab may have replaced a forgotten key with a new
   * one meanwhile). `lost`: because the server no longer knew it.
   */
  forget(key: string, lost = false): void {
    if (this.read() === key) {
      this.write(null);
      if (lost) {
        this.lost.set(true);
      }
    }
    this._key.set(this.read());
  }

  /** The move after a sign-in (see the class). Safe to call at any time: it does nothing without a person and a key. */
  async moveIntoAccount(): Promise<void> {
    const key = this.read();
    if (this.moving || key === null || !this.auth.signedIn()) {
      return;
    }
    this.moving = true;
    if (this.retryTimer !== null) {
      clearTimeout(this.retryTimer);
      this.retryTimer = null;
    }
    try {
      const result = await firstValueFrom(this.api.moveVisitor(key, this.signInKind.silent()));
      this.retries = 0;
      if (result.held) {
        // A silent sign-in into an account with data here: nothing moved, and the key stays this browser's.
        return;
      }
      this.forget(key);
      this.moves.update((count) => count + 1);
      if (result.moved > 0 || result.kept > 0) {
        this.moved.set(result);
      }
    } catch (error) {
      if (!(error instanceof HttpErrorResponse)) {
        return;
      }
      if (error.status === 0 || error.status >= 500) {
        this.askAgainLater(error);
      } else if (error.status === 422) {
        // Not a key the server could ever know: nothing to move, now or later.
        this.forget(key);
      }
      // A 401: the sign-in ended meanwhile - the next sign-in asks again (the effect).
    } finally {
      this.moving = false;
    }
  }

  private askAgainLater(error: HttpErrorResponse): void {
    if (this.retries >= MOVE_RETRY_MS.length) {
      return;
    }
    const seconds = Number(error.headers?.get('Retry-After'));
    const delay =
      Number.isFinite(seconds) && seconds > 0
        ? Math.min(seconds, 60) * 1000
        : MOVE_RETRY_MS[this.retries];
    this.retries++;
    this.retryTimer = setTimeout(() => {
      this.retryTimer = null;
      void this.moveIntoAccount();
    }, delay);
  }

  private read(): string | null {
    const value =
      this.inMemory || !storageUsable() ? this.memory : readStorage(VISITOR_STORAGE_KEY);
    return value !== null && KEY_SHAPE.test(value) ? value : null;
  }

  private write(value: string | null): void {
    this.memory = value;
    if (this.inMemory || !storageUsable()) {
      return;
    }
    writeStorage(VISITOR_STORAGE_KEY, value);
    if (readStorage(VISITOR_STORAGE_KEY) !== value) {
      // Storage would not keep it (a full quota): from now on the key lives in memory for this tab.
      this.inMemory = true;
    }
  }
}
