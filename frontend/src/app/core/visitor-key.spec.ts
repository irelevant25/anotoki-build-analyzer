import { HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Observable, Subject, of, throwError } from 'rxjs';
import { Api } from './api';
import { AuthService } from './auth';
import { VisitorMove } from './models';
import { SIGN_IN_KIND_STORAGE_KEY } from './sign-in-kind';
import { MOVE_RETRY_MS, VISITOR_STORAGE_KEY, VisitorKey } from './visitor-key';

const KEY_A = 'A'.repeat(43);
const KEY_B = 'b-_'.repeat(14) + 'x';

/** Lets the move's promise settle. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function answer(moved: number, kept: number, found = true): VisitorMove {
  return { found, moved, kept, imports: moved, held: false };
}

/** The server's answer after a silent sign-in into an account with data here: nothing moved. */
const HELD: VisitorMove = { found: true, moved: 0, kept: 0, imports: 0, held: true };

function failure(status: number, code?: string, retryAfter?: string): HttpErrorResponse {
  return new HttpErrorResponse({
    status,
    error: code ? { message: 'x', code } : null,
    headers: retryAfter ? new HttpHeaders({ 'Retry-After': retryAfter }) : undefined,
  });
}

describe('VisitorKey', () => {
  let signedIn: ReturnType<typeof signal<boolean>>;
  let loginRequired: ReturnType<typeof signal<boolean>>;
  let moveVisitor: ReturnType<typeof vi.fn<(key: string, silent?: boolean) => Observable<VisitorMove>>>;

  beforeEach(() => {
    localStorage.clear();
    signedIn = signal(false);
    loginRequired = signal(false);
    moveVisitor = vi.fn<(key: string, silent?: boolean) => Observable<VisitorMove>>(() => of(answer(0, 0, false)));
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    localStorage.clear();
  });

  function service(): VisitorKey {
    TestBed.configureTestingModule({
      providers: [
        { provide: AuthService, useValue: { signedIn, loginRequired } },
        { provide: Api, useValue: { moveVisitor } },
      ],
    });
    return TestBed.inject(VisitorKey);
  }

  describe('the key', () => {
    it('is the one the browser holds: read at the start, and again each time a request leaves', () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      const visitor = service();
      expect(visitor.key()).toBe(KEY_A);

      // Another tab forgot it and a third made a new one: the next request sends that one.
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_B);
      expect(visitor.current()).toBe(KEY_B);
      expect(visitor.key()).toBe(KEY_B);
    });

    it('follows another tab through the storage event', () => {
      const visitor = service();
      expect(visitor.key()).toBeNull();

      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      window.dispatchEvent(new StorageEvent('storage', { key: VISITOR_STORAGE_KEY, newValue: KEY_A }));
      expect(visitor.key()).toBe(KEY_A);
      expect(visitor.active()).toBe(true);

      localStorage.removeItem(VISITOR_STORAGE_KEY);
      window.dispatchEvent(new StorageEvent('storage', { key: VISITOR_STORAGE_KEY, newValue: null }));
      expect(visitor.key()).toBeNull();
      expect(visitor.active()).toBe(false);
    });

    it('is never overwritten by a different one: the first key stays (two tabs that both imported first)', () => {
      const visitor = service();
      visitor.remember(KEY_A);
      expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBe(KEY_A);

      visitor.remember(KEY_B);
      expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBe(KEY_A);
      expect(visitor.key()).toBe(KEY_A);

      // A tab still holding nothing in its signal finds the stored key, and keeps it.
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_B);
      visitor.remember(KEY_A);
      expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBe(KEY_B);
      expect(visitor.key()).toBe(KEY_B);
    });

    it('is only ever a key: anything else the server or the storage holds is none', () => {
      const visitor = service();
      visitor.remember('short');
      visitor.remember(`${KEY_A}=`);
      expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBeNull();

      localStorage.setItem(VISITOR_STORAGE_KEY, 'not a key at all');
      expect(visitor.current()).toBeNull();
      visitor.remember(KEY_A);
      expect(visitor.current()).toBe(KEY_A);
    });

    it('is forgotten when the server no longer knows it - the one that was sent, not a newer one', () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      const visitor = service();

      visitor.forget(KEY_B, true);
      expect(visitor.key()).toBe(KEY_A);
      expect(visitor.lost()).toBe(false);

      visitor.forget(KEY_A, true);
      expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBeNull();
      expect(visitor.key()).toBeNull();
      expect(visitor.lost()).toBe(true);

      // A new visit begins: nothing is lost any more.
      visitor.remember(KEY_B);
      expect(visitor.lost()).toBe(false);
    });

    it('lives in memory for the tab where the browser refuses storage (a private window)', () => {
      vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new DOMException('denied', 'SecurityError');
      });
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new DOMException('denied', 'SecurityError');
      });
      const visitor = service();
      visitor.remember(KEY_A);
      expect(visitor.current()).toBe(KEY_A);
      expect(visitor.active()).toBe(true);
      visitor.forget(KEY_A);
      expect(visitor.current()).toBeNull();
    });

    it('lives in memory when storage will not keep it (a full quota)', () => {
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new DOMException('full', 'QuotaExceededError');
      });
      const visitor = service();
      visitor.remember(KEY_A);
      expect(visitor.current()).toBe(KEY_A);
    });

    it('is kept, but makes nobody a visitor, while the site requires a sign-in - and is back when it no longer does', () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      loginRequired.set(true);
      const visitor = service();
      expect(visitor.key()).toBe(KEY_A);
      expect(visitor.active()).toBe(false);
      expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBe(KEY_A);

      loginRequired.set(false);
      expect(visitor.active()).toBe(true);
    });
  });

  describe('the move after a sign-in', () => {
    it("moves the key held while the site requires a sign-in too: what the visitor imported becomes the person's", async () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      loginRequired.set(true);
      moveVisitor.mockReturnValue(of(answer(1, 0)));
      const visitor = service();
      signedIn.set(true);
      TestBed.tick();
      await settle();

      expect(moveVisitor).toHaveBeenCalledWith(KEY_A, false);
      expect(visitor.key()).toBeNull();
      expect(visitor.moved()).toEqual(answer(1, 0));
    });

    it('moves what the visitor imported, once, then forgets the key and says what moved', async () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      moveVisitor.mockReturnValue(of(answer(2, 1)));
      const visitor = service();
      TestBed.tick();
      expect(moveVisitor).not.toHaveBeenCalled(); // nobody signed in yet

      signedIn.set(true);
      TestBed.tick();
      await settle();

      expect(moveVisitor).toHaveBeenCalledTimes(1);
      expect(moveVisitor).toHaveBeenCalledWith(KEY_A, false);
      expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBeNull();
      expect(visitor.key()).toBeNull();
      expect(visitor.moves()).toBe(1);
      expect(visitor.moved()).toEqual(answer(2, 1));
      expect(visitor.active()).toBe(false);
    });

    it('forgets the key when there was nothing to move (another tab moved it, or it was swept) - and says nothing', async () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      signedIn.set(true);
      const visitor = service();
      TestBed.tick();
      await settle();

      expect(moveVisitor).toHaveBeenCalledTimes(1);
      expect(visitor.key()).toBeNull();
      expect(visitor.moves()).toBe(1);
      expect(visitor.moved()).toBeNull();
    });

    it('asks once at a time', async () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      const pending = new Subject<VisitorMove>();
      moveVisitor.mockReturnValue(pending);
      signedIn.set(true);
      const visitor = service();
      TestBed.tick();
      void visitor.moveIntoAccount();
      void visitor.moveIntoAccount();
      await settle();
      expect(moveVisitor).toHaveBeenCalledTimes(1);

      pending.next(answer(1, 0));
      pending.complete();
      await settle();
      expect(visitor.key()).toBeNull();
    });

    it('keeps the key on a network error or a 5xx, and asks again later - after the Retry-After the server says', async () => {
      vi.useFakeTimers();
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      moveVisitor
        .mockReturnValueOnce(throwError(() => failure(0)))
        .mockReturnValueOnce(throwError(() => failure(503, 'visitor_move_retry', '2')))
        .mockReturnValueOnce(of(answer(1, 0)));
      signedIn.set(true);
      const visitor = service();
      TestBed.tick();
      await vi.advanceTimersByTimeAsync(0);

      expect(moveVisitor).toHaveBeenCalledTimes(1);
      expect(visitor.key()).toBe(KEY_A);

      await vi.advanceTimersByTimeAsync(MOVE_RETRY_MS[0]);
      expect(moveVisitor).toHaveBeenCalledTimes(2);
      expect(visitor.key()).toBe(KEY_A);

      await vi.advanceTimersByTimeAsync(1999);
      expect(moveVisitor).toHaveBeenCalledTimes(2);
      await vi.advanceTimersByTimeAsync(1);
      expect(moveVisitor).toHaveBeenCalledTimes(3);
      expect(visitor.key()).toBeNull();
      expect(visitor.moved()).toEqual(answer(1, 0));
    });

    it('stops asking in this page load after the last retry - the key stays for the next one', async () => {
      vi.useFakeTimers();
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      moveVisitor.mockReturnValue(throwError(() => failure(503, 'visitors_unavailable')));
      signedIn.set(true);
      const visitor = service();
      TestBed.tick();
      await vi.advanceTimersByTimeAsync(MOVE_RETRY_MS.reduce((sum, ms) => sum + ms, 0) + 120_000);

      expect(moveVisitor).toHaveBeenCalledTimes(MOVE_RETRY_MS.length + 1);
      expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBe(KEY_A);
      expect(visitor.key()).toBe(KEY_A);
    });

    it('forgets a key the server says is not one (422); keeps it, without asking again, when the sign-in has ended (401)', async () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      moveVisitor.mockReturnValueOnce(throwError(() => failure(401, 'sign_in_required')));
      signedIn.set(true);
      const visitor = service();
      TestBed.tick();
      await settle();
      expect(visitor.key()).toBe(KEY_A);

      moveVisitor.mockReturnValueOnce(throwError(() => failure(422, 'invalid')));
      await visitor.moveIntoAccount();
      expect(visitor.key()).toBeNull();
      expect(moveVisitor).toHaveBeenCalledTimes(2);
    });
  });

  describe("the move after a silent sign-in (the IAM's hint)", () => {
    it('says the sign-in was silent - the server then moves nothing into an account with data here', async () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      localStorage.setItem(SIGN_IN_KIND_STORAGE_KEY, 'silent');
      moveVisitor.mockReturnValue(of(answer(1, 0)));
      signedIn.set(true);
      const visitor = service();
      TestBed.tick();
      await settle();

      // An account new here takes the visitor's data in, as after any sign-in.
      expect(moveVisitor).toHaveBeenCalledWith(KEY_A, true);
      expect(visitor.key()).toBeNull();
      expect(visitor.moved()).toEqual(answer(1, 0));
    });

    it("held: the key stays this browser's, nothing is said or reloaded, and nothing is asked again in this page load", async () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      localStorage.setItem(SIGN_IN_KIND_STORAGE_KEY, 'silent');
      moveVisitor.mockReturnValue(of(HELD));
      signedIn.set(true);
      const visitor = service();
      TestBed.tick();
      await settle();
      TestBed.tick();
      await settle();

      expect(moveVisitor).toHaveBeenCalledTimes(1);
      expect(localStorage.getItem(VISITOR_STORAGE_KEY)).toBe(KEY_A);
      expect(visitor.key()).toBe(KEY_A);
      expect([visitor.moves(), visitor.moved(), visitor.lost()]).toEqual([0, null, false]);

      // Signed out again: this browser's visitor, with what it imported.
      signedIn.set(false);
      expect(visitor.active()).toBe(true);
    });

    it('a sign-in the person clicked says so - and moves, whatever the account has', async () => {
      localStorage.setItem(VISITOR_STORAGE_KEY, KEY_A);
      localStorage.setItem(SIGN_IN_KIND_STORAGE_KEY, 'clicked');
      moveVisitor.mockReturnValue(of(answer(1, 0)));
      signedIn.set(true);
      const visitor = service();
      TestBed.tick();
      await settle();

      expect(moveVisitor).toHaveBeenCalledWith(KEY_A, false);
      expect(visitor.key()).toBeNull();
    });
  });
});
