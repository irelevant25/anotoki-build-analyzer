import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, provideRouter } from '@angular/router';
import { SiteStatus, siteStatusInterceptor } from '@anotoki/lib/migrations';
import { App } from './app';
import { AuthService } from './core/auth';
import { Changelog } from './core/changelog';
import { AnotokiUser } from './core/iam/anotoki-auth';
import { provideTestTranslations } from './core/i18n/testing';
import { VisitorMove } from './core/models';
import { provideSiteStatus } from './core/site-status';
import { VisitorKey } from './core/visitor-key';

const ENGLISH = {
  'brand.area': 'Build Analyzer',
  'common.close': 'Close',
};
const SLOVAK = {
  'brand.area': 'Build Analyzer',
  'common.close': 'Zavrieť',
  'visitor.moved.one': 'Účet importovaný bez prihlásenia je teraz v tvojom anotoki účte ({count}).',
  'visitor.moved.few': 'Účty importované bez prihlásenia sú teraz v tvojom anotoki účte ({count}).',
  'visitor.moved.other':
    'Účty importované bez prihlásenia sú teraz v tvojom anotoki účte ({count}).',
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

/** Where the site stands, as the server says it: ready, or a 503's code (and whether it was installed). */
type Standing =
  { code: 'ready' } | { code: 'update_pending' } | { code: 'not_set_up'; installed: boolean };

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
  let snackBar: { open: ReturnType<typeof vi.fn> };
  let visitor: {
    key: ReturnType<typeof signal<string | null>>;
    moved: ReturnType<typeof signal<VisitorMove | null>>;
  };
  let changelog: {
    current: ReturnType<typeof signal<string | null>>;
    open: ReturnType<typeof vi.fn>;
    showIfNew: ReturnType<typeof vi.fn>;
  };

  async function setup(
    options: { who?: Who; key?: string | null; site?: Standing; path?: string } = {},
  ): Promise<void> {
    user = signal(person(options.who ?? 'member'));
    login = vi.fn(async () => true);
    visitor = { key: signal(options.key ?? null), moved: signal<VisitorMove | null>(null) };
    changelog = { current: signal<string | null>('0.3'), open: vi.fn(), showIfNew: vi.fn() };
    snackBar = { open: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: '**', component: TestPage }]),
        provideHttpClient(withInterceptors([siteStatusInterceptor])),
        provideHttpClientTesting(),
        provideSiteStatus(),
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        {
          provide: AuthService,
          useValue: {
            user,
            signedIn: () => user() !== null,
            isAdmin: computed(() => (user()?.roles ?? []).includes('ADMIN')),
            preferences: () => null,
            lastError: signal(null),
            login,
          },
        },
        { provide: Changelog, useValue: changelog },
        { provide: VisitorKey, useValue: visitor },
        { provide: MatSnackBar, useValue: snackBar },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
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
  const links = () =>
    [...host.querySelectorAll('anotoki-site-status a')].map((link) => [
      words(link),
      link.getAttribute('href'),
    ]);

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    pagesMade = 0;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.documentElement.classList.remove('theme-light', 'theme-dark');
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
    expect(words(statusPage())).toBe(
      'Stránku práve aktualizujeme O pár minút bude späť. Táto stránka sa obnoví sama. Prihlásiť sa',
    );
    expect(buttons()).toEqual(['Prihlásiť sa']);
    expect(links()).toEqual([]);
    expect(words(host)).not.toMatch(/update_pending|503|setup|Migrations/i);

    // Sign in: to the IAM, and back to this page (no address given: the one the person is on).
    host.querySelector<HTMLButtonElement>('anotoki-site-status button')!.click();
    await fixture.whenStable();
    expect(login).toHaveBeenCalledWith(undefined, { uiLocales: 'sk' });
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

  it('puts the theme chosen on this device on <html> before anything is drawn', async () => {
    localStorage.setItem('build-analyzer-theme', 'dark');
    await setup();
    expect(document.documentElement.classList.contains('theme-dark')).toBe(true);
  });

  it('says once, after a sign-in, what moved from the visitor into the account - in the language on the page', async () => {
    await setup();
    visitor.moved.set({ found: true, moved: 2, kept: 1, imports: 3 });
    await fixture.whenStable();

    expect(snackBar.open).toHaveBeenCalledTimes(1);
    expect(snackBar.open).toHaveBeenCalledWith(
      'Účty importované bez prihlásenia sú teraz v tvojom anotoki účte (2). Účet, ktorý už bol v tvojom anotoki účte, zostal, ako bol (1).',
      'Zavrieť',
      expect.anything(),
    );
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
