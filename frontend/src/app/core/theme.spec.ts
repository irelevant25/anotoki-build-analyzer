import { computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from './auth';
import {
  AnotokiPreferenceChanges,
  AnotokiPreferences,
  AnotokiSavedPreferences,
  AnotokiTheme,
} from './iam/anotoki-auth';
import { THEME_KEY, ThemeService } from './theme';

const ENGLISH = {
  'error.offline': 'Cannot reach the server. Check your connection and try again.',
  'error.unknown': 'Something went wrong.',
};

/** Everything already on its way has run. */
function settled(): Promise<void> {
  return new Promise((done) => setTimeout(done));
}

/** A promise the test settles by hand: the IAM's answer, when the test says. */
function later<T>() {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
}

describe('ThemeService: the theme, the account’s while somebody is signed in', () => {
  const user = signal<{
    sub: string;
    locale: string | null;
    preferences: AnotokiPreferences | null;
  } | null>(null);
  /** AuthService.savePreferences(): the account takes the change, as the SDK answers it - unless a test says otherwise. */
  const savePreferences =
    vi.fn<(changes: AnotokiPreferenceChanges) => Promise<AnotokiSavedPreferences>>();
  let snackBar: { open: ReturnType<typeof vi.fn> };

  /** Mona signs in; her account's theme - null: an IAM that keeps no preferences (before its migration 015). */
  function signedIn(theme: AnotokiTheme | null): void {
    user.set({
      sub: '7',
      locale: 'en',
      preferences: theme === null ? null : { theme, date_format: null, time_format: null },
    });
  }

  /** As the SDK does once the IAM has taken a change: the account as it now stands is in user() at once. */
  function accountTakes(changes: AnotokiPreferenceChanges): AnotokiSavedPreferences {
    const current = user()!;
    const saved = { locale: current.locale, preferences: { ...current.preferences!, ...changes } };
    user.set({ ...current, ...saved });
    return saved;
  }

  /** The account's theme changed elsewhere - on another anotoki site, in another tab. */
  function changedElsewhere(theme: AnotokiTheme): void {
    user.set({ ...user()!, preferences: { theme, date_format: null, time_format: null } });
  }

  const classes = () => document.documentElement.classList;
  /** The theme <html> is drawn in. */
  const drawn = () =>
    classes().contains('theme-dark')
      ? 'dark'
      : classes().contains('theme-light')
        ? 'light'
        : 'system';

  function theme(): ThemeService {
    return TestBed.inject(ThemeService);
  }

  beforeEach(() => {
    localStorage.clear();
    user.set(null);
    savePreferences.mockReset().mockImplementation(async (changes) => accountTakes(changes));
    snackBar = { open: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations(ENGLISH),
        {
          provide: AuthService,
          useValue: {
            user,
            preferences: computed(() => user()?.preferences ?? null),
            savePreferences,
          },
        },
        { provide: MatSnackBar, useValue: snackBar },
      ],
    });
  });

  afterEach(() => classes().remove('theme-light', 'theme-dark'));

  it("is this device's while nobody is signed in: drawn, remembered here, and saved nowhere else", () => {
    localStorage.setItem(THEME_KEY, 'dark');
    const service = theme();
    expect(service.mode()).toBe('dark');
    expect(drawn()).toBe('dark');

    service.set('light');
    expect(drawn()).toBe('light');
    expect(localStorage.getItem(THEME_KEY)).toBe('light');
    expect(savePreferences).not.toHaveBeenCalled();
  });

  it("is the account's from the first frame once somebody is signed in - and kept on this device for the next page load", () => {
    localStorage.setItem(THEME_KEY, 'light');
    signedIn('dark');

    // Before anything has run: the sign-in settles before the first page is drawn.
    const service = theme();
    expect(service.mode()).toBe('dark');
    expect(drawn()).toBe('dark');
    expect(localStorage.getItem(THEME_KEY)).toBe('dark');
  });

  it("follows the account's theme when it changes on another anotoki site or in another tab - and keeps it after a sign-out", () => {
    signedIn('dark');
    const service = theme();
    TestBed.tick();

    changedElsewhere('auto');
    TestBed.tick();
    expect(service.mode()).toBe('system');
    expect(drawn()).toBe('system');
    expect(localStorage.getItem(THEME_KEY)).toBe('system');

    // Signed out in another tab: the theme on the page stays - this device's now.
    user.set(null);
    TestBed.tick();
    expect(service.mode()).toBe('system');
    expect(savePreferences).not.toHaveBeenCalled();
  });

  it('saves the choice to the account - "system" as the IAM\'s "auto" - and draws it at once', async () => {
    signedIn('dark');
    const service = theme();
    TestBed.tick();

    service.set('system');
    expect(service.mode()).toBe('system');
    expect(drawn()).toBe('system');
    expect(savePreferences.mock.calls).toEqual([[{ theme: 'auto' }]]);
    await settled();
    TestBed.tick();
    expect(user()?.preferences?.theme).toBe('auto');
    expect(service.mode()).toBe('system');
    expect(localStorage.getItem(THEME_KEY)).toBe('system');

    // The account's own theme is not sent again.
    service.set('system');
    expect(savePreferences).toHaveBeenCalledTimes(1);

    service.set('light');
    expect(savePreferences.mock.calls[1]).toEqual([{ theme: 'light' }]);
  });

  it('saves one at a time, the latest choice last - and nothing the account has by then', async () => {
    signedIn('auto');
    const service = theme();
    TestBed.tick();
    const first = later<AnotokiSavedPreferences>();
    savePreferences.mockImplementationOnce(() => first.promise);

    service.set('dark');
    service.set('light');
    service.set('dark');
    expect(drawn()).toBe('dark');
    expect(savePreferences.mock.calls).toEqual([[{ theme: 'dark' }]]);

    // The first save lands, and the last choice is the account's by then: nothing more is sent.
    first.resolve(accountTakes({ theme: 'dark' }));
    await settled();
    TestBed.tick();
    expect(savePreferences.mock.calls).toEqual([[{ theme: 'dark' }]]);
    expect(service.mode()).toBe('dark');

    // Light, then system, while a save is on its way: system is saved after it.
    const second = later<AnotokiSavedPreferences>();
    savePreferences.mockImplementationOnce(() => second.promise);
    service.set('light');
    service.set('system');
    second.resolve(accountTakes({ theme: 'light' }));
    await settled();
    TestBed.tick();
    expect(savePreferences.mock.calls.slice(1)).toEqual([
      [{ theme: 'light' }],
      [{ theme: 'auto' }],
    ]);
    expect(user()?.preferences?.theme).toBe('auto');
    expect(service.mode()).toBe('system');
    expect(drawn()).toBe('system');
  });

  it("does not flick back to the account's earlier theme while its own save is on its way", async () => {
    signedIn('auto');
    const service = theme();
    TestBed.tick();
    const pending = later<AnotokiSavedPreferences>();
    savePreferences.mockImplementationOnce(() => pending.promise);

    service.set('dark');
    changedElsewhere('light');
    TestBed.tick();
    expect(service.mode()).toBe('dark');
    expect(drawn()).toBe('dark');

    pending.resolve(accountTakes({ theme: 'dark' }));
    await settled();
    TestBed.tick();
    expect(service.mode()).toBe('dark');
  });

  it("puts the account's theme back when the save is refused, and says why in the page's words", async () => {
    signedIn('dark');
    const service = theme();
    TestBed.tick();
    savePreferences.mockRejectedValueOnce(
      Object.assign(new Error('The IAM could not be reached'), { code: 'network' }),
    );

    service.set('light');
    expect(drawn()).toBe('light');
    await settled();

    expect(service.mode()).toBe('dark');
    expect(drawn()).toBe('dark');
    expect(localStorage.getItem(THEME_KEY)).toBe('dark');
    expect(snackBar.open).toHaveBeenCalledWith(
      'Cannot reach the server. Check your connection and try again.',
      undefined,
      { duration: 6000 },
    );
  });

  it('says nothing of a refused save that a later choice has overtaken', async () => {
    signedIn('auto');
    const service = theme();
    TestBed.tick();
    const first = later<AnotokiSavedPreferences>();
    savePreferences.mockImplementationOnce(() => first.promise);

    service.set('dark');
    service.set('light');
    first.reject(Object.assign(new Error('The IAM could not be reached'), { code: 'network' }));
    await settled();
    TestBed.tick();

    expect(savePreferences.mock.calls).toEqual([[{ theme: 'dark' }], [{ theme: 'light' }]]);
    expect(snackBar.open).not.toHaveBeenCalled();
    expect(user()?.preferences?.theme).toBe('light');
    expect(service.mode()).toBe('light');
  });

  it("saves nothing to an IAM that keeps no preferences: the device's, as before", () => {
    localStorage.setItem(THEME_KEY, 'dark');
    signedIn(null);
    const service = theme();
    TestBed.tick();
    expect(service.mode()).toBe('dark');

    service.set('light');
    expect(drawn()).toBe('light');
    expect(localStorage.getItem(THEME_KEY)).toBe('light');
    expect(savePreferences).not.toHaveBeenCalled();
  });
});
