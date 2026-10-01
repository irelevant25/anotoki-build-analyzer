import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { provideRouter } from '@angular/router';
import { App } from './app';
import { AuthMode, AuthService } from './core/auth';
import { Changelog } from './core/changelog';
import { TEST_LANGUAGES, provideTestTranslations } from './core/i18n/testing';
import { TranslationService } from './core/i18n/translation';
import { MetaStore } from './core/meta-store';
import { SiteLanguage } from './core/models';
import { SyncDriver } from './core/sync-driver';

const ENGLISH = {
  'toolbar.menu': 'Settings',
  'theme.label': 'Theme',
  'theme.system': 'System',
  'theme.light': 'Light',
  'theme.dark': 'Dark',
  'language.label': 'Language',
  'language.notLoaded': 'The language could not be loaded. Try again.',
  'nav.translations': 'Translations',
  'nav.languages': 'Languages',
  'nav.dashboard': 'Dashboard',
  'nav.import': 'Import',
  'nav.builds': 'Builds',
  'nav.sources': 'Sources',
  'toolbar.syncing': 'Syncing builds (keep this tab open)',
  'toolbar.syncingLabel': 'Syncing builds',
  'toolbar.version': 'Version {version}, what is new',
  'toolbar.yourAccount': 'Your account',
  'toolbar.account': 'Account',
  'whatsNew.title': "What's new",
  'common.signIn': 'Sign in',
  'common.signOut': 'Sign out',
  'common.userFallback': 'user {sub}',
};
const SLOVAK = {
  'nav.dashboard': 'Prehľad',
  'nav.import': 'Import',
  'nav.builds': 'Buildy',
  'nav.sources': 'Zdroje',
  'toolbar.syncing': 'Synchronizujú sa buildy (nechaj túto kartu otvorenú)',
  'toolbar.syncingLabel': 'Synchronizujú sa buildy',
  'toolbar.version': 'Verzia {version}, čo je nové',
  'toolbar.yourAccount': 'Tvoj účet',
  'toolbar.account': 'Účet',
  'whatsNew.title': 'Čo je nové',
  'common.signIn': 'Prihlásiť sa',
  'common.signOut': 'Odhlásiť sa',
  'common.userFallback': 'č. {sub}',
  'toolbar.menu': 'Nastavenia',
  'theme.label': 'Vzhľad',
  'theme.system': 'Systémový',
  'theme.light': 'Svetlý',
  'theme.dark': 'Tmavý',
  'language.label': 'Jazyk',
  'language.notLoaded': 'Jazyk sa nepodarilo načítať. Skús to znova.',
  'nav.translations': 'Preklady',
  'nav.languages': 'Jazyky',
};

describe('the toolbar’s menu: the theme, the language, the owner’s pages', () => {
  let fixture: ComponentFixture<App>;
  let host: HTMLElement;
  let i18n: TranslationService;
  let snackBar: { open: ReturnType<typeof vi.fn> };
  let auth: {
    mode: ReturnType<typeof signal<AuthMode>>;
    user: ReturnType<typeof signal<{ sub: string; locale: string | null } | null>>;
    usesSignIn: ReturnType<typeof signal<boolean>>;
    isAdmin: ReturnType<typeof signal<boolean>>;
    isMember: ReturnType<typeof signal<boolean>>;
    displayName: ReturnType<typeof signal<string | null>>;
    accountUrl: ReturnType<typeof signal<string | null>>;
    login: ReturnType<typeof vi.fn>;
    logout: ReturnType<typeof vi.fn>;
  };

  /** The menu lives in an overlay, outside the component. */
  const overlay = () => document.querySelector<HTMLElement>('.cdk-overlay-container');
  const trigger = () => host.querySelector<HTMLButtonElement>('button[aria-haspopup="menu"]')!;
  const groups = () => [...(overlay()?.querySelectorAll<HTMLElement>('[role="group"]') ?? [])];
  const group = (label: string) => groups().find((one) => one.getAttribute('aria-label') === label);
  const radios = (within: HTMLElement | undefined) => [...(within?.querySelectorAll<HTMLElement>('[role="menuitemradio"]') ?? [])];
  const label = (item: HTMLElement) => item.querySelector('.mat-mdc-menu-item-text')?.textContent?.trim();
  const links = () => [...(overlay()?.querySelectorAll<HTMLAnchorElement>('a[mat-menu-item]') ?? [])];

  async function setup(options: { admin?: boolean; languages?: SiteLanguage[]; signedIn?: boolean } = {}): Promise<void> {
    auth = {
      mode: signal<AuthMode>('iam'),
      user: signal(options.signedIn === false ? null : { sub: '7', locale: null }),
      usesSignIn: signal(true),
      isAdmin: signal(options.admin ?? false),
      isMember: signal(true),
      displayName: signal<string | null>('mona'),
      accountUrl: signal<string | null>(null),
      login: vi.fn(async () => undefined),
      logout: vi.fn(async () => undefined),
    };
    snackBar = { open: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH, languages: options.languages ?? TEST_LANGUAGES }),
        { provide: AuthService, useValue: auth },
        { provide: MetaStore, useValue: { notSetUp: signal<string | null>(null), loaded: signal(true) } },
        { provide: SyncDriver, useValue: { driving: signal(false) } },
        { provide: Changelog, useValue: { current: signal<string | null>(null), open: vi.fn(), showIfNew: vi.fn() } },
        { provide: MatSnackBar, useValue: snackBar },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(App);
    host = fixture.nativeElement;
    i18n = TestBed.inject(TranslationService);
    await fixture.whenStable();
  }

  async function open(): Promise<void> {
    trigger().click();
    await fixture.whenStable();
  }

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.documentElement.classList.remove('theme-light', 'theme-dark');
  });

  it('is one button, named in the language on the page', async () => {
    await setup();
    expect(host.querySelectorAll('button[aria-label="Nastavenia"]')).toHaveLength(1);
    expect(trigger().getAttribute('aria-label')).toBe('Nastavenia');
    expect(trigger().textContent?.trim()).toBe('contrast');
  });

  it('offers the theme, the chosen one marked', async () => {
    await setup();
    await open();

    const themes = radios(group('Vzhľad'));
    expect(themes.map(label)).toEqual(['Systémový', 'Svetlý', 'Tmavý']);
    expect(themes.map((item) => item.querySelector('mat-icon')?.textContent?.trim())).toEqual(['contrast', 'light_mode', 'dark_mode']);
    expect(themes.map((item) => item.getAttribute('aria-checked'))).toEqual(['true', 'false', 'false']);

    themes[2].click();
    await fixture.whenStable();
    expect(document.documentElement.classList.contains('theme-dark')).toBe(true);
    expect(localStorage.getItem('build-analyzer-theme')).toBe('dark');
    expect(trigger().textContent?.trim()).toBe('dark_mode');

    await open();
    expect(radios(group('Vzhľad')).map((item) => item.getAttribute('aria-checked'))).toEqual(['false', 'false', 'true']);
  });

  it('offers the languages in their own names, each saying which language it is, the one on the page marked', async () => {
    await setup();
    await open();

    const languages = radios(group('Jazyk'));
    expect(languages.map((item) => [label(item), item.getAttribute('lang'), item.getAttribute('aria-checked')])).toEqual([
      ['English', 'en', 'false'],
      ['Slovenčina', 'sk', 'true'],
    ]);
    expect(languages.map((item) => item.querySelector('mat-icon')?.textContent?.trim())).toEqual(['', 'check']);
  });

  it('switches the language of the page, and remembers it on this device', async () => {
    await setup();
    await open();
    radios(group('Jazyk'))[0].click();
    await fixture.whenStable();

    expect(i18n.language()).toBe('en');
    expect(localStorage.getItem('build-analyzer-language')).toBe('en');
    expect(trigger().getAttribute('aria-label')).toBe('Settings');
    expect(snackBar.open).not.toHaveBeenCalled();

    await open();
    expect(radios(group('Language')).map((item) => item.getAttribute('aria-checked'))).toEqual(['true', 'false']);
    expect(radios(group('Theme')).map(label)).toEqual(['System', 'Light', 'Dark']);
  });

  it('says so, and stays as it was, when the language could not be loaded', async () => {
    await setup();
    vi.spyOn(i18n, 'setLanguage').mockResolvedValue(false);
    await open();
    radios(group('Jazyk'))[0].click();
    await fixture.whenStable();

    expect(i18n.language()).toBe('sk');
    expect(snackBar.open).toHaveBeenCalledWith('Jazyk sa nepodarilo načítať. Skús to znova.', undefined, { duration: 6000 });
  });

  it('takes a second choice while the first is on its way - the last one wins, and the one overtaken is not called a failure', async () => {
    await setup();
    let overtaken!: (switched: boolean) => void;
    const slow = new Promise<boolean>((done) => (overtaken = done));
    const setLanguage = vi.spyOn(i18n, 'setLanguage').mockImplementation((code: string) => (code === 'en' ? slow : Promise.resolve(true)));

    await open();
    radios(group('Jazyk'))[0].click();
    await fixture.whenStable();
    await open();
    radios(group('Jazyk'))[1].click();
    await fixture.whenStable();
    expect(setLanguage.mock.calls).toEqual([['en'], ['sk']]);

    // The first choice comes back as the service answers one that was overtaken.
    overtaken(false);
    await fixture.whenStable();
    expect(snackBar.open).not.toHaveBeenCalled();
  });

  it('offers no language where there is no choice to make', async () => {
    await setup({ languages: [TEST_LANGUAGES[1]] });
    await open();

    expect(groups().map((one) => one.getAttribute('aria-label'))).toEqual(['Vzhľad']);
    expect(radios(overlay()!)).toHaveLength(3);
  });

  it('offers no language on the English pages, where the bar reads English too', async () => {
    await setup();
    i18n.noteAddress('/sources');
    await fixture.whenStable();
    expect(trigger().getAttribute('aria-label')).toBe('Settings');

    await open();
    expect(groups().map((one) => one.getAttribute('aria-label'))).toEqual(['Theme']);
    expect(radios(group('Theme')).map(label)).toEqual(['System', 'Light', 'Dark']);
  });

  it('has the owner’s two pages for an admin', async () => {
    await setup({ admin: true });
    await open();

    expect(links().map((link) => [label(link), link.getAttribute('href')])).toEqual([
      ['Preklady', '/translations'],
      ['Jazyky', '/languages'],
    ]);
  });

  it('has them for nobody else', async () => {
    await setup({ admin: false });
    await open();

    expect(links()).toEqual([]);
  });

  it('signs in in the person’s language when it is known', async () => {
    await setup({ signedIn: false });
    // Found by its icon: the button's word is the bar's own, and may be in any language.
    const signIn = [...host.querySelectorAll<HTMLButtonElement>('button')].find((button) => button.querySelector('mat-icon')?.textContent?.trim() === 'login')!;
    signIn.click();

    expect(auth.login).toHaveBeenCalledWith(undefined, { uiLocales: 'sk' });
  });
});

describe('the toolbar’s words', () => {
  let fixture: ComponentFixture<App>;
  let host: HTMLElement;
  let i18n: TranslationService;

  const overlay = () => document.querySelector<HTMLElement>('.cdk-overlay-container');
  const navLinks = () => [...host.querySelectorAll<HTMLAnchorElement>('nav a')];
  const label = (link: HTMLElement) => link.querySelector('.nav-label')?.textContent?.trim();
  const itemText = (item: HTMLElement) => item.querySelector('.mat-mdc-menu-item-text')?.textContent?.trim();

  interface Options {
    admin?: boolean;
    signedIn?: boolean;
    name?: string | null;
    mode?: AuthMode;
    syncing?: boolean;
    version?: string | null;
    accountUrl?: string | null;
  }

  async function setup(options: Options = {}): Promise<void> {
    const mode = options.mode ?? 'iam';
    const auth = {
      mode: signal<AuthMode>(mode),
      user: signal(options.signedIn === false ? null : { sub: '7', locale: null }),
      usesSignIn: signal(mode === 'iam'),
      isAdmin: signal(options.admin ?? false),
      isMember: signal(true),
      displayName: signal<string | null>(options.name === undefined ? 'mona' : options.name),
      accountUrl: signal<string | null>(options.accountUrl ?? null),
      login: vi.fn(async () => undefined),
      logout: vi.fn(async () => undefined),
    };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        { provide: AuthService, useValue: auth },
        { provide: MetaStore, useValue: { notSetUp: signal<string | null>(null), loaded: signal(true) } },
        { provide: SyncDriver, useValue: { driving: signal(options.syncing ?? false) } },
        { provide: Changelog, useValue: { current: signal<string | null>(options.version ?? null), open: vi.fn(), showIfNew: vi.fn() } },
        { provide: MatSnackBar, useValue: { open: vi.fn() } },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(App);
    host = fixture.nativeElement;
    i18n = TestBed.inject(TranslationService);
    await fixture.whenStable();
  }

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    overlay()?.replaceChildren();
  });

  it('names the pages in the reader’s language: a link’s label is a key, worded where it is drawn', async () => {
    await setup({ admin: true });

    expect(navLinks().map((link) => [label(link), link.getAttribute('href')])).toEqual([
      ['Prehľad', '/'],
      ['Import', '/import'],
      ['Buildy', '/builds'],
      ['Zdroje', '/sources'],
    ]);
    // The brand is a name: the same in every language.
    expect(host.querySelector('.brand span')?.textContent).toBe('Build Analyzer');
    expect(host.querySelector('mat-toolbar')?.textContent).not.toMatch(/\b(nav|toolbar|common|whatsNew)\.[A-Za-z]/);
  });

  it('has no Sources link for anybody but an admin', async () => {
    await setup({ admin: false });

    expect(navLinks().map(label)).toEqual(['Prehľad', 'Import', 'Buildy']);
  });

  it('reads English on the English pages, and the reader’s language again after them', async () => {
    await setup({ admin: true, syncing: true, version: '0.2' });
    i18n.noteAddress('/sources');
    await fixture.whenStable();

    expect(navLinks().map(label)).toEqual(['Dashboard', 'Import', 'Builds', 'Sources']);
    expect(host.querySelector('a[href="/sources"][mat-icon-button]')?.getAttribute('aria-label')).toBe('Syncing builds');
    expect(host.querySelector('button.version')?.getAttribute('aria-label')).toBe('Version 0.2, what is new');
    expect(host.querySelector('button.user')?.getAttribute('aria-label')).toBe('Your account');

    i18n.noteAddress('/builds/genshin');
    await fixture.whenStable();
    expect(navLinks().map(label)).toEqual(['Prehľad', 'Import', 'Buildy', 'Zdroje']);
  });

  it('follows the language when it is switched', async () => {
    await setup({ admin: true });
    expect(await i18n.setLanguage('en', false)).toBe(true);
    await fixture.whenStable();

    expect(navLinks().map(label)).toEqual(['Dashboard', 'Import', 'Builds', 'Sources']);
  });

  it('names the sync that is running and the version for a screen reader; the version itself is a number', async () => {
    await setup({ syncing: true, version: '0.2' });

    expect(host.querySelector('a[mat-icon-button]')?.getAttribute('aria-label')).toBe('Synchronizujú sa buildy');
    const version = host.querySelector<HTMLButtonElement>('button.version')!;
    expect(version.getAttribute('aria-label')).toBe('Verzia 0.2, čo je nové');
    expect(version.textContent?.trim()).toBe('v0.2');
  });

  it('shows who is signed in - by name, or for an account without one by its number, worded', async () => {
    await setup();
    expect(host.querySelector('button.user')?.getAttribute('aria-label')).toBe('Tvoj účet');
    expect(host.querySelector('.user-name')?.textContent?.trim()).toBe('mona');

    TestBed.resetTestingModule();
    await setup({ name: null });
    expect(host.querySelector('.user-name')?.textContent?.trim()).toBe('č. 7');
  });

  it('words the account menu', async () => {
    await setup({ accountUrl: '/account-page' });
    host.querySelector<HTMLButtonElement>('button.user')!.click();
    await fixture.whenStable();

    const items = [...(overlay()?.querySelectorAll<HTMLElement>('[mat-menu-item]') ?? [])];
    expect(items.map(itemText)).toEqual(['Účet', 'Odhlásiť sa']);
  });

  it('words the way in for somebody who is not signed in', async () => {
    await setup({ signedIn: false });

    const signIn = [...host.querySelectorAll<HTMLButtonElement>('button')].find((button) => button.querySelector('mat-icon')?.textContent?.trim() === 'login')!;
    expect(signIn.querySelector('.mdc-button__label')?.textContent?.trim()).toBe('Prihlásiť sa');
  });
});
