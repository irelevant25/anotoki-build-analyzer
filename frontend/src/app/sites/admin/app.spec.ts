import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { AnotokiPreferences } from '@anotoki/lib/auth';
import { SiteStatus } from '@anotoki/lib/migrations';
import { provideAnotokiTranslations } from '@anotoki/lib/translations';
import { LocalizationDrafts } from '@anotoki/lib/translations/admin';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { AuthService } from '../../shared/auth';
import { BuildEditorChanges } from '../../shared/build-editor-changes';
import { Changelog } from '../../shared/changelog';
import { TranslationService } from '../../shared/i18n/translation';
import { LANGUAGE_STORAGE } from '../../shared/i18n/translations.config';
import { provideKit } from '../../shared/kit';
import { provideShell } from '../../shared/shell';
import { AdminApp } from './app';

/** The site's words the frame says - in English, what the admin panel reads - and in Slovak, what the reader chose. */
const ENGLISH = {
  'brand.short': 'Analyzer',
  'brand.admin': 'admin panel',
  'topbar.backToApp': 'Back to Build Analyzer',
  'common.userFallback': 'user {sub}',
  'topbar.signOutUnsaved.title': 'Sign out without your latest changes?',
  'topbar.signOutUnsaved.message': "Some changes to the site's strings or languages are not saved - the Translations and Languages pages show them. Signed out, they are lost.",
  'topbar.signOutUnsaved.confirm': 'Sign out anyway',
  'topbar.signOutUnsaved.stay': 'Stay',
};
const SLOVAK = {
  'brand.short': 'Analyzer',
  'brand.admin': 'administrátorský panel',
  'topbar.backToApp': 'Späť na Build Analyzer',
  'common.userFallback': 'č. {sub}',
  'topbar.signOutUnsaved.title': 'Odhlásiť sa bez posledných zmien?',
  'topbar.signOutUnsaved.message': 'Niektoré zmeny v textoch alebo jazykoch stránky nie sú uložené - nájdeš ich na stránkach Translations a Languages. Po odhlásení sa stratia.',
  'topbar.signOutUnsaved.confirm': 'Aj tak sa odhlásiť',
  'topbar.signOutUnsaved.stay': 'Zostať',
};

@Component({ selector: 'app-test-page', template: '<p class="the-page">the page</p>' })
class TestPage {}

interface Who {
  signedIn?: boolean;
  roles?: string[];
  /** How many feedback messages nobody opened yet (FeedbackUnread), as the server would say. */
  unread?: number | null;
  /** A database update waits (the ADMIN on the Migrations page has the panel then). */
  updateWaits?: boolean;
  /** The page the panel opens on. */
  path?: string;
  /** The account's theme, dates and clock, as an IAM that keeps them sends them. */
  preferences?: AnotokiPreferences | null;
}

/** A window `width` wide, as the frame's media queries see it: each (max-width: N px) answers for this width. */
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

/** jsdom has <dialog> but neither showModal() nor close(): enough of both for the phone's drawer (the kit's). */
function dialogsThatOpen(): void {
  const dialogs = HTMLDialogElement.prototype;
  if (typeof dialogs.showModal !== 'function') {
    dialogs.showModal = function (this: HTMLDialogElement): void {
      this.setAttribute('open', '');
    };
    dialogs.close = function (this: HTMLDialogElement): void {
      if (this.hasAttribute('open')) {
        this.removeAttribute('open');
        this.dispatchEvent(new Event('close'));
      }
    };
  }
}

async function open(who: Who) {
  const roles = who.roles ?? [];
  const signedIn = signal(who.signedIn ?? true);
  const auth = {
    signedIn,
    // The panel is never shown to a visitor: a session that ended there is signed out.
    visiting: signal(false),
    isAdmin: () => signedIn() && roles.includes('ADMIN'),
    isStaff: () => signedIn() && (roles.includes('ADMIN') || roles.includes('EDITOR')),
    available: signal(true),
    failure: signal(null),
    user: () => (signedIn() ? { sub: '7', email: 'mona@example.test', preferred_username: 'mona' } : null),
    displayName: () => (signedIn() ? 'mona' : null),
    preferences: signal<AnotokiPreferences | null>(who.preferences ?? null),
    savePreferences: vi.fn(async (_changes: object) => ({})),
    accountUrl: signal<string | null>(null),
    login: vi.fn(async () => true),
    logout: vi.fn(async () => undefined),
  };
  const count = signal<number | null>(null);
  const unread = {
    count,
    refresh: vi.fn(async () => count.set(who.unread ?? null)),
  };
  const changelog = { current: signal('0.4.0'), open: vi.fn() };
  TestBed.configureTestingModule({
    providers: [
      // The frame is the component under test: the route only gives its outlet a page.
      provideRouter([{ path: '**', component: TestPage }]),
      provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
      // Whose the drafts of the Translations and Languages pages are (the library's LocalizationDrafts), as the app has it.
      provideAnotokiTranslations(() => ({
        storageKeys: LANGUAGE_STORAGE,
        account: { language: () => null, userKey: () => (signedIn() ? '7' : null) },
      })),
      // The kit's words - the frame's and the footer's among them - in the language the page reads.
      provideKit(),
      // The theme's switch is the shell's, as the app wires it (shared/shell.ts): the account's theme.
      provideShell(),
      { provide: AuthService, useValue: auth },
      { provide: FeedbackUnread, useValue: unread },
      { provide: Changelog, useValue: changelog },
      { provide: SiteStatus, useValue: { blocked: signal(who.updateWaits ?? false) } },
    ],
  });
  // The admin panel reads English whatever the reader reads (the title strategy tells the service where it is).
  const path = who.path ?? '/admin/builds';
  TestBed.inject(TranslationService).noteAddress(path);
  const fixture = TestBed.createComponent(AdminApp);
  const host: HTMLElement = fixture.nativeElement;
  await TestBed.inject(Router).navigateByUrl(path);
  await fixture.whenStable();
  const settle = async () => {
    for (let i = 0; i < 5; i++) {
      await new Promise((done) => setTimeout(done));
      await fixture.whenStable();
    }
  };
  const at = async (url: string) => {
    TestBed.inject(TranslationService).noteAddress(url);
    await TestBed.inject(Router).navigateByUrl(url);
    await settle();
  };
  return { fixture, host, auth, unread, changelog, settle, at };
}

const clean = (text: string | null | undefined) => (text ?? '').replace(/\s+/g, ' ').trim();
/** What an element says to a screen reader: its text, without what is hidden from it. */
function heard(element: Element | null | undefined): string {
  const said = (node: Node): string =>
    node.nodeType === Node.TEXT_NODE ? (node.textContent ?? '') : node instanceof Element && node.getAttribute('aria-hidden') === 'true' ? '' : Array.from(node.childNodes, said).join('');
  return element ? clean(said(element)) : '';
}
const frame = (host: HTMLElement) => host.querySelector<HTMLElement>('anotoki-admin-frame');
const column = (host: HTMLElement) => frame(host)!.querySelector<HTMLElement>('header.anotoki-admin-frame__column')!;
/** The parts of the column (or the drawer) in their order, each as it is named. */
function partsOf(root: ParentNode): string[] {
  const parts = root.querySelectorAll(
    'a.anotoki-admin-frame__brand, .anotoki-admin-frame__name, .anotoki-admin-frame__email, a.anotoki-admin-frame__back, button.anotoki-admin-frame__sign-out, [role=radiogroup], nav',
  );
  return Array.from(parts, (part) =>
    part.matches('nav') ? `navigation: ${part.getAttribute('aria-label')}` : part.matches('[role=radiogroup]') ? `theme: ${part.getAttribute('aria-label')}` : heard(part),
  );
}
/** The column's pages as "words href", with " [page]" for the one it marks as the page's. */
const pages = (root: ParentNode) =>
  Array.from(root.querySelectorAll<HTMLAnchorElement>('nav a.anotoki-side-nav__link')).map(
    (link) => `${clean(link.querySelector('.anotoki-side-nav__label')?.textContent)} ${link.getAttribute('href')}${link.getAttribute('aria-current') === 'page' ? ' [page]' : ''}`,
  );
/** The groups' headings, in their order (the first group, the panel's home, has none). */
const headings = (root: ParentNode) => Array.from(root.querySelectorAll('nav .anotoki-side-nav__heading'), (heading) => clean(heading.textContent));
const signOutButton = (host: HTMLElement) => host.querySelector<HTMLButtonElement>('button.anotoki-admin-frame__sign-out')!;

describe("the admin panel: the anotoki family's admin frame, told what is this site's", () => {
  beforeEach(() => {
    sessionStorage.clear();
    windowOf(1280);
  });

  afterEach(() => {
    sessionStorage.clear();
    vi.unstubAllGlobals();
    // The frame keeps the window from scrolling on a computer, and gives it back as it goes.
    document.body.style.overflow = '';
  });

  it('draws the page in the frame for an editor: the column - the panel’s name, who is signed in, Back, Sign out, the theme, the pages - and no top bar; English, whatever the reader reads', async () => {
    const { host, unread } = await open({ roles: ['EDITOR'] });

    expect(frame(host)).not.toBeNull();
    expect(partsOf(column(host))).toEqual(['Analyzer · admin panel', 'mona', 'mona@example.test', 'Back to Build Analyzer', 'Sign out', 'theme: Theme', 'navigation: Admin panel']);
    // The panel's name as its bar said it, to its first page - each side held together, so it breaks after the dot.
    const brand = column(host).querySelector('a.anotoki-admin-frame__brand')!;
    expect(brand.textContent?.trim()).toBe('Analyzer\u00a0· admin\u00a0panel');
    expect(brand.getAttribute('href')).toBe('/admin');
    expect(column(host).querySelector('a.anotoki-admin-frame__back')?.getAttribute('href')).toBe('/');
    // An editor's pages: Builds and Translations.
    expect(pages(column(host))).toEqual(['Builds /admin/builds [page]', 'Translations /admin/translations']);
    expect(headings(column(host))).toEqual(['Localization']);
    // The page in the frame's <main>, in the measure the panel's pages always had; no card, no bar.
    expect(host.querySelector('anotoki-admin-frame main#main .page-width .the-page')).not.toBeNull();
    expect(host.querySelectorAll('main').length).toBe(1);
    expect(host.querySelector('app-top-bar, anotoki-top-bar, anotoki-shell, .no-access')).toBeNull();
    expect([host.getAttribute('lang'), host.classList.contains('admin')]).toEqual(['en', true]);
    // The admin tools are the ADMIN's: nobody asks the server what is new for an editor.
    expect(unread.refresh).not.toHaveBeenCalled();
  });

  it("gives the ADMIN every page, as the bar had them - Languages, People, Migrations and the family's admin tools too - under their headings", async () => {
    const { host } = await open({ roles: ['ADMIN'] });

    expect(pages(column(host))).toEqual([
      'Builds /admin/builds [page]',
      'Translations /admin/translations',
      'Languages /admin/languages',
      'People /admin/people',
      'Migrations /admin/migrations',
      'Errors /admin/errors',
      'Audit /admin/audit',
      'Feedback /admin/feedback',
      'Backups /admin/backups',
    ]);
    expect(headings(column(host))).toEqual(['Localization', 'System']);
  });

  it("marks the page's own: a character's builds editor is the Builds page's", async () => {
    const { host, at } = await open({ roles: ['ADMIN'] });
    const marked = () => pages(column(host)).filter((page) => page.endsWith('[page]'));

    await at('/admin/builds/genshin/hutao');
    expect(marked()).toEqual(['Builds /admin/builds [page]']);
    await at('/admin/feedback');
    expect(marked()).toEqual(['Feedback /admin/feedback [page]']);
  });

  it('shows the ADMIN how much feedback nobody has opened yet, beside Feedback - asked once; seen as a number, heard after its name; nothing when there is none', async () => {
    const { host, unread, fixture } = await open({ roles: ['ADMIN'], unread: 3 });

    expect(unread.refresh).toHaveBeenCalledTimes(1);
    await fixture.whenStable();
    const feedback = () => Array.from(column(host).querySelectorAll<HTMLAnchorElement>('nav a')).find((link) => link.getAttribute('href') === '/admin/feedback')!;
    expect(feedback().querySelector('.anotoki-side-nav__badge')?.textContent?.trim()).toBe('3');
    expect(feedback().querySelector('.anotoki-side-nav__badge')?.getAttribute('aria-hidden')).toBe('true');
    expect(heard(feedback())).toBe('Feedback , 3 new');

    // The Feedback page opened them all.
    unread.count.set(0);
    await fixture.whenStable();
    expect(feedback().querySelector('.anotoki-side-nav__badge')).toBeNull();
    expect(column(host).querySelectorAll('.anotoki-side-nav__badge').length).toBe(0);
  });

  it("has the family's footer in the frame's footer, in English as the panel is: the version, What's new - the site's own -, Feedback, About · Support · Terms · Privacy · Contact and the fan clause", async () => {
    const { host, changelog } = await open({ roles: ['ADMIN'] });

    const footer = frame(host)!.querySelector('anotoki-site-footer')!;
    expect(footer.parentElement?.classList).toContain('anotoki-admin-frame__page-column');
    expect(footer.previousElementSibling?.querySelector('main#main')).not.toBeNull();
    // The version reads "v0.4.0" on screen and "Version 0.4.0" to a screen reader (anotoki-lib 0.7.1): its text holds both.
    expect([...footer.querySelectorAll('.anotoki-site-footer__row > *')].map((part) => clean(part.textContent))).toEqual(['v0.4.0Version 0.4.0', '·', "What's new", '·', 'Feedback']);
    // The links go to everybody's pages, outside the admin panel.
    expect([...footer.querySelectorAll('anotoki-legal-links a')].map((link) => [clean(link.textContent), link.getAttribute('href')])).toEqual([
      ['About', '/about'],
      ['Support', '/support'],
      ['Terms', '/terms'],
      ['Privacy', '/privacy'],
      ['Contact', '/contact'],
    ]);
    expect(clean(footer.querySelector('[data-fan]')?.textContent)).toBe('This site is a fan-made project and is not affiliated with or endorsed by HoYoverse.');
    footer.querySelector<HTMLButtonElement>('[data-whats-new]')!.click();
    expect(changelog.open).toHaveBeenCalledTimes(1);
  });

  it('offers no Feedback where no message can be sent: a session that ended ("You are signed out"), or an update waiting', async () => {
    const feedback = (host: HTMLElement) => host.querySelector('[data-open-feedback]');

    expect(feedback((await open({ signedIn: false })).host)).toBeNull();
    TestBed.resetTestingModule();
    expect(feedback((await open({ roles: ['ADMIN'], updateWaits: true })).host)).toBeNull();
    // Somebody signed in with no role here may still send one.
    TestBed.resetTestingModule();
    expect(feedback((await open({ roles: ['MEMBER'] })).host)).not.toBeNull();
  });

  it("shows the anotoki account's theme - one for every site and every part of each - and saves a choice to it", async () => {
    localStorage.removeItem('build-analyzer-theme');
    const { host, auth, settle } = await open({ roles: ['EDITOR'], preferences: { theme: 'dark', date_format: null, time_format: null } });
    const option = (name: string) => column(host).querySelector<HTMLButtonElement>(`anotoki-theme-toggle [aria-label="${name}"]`)!;
    expect(option('Dark').getAttribute('aria-checked')).toBe('true');

    option('Light').click();
    await settle();
    expect(option('Light').getAttribute('aria-checked')).toBe('true');
    expect(auth.savePreferences.mock.calls).toEqual([[{ theme: 'light' }]]);
    localStorage.removeItem('build-analyzer-theme');
    document.documentElement.removeAttribute('data-theme');
  });

  it('on a phone: a row with the panel’s name and Menu, and in the drawer the person, Back, Sign out, the theme and the pages - in English', async () => {
    windowOf(390);
    dialogsThatOpen();
    const { host, settle } = await open({ roles: ['ADMIN'] });
    const menu = column(host).querySelector<HTMLButtonElement>('button.anotoki-admin-frame__menu')!;
    expect(clean(column(host).querySelector('.anotoki-admin-frame__row a.anotoki-admin-frame__brand')?.textContent)).toBe('Analyzer · admin panel');
    expect(heard(menu)).toBe('Menu');

    menu.click();
    await settle();
    const drawer = document.querySelector<HTMLDialogElement>('dialog')!;
    expect(drawer.open).toBe(true);
    expect(partsOf(drawer)).toEqual(['mona', 'mona@example.test', 'Back to Build Analyzer', 'Sign out', 'theme: Theme', 'navigation: Admin panel']);
    expect(Array.from(drawer.querySelectorAll('nav .anotoki-side-nav__label'), (label) => clean(label.textContent))).toEqual([
      'Builds',
      'Translations',
      'Languages',
      'People',
      'Migrations',
      'Errors',
      'Audit',
      'Feedback',
      'Backups',
    ]);
  });

  describe("Sign out: the site's one (shared/sign-out.ts), the top bar's too", () => {
    it('signs out at once when nothing waits - its button saying "Saving…" until the sign-out is done', async () => {
      const { host, auth, fixture, settle } = await open({ roles: ['EDITOR'] });
      const ask = vi.spyOn(TestBed.inject(ConfirmService), 'ask');
      let done!: () => void;
      auth.logout.mockReturnValueOnce(new Promise<undefined>((resolve) => (done = () => resolve(undefined))));
      signOutButton(host).click();
      await settle();
      expect(clean(signOutButton(host).textContent)).toBe('Saving…');
      expect(signOutButton(host).getAttribute('aria-busy')).toBe('true');
      expect(auth.logout).toHaveBeenCalledTimes(1);

      done();
      await settle();
      await fixture.whenStable();
      expect(ask).not.toHaveBeenCalled();
      expect(clean(signOutButton(host).textContent)).toBe('Sign out');
    });

    it("asks first while the builds editor holds changes not saved - in the admin panel's English - and gives them up when the answer is to go", async () => {
      const { host, auth, settle } = await open({ roles: ['EDITOR'], path: '/admin/builds/genshin/hutao' });
      const ask = vi.spyOn(TestBed.inject(ConfirmService), 'ask').mockResolvedValueOnce(false).mockResolvedValueOnce(true);
      const changes = TestBed.inject(BuildEditorChanges);
      changes.unsaved.set(true);

      signOutButton(host).click();
      await settle();
      expect(ask).toHaveBeenCalledWith({
        title: 'Sign out without saving?',
        message: 'The builds editor holds changes that are not saved. Signed out, they are lost.',
        confirmLabel: 'Sign out anyway',
        cancelLabel: 'Stay',
        tone: 'danger',
      });
      expect(auth.logout).not.toHaveBeenCalled();
      expect(changes.unsaved()).toBe(true);

      signOutButton(host).click();
      await settle();
      expect(ask).toHaveBeenCalledTimes(2);
      expect(auth.logout).toHaveBeenCalledTimes(1);
      // Given up: the editor's page lets the tab go without asking again.
      expect([changes.unsaved(), changes.givenUp()]).toEqual([false, true]);
    });

    it('asks once for both while the builds editor and the Translations or Languages pages hold changes not saved - and the strings go with the builds', async () => {
      const { host, auth, settle } = await open({ roles: ['ADMIN'], path: '/admin/builds/genshin/hutao' });
      const ask = vi.spyOn(TestBed.inject(ConfirmService), 'ask').mockResolvedValue(true);
      TestBed.inject(BuildEditorChanges).unsaved.set(true);
      const drafts = TestBed.inject(LocalizationDrafts);
      drafts.strings.set({ 'home.title': { sk: 'Každá postava' } });
      await settle();
      expect(sessionStorage.getItem('build-analyzer-localization-drafts')).not.toBeNull();

      signOutButton(host).click();
      await settle();
      expect(ask).toHaveBeenCalledTimes(1);
      expect(ask.mock.calls[0][0].message).toBe('The builds editor holds changes that are not saved, and so do the Translations or Languages pages. Signed out, they are lost.');
      expect(auth.logout).toHaveBeenCalledTimes(1);
      expect(drafts.count()).toBe(0);
    });
  });

  it('tells somebody signed in without a role whose the area is, and the way back - a page of its own, without the frame', async () => {
    const { host } = await open({ roles: ['MEMBER'] });

    expect(frame(host)).toBeNull();
    expect(host.querySelector('.the-page')).toBeNull();
    expect(clean(host.querySelector('h1')?.textContent)).toBe('This part is for editors and admins');
    expect(host.querySelector('.no-access a')?.getAttribute('href')).toBe('/');
    // The family's page frame - a skip link, the card in <main>, the footer under it - with no bar and no menu.
    expect(host.querySelector('anotoki-shell main#main .no-access')).not.toBeNull();
    expect(host.querySelector('anotoki-shell anotoki-site-footer')).not.toBeNull();
    expect(host.querySelector('app-top-bar, anotoki-top-bar, anotoki-side-nav')).toBeNull();
  });

  it('says a session that ended is signed out, and signs in again - back to this page; the page comes back in the frame for the editor who returns', async () => {
    const { host, auth, settle } = await open({ roles: ['EDITOR'] });
    expect(host.querySelector('anotoki-admin-frame main .the-page')).not.toBeNull();

    // The roles go with the sign-in, as the token's do: an editor signed out is no editor.
    auth.signedIn.set(false);
    await settle();
    expect(frame(host)).toBeNull();
    expect(host.querySelector('.the-page')).toBeNull();
    expect(clean(host.querySelector('h1')?.textContent)).toBe('You are signed out');
    expect(host.querySelector('app-top-bar, anotoki-top-bar, anotoki-side-nav')).toBeNull();
    expect(TestBed.inject(Router).url).toBe('/admin/builds');
    host.querySelector<HTMLButtonElement>('.no-access button')!.click();
    expect(auth.login).toHaveBeenCalledWith(undefined);

    auth.signedIn.set(true);
    await settle();
    expect(host.querySelector('anotoki-admin-frame main .the-page')).not.toBeNull();
    expect(host.querySelector('.no-access')).toBeNull();
  });
});
