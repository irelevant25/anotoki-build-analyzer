import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, Routes, provideRouter } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { SiteStatus, siteStatusInterceptor } from '@anotoki/lib/migrations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { App } from './app';
import { AuthService } from './core/auth';
import { Changelog } from './core/changelog';
import { AnotokiUser } from '@anotoki/lib/auth';
import { VisitorMove } from './core/models';
import { provideShell } from './core/shell';
import { provideSiteStatus } from './core/site-status';
import { SyncDriver } from './core/sync-driver';
import { VisitorKey } from './core/visitor-key';
import { AdminLayout } from './layouts/admin-layout/admin-layout';
import { AppLayout } from './layouts/app-layout/app-layout';

const ENGLISH = {
  'brand.area': 'Build Analyzer',
  'common.close': 'Close',
};
const SLOVAK = {
  'brand.area': 'Build Analyzer',
  'common.close': 'Zavrieť',
  'visitor.moved.one': 'Účet importovaný bez prihlásenia je teraz v tvojom anotoki účte ({count}).',
  'visitor.moved.few': 'Účty importované bez prihlásenia sú teraz v tvojom anotoki účte ({count}).',
  'visitor.moved.other': 'Účty importované bez prihlásenia sú teraz v tvojom anotoki účte ({count}).',
  'visitor.kept.one': 'Účet, ktorý už bol v tvojom anotoki účte, zostal, ako bol ({count}).',
  'visitor.kept.few': 'Účty, ktoré už boli v tvojom anotoki účte, zostali, ako boli ({count}).',
  'visitor.kept.other': 'Účty, ktoré už boli v tvojom anotoki účte, zostali, ako boli ({count}).',
};

/** How many times a page was made: a page the gate does not show must not even be made. */
let pagesMade = 0;

@Component({ selector: 'app-test-page', template: '<p class="the-page">the page</p>' })
class TestPage {
  constructor() {
    pagesMade++;
  }
}

/** Who is on the page: nobody, an ordinary member, or the site's ADMIN. */
type Who = 'nobody' | 'member' | 'admin';

/**
 * Where the site stands, as the server says it: ready, a 503's code (and whether it was installed), or the status
 * path's `unavailable` - the database out of reach.
 */
type Standing = { code: 'ready' } | { code: 'update_pending' } | { code: 'unavailable' } | { code: 'not_set_up'; installed: boolean };

/**
 * The page in the site's two real frames, as app.routes.ts nests them: everybody's pages in the AppLayout, the
 * admin panel in the AdminLayout - each with the family's bar and footer.
 */
const FRAMED: Routes = [
  { path: 'admin', component: AdminLayout, children: [{ path: '**', component: TestPage }] },
  { path: '', component: AppLayout, children: [{ path: '**', component: TestPage }] },
];

function person(who: Who): AnotokiUser | null {
  if (who === 'nobody') {
    return null;
  }
  const roles = who === 'admin' ? ['MEMBER', 'ADMIN'] : ['MEMBER'];
  return {
    sub: who === 'admin' ? '1' : '7',
    preferred_username: who,
    email: null,
    email_verified: true,
    locale: null,
    roles,
    preferences: null,
  };
}

describe('the shell', () => {
  let fixture: ComponentFixture<App>;
  let host: HTMLElement;
  let user: ReturnType<typeof signal<AnotokiUser | null>>;
  let login: ReturnType<typeof vi.fn>;
  let visitor: {
    key: ReturnType<typeof signal<string | null>>;
    moved: ReturnType<typeof signal<VisitorMove | null>>;
  };
  let changelog: {
    current: ReturnType<typeof signal<string | null>>;
    open: ReturnType<typeof vi.fn>;
    showIfNew: ReturnType<typeof vi.fn>;
  };

  /** `framed`: the page in the site's real layouts (FRAMED), where it is otherwise the bare page alone. */
  async function setup(
    options: {
      who?: Who;
      key?: string | null;
      site?: Standing;
      path?: string;
      framed?: boolean;
    } = {},
  ): Promise<void> {
    user = signal(person(options.who ?? 'member'));
    login = vi.fn(async () => true);
    visitor = { key: signal(options.key ?? null), moved: signal<VisitorMove | null>(null) };
    changelog = { current: signal<string | null>('0.3'), open: vi.fn(), showIfNew: vi.fn() };
    const roles = () => user()?.roles ?? [];
    TestBed.configureTestingModule({
      providers: [
        provideRouter(options.framed ? FRAMED : [{ path: '**', component: TestPage }]),
        provideHttpClient(withInterceptors([siteStatusInterceptor])),
        provideHttpClientTesting(),
        provideSiteStatus(),
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        provideShell(),
        {
          provide: AuthService,
          useValue: {
            user,
            available: signal(true),
            signedIn: () => user() !== null,
            // Nobody signed in uses the site as a visitor here (the IAM lets visitors in).
            visiting: () => user() === null,
            displayName: () => user()?.preferred_username ?? null,
            isAdmin: computed(() => roles().includes('ADMIN')),
            isStaff: computed(() => roles().includes('ADMIN') || roles().includes('EDITOR')),
            accountUrl: signal(null),
            preferences: () => null,
            savePreferences: vi.fn(async () => ({})),
            failure: signal(null),
            login,
            logout: vi.fn(async () => undefined),
          },
        },
        { provide: Changelog, useValue: changelog },
        { provide: VisitorKey, useValue: visitor },
        // What the layouts' bar asks: the sync this tab drives, and the feedback nobody opened.
        { provide: SyncDriver, useValue: { driving: signal(false) } },
        { provide: FeedbackUnread, useValue: { count: signal(null), refresh: vi.fn() } },
      ],
    });
    // What the start asked of the server (app.config.ts): here told, not asked.
    const site = options.site ?? { code: 'ready' };
    const status = TestBed.inject(SiteStatus);
    if (site.code === 'ready') {
      status.markReady();
    } else {
      status.report(site.code, site.code === 'not_set_up' ? site.installed : undefined);
    }
    fixture = TestBed.createComponent(App);
    host = fixture.nativeElement;
    await TestBed.inject(Router).navigateByUrl(options.path ?? '/');
    await fixture.whenStable();
  }

  /** An element's words: its text nodes, one space between them (the templates leave none between their tags). */
  const words = (element: Element | null | undefined): string | null => {
    if (!element) {
      return null;
    }
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    const parts: string[] = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      parts.push(node.textContent ?? '');
    }
    return parts.join(' ').replace(/\s+/g, ' ').trim();
  };
  const statusPage = () => host.querySelector('anotoki-site-status');
  const buttons = () => [...host.querySelectorAll('anotoki-site-status button')].map(words);
  const links = () => [...host.querySelectorAll('anotoki-site-status a')].map((link) => [words(link), link.getAttribute('href')]);

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    pagesMade = 0;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.documentElement.removeAttribute('data-theme');
  });

  it('draws the router while the site is ready: the layouts bring the bar and the footer', async () => {
    await setup();
    expect(host.querySelector('.the-page')).not.toBeNull();
    expect(host.querySelector('app-top-bar')).toBeNull();
    expect(statusPage()).toBeNull();
  });

  it('an update waits: a visitor reads, in their language, that the site is being updated - no error, no code, only a quiet Sign in', async () => {
    await setup({ who: 'nobody', site: { code: 'update_pending' } });
    expect(host.querySelector('.the-page')).toBeNull();
    expect(words(statusPage()?.querySelector('h1'))).toBe('Stránku práve aktualizujeme');
    expect(words(statusPage())).toBe('Stránku práve aktualizujeme O pár minút bude späť. Táto stránka sa obnoví sama. Prihlásiť sa');
    expect(buttons()).toEqual(['Prihlásiť sa']);
    expect(links()).toEqual([]);
    expect(words(host)).not.toMatch(/update_pending|503|setup|Migrations/i);

    // Sign in: to the IAM, and back to this page (no address given: the one the person is on).
    host.querySelector<HTMLButtonElement>('anotoki-site-status button')!.click();
    await fixture.whenStable();
    expect(login).toHaveBeenCalledWith(undefined);
  });

  it('an update waits: a signed-in member reads the same, with no button at all', async () => {
    await setup({ who: 'member', site: { code: 'update_pending' }, path: '/accounts/3' });
    expect(words(statusPage()?.querySelector('h1'))).toBe('Stránku práve aktualizujeme');
    expect(buttons()).toEqual([]);
    expect(links()).toEqual([]);
    expect(host.querySelector('.the-page')).toBeNull();
  });

  it("an update waits: the site's ADMIN reads that a database update is waiting, and reaches the Migrations page - the one page that shows", async () => {
    await setup({ who: 'admin', site: { code: 'update_pending' } });
    expect(words(statusPage()?.querySelector('h1'))).toBe('A database update is waiting');
    expect(links()).toEqual([['Open Migrations', '/admin/migrations']]);
    expect(host.querySelector('.the-page')).toBeNull();

    await TestBed.inject(Router).navigateByUrl('/admin/migrations');
    await fixture.whenStable();
    expect(host.querySelector('.the-page')).not.toBeNull();
    expect(statusPage()).toBeNull();

    // Any other page: still the gate's.
    await TestBed.inject(Router).navigateByUrl('/admin/sources');
    await fixture.whenStable();
    expect(host.querySelector('.the-page')).toBeNull();
    expect(words(statusPage()?.querySelector('h1'))).toBe('A database update is waiting');
  });

  it('a page the gate does not show is not even made (its requests would all be refused, and leave their errors behind)', async () => {
    await setup({ who: 'member', site: { code: 'update_pending' }, path: '/accounts/3' });
    expect(pagesMade).toBe(0);

    TestBed.resetTestingModule();
    pagesMade = 0;
    await setup({ who: 'admin', site: { code: 'update_pending' }, path: '/admin/sources' });
    expect(pagesMade).toBe(0);
    await TestBed.inject(Router).navigateByUrl('/admin/migrations');
    await fixture.whenStable();
    expect(pagesMade).toBe(1);

    // Applied here: the page that applied it stays as it is (its answer on it), and the site opens around it.
    TestBed.inject(SiteStatus).markReady();
    await fixture.whenStable();
    expect(pagesMade).toBe(1);
    await TestBed.inject(Router).navigateByUrl('/admin/sources');
    await fixture.whenStable();
    expect(host.querySelector('.the-page')).not.toBeNull();
    expect(statusPage()).toBeNull();

    // The gate alone decides (its template, @anotoki/lib 0.1.1): the ADMIN on the Migrations route of a site
    // that is out of reach gets the gate's page, and the Migrations page is not made behind it either.
    TestBed.resetTestingModule();
    pagesMade = 0;
    await setup({
      who: 'admin',
      site: { code: 'not_set_up', installed: true },
      path: '/admin/migrations',
    });
    expect(statusPage()).not.toBeNull();
    expect(host.querySelector('.the-page')).toBeNull();
    expect(pagesMade).toBe(0);
  });

  it('closed while a page was open, the site takes the page away - and, once ready, makes it again where the person was', async () => {
    await setup({ who: 'member', path: '/accounts/3' });
    expect([pagesMade, host.querySelector('.the-page') !== null]).toEqual([1, true]);

    // An answer said an update waits (siteStatusInterceptor): the page goes.
    TestBed.inject(SiteStatus).report('update_pending');
    await fixture.whenStable();
    expect(host.querySelector('.the-page')).toBeNull();
    expect(words(statusPage()?.querySelector('h1'))).toBe('Stránku práve aktualizujeme');

    TestBed.inject(SiteStatus).markReady();
    await fixture.whenStable();
    expect([pagesMade, host.querySelector('.the-page') !== null]).toEqual([2, true]);
    expect(TestBed.inject(Router).url).toBe('/accounts/3');
  });

  it('an ADMIN who was signed out presses Sign in on the waiting page, and back from the IAM has the Migrations page they asked for', async () => {
    await setup({ who: 'nobody', site: { code: 'update_pending' }, path: '/admin/migrations' });
    expect(words(statusPage()?.querySelector('h1'))).toBe('Stránku práve aktualizujeme');
    host.querySelector<HTMLButtonElement>('anotoki-site-status button')!.click();
    await fixture.whenStable();
    expect(login).toHaveBeenCalledTimes(1);

    // Back from the IAM on the page they left: signed in as the site's ADMIN.
    user.set(person('admin'));
    await fixture.whenStable();
    expect(host.querySelector('.the-page')).not.toBeNull();
    expect(statusPage()).toBeNull();
  });

  it('never installed: "not set up yet" and the setup page; installed once: "not available right now", never the setup page', async () => {
    await setup({ who: 'nobody', site: { code: 'not_set_up', installed: false } });
    expect(words(statusPage()?.querySelector('h1'))).toBe('Táto stránka ešte nie je nastavená');
    expect(links()).toEqual([['Otvoriť stránku nastavenia', '/setup.php']]);
    expect(host.querySelector('.the-page')).toBeNull();

    TestBed.resetTestingModule();
    await setup({ who: 'admin', site: { code: 'not_set_up', installed: true } });
    expect(words(statusPage()?.querySelector('h1'))).toBe('Stránka teraz nie je dostupná');
    expect(links()).toEqual([]);
    expect(words(host)).not.toMatch(/setup|nastaven/i);
  });

  /**
   * Anything of a frame on the page: the family's shell, the bar, the footer, the legal links - or any banner,
   * foot or navigation at all.
   */
  const frame = () => [...host.querySelectorAll('anotoki-shell, app-top-bar, anotoki-top-bar, anotoki-site-footer, anotoki-legal-links, header, footer, nav')].map((element) => element.localName);

  it("the gate's page is bare - no bar, no footer, nothing of the frame - while an update waits, the database is out of reach or the site is not set up, in the site's real layouts", async () => {
    const closed: [string, Who, Standing, string][] = [
      ['an update waits: a visitor', 'nobody', { code: 'update_pending' }, '/'],
      ['an update waits: a member', 'member', { code: 'update_pending' }, '/accounts/3'],
      ['an update waits: the ADMIN off the Migrations page', 'admin', { code: 'update_pending' }, '/admin/sources'],
      ['the database out of reach', 'member', { code: 'unavailable' }, '/builds/genshin'],
      ['never set up', 'nobody', { code: 'not_set_up', installed: false }, '/'],
      ['installed once, not set up now', 'admin', { code: 'not_set_up', installed: true }, '/admin/migrations'],
    ];
    for (const [what, who, site, path] of closed) {
      TestBed.resetTestingModule();
      pagesMade = 0;
      await setup({ who, site, path, framed: true });
      expect(statusPage(), what).not.toBeNull();
      expect(frame(), what).toEqual([]);
      // Nothing on the page but the gate's own words, and no page made behind them.
      expect(words(host), what).toBe(words(statusPage()));
      expect(pagesMade, what).toBe(0);
    }
  });

  it("the frame comes back with the site: the bar and the footer around everybody's pages and the admin panel - and around the one page the ADMIN reaches while an update waits, Migrations", async () => {
    const framed = () => ({
      bar: host.querySelector('anotoki-shell app-top-bar anotoki-top-bar') !== null,
      page: host.querySelector('anotoki-shell main .the-page') !== null,
      footer: host.querySelector('anotoki-shell anotoki-site-footer anotoki-legal-links') !== null,
      gate: statusPage() !== null,
    });
    const whole = { bar: true, page: true, footer: true, gate: false };

    const feedback = () => host.querySelector('anotoki-site-footer [data-open-feedback]') !== null;

    await setup({ who: 'member', path: '/accounts/3', framed: true });
    expect([framed(), feedback()]).toEqual([whole, true]);

    TestBed.resetTestingModule();
    await setup({ who: 'admin', path: '/admin/sources', framed: true });
    expect([framed(), feedback()]).toEqual([whole, true]);

    // The ADMIN's Migrations page while an update waits: the frame, but no Feedback - the gate would answer it 503.
    TestBed.resetTestingModule();
    await setup({
      who: 'admin',
      site: { code: 'update_pending' },
      path: '/admin/migrations',
      framed: true,
    });
    expect([framed(), feedback()]).toEqual([whole, false]);

    // Closed while a page was open: the frame goes with the page, and comes back with it.
    TestBed.resetTestingModule();
    await setup({ who: 'member', path: '/accounts/3', framed: true });
    TestBed.inject(SiteStatus).report('unavailable');
    await fixture.whenStable();
    expect([frame(), framed().gate]).toEqual([[], true]);
    TestBed.inject(SiteStatus).markReady();
    await fixture.whenStable();
    expect(framed()).toEqual(whole);
  });

  it("puts the theme chosen on this device on <html> - the family's ThemeService, under the key the site always used", async () => {
    localStorage.setItem('build-analyzer-theme', 'dark');
    await setup();
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
  });

  it("draws the kit's question and messages once, outside the gate, for every page and for the gate's page alike", async () => {
    await setup({ who: 'nobody', site: { code: 'update_pending' } });
    expect(host.querySelectorAll(':scope > anotoki-confirm-host')).toHaveLength(1);
    expect(host.querySelectorAll(':scope > anotoki-toast-host')).toHaveLength(1);
  });

  it('says once, after a sign-in, what moved from the visitor into the account - in the language on the page', async () => {
    await setup();
    visitor.moved.set({ found: true, moved: 2, kept: 1, imports: 3, held: false });
    await fixture.whenStable();

    const said = [...host.querySelectorAll('anotoki-toast-host .anotoki-toast-host__message')].map(words);
    expect(said).toEqual(['Účty importované bez prihlásenia sú teraz v tvojom anotoki účte (2). Účet, ktorý už bol v tvojom anotoki účte, zostal, ako bol (1).']);
    expect(visitor.moved()).toBeNull();
  });

  it('shows what is new to somebody who has used the site before - signed in, or holding a visitor key - and never to a first visit, nor while the site is not there', async () => {
    await setup();
    expect(changelog.showIfNew).toHaveBeenCalledTimes(1);

    TestBed.resetTestingModule();
    await setup({ who: 'nobody', key: 'K'.repeat(43) });
    expect(changelog.showIfNew).toHaveBeenCalledTimes(1);

    TestBed.resetTestingModule();
    await setup({ who: 'nobody' });
    expect(changelog.showIfNew).not.toHaveBeenCalled();

    TestBed.resetTestingModule();
    await setup({ who: 'member', site: { code: 'update_pending' } });
    expect(changelog.showIfNew).not.toHaveBeenCalled();
  });
});
