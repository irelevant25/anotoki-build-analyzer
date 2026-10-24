/**
 * localStorage that never throws: it can be missing, or refuse reads and writes (a private window, blocked
 * site data, a full quota). What it cannot keep simply does not survive a reload.
 *
 * `storageUsable()` tells a value that is not there from storage that cannot be read at all - for the one
 * caller that then keeps the value in memory instead (VisitorKey).
 */
export function readStorage(key: string): string | null {
  try {
    return globalThis.localStorage?.getItem(key) ?? null;
  } catch {
    return null;
  }
}

/** Stores a value - or, given null, removes it. */
export function writeStorage(key: string, value: string | null): void {
  try {
    if (value === null) {
      globalThis.localStorage?.removeItem(key);
    } else {
      globalThis.localStorage?.setItem(key, value);
    }
  } catch {
    // Nothing to do: the value just will not survive a reload.
  }
}

/** Whether localStorage can be read at all. */
export function storageUsable(): boolean {
  try {
    const storage = globalThis.localStorage;
    storage?.getItem('');
    return !!storage;
  } catch {
    return false;
  }
}
