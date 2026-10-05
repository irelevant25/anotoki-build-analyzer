import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, provideRouter } from '@angular/router';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { provideTestTranslations } from '../../core/i18n/testing';
import { Wording } from '../../core/i18n/translation';
import { AuthCallbackPage } from './auth-callback-page';
import { SignInPage, safePath } from './sign-in-page';

describe('safePath (where a sign-in comes back to)', () => {
  it('keeps paths on this site', () => {
    expect(safePath('/accounts/3?build=7')).toBe('/accounts/3?build=7');
    expect(safePath('/')).toBe('/');
  });

  it('refuses anything that could lead elsewhere', () => {
    for (const value of [undefined, '', 'https://elsewhere.example/', '//elsewhere.example', '/\\elsewhere.example', '/\t/elsewhere.example', '/\n/elsewhere.example', 'accounts']) {
      expect(safePath(value)).toBe('/');
    }
  });
});

/** The sign-in page's Slovak, as the catalogue has it; English underneath, as the server merges it. */
const SLOVAK = {
  ...en,
  'common.signIn': 'Prihlásiť sa',
  'common.signOut': 'Odhlásiť sa',
  'common.userFallback': 'č. {sub}',
  'signIn.intro': 'Importuj svoje účty z hier Genshin Impact a Honkai: Star Rail a pozri si pri každej postave, čo zlepšiť. Ak chceš pokračovať, prihlás sa svojím anotoki účtom.',
  'common.close': 'Zavrieť',
  'signIn.unreachable': 'Nepodarilo sa spojiť so serverom, takže sa teraz nedá prihlásiť. Obnov stránku a skús to znova.',
  'signIn.signingIn': 'Prihlasujeme ťa…',
  'signIn.failed': 'Prihlásenie sa nedokončilo: {code}',
  'signIn.stateMismatch': 'Toto prihlásenie sa nezačalo tu alebo trvalo príliš dlho. Prihlás sa znova.',
};

describe('the sign-in page', () => {
  let fixture: ComponentFixture<SignInPage>;
  let host: HTMLElement;
  let auth: {
    available: ReturnType<typeof signal<boolean>>;
    signedIn: ReturnType<typeof signal<boolean>>;
    user: ReturnType<typeof signal<null>>;
    lastError: ReturnType<typeof signal<Wording | null>>;
    login: ReturnType<typeof vi.fn>;
  };
  let snackBar: { open: ReturnType<typeof vi.fn> };

  /** What an element says, without the names of its icons. */
  const text = (element: Element | null | undefined) => {
    const copy = element?.cloneNode(true) as Element | undefined;
    copy?.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
    return copy?.textContent?.replace(/\s+/g, ' ').trim();
  };
  const notices = () => [...host.querySelectorAll<HTMLElement>('p.notice')];
  const buttons = () => [...host.querySelectorAll<HTMLButtonElement>('button')];

  interface Options {
    /** 'none': no bundle at all - the real service, reading the compiled English. */
    language?: 'en' | 'sk' | 'none';
    strings?: Record<string, string>;
    /** The server could not be asked: there is no IAM to go to. */
    unavailable?: boolean;
    signedIn?: boolean;
    /** The browser refuses the site's storage: the sign-in cannot start. */
    cannotStart?: boolean;
    problem?: Wording | null;
    returnTo?: string;
  }

  async function setup(options: Options = {}): Promise<void> {
    const lastError = signal<Wording | null>(options.problem ?? null);
    auth = {
      available: signal(!options.unavailable),
      signedIn: signal(options.signedIn ?? false),
      user: signal(null),
      lastError,
      login: vi.fn(async () => {
        if (options.cannotStart) {
          lastError.set({ key: 'signIn.storageUnavailable', params: {} });
          return false;
        }
        return true;
      }),
    };
    snackBar = { open: vi.fn() };
    const language = options.language ?? 'sk';
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        language === 'sk' ? provideTestTranslations({ ...SLOVAK, ...options.strings }, { language: 'sk', english: en }) : language === 'en' ? provideTestTranslations(en) : [],
        { provide: AuthService, useValue: auth },
        { provide: MatSnackBar, useValue: snackBar },
      ],
    });
    fixture = TestBed.createComponent(SignInPage);
    if (options.returnTo !== undefined) {
      fixture.componentRef.setInput('returnTo', options.returnTo);
    }
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  afterEach(() => vi.restoreAllMocks());

  it('says what the app is for and offers the sign-in, in the reader’s language', async () => {
    await setup();

    expect(text(host.querySelector('h1'))).toBe('Build Analyzer');
    expect(text(host.querySelector('p.muted'))).toBe(SLOVAK['signIn.intro']);
    expect(buttons().map(text)).toEqual(['Prihlásiť sa']);
    expect(notices()).toEqual([]);
  });

  it('goes to the sign-in in the reader’s language, and comes back where the person was going', async () => {
    await setup({ returnTo: '/accounts/3?build=7' });
    buttons()[0].click();

    expect(auth.login).toHaveBeenCalledWith('/accounts/3?build=7', { uiLocales: 'sk' });
  });

  it('sends somebody who is signed in on to where they were going', async () => {
    const navigate = vi.spyOn(Router.prototype, 'navigateByUrl').mockResolvedValue(true);
    await setup({ signedIn: true, returnTo: '/accounts/3' });

    expect(navigate).toHaveBeenCalledWith('/accounts/3', { replaceUrl: true });
  });

  it('says why when the sign-in cannot start - the browser refuses the site storage', async () => {
    await setup({ cannotStart: true });
    buttons()[0].click();
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(snackBar.open).toHaveBeenCalledWith(en['signIn.storageUnavailable'], 'Zavrieť', expect.anything());
  });

  describe('after a sign-in that did not complete', () => {
    it('words the reason from its key and what fills it - never an object, never the IAM’s sentence', async () => {
      await setup({ problem: { key: 'signIn.failed', params: { code: 'invalid_grant' } } });

      expect(notices().map(text)).toEqual(['Prihlásenie sa nedokončilo: invalid_grant']);
      expect(notices()[0].getAttribute('role')).toBe('alert');
      expect(host.textContent).not.toContain('[object');
    });

    it('follows the reason as it changes, and the language', async () => {
      await setup({ language: 'en', problem: { key: 'signIn.stateMismatch', params: {} } });
      expect(notices().map(text)).toEqual(['That sign-in did not start here, or took too long. Please sign in again.']);

      auth.lastError.set({ key: 'signIn.failed', params: { code: 'unknown' } });
      await fixture.whenStable();
      expect(notices().map(text)).toEqual(['The sign-in did not complete: unknown']);

      auth.lastError.set(null);
      await fixture.whenStable();
      expect(notices()).toEqual([]);
    });
  });

  describe('while the server cannot be reached', () => {
    it('says that signing in is not possible now, and offers no button', async () => {
      await setup({ unavailable: true });

      expect(notices().map(text)).toEqual(['Nepodarilo sa spojiť so serverom, takže sa teraz nedá prihlásiť. Obnov stránku a skús to znova.']);
      expect(buttons()).toEqual([]);
    });

    it('reads the compiled English when there are no strings from the server at all - never a key', async () => {
      await setup({ language: 'none', unavailable: true });

      expect(text(host.querySelector('p.muted'))).toBe(en['signIn.intro']);
      expect(notices().map(text)).toEqual(['The server could not be reached, so signing in is not possible right now. Reload the page to try again.']);
      expect(host.textContent).not.toMatch(/\b(signIn|common)\.[A-Za-z]/);
    });
  });

  it('leaves the language of the IAM’s pages to the IAM while the reader’s is not known', async () => {
    await setup({ language: 'none' });
    expect(buttons().map(text)).toEqual(['Sign in']);
    buttons()[0].click();

    expect(auth.login).toHaveBeenCalledWith('/', { uiLocales: undefined });
  });
});

describe('the page shown while a sign-in is being finished', () => {
  async function page(language: 'en' | 'sk'): Promise<HTMLElement> {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        language === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: en }) : provideTestTranslations(en),
        { provide: AuthService, useValue: { lastError: signal(null), signedIn: signal(true), available: signal(true), user: signal(null) } },
      ],
    });
    const fixture = TestBed.createComponent(AuthCallbackPage);
    await fixture.whenStable();
    return fixture.nativeElement;
  }

  it('says so in the reader’s language', async () => {
    expect((await page('sk')).querySelector('p')?.textContent?.trim()).toBe('Prihlasujeme ťa…');
    TestBed.resetTestingModule();
    expect((await page('en')).querySelector('p')?.textContent?.trim()).toBe('Signing in…');
  });
});
