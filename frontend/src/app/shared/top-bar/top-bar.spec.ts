import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { provideAnotokiTranslations } from '@anotoki/lib/translations';
import { LocalizationDrafts } from '@anotoki/lib/translations/admin';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ConfirmHostComponent, ConfirmService } from '@anotoki/lib/ui/dialog';
import { AuthService } from '../auth';
import { BuildEditorChanges } from '../build-editor-changes';
import { en } from '../i18n/en';
import { TranslationService } from '../i18n/translation';
import { LANGUAGE_STORAGE } from '../i18n/translations.config';
import { provideKit } from '../kit';
import { provideShell } from '../shell';
import { ADMIN_PHONE_BAR_MAX, EDITOR_PHONE_BAR_MAX, PHONE_BAR_MAX, TopBar } from './top-bar';

const ENGLISH = {
  'brand.area': 'Build Analyzer',
  'brand.short': 'Analyzer',
  'brand.admin': en['brand.admin'],
  'topbar.home': 'anotoki Build Analyzer: the start page',
  'topbar.homeAdmin': en['topbar.homeAdmin'],
  'topbar.sections': "The site's sections",
  'topbar.dashboard': 'Dashboard',
  'topbar.import': 'Import',
  'topbar.builds': 'Builds',
  'topbar.backToApp': 'Back to Build Analyzer',
  'topbar.signOutUnsaved.title': 'Sign out without your latest changes?',
  'topbar.signOutUnsaved.message': "Some changes to the site's strings or languages are not saved - the Translations and Languages pages show them. Signed out, they are lost.",
  'topbar.signOutUnsaved.confirm': 'Sign out anyway',
  'topbar.signOutUnsaved.stay': 'Stay',
  'common.userFallback': 'user {sub}',
};
/** Slovak - with the site's own wording of the family's words, as migration 010 keeps them under the library's keys. */
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
  'topbar.backToApp': 'Späť na Build Analyzer',
  'topbar.signOutUnsaved.title': 'Odhlásiť sa bez posledných zmien?',
  'topbar.signOutUnsaved.message': 'Niektoré zmeny v textoch alebo jazykoch stránky nie sú uložené - nájdeš ich na stránkach Translations a Languages. Po odhlásení sa stratia.',
  'topbar.signOutUnsaved.confirm': 'Aj tak sa odhlásiť',
  'topbar.signOutUnsaved.stay': 'Zostať',
  'common.userFallback': 'č. {sub}',
  'anotoki.topbar.roleAdmin': 'Správca',
};

/** Whatever page the router draws: the bar only needs it to be somewhere. */
@Component({ selector: 'app-test-page', template: '' })
class TestPage {}

/** The bar with the kit's "Are you sure?" beside it, as the app's root has it. */
@Component({
  selector: 'app-test-frame',
  imports: [TopBar, ConfirmHostComponent],
  template: '<app-top-bar [area]="area()" /><anotoki-confirm-host />',
})
class TestFrame {
  readonly area = signal<'app' | 'admin'>('app');
}

describe("the top bar: the family's bar, told what is this site's", () => {
  const user = signal<{
    sub: string;
    email: string | null;
    preferred_username: string | null;
  } | null>(null);
  const roles = signal<string[]>([]);
  const failure = signal<{ code: string; returnTo: string | null } | null>(null);
  const auth = {
    user,
    available: signal(true),
    signedIn: () => user() !== null,
    displayName: () => user()?.preferred_username ?? user()?.email ?? null,
    preferences: () => null,
    savePreferences: vi.fn(async () => ({})),
    isAdmin: () => roles().includes('ADMIN'),
    isStaff: () => roles().includes('ADMIN') || roles().includes('EDITOR'),
    accountUrl: signal<string | null>('https://iam.example.test/account'),
    failure,
    login: vi.fn(async () => true),
    logout: vi.fn(async () => undefined),
  };
  /** The feedback nobody has opened yet, as the anotoki family's FeedbackUnread holds it. */
  const unread = { count: signal<number | null>(null), refresh: vi.fn(async () => undefined) };

  let fixture: ComponentFixture<TestFrame>;
  let host: HTMLElement;

  const words = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const personButton = () => host.querySelector<HTMLButtonElement>('.anotoki-top-bar__person-button');
  const phoneButton = () => host.querySelector<HTMLButtonElement>('.anotoki-top-bar__menu-button');
  /** The items of the open menu (the kit's: anotoki-menu, its items role="menuitem"). */
  const items = () => Array.from(document.querySelectorAll<HTMLElement>('anotoki-menu [role="menuitem"]'));
  const links = () => Array.from(host.querySelectorAll<HTMLAnchorElement>('.anotoki-top-bar__nav a'));
  /** A link as "words href", with " [page]" for aria-current and " [here]" for the section the page is in. */
  const described = (link: HTMLAnchorElement) =>
    `${words(link)} ${link.getAttribute('href')}${link.getAttribute('aria-current') === 'page' ? ' [page]' : ''}${link.classList.contains('anotoki-top-bar__link--active') ? ' [here]' : ''}`;
  /** The pieces of the bar's actions, in order: each as its first class, or its tag. */
  const actions = () => Array.from(host.querySelector('.anotoki-top-bar__actions')!.children).map((child) => (child.classList.length ? String(child.classList[0]) : child.tagName.toLowerCase()));

  async function draw(area: 'app' | 'admin' = 'app'): Promise<void> {
    fixture = TestBed.createComponent(TestFrame);
    fixture.componentInstance.area.set(area);
    host = fixture.nativeElement;
    document.body.appendChild(host);
    await fixture.whenStable();
  }

  async function at(url: string): Promise<void> {
    await TestBed.inject(Router).navigateByUrl(url);
    await fixture.whenStable();
  }

  /** Opens the person's menu (the phone's on a phone) - unless it is open already: its button would close it. */
  async function openMenu(): Promise<void> {
    const button = (personButton() ?? phoneButton())!;
    if (button.getAttribute('aria-expanded') !== 'true') {
      button.click();
      await fixture.whenStable();
    }
  }

  /** A window `width` wide, as the bar's media queries see it: each (max-width: N px) answers for this width. */
  function windowOf(width: number): void {
    vi.stubGlobal('matchMedia', (query: string) => ({
      matches: width <= Number(/max-width:\s*(\d+)px/.exec(query)?.[1] ?? Infinity),
      media: query,
      addEventListener: () => undefined,
      removeEventListener: () => undefined,
      addListener: () => undefined,
      removeListener: () => undefined,
    }));
  }

  beforeEach(() => {
    sessionStorage.clear();
    user.set({ sub: '7', email: 'mona@example.test', preferred_username: 'mona' });
    roles.set(['ADMIN']);
    failure.set(null);
    unread.count.set(null);
    auth.login.mockReset().mockResolvedValue(true);
    auth.logout.mockClear();
    windowOf(1280);
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        // The site's names in storage, and whose the drafts of the admin pages are (the library's LocalizationDrafts).
        provideAnotokiTranslations(() => ({
          storageKeys: LANGUAGE_STORAGE,
          account: { language: () => null, userKey: () => user()?.sub ?? null },
        })),
        provideKit(),
        provideShell(),
        provideRouter([{ path: '**', component: TestPage }]),
        { provide: AuthService, useValue: auth },
        { provide: FeedbackUnread, useValue: unread },
      ],
    });
  });

  afterEach(() => {
    fixture?.destroy();
    host?.remove();
    sessionStorage.clear();
    vi.unstubAllGlobals();
  });

  it("is the family's one row: the brand, the site's sections, then the language, the theme and the person", async () => {
    await draw();
    expect(host.querySelector('app-top-bar anotoki-top-bar')).not.toBeNull();
    expect(words(host.querySelector('.anotoki-brand__wordmark'))).toBe('anotoki Build Analyzer');
    expect(host.querySelector('anotoki-brand a')?.getAttribute('aria-label')).toBe('anotoki Build Analyzer: úvodná stránka');
    expect(host.querySelector('.anotoki-top-bar__nav')?.getAttribute('aria-label')).toBe('Časti stránky');
    expect(actions()).toEqual(['anotoki-language-switcher', 'anotoki-theme-toggle', 'anotoki-top-bar__person-button', 'anotoki-menu']);
    // The person's button names them in the kit's Slovak words.
    expect(personButton()?.getAttribute('aria-label')).toBe('Tvoja ponuka: mona');
  });

  it("links the sections everybody has, and marks the one the page is in - an account is the dashboard's, a game's builds the builds'", async () => {
    await draw();
    await at('/');
    expect(links().map(described)).toEqual(['Prehľad / [page] [here]', 'Import /import', 'Buildy /builds']);
    await at('/accounts/3/characters/hutao?tab=1');
    expect(links().map(described)).toEqual(['Prehľad / [here]', 'Import /import', 'Buildy /builds']);
    await at('/builds/genshin');
    expect(links().map(described)).toEqual(['Prehľad /', 'Import /import', 'Buildy /builds [here]']);
  });

  it('names the person, their address and role, and holds their anotoki account and the way into the admin panel', async () => {
    await draw();
    await openMenu();
    expect(words(document.querySelector('.anotoki-top-bar__menu-name'))).toBe('mona');
    expect(words(document.querySelector('.anotoki-top-bar__menu-email'))).toBe('mona@example.test');
    // The site's own wording of the role (migration 010: under the library's key).
    expect(words(document.querySelector('.anotoki-top-bar__menu-role'))).toBe('Správca');
    expect(items().map(words)).toEqual(['Tvoj anotoki účet', 'Administrátorský panel', 'Odhlásiť sa']);
    expect(items()[0].getAttribute('href')).toBe('https://iam.example.test/account');
    expect(items()[0].getAttribute('target')).toBe('_blank');
    expect(items()[1].getAttribute('href')).toBe('/admin');

    roles.set(['EDITOR']);
    await fixture.whenStable();
    await openMenu();
    expect(words(document.querySelector('.anotoki-top-bar__menu-role'))).toBe('Editor');

    roles.set([]);
    await fixture.whenStable();
    await openMenu();
    expect(document.querySelector('.anotoki-top-bar__menu-role')).toBeNull();
    expect(items().map(words)).toEqual(['Tvoj anotoki účet', 'Odhlásiť sa']);
  });

  it('names an account with neither a user name nor an email by its number, worded', async () => {
    user.set({ sub: '42', email: null, preferred_username: null });
    await draw();
    expect(personButton()?.getAttribute('aria-label')).toBe('Tvoja ponuka: č. 42');
  });

  it('reads English in the admin panel, whatever the person reads - the family\'s "anotoki Build Analyzer · admin panel", short there; no language chooser', async () => {
    TestBed.inject(TranslationService).noteAddress('/admin/builds');
    windowOf(ADMIN_PHONE_BAR_MAX + 201);
    await draw('admin');
    await at('/admin/builds');
    // Short at every width: the area and the part of the site under it, the whole name in the link's name.
    expect(words(host.querySelector('.anotoki-brand__wordmark'))).toBe('Analyzer · admin panel');
    expect(host.querySelector('anotoki-brand a')?.getAttribute('href')).toBe('/admin');
    expect(host.querySelector('anotoki-brand a')?.getAttribute('aria-label')).toBe('anotoki Build Analyzer: the admin panel');
    expect(host.querySelector('anotoki-language-switcher')).toBeNull();
    expect(links().map(described)).toEqual([
      'Builds /admin/builds [page] [here]',
      'Translations /admin/translations',
      'Languages /admin/languages',
      'People /admin/people',
      'Migrations /admin/migrations',
      'Errors /admin/errors',
      'Audit /admin/audit',
      'Feedback /admin/feedback',
      'Backups /admin/backups',
    ]);
    await openMenu();
    expect(items().map(words)).toEqual(['Your anotoki account', 'Back to Build Analyzer', 'Sign out']);
    expect(items()[1].getAttribute('href')).toBe('/');

    roles.set(['EDITOR']);
    await fixture.whenStable();
    expect(links().map((link) => words(link))).toEqual(['Builds', 'Translations']);
    roles.set([]);
    await fixture.whenStable();
    expect(host.querySelector('.anotoki-top-bar__nav')).toBeNull();
  });

  it('follows a change of language without being drawn again', async () => {
    await draw();
    await TestBed.inject(TranslationService).setLanguage('en', { remember: false });
    await fixture.whenStable();
    expect(personButton()?.getAttribute('aria-label')).toBe('Your menu: mona');
    expect(links().map((link) => words(link))).toEqual(['Dashboard', 'Import', 'Builds']);
  });

  it('carries no widget of its own: the language comes first after the sections, for an editor and in the admin panel too', async () => {
    roles.set(['EDITOR']);
    await draw();
    expect(actions()).toEqual(['anotoki-language-switcher', 'anotoki-theme-toggle', 'anotoki-top-bar__person-button', 'anotoki-menu']);
    fixture.destroy();
    host.remove();

    TestBed.inject(TranslationService).noteAddress('/admin/builds');
    windowOf(ADMIN_PHONE_BAR_MAX + 201);
    roles.set(['ADMIN']);
    await draw('admin');
    expect(actions()).toEqual(['anotoki-theme-toggle', 'anotoki-top-bar__person-button', 'anotoki-menu']);
  });

  describe('signed out', () => {
    beforeEach(() => {
      user.set(null);
      roles.set([]);
    });

    it('has the button to sign in, which opens the IAM', async () => {
      await draw();
      const button = host.querySelector<HTMLButtonElement>('.anotoki-top-bar__sign-in')!;
      expect(words(button)).toBe('Prihlásiť sa');
      button.click();
      await fixture.whenStable();
      expect(auth.login).toHaveBeenCalledWith(undefined);
      expect(TestBed.inject(Router).url).not.toBe('/sign-in');
    });

    it("says why on the failure's screen when the sign-in cannot start - the browser refuses the site's storage", async () => {
      auth.login.mockImplementation(async () => {
        failure.set({ code: 'storage_unavailable', returnTo: '/' });
        return false;
      });
      await draw();
      host.querySelector<HTMLButtonElement>('.anotoki-top-bar__sign-in')!.click();
      await vi.waitFor(async () => {
        await fixture.whenStable();
        expect(TestBed.inject(Router).url).toBe('/sign-in');
      });
    });

    it('has no Sign in while there is no IAM to go to', async () => {
      auth.available.set(false);
      await draw();
      expect(host.querySelector('.anotoki-top-bar__sign-in')).toBeNull();
      auth.available.set(true);
    });
  });

  describe('on a phone', () => {
    it("is the brand (the mark and the app's short name), the language, the theme and a menu button", async () => {
      windowOf(390);
      await draw();
      expect(words(host.querySelector('.anotoki-brand__wordmark'))).toBe('Analyzer');
      expect(host.querySelector('.anotoki-top-bar__nav')).toBeNull();
      expect(personButton()).toBeNull();
      expect(phoneButton()?.getAttribute('aria-label')).toBe('Ponuka');
    });

    it("holds the links and the person's menu", async () => {
      windowOf(360);
      await draw();
      await at('/');
      await openMenu();
      expect(items().map(words)).toEqual(['Prehľad', 'Import', 'Buildy', 'Tvoj anotoki účet', 'Administrátorský panel', 'Odhlásiť sa']);
      expect(items()[0].getAttribute('aria-current')).toBe('page');
    });

    it('marks nothing on the menu button of an editor or an admin on the member pages: the menu holds the links and theirs alone', async () => {
      windowOf(390);
      for (const role of ['EDITOR', 'ADMIN']) {
        roles.set([role]);
        await draw();
        expect(host.querySelector('.anotoki-top-bar__mark-dot'), role).toBeNull();
        expect(host.querySelector('.anotoki-top-bar__mark-badge'), role).toBeNull();
        await openMenu();
        expect(items().map(words), role).toEqual(['Prehľad', 'Import', 'Buildy', 'Tvoj anotoki účet', 'Administrátorský panel', 'Odhlásiť sa']);
        fixture.destroy();
        host.remove();
      }
    });

    it('signed out: Sign in is in the menu, after the links', async () => {
      user.set(null);
      roles.set([]);
      windowOf(360);
      await draw();
      await openMenu();
      expect(items().map(words)).toEqual(['Prehľad', 'Import', 'Buildy', 'Prihlásiť sa']);
      items()[3].click();
      await fixture.whenStable();
      expect(auth.login).toHaveBeenCalledWith(undefined);
    });

    it(`folds up to ${PHONE_BAR_MAX} px on the member pages, ${ADMIN_PHONE_BAR_MAX} px for the ADMIN's admin panel and ${EDITOR_PHONE_BAR_MAX} px for an editor's`, async () => {
      for (const [width, area, who, folded] of [
        [PHONE_BAR_MAX, 'app', 'ADMIN', true],
        [PHONE_BAR_MAX + 1, 'app', 'ADMIN', false],
        [ADMIN_PHONE_BAR_MAX, 'admin', 'ADMIN', true],
        [ADMIN_PHONE_BAR_MAX + 1, 'admin', 'ADMIN', false],
        [EDITOR_PHONE_BAR_MAX, 'admin', 'EDITOR', true],
        [EDITOR_PHONE_BAR_MAX + 1, 'admin', 'EDITOR', false],
      ] as const) {
        if (area === 'admin') {
          TestBed.inject(TranslationService).noteAddress('/admin/builds');
        }
        roles.set([who]);
        windowOf(width);
        await draw(area);
        expect(phoneButton() !== null, `${area}, ${who}, ${width} px`).toBe(folded);
        fixture.destroy();
        host.remove();
      }
    });

    it('counts the new feedback on the menu button in the admin panel, and beside Feedback in its menu - for the ADMIN alone', async () => {
      TestBed.inject(TranslationService).noteAddress('/admin/builds');
      unread.count.set(4);
      windowOf(390);
      await draw('admin');
      expect(words(host.querySelector('.anotoki-top-bar__mark-badge'))).toBe('4');
      expect(phoneButton()?.getAttribute('aria-label')).toBe('Menu: 4 new feedback');
      await openMenu();
      const item = items().find((one) => one.getAttribute('href') === '/admin/feedback')!;
      // The number seen alone, and heard after the item's name (anotoki-lib 0.6.0: the kit's topbar.linkCount).
      expect(words(item)).toBe('Feedback 4, 4 new');
      fixture.destroy();
      host.remove();

      // An editor has no Feedback, and no count anywhere.
      roles.set(['EDITOR']);
      await draw('admin');
      expect(host.querySelector('.anotoki-top-bar__mark-badge')).toBeNull();
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
      const ask = vi.spyOn(TestBed.inject(ConfirmService), 'ask');
      await draw();
      await chooseSignOut('Odhlásiť sa');
      expect(ask).not.toHaveBeenCalled();
      expect(auth.logout).toHaveBeenCalledTimes(1);
    });

    it("asks first over strings typed and not saved - in the person's language, the kit's question - and stays, with them kept, when the answer is to stay", async () => {
      const ask = vi.spyOn(TestBed.inject(ConfirmService), 'ask').mockResolvedValue(false);
      await draw();
      const drafts = TestBed.inject(LocalizationDrafts);
      drafts.strings.set({ 'home.title': { sk: 'Každá postava' } });
      await fixture.whenStable();
      expect(sessionStorage.getItem('build-analyzer-localization-drafts')).not.toBeNull();
      await chooseSignOut('Odhlásiť sa');

      expect(ask).toHaveBeenCalledWith({
        title: 'Odhlásiť sa bez posledných zmien?',
        message: 'Niektoré zmeny v textoch alebo jazykoch stránky nie sú uložené - nájdeš ich na stránkach Translations a Languages. Po odhlásení sa stratia.',
        confirmLabel: 'Aj tak sa odhlásiť',
        cancelLabel: 'Zostať',
        tone: 'danger',
      });
      expect(auth.logout).not.toHaveBeenCalled();
      expect(drafts.count()).toBe(1);
    });

    it('gives the drafts up before it signs out when the answer is to go - the question drawn by the kit, its no first', async () => {
      TestBed.inject(TranslationService).noteAddress('/admin/translations');
      await draw('admin');
      const drafts = TestBed.inject(LocalizationDrafts);
      drafts.languages.set({ cs: { name: 'Czech', native_name: 'Čeština', sort_order: '3' } });
      await fixture.whenStable();
      let draftsAtSignOut = -1;
      auth.logout.mockImplementationOnce(async () => {
        draftsAtSignOut = drafts.count();
      });
      await chooseSignOut('Sign out');

      // The kit's question: its title, the stay first.
      expect(words(document.querySelector('anotoki-confirm-host .anotoki-dialog__title'))).toBe('Sign out without your latest changes?');
      expect(words(document.querySelector('anotoki-confirm-host [data-answer="no"]'))).toBe('Stay');
      document.querySelector<HTMLButtonElement>('anotoki-confirm-host [data-answer="yes"]')!.click();
      await vi.waitFor(() => expect(auth.logout).toHaveBeenCalledTimes(1));
      expect(draftsAtSignOut).toBe(0);
      expect(sessionStorage.getItem('build-analyzer-localization-drafts')).toBeNull();
    });

    it("asks first while the builds editor holds changes not saved - in the admin panel's English, one question for the strings too - and gives them up when the answer is to go", async () => {
      const ask = vi.spyOn(TestBed.inject(ConfirmService), 'ask').mockResolvedValueOnce(false).mockResolvedValueOnce(true);
      TestBed.inject(TranslationService).noteAddress('/admin/builds/genshin/hutao');
      await draw('admin');
      const changes = TestBed.inject(BuildEditorChanges);
      changes.unsaved.set(true);
      TestBed.inject(LocalizationDrafts).strings.set({ 'home.title': { sk: 'Každá postava' } });
      await fixture.whenStable();

      await chooseSignOut('Sign out');
      expect(ask).toHaveBeenCalledWith({
        title: 'Sign out without saving?',
        message: 'The builds editor holds changes that are not saved, and so do the Translations or Languages pages. Signed out, they are lost.',
        confirmLabel: 'Sign out anyway',
        cancelLabel: 'Stay',
        tone: 'danger',
      });
      expect(auth.logout).not.toHaveBeenCalled();
      expect(changes.unsaved()).toBe(true);

      await chooseSignOut('Sign out');
      expect(ask).toHaveBeenCalledTimes(2);
      expect(auth.logout).toHaveBeenCalledTimes(1);
      // Given up: the editor's page lets the tab go without asking again, and the drafts went with them.
      expect([changes.unsaved(), changes.givenUp(), TestBed.inject(LocalizationDrafts).count()]).toEqual([false, true, 0]);
    });

    it("does not ask over somebody else's drafts left in the tab: they are not this person's to give up", async () => {
      const ask = vi.spyOn(TestBed.inject(ConfirmService), 'ask');
      sessionStorage.setItem('build-analyzer-localization-drafts', JSON.stringify({ owner: '99', strings: { 'home.title': { sk: 'Cudzie' } }, languages: {} }));
      await draw();
      await chooseSignOut('Odhlásiť sa');
      expect(ask).not.toHaveBeenCalled();
      expect(auth.logout).toHaveBeenCalledTimes(1);
    });
  });
});
