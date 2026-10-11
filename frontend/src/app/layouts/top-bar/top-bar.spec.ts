import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatDialog } from '@angular/material/dialog';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, provideRouter } from '@angular/router';
import { provideAnotokiTranslations } from '@anotoki/lib/translations';
import { LocalizationDrafts } from '@anotoki/lib/translations/admin';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { of } from 'rxjs';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { TranslationService, Wording } from '../../core/i18n/translation';
import { LANGUAGE_STORAGE } from '../../core/i18n/translations.config';
import { SyncDriver } from '../../core/sync-driver';
import { ADMIN_PHONE_BAR_MAX, PHONE_BAR_MAX, TopBar } from './top-bar';

const ENGLISH = {
  'brand.area': 'Build Analyzer',
  'brand.short': 'Analyzer',
  // The admin panel's brand and its link's name as the site ships them (the panel's row is measured with them).
  'brand.admin': en['brand.admin'],
  'topbar.home': 'anotoki Build Analyzer: the start page',
  'topbar.homeAdmin': en['topbar.homeAdmin'],
  'topbar.sections': "The site's sections",
  'topbar.dashboard': 'Dashboard',
  'topbar.import': 'Import',
  'topbar.builds': 'Builds',
  'topbar.phoneMenu': 'Menu',
  'topbar.menuButton': 'Your menu: {name}',
  'topbar.menu': 'Your menu',
  'topbar.roleAdmin': 'Administrator',
  'topbar.roleEditor': 'Editor',
  'topbar.account': 'Your anotoki account',
  'topbar.admin': 'Admin panel',
  'topbar.backToApp': 'Back to Build Analyzer',
  'topbar.signOutUnsaved.title': 'Sign out without your latest changes?',
  'topbar.signOutUnsaved.message':
    "Some changes to the site's strings or languages are not saved - the Translations and Languages pages show them. Signed out, they are lost.",
  'topbar.signOutUnsaved.confirm': 'Sign out anyway',
  'topbar.signOutUnsaved.stay': 'Stay',
  'toolbar.syncing': 'Syncing builds (keep this tab open)',
  'toolbar.syncingLabel': 'Syncing builds',
  'common.signIn': 'Sign in',
  'common.signOut': 'Sign out',
  'common.close': 'Close',
  'common.userFallback': 'user {sub}',
  'theme.label': 'Theme',
  'theme.system': 'System',
  'theme.light': 'Light',
  'theme.dark': 'Dark',
  'anotoki.language.button': 'Language: {name} ({code})',
  'anotoki.language.notLoaded': 'The language could not be loaded. Try again.',
  'signIn.storageUnavailable': 'This browser will not let the site remember a sign-in.',
};
const SLOVAK = {
  'brand.area': 'Build Analyzer',
  'brand.short': 'Analyzer',
  'brand.admin': 'administrátorský panel',
  'topbar.home': 'anotoki Build Analyzer: úvodná stránka',
  'topbar.homeAdmin': 'anotoki Build Analyzer: administrátorský panel',
  'topbar.sections': 'Časti stránky',
  'topbar.dashboard': 'Prehľad',
  'topbar.import': 'Import',
  'topbar.builds': 'Buildy',
  'topbar.phoneMenu': 'Ponuka',
  'topbar.menuButton': 'Tvoja ponuka: {name}',
  'topbar.menu': 'Tvoja ponuka',
  'topbar.roleAdmin': 'Správca',
  'topbar.roleEditor': 'Editor',
  'topbar.account': 'Tvoj anotoki účet',
  'topbar.admin': 'Administrátorský panel',
  'topbar.backToApp': 'Späť na Build Analyzer',
  'topbar.signOutUnsaved.title': 'Odhlásiť sa bez posledných zmien?',
  'topbar.signOutUnsaved.message':
    'Niektoré zmeny v textoch alebo jazykoch stránky nie sú uložené - nájdeš ich na stránkach Translations a Languages. Po odhlásení sa stratia.',
  'topbar.signOutUnsaved.confirm': 'Aj tak sa odhlásiť',
  'topbar.signOutUnsaved.stay': 'Zostať',
  'toolbar.syncing': 'Synchronizujú sa buildy (nechaj túto kartu otvorenú)',
  'toolbar.syncingLabel': 'Synchronizujú sa buildy',
  'common.signIn': 'Prihlásiť sa',
  'common.signOut': 'Odhlásiť sa',
  'common.close': 'Zavrieť',
  'common.userFallback': 'č. {sub}',
  'theme.label': 'Vzhľad',
  'theme.system': 'Systémový',
  'theme.light': 'Svetlý',
  'theme.dark': 'Tmavý',
  'anotoki.language.button': 'Jazyk: {name} ({code})',
  'anotoki.language.notLoaded': 'Jazyk sa nepodarilo načítať. Skús to znova.',
  'signIn.storageUnavailable': 'Tento prehliadač nedovolí stránke zapamätať si prihlásenie.',
};

/** Whatever page the router draws: the bar only needs it to be somewhere. */
@Component({ selector: 'app-test-page', template: '' })
class TestPage {}

/** A media query list whose answer a test can change, telling its listeners as a browser does. */
class FakeMedia {
  private listeners: ((event: { matches: boolean }) => void)[] = [];
  constructor(public matches: boolean) {}
  addEventListener(_type: string, listener: (event: { matches: boolean }) => void): void {
    this.listeners.push(listener);
  }
  removeEventListener(): void {
    this.listeners = [];
  }
  /** The older names, which the CDK's breakpoint observer (a tooltip's) still calls. */
  addListener(listener: (event: { matches: boolean }) => void): void {
    this.addEventListener('change', listener);
  }
  removeListener(): void {
    this.removeEventListener();
  }
  change(matches: boolean): void {
    this.matches = matches;
    this.listeners.forEach((listener) => listener({ matches }));
  }
}

describe('the top bar', () => {
  const user = signal<{
    sub: string;
    email: string | null;
    preferred_username: string | null;
  } | null>(null);
  const roles = signal<string[]>([]);
  const lastError = signal<Wording | null>(null);
  const auth = {
    user,
    available: signal(true),
    signedIn: () => user() !== null,
    displayName: () => user()?.preferred_username ?? user()?.email ?? null,
    preferences: () => null,
    isAdmin: () => roles().includes('ADMIN'),
    isStaff: () => roles().includes('ADMIN') || roles().includes('EDITOR'),
    accountUrl: signal<string | null>('https://iam.example.test/account'),
    lastError,
    login: vi.fn(async () => true),
    logout: vi.fn(async () => undefined),
  };
  const sync = { driving: signal(false) };
  let snackBar: { open: ReturnType<typeof vi.fn> };
  let dialog: { open: ReturnType<typeof vi.fn> };
  let answer: boolean;
  let media: FakeMedia;

  let fixture: ComponentFixture<TopBar>;
  let host: HTMLElement;

  /** An element's words: its text without the icons' ligatures, spaces folded. */
  const words = (element: Element | null | undefined) => {
    const copy = element?.cloneNode(true) as Element | undefined;
    copy?.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
    return copy?.textContent?.replace(/\s+/g, ' ').trim();
  };
  const accountButton = () => host.querySelector<HTMLButtonElement>('.user-button')!;
  const phoneButton = () => host.querySelector<HTMLButtonElement>('.menu-button')!;
  const items = () => Array.from(host.querySelectorAll<HTMLElement>('[role="menuitem"]'));
  const parts = (selector: string) => Array.from(host.querySelectorAll(selector)).map(words);
  const links = () => Array.from(host.querySelectorAll<HTMLAnchorElement>('.topbar-nav a'));
  /** A link as "words href", with " [page]" for aria-current and " [here]" for the section the page is in. */
  const described = (link: HTMLAnchorElement) =>
    `${words(link)} ${link.getAttribute('href')}${link.getAttribute('aria-current') === 'page' ? ' [page]' : ''}${link.classList.contains('is-active') ? ' [here]' : ''}`;
  /** What stands in an element, in order: each child as tag.firstClass. */
  const childrenOf = (selector: string) =>
    Array.from(host.querySelector(selector)!.children).map(
      (child) =>
        child.tagName.toLowerCase() +
        (child.className ? '.' + String(child.className).split(' ')[0] : ''),
    );

  async function draw(area: 'app' | 'admin' = 'app'): Promise<void> {
    fixture = TestBed.createComponent(TopBar);
    fixture.componentRef.setInput('area', area);
    host = fixture.nativeElement;
    document.body.appendChild(host);
    await fixture.whenStable();
  }

  async function at(url: string): Promise<void> {
    await TestBed.inject(Router).navigateByUrl(url);
    await fixture.whenStable();
  }

  async function openMenu(): Promise<void> {
    (host.querySelector<HTMLButtonElement>('.user-button') ?? phoneButton()).click();
    await fixture.whenStable();
  }

  /**
   * A window `width` wide, as the bar's media queries see it: each (max-width: N px) answers for this width.
   * `media` is the member pages' phone query, the one a test turns.
   */
  function windowOf(width: number): void {
    const queries = new Map<string, FakeMedia>();
    media = new FakeMedia(width <= PHONE_BAR_MAX);
    queries.set(`(max-width: ${PHONE_BAR_MAX}px)`, media);
    vi.stubGlobal('matchMedia', (query: string) => {
      let answer = queries.get(query);
      if (!answer) {
        answer = new FakeMedia(width <= Number(/max-width:\s*(\d+)px/.exec(query)?.[1] ?? Infinity));
        queries.set(query, answer);
      }
      return answer;
    });
  }

  beforeEach(() => {
    sessionStorage.clear();
    user.set({ sub: '7', email: 'mona@example.test', preferred_username: 'mona' });
    roles.set(['ADMIN']);
    lastError.set(null);
    sync.driving.set(false);
    auth.login.mockReset().mockResolvedValue(true);
    auth.logout.mockClear();
    snackBar = { open: vi.fn() };
    answer = false;
    dialog = { open: vi.fn(() => ({ afterClosed: () => of(answer) })) };
    windowOf(1280);
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        // The site's names in storage, and whose the drafts of the admin pages are (the library's LocalizationDrafts).
        provideAnotokiTranslations(() => ({
          storageKeys: LANGUAGE_STORAGE,
          account: { language: () => null, userKey: () => user()?.sub ?? null },
        })),
        provideRouter([{ path: '**', component: TestPage }]),
        { provide: AuthService, useValue: auth },
        { provide: SyncDriver, useValue: sync },
        { provide: MatSnackBar, useValue: snackBar },
        { provide: MatDialog, useValue: dialog },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
  });

  afterEach(() => {
    host?.remove();
    sessionStorage.clear();
    vi.unstubAllGlobals();
  });

  it("is one row in the family's order: the brand, the links, then the language, the theme and the person", async () => {
    await draw();
    expect(childrenOf('.topbar-inner')).toEqual([
      'app-brand',
      'nav.topbar-nav',
      'div.topbar-actions',
    ]);
    expect(childrenOf('.topbar-actions')).toEqual([
      'app-language-switcher',
      'app-theme-toggle',
      'div.user-menu',
    ]);
    expect(host.querySelector('.topbar-nav')?.getAttribute('aria-label')).toBe('Časti stránky');
    // The brand: the mark, "anotoki" and the app's name.
    expect(parts('app-brand .wordmark > span')).toEqual(['anotoki', 'Build Analyzer']);
    expect(host.querySelector('app-brand img')?.getAttribute('alt')).toBe('');
  });

  it("links the sections everybody has, and marks the one the page is in - an account is the dashboard's, a game's builds the builds'", async () => {
    await draw();
    await at('/');
    expect(links().map(described)).toEqual([
      'Prehľad / [page] [here]',
      'Import /import',
      'Buildy /builds',
    ]);
    await at('/accounts/3/characters/hutao?tab=1');
    expect(links().map(described)).toEqual([
      'Prehľad / [here]',
      'Import /import',
      'Buildy /builds',
    ]);
    await at('/builds/genshin');
    expect(links().map(described)).toEqual([
      'Prehľad /',
      'Import /import',
      'Buildy /builds [here]',
    ]);
    await at('/import');
    expect(links().map(described)).toEqual([
      'Prehľad /',
      'Import /import [page] [here]',
      'Buildy /builds',
    ]);
  });

  it("in the admin panel: an admin's five sections, an editor's two, and none for anybody else", async () => {
    TestBed.inject(TranslationService).noteAddress('/admin/sources');
    await draw('admin');
    await at('/admin/sources');
    expect(links().map(described)).toEqual([
      'Sources /admin/sources [page] [here]',
      'Translations /admin/translations',
      'Languages /admin/languages',
      'People /admin/people',
      'Migrations /admin/migrations',
    ]);
    roles.set(['EDITOR']);
    await fixture.whenStable();
    expect(links().map(described)).toEqual([
      'Sources /admin/sources [page] [here]',
      'Translations /admin/translations',
    ]);
    roles.set([]);
    await fixture.whenStable();
    expect(host.querySelector('.topbar-nav')).toBeNull();
  });

  it('moves the focus into the menu when it opens, between its items with the arrow keys, and back to its button on Escape', async () => {
    await draw();
    expect(items()).toEqual([]);

    await openMenu();
    expect(accountButton().getAttribute('aria-expanded')).toBe('true');
    expect(document.activeElement).toBe(items()[0]);

    items()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    expect(document.activeElement).toBe(items()[1]);
    items()[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }));
    expect(document.activeElement).toBe(items().at(-1));
    items()
      .at(-1)!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    expect(document.activeElement).toBe(items()[0]);

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await fixture.whenStable();
    expect(items()).toEqual([]);
    expect(document.activeElement).toBe(accountButton());
  });

  /**
   * Tab (and Shift+Tab) from the first, a middle and the last item of the open menu: the menu closes, the
   * browser's own move is stopped (it would land on the next item of a menu about to go, then on <body>),
   * and the focus is on the button that opened it - where the next Tab moves on from. (mat-menu fails the
   * first of these: it closes and refocuses, but leaves the Tab to the browser.)
   */
  async function expectTabOut(button: () => HTMLButtonElement): Promise<void> {
    const count = items().length;
    for (const [which, shift] of [
      [0, false],
      [1, false],
      [count - 1, false],
      [1, true],
    ] as const) {
      if (items().length === 0) {
        button().click();
        await fixture.whenStable();
      }
      expect(items().length).toBe(count);
      items()[which].focus();
      const tab = new KeyboardEvent('keydown', {
        key: 'Tab',
        shiftKey: shift,
        bubbles: true,
        cancelable: true,
      });
      items()[which].dispatchEvent(tab);
      expect(tab.defaultPrevented).toBe(true);
      expect(document.activeElement).toBe(button());
      await fixture.whenStable();
      expect(items()).toEqual([]);
      expect(button().getAttribute('aria-expanded')).toBe('false');
      expect(document.activeElement).toBe(button());
    }
  }

  it('closes on Tab and Shift+Tab with the focus on its button, from any item - never on <body>', async () => {
    await draw();
    await openMenu();
    expect(items().length).toBe(3);
    await expectTabOut(accountButton);
  });

  it('closes on a click outside it - a click inside it is no reason to', async () => {
    await draw();
    await openMenu();
    host.querySelector<HTMLElement>('.menu-head')!.click();
    await fixture.whenStable();
    expect(items().length).toBeGreaterThan(1);

    document.body.click();
    await fixture.whenStable();
    expect(items()).toEqual([]);
    expect(accountButton().getAttribute('aria-expanded')).toBe('false');
  });

  it("reads in the person's language: the brand, the menu, the role, the way into the admin panel", async () => {
    await draw();
    expect(host.querySelector('app-brand a')?.getAttribute('aria-label')).toBe(
      'anotoki Build Analyzer: úvodná stránka',
    );
    expect(accountButton().getAttribute('aria-label')).toBe('Tvoja ponuka: mona');
    expect(words(host.querySelector('.user-name'))).toBe('mona');

    await openMenu();
    expect(host.querySelector('[role="menu"]')?.getAttribute('aria-label')).toBe('Tvoja ponuka');
    expect(parts('.menu-head > span')).toEqual(['mona', 'mona@example.test', 'Správca']);
    expect(items().map(words)).toEqual(['Tvoj anotoki účet', 'Administrátorský panel', 'Odhlásiť sa']);
    expect(items()[0].getAttribute('href')).toBe('https://iam.example.test/account');
    expect(items()[1].getAttribute('href')).toBe('/admin');

    roles.set(['EDITOR']);
    await fixture.whenStable();
    expect(words(host.querySelector('.menu-role'))).toBe('Editor');

    roles.set([]);
    await fixture.whenStable();
    expect(host.querySelector('.menu-role')).toBeNull();
    expect(items().map(words)).toEqual(['Tvoj anotoki účet', 'Odhlásiť sa']);
  });

  it('names an account with neither a user name nor an email by its number, worded', async () => {
    user.set({ sub: '42', email: null, preferred_username: null });
    await draw();
    expect(words(host.querySelector('.user-name'))).toBe('č. 42');
    expect(accountButton().getAttribute('aria-label')).toBe('Tvoja ponuka: č. 42');
  });

  it('reads in English in the admin panel, whatever the person reads - with no case of its own, and no language chooser', async () => {
    TestBed.inject(TranslationService).noteAddress('/admin/sources');
    await draw('admin');
    expect(host.querySelector('app-brand a')?.getAttribute('aria-label')).toBe(
      'anotoki Build Analyzer: the admin panel',
    );
    // As the IAM's: "anotoki admin panel" beside the mark, the app's name in the link's accessible name.
    expect(parts('app-brand .wordmark > span')).toEqual(['anotoki', 'admin panel']);
    expect(host.querySelector('app-brand a')?.getAttribute('href')).toBe('/admin');
    expect(accountButton().getAttribute('aria-label')).toBe('Your menu: mona');
    expect(host.querySelector('app-language-switcher')).toBeNull();

    await openMenu();
    expect(words(host.querySelector('.menu-role'))).toBe('Administrator');
    expect(items().map(words)).toEqual([
      'Your anotoki account',
      'Back to Build Analyzer',
      'Sign out',
    ]);
    expect(items()[1].getAttribute('href')).toBe('/');
  });

  it('follows a change of language without being drawn again', async () => {
    await draw();
    expect(accountButton().getAttribute('aria-label')).toBe('Tvoja ponuka: mona');
    await TestBed.inject(TranslationService).setLanguage('en', { remember: false });
    await fixture.whenStable();
    expect(accountButton().getAttribute('aria-label')).toBe('Your menu: mona');
    expect(links().map(words)).toEqual(['Dashboard', 'Import', 'Builds']);
  });

  it('offers no language where there is no choice to make', async () => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations(ENGLISH, {
          languages: [{ code: 'en', name: 'English', native_name: 'English' }],
        }),
        provideRouter([{ path: '**', component: TestPage }]),
        { provide: AuthService, useValue: auth },
        { provide: SyncDriver, useValue: sync },
        { provide: MatSnackBar, useValue: snackBar },
        { provide: MatDialog, useValue: dialog },
      ],
    });
    await draw();
    expect(childrenOf('.topbar-actions')).toEqual(['app-theme-toggle', 'div.user-menu']);
  });

  it('shows the sync this tab drives, just left of the language - for an editor or an admin, and nobody else', async () => {
    sync.driving.set(true);
    await draw();
    // (A tooltip puts its own class first.)
    expect(childrenOf('.topbar-actions').slice(1)).toEqual([
      'app-language-switcher',
      'app-theme-toggle',
      'div.user-menu',
    ]);
    expect(host.querySelector('.topbar-actions')!.children[0].classList).toContain('topbar-widget');
    const widget = host.querySelector('.topbar-widget')!;
    expect(widget.getAttribute('href')).toBe('/admin/sources');
    expect(widget.getAttribute('aria-label')).toBe('Synchronizujú sa buildy');

    roles.set([]);
    await fixture.whenStable();
    expect(host.querySelector('.topbar-widget')).toBeNull();
  });

  describe('signed out', () => {
    beforeEach(() => {
      user.set(null);
      roles.set([]);
    });

    it("has the button to sign in, which opens the IAM in the person's language", async () => {
      await draw();
      const button = host.querySelector<HTMLButtonElement>('.sign-in')!;
      expect(words(button)).toBe('Prihlásiť sa');
      button.click();
      await fixture.whenStable();
      expect(auth.login).toHaveBeenCalledWith(undefined, { uiLocales: 'sk' });
      expect(snackBar.open).not.toHaveBeenCalled();
    });

    it("says why when the sign-in cannot start - the browser refuses the site's storage", async () => {
      auth.login.mockImplementation(async () => {
        lastError.set({ key: 'signIn.storageUnavailable', params: {} });
        return false;
      });
      await draw();
      host.querySelector<HTMLButtonElement>('.sign-in')!.click();
      await fixture.whenStable();
      expect(snackBar.open).toHaveBeenCalledWith(
        'Tento prehliadač nedovolí stránke zapamätať si prihlásenie.',
        'Zavrieť',
        { duration: 10000 },
      );
    });

    it('has no Sign in while there is no IAM to go to', async () => {
      auth.available.set(false);
      await draw();
      expect(host.querySelector('.sign-in')).toBeNull();
      auth.available.set(true);
    });
  });

  describe('on a phone', () => {
    it("is the brand (the mark and the app's short name), the language, the theme and a menu button - nothing else in the row", async () => {
      windowOf(390);
      await draw();
      expect(parts('app-brand .wordmark > span')).toEqual(['Analyzer']);
      expect(host.querySelector('.topbar-nav')).toBeNull();
      expect(host.querySelector('.user-button')).toBeNull();
      expect(childrenOf('.topbar-actions')).toEqual([
        'app-language-switcher',
        'app-theme-toggle',
        'div.phone-menu',
      ]);
      expect(phoneButton().getAttribute('aria-label')).toBe('Ponuka');
      expect(phoneButton().getAttribute('aria-haspopup')).toBe('menu');
      expect(phoneButton().getAttribute('aria-expanded')).toBe('false');
    });

    it("holds the links and the person's menu: focus in, arrow keys, Escape back to its button", async () => {
      windowOf(360);
      await draw();
      await at('/');
      await openMenu();
      expect(phoneButton().getAttribute('aria-expanded')).toBe('true');
      expect(host.querySelector('#topbar-menu')?.getAttribute('aria-label')).toBe('Ponuka');
      expect(items().map(words)).toEqual([
        'Prehľad',
        'Import',
        'Buildy',
        'Tvoj anotoki účet',
        'Administrátorský panel',
        'Odhlásiť sa',
      ]);
      expect(items()[0].getAttribute('aria-current')).toBe('page');
      expect(parts('#topbar-menu .menu-head > span')).toEqual([
        'mona',
        'mona@example.test',
        'Správca',
      ]);
      expect(document.activeElement).toBe(items()[0]);

      items()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
      expect(document.activeElement).toBe(items().at(-1));
      items()
        .at(-1)!
        .dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
      expect(document.activeElement).toBe(items()[0]);

      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
      await fixture.whenStable();
      expect(items()).toEqual([]);
      expect(document.activeElement).toBe(phoneButton());
    });

    it('closes when a link is chosen, on a click outside, and on Tab out of it', async () => {
      TestBed.inject(TranslationService).noteAddress('/admin/sources');
      windowOf(390);
      await draw('admin');
      await openMenu();
      expect(items().map(words)).toEqual([
        'Sources',
        'Translations',
        'Languages',
        'People',
        'Migrations',
        'Your anotoki account',
        'Back to Build Analyzer',
        'Sign out',
      ]);
      items()[1].click();
      await fixture.whenStable();
      expect(items()).toEqual([]);
      expect(TestBed.inject(Router).url).toBe('/admin/translations');

      await openMenu();
      document.body.click();
      await fixture.whenStable();
      expect(items()).toEqual([]);

      // Tab from the first, a middle and the last item (and Shift+Tab): closed, the focus on the menu button.
      await openMenu();
      await expectTabOut(phoneButton);
    });

    it('names the sync this tab drives in its menu, with a dot on the button', async () => {
      windowOf(390);
      sync.driving.set(true);
      await draw();
      expect(host.querySelector('.topbar-widget')).toBeNull();
      expect(host.querySelector('.menu-badge')).not.toBeNull();
      await openMenu();
      expect(items().map(words)).toContain('Synchronizujú sa buildy');
    });

    it('signed out: Sign in is in the menu, after the links', async () => {
      user.set(null);
      roles.set([]);
      windowOf(360);
      await draw();
      expect(host.querySelector('.sign-in')).toBeNull();
      await openMenu();
      expect(items().map(words)).toEqual(['Prehľad', 'Import', 'Buildy', 'Prihlásiť sa']);
      items()[3].click();
      await fixture.whenStable();
      expect(auth.login).toHaveBeenCalledWith(undefined, { uiLocales: 'sk' });
      expect(items()).toEqual([]);
    });

    it(`is a desktop's bar from ${PHONE_BAR_MAX + 1} px: the links and the person in the row again`, async () => {
      windowOf(PHONE_BAR_MAX + 1);
      await draw();
      expect(host.querySelector('.menu-button')).toBeNull();
      expect(links().map(words)).toEqual(['Prehľad', 'Import', 'Buildy']);
      expect(accountButton()).not.toBeNull();
      expect(parts('app-brand .wordmark > span')).toEqual(['anotoki', 'Build Analyzer']);
    });

    it(`is a phone's bar in the admin panel up to ${ADMIN_PHONE_BAR_MAX} px - its row is longer - and a desktop's from ${ADMIN_PHONE_BAR_MAX + 1} px`, async () => {
      TestBed.inject(TranslationService).noteAddress('/admin/sources');
      windowOf(ADMIN_PHONE_BAR_MAX);
      await draw('admin');
      expect(host.querySelector('.menu-button')).not.toBeNull();
      expect(host.querySelector('.topbar-nav')).toBeNull();
      expect(parts('app-brand .wordmark > span')).toEqual(['Analyzer']);
      await openMenu();
      expect(items().map(words)).toEqual(['Sources', 'Translations', 'Languages', 'People', 'Migrations', 'Your anotoki account', 'Back to Build Analyzer', 'Sign out']);
      fixture.destroy();

      windowOf(ADMIN_PHONE_BAR_MAX + 1);
      await draw('admin');
      expect(host.querySelector('.menu-button')).toBeNull();
      expect(links().map(words)).toEqual(['Sources', 'Translations', 'Languages', 'People', 'Migrations']);
      expect(host.querySelector('.topbar')?.classList.contains('is-nameless')).toBe(true);
      fixture.destroy();

      windowOf(ADMIN_PHONE_BAR_MAX + 201);
      await draw('admin');
      expect(host.querySelector('.topbar')?.classList.contains('is-nameless')).toBe(false);
    });

    it("is a desktop's bar in the admin panel on a 1024 px screen (a small laptop, a tablet held sideways), with the person's name at 1280 px", async () => {
      TestBed.inject(TranslationService).noteAddress('/admin/sources');
      windowOf(1024);
      await draw('admin');
      expect(host.querySelector('.menu-button')).toBeNull();
      expect(links().map(words)).toEqual([
        'Sources',
        'Translations',
        'Languages',
        'People',
        'Migrations',
      ]);
      fixture.destroy();

      windowOf(1280);
      await draw('admin');
      expect(host.querySelector('.topbar')?.classList.contains('is-nameless')).toBe(false);
      expect(words(host.querySelector('.user-name'))).toBe('mona');
    });

    it('closes what is open when the window crosses the breakpoint (a turned tablet)', async () => {
      windowOf(1280);
      await draw();
      await openMenu();
      expect(items().length).toBe(3);
      media.change(true);
      await fixture.whenStable();
      expect(items()).toEqual([]);
      expect(host.querySelector('.menu-button')).not.toBeNull();
    });
  });

  describe('signing out', () => {
    const chooseSignOut = async (label: string) => {
      await openMenu();
      items()
        .find((item) => words(item) === label)!
        .click();
      // The drafts are the admin pages' (their own entry point, loaded on the way when the tab holds some).
      for (let i = 0; i < 5; i++) {
        await new Promise((done) => setTimeout(done));
        await fixture.whenStable();
      }
    };

    it('signs out at once when nothing waits', async () => {
      await draw();
      await chooseSignOut('Odhlásiť sa');
      expect(dialog.open).not.toHaveBeenCalled();
      expect(auth.logout).toHaveBeenCalledTimes(1);
    });

    it("asks first over strings typed and not saved - in the person's language - and stays, with them kept, when the answer is to stay", async () => {
      await draw();
      const drafts = TestBed.inject(LocalizationDrafts);
      drafts.strings.set({ 'home.title': { sk: 'Každá postava' } });
      await fixture.whenStable();
      expect(sessionStorage.getItem('build-analyzer-localization-drafts')).not.toBeNull();
      await chooseSignOut('Odhlásiť sa');

      expect(dialog.open.mock.calls[0][1].data).toEqual({
        title: 'Odhlásiť sa bez posledných zmien?',
        message:
          'Niektoré zmeny v textoch alebo jazykoch stránky nie sú uložené - nájdeš ich na stránkach Translations a Languages. Po odhlásení sa stratia.',
        confirmLabel: 'Aj tak sa odhlásiť',
        cancelLabel: 'Zostať',
        danger: true,
      });
      expect(auth.logout).not.toHaveBeenCalled();
      expect(drafts.count()).toBe(1);
      // The item can be chosen again.
      await openMenu();
      expect(
        items()
          .find((item) => words(item) === 'Odhlásiť sa')!
          .hasAttribute('disabled'),
      ).toBe(false);
    });

    it('gives the drafts up before it signs out when the answer is to go', async () => {
      TestBed.inject(TranslationService).noteAddress('/admin/translations');
      answer = true;
      await draw('admin');
      const drafts = TestBed.inject(LocalizationDrafts);
      drafts.languages.set({ cs: { name: 'Czech', native_name: 'Čeština', sort_order: '3' } });
      await fixture.whenStable();
      let draftsAtSignOut = -1;
      auth.logout.mockImplementationOnce(async () => {
        draftsAtSignOut = drafts.count();
      });
      await chooseSignOut('Sign out');

      expect(dialog.open.mock.calls[0][1].data.title).toBe('Sign out without your latest changes?');
      expect(auth.logout).toHaveBeenCalledTimes(1);
      expect(draftsAtSignOut).toBe(0);
      expect(sessionStorage.getItem('build-analyzer-localization-drafts')).toBeNull();
    });

    it("does not ask over somebody else's drafts left in the tab: they are not this person's to give up", async () => {
      sessionStorage.setItem(
        'build-analyzer-localization-drafts',
        JSON.stringify({ owner: '99', strings: { 'home.title': { sk: 'Cudzie' } }, languages: {} }),
      );
      await draw();
      await chooseSignOut('Odhlásiť sa');
      expect(dialog.open).not.toHaveBeenCalled();
      expect(auth.logout).toHaveBeenCalledTimes(1);
    });
  });
});
