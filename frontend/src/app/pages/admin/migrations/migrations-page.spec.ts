import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { provideRouter } from '@angular/router';
import { SiteStatus, siteStatusInterceptor } from '@anotoki/lib/migrations';
import { AuthService } from '../../../core/auth';
import { AnotokiPreferences } from '../../../core/iam/anotoki-auth';
import { provideTestTranslations } from '../../../core/i18n/testing';
import { TranslationService } from '../../../core/i18n/translation';
import { appliedAt, provideSiteStatus } from '../../../core/site-status';
import { MigrationsPage } from './migrations-page';

const SET = 'build_analyzer';
const BEHIND = {
  sets: [SET],
  applied: [
    { set: SET, name: '001_adopt_laravel_database.sql', applied_at: '2026-09-01T08:00:00Z' },
    { set: SET, name: '002_schema.sql', applied_at: '2026-09-01T08:00:01Z' },
    { set: SET, name: '003_languages_and_translations.sql', applied_at: '2026-09-20T12:30:00Z' },
  ],
  pending: [
    { set: SET, name: '004_visitors_and_family_bar.sql', ready: true, blocked: false },
    { set: SET, name: '005_retire_not_set_up_page.sql', ready: true, blocked: false },
  ],
  missing: [],
};
const CURRENT = {
  ...BEHIND,
  applied: [
    ...BEHIND.applied,
    { set: SET, name: '004_visitors_and_family_bar.sql', applied_at: '2026-10-07T09:00:00Z' },
    { set: SET, name: '005_retire_not_set_up_page.sql', applied_at: '2026-10-07T09:00:01Z' },
  ],
  pending: [],
};

/**
 * jsdom has <dialog> but neither showModal() nor close(): enough of both for the confirmation the family's
 * page asks before an Apply.
 */
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

/** Lets pending promises, timers of 0 and change detection run out. */
async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  for (let i = 0; i < 3; i++) {
    await new Promise((done) => setTimeout(done));
    await fixture.whenStable();
  }
}

/** An element's words: its text nodes, one space between them (the templates leave none between their tags). */
function words(element: Element | null | undefined): string | null {
  if (!element) {
    return null;
  }
  const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
  const parts: string[] = [];
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    parts.push(node.textContent ?? '');
  }
  return parts.join(' ').replace(/\s+/g, ' ').trim();
}

describe("/admin/migrations: the family's Migrations page in the admin panel (ADMIN)", () => {
  let fixture: ComponentFixture<MigrationsPage>;
  let host: HTMLElement;
  let http: HttpTestingController;
  let status: SiteStatus;
  let preferences: ReturnType<typeof signal<AnotokiPreferences | null>>;

  async function setup(account: AnotokiPreferences | null = null): Promise<void> {
    dialogsThatOpen();
    preferences = signal(account);
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([siteStatusInterceptor])),
        provideHttpClientTesting(),
        provideSiteStatus(),
        provideTestTranslations({}),
        {
          provide: AuthService,
          useValue: {
            user: signal(null),
            signedIn: () => true,
            isAdmin: () => true,
            preferences,
            lastError: signal(null),
            login: vi.fn(),
          },
        },
        { provide: MatSnackBar, useValue: { open: vi.fn() } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    status = TestBed.inject(SiteStatus);
    // An update waits: the one page that shows then, to an ADMIN.
    status.report('update_pending');
    fixture = TestBed.createComponent(MigrationsPage);
    host = fixture.nativeElement;
    await settle(fixture);
    http.expectOne('/api/admin/migrations').flush(BEHIND);
    await settle(fixture);
  }

  /** Apply pending, yes - and the server's answer, then the lists as they are after it. */
  async function apply(answer: object, after: object): Promise<void> {
    host.querySelector<HTMLButtonElement>('[data-apply]')!.click();
    await settle(fixture);
    expect(words(host.querySelector('dialog[data-confirm] h2'))).toBe(
      'Apply 2 pending migrations?',
    );
    host.querySelector<HTMLButtonElement>('[data-confirm-apply]')!.click();
    await settle(fixture);
    const request = http.expectOne('/api/admin/migrations/apply');
    expect(request.request.method).toBe('POST');
    request.flush(answer);
    await settle(fixture);
    http.expectOne('/api/admin/migrations').flush(after);
    await settle(fixture);
  }

  afterEach(() => http.verify());

  it("reads this site's files and shows them in the admin panel's frame, in English", async () => {
    await setup();
    expect(host.querySelector('.page > anotoki-migrations-page')).not.toBeNull();
    expect(host.querySelector('anotoki-migrations-page')?.getAttribute('lang')).toBe('en');
    expect(words(host.querySelector('h1'))).toBe('Migrations');
    expect([...host.querySelectorAll('[data-pending] li')].map(words)).toEqual([
      '004_visitors_and_family_bar.sql pending',
      '005_retire_not_set_up_page.sql pending',
    ]);
    expect([...host.querySelectorAll('[data-applied] li code')].map(words)).toEqual([
      '001_adopt_laravel_database.sql',
      '002_schema.sql',
      '003_languages_and_translations.sql',
    ]);
    expect(words(host.querySelectorAll('[data-applied] li time')[2])).toBe(
      appliedAt('2026-09-20T12:30:00Z', null, 'en-GB'),
    );
  });

  it("writes when each file was applied in the account's date order and clock, where the admin has chosen them", async () => {
    await setup({ theme: 'auto', date_format: 'ymd_dash', time_format: '24' });
    const at = new Date('2026-09-20T12:30:00Z');
    const day = `${at.getFullYear()}-${String(at.getMonth() + 1).padStart(2, '0')}-${String(at.getDate()).padStart(2, '0')}`;
    const clock = `${String(at.getHours()).padStart(2, '0')}:${String(at.getMinutes()).padStart(2, '0')}`;
    expect(words(host.querySelectorAll('[data-applied] li time')[2])).toBe(`${day} ${clock}`);
  });

  it('applies after asking; once nothing is left the site opens again in this tab, and reads its words again', async () => {
    await setup();
    const reload = vi.spyOn(TestBed.inject(TranslationService), 'reload');
    await apply(
      {
        applied: BEHIND.pending.map(({ set, name }) => ({ set, name })),
        failed: null,
        error: null,
        note: null,
      },
      CURRENT,
    );

    expect(words(host.querySelector('[data-result]'))).toBe(
      'Up to date Applied: 004_visitors_and_family_bar.sql, 005_retire_not_set_up_page.sql.',
    );
    expect(status.state()).toBe('ready');
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it("says where it stopped and the database's error - and the site stays closed to everybody else", async () => {
    await setup();
    const reload = vi.spyOn(TestBed.inject(TranslationService), 'reload');
    const error =
      'statement 1 of 9: SQLSTATE[42P07]: Duplicate table: 7 ERROR:  relation "visitor_ids" already exists\n\n  CREATE SEQUENCE visitor_ids';
    await apply(
      {
        applied: [],
        failed: { set: SET, name: '004_visitors_and_family_bar.sql' },
        error,
        note: null,
      },
      BEHIND,
    );

    const result = host.querySelector('[data-result]');
    expect(result?.getAttribute('role')).toBe('alert');
    expect(words(result?.querySelector('h2'))).toBe('Stopped at 004_visitors_and_family_bar.sql');
    expect(words(result?.querySelector('p'))).toBe(
      'Nothing was applied. The failed file was rolled back as a whole.',
    );
    expect(result?.querySelector('pre')?.textContent).toBe(error);
    expect(status.state()).toBe('update-pending');
    expect(reload).not.toHaveBeenCalled();
  });
});
