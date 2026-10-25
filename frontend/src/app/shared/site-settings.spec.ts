import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ANOTOKI_SITE_SETTINGS_CONFIG, SiteSettings } from '@anotoki/lib/site-settings';
import { AuthService } from './auth';
import { ANNOUNCEMENT_KEY, OPEN_WHILE_CLOSED, STAFF_ROUTE, provideSiteSettings } from './site-settings';

/**
 * The family's site settings wired to this site (shared/site-settings.ts): who the reader is - the token's roles and
 * whether anybody is signed in (a visitor is nobody) - for Page access, and where the announcement's mark is kept.
 */
describe('the site settings, wired to this site (provideSiteSettings)', () => {
  const roles = signal<string[]>([]);
  const signedIn = signal(false);

  beforeEach(() => {
    roles.set([]);
    signedIn.set(false);
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), provideSiteSettings(), { provide: AuthService, useValue: { roles, signedIn } }],
    });
  });

  afterEach(() => TestBed.inject(HttpTestingController).verify());

  /** The settings as GET /api/site-settings tells every page: one page for people signed in, one for editors, one switched off. */
  async function told(): Promise<SiteSettings> {
    const settings = TestBed.inject(SiteSettings);
    const loading = settings.load();
    TestBed.inject(HttpTestingController)
      .expectOne('/api/site-settings')
      .flush({
        maintenance: false,
        announcement: null,
        pages: [
          { path: '/accounts/:id', visibility: 'USER', blocked: false },
          { path: '/import', visibility: 'EDITOR', blocked: false },
          { path: '/builds/:game/:key', visibility: 'PUBLIC', blocked: true },
        ],
      });
    await loading;
    return settings;
  }

  it("keeps the announcement's mark under the site's own name, the one the Privacy page names", () => {
    expect(TestBed.inject(ANOTOKI_SITE_SETTINGS_CONFIG).storageKey).toBe(ANNOUNCEMENT_KEY);
    expect(ANNOUNCEMENT_KEY).toBe('build-analyzer-announcement');
  });

  it("decides a page by who the reader is: nobody and a visitor are nobody, a person their roles - and the site's ADMIN has every page", async () => {
    const settings = await told();
    // Nobody signed in (somebody new, or a visitor): only what no row keeps from them.
    expect(['/accounts/3', '/import', '/builds/genshin/hutao', '/builds/genshin', '/'].map((path) => settings.mayNavigate(path))).toEqual([false, false, false, true, true]);
    signedIn.set(true);
    expect(settings.mayNavigate('/accounts/3')).toBe(true);
    expect(settings.mayNavigate('/import')).toBe(false);
    roles.set(['EDITOR']);
    expect([settings.mayNavigate('/import'), settings.mayNavigate('/builds/genshin/hutao')]).toEqual([true, false]);
    roles.set(['ADMIN']);
    expect(['/accounts/3', '/import', '/builds/genshin/hutao'].map((path) => settings.mayNavigate(path))).toEqual([true, true, true]);
    expect(settings.noticeFor('/builds/genshin/hutao')).toEqual({ kind: 'off' });
  });

  it("signs an administrator in at /staff while the site is closed; the sign-in's way back, its failure's screen, the legal pages, About and Support stay anybody's", () => {
    expect(STAFF_ROUTE).toBe('/staff');
    expect(OPEN_WHILE_CLOSED).toEqual(['/auth', '/terms', '/privacy', '/contact', '/about', '/support', '/sign-in']);
  });
});
