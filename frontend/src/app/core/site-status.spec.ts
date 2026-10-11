import { computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ANOTOKI_MIGRATIONS_CONFIG, AnotokiMigrationsConfig } from '@anotoki/lib/migrations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from './auth';
import { AnotokiPreferences, AnotokiUser } from '@anotoki/lib/auth';
import { TranslationService } from './i18n/translation';
import { appliedAt, provideSiteStatus } from './site-status';

/** A moment, in this computer's time zone - written by hand as the Migrations page should write it. */
const AT = new Date('2026-09-20T12:30:00Z');
const CLOCK = `${String(AT.getHours()).padStart(2, '0')}:${String(AT.getMinutes()).padStart(2, '0')}`;
const DAY = `${AT.getFullYear()}-${String(AT.getMonth() + 1).padStart(2, '0')}-${String(AT.getDate()).padStart(2, '0')}`;

const choices = (
  date_format: AnotokiPreferences['date_format'],
  time_format: AnotokiPreferences['time_format'],
) => ({ date_format, time_format });

describe('appliedAt: when a migration was applied, as the Migrations page writes it', () => {
  it("writes it the page's own way for somebody who chose nothing, or for whom no account says", () => {
    expect(appliedAt(AT.toISOString(), null, 'en-GB')).toMatch(/^\d{1,2} Sep 2026, \d\d:\d\d$/);
    expect(appliedAt(AT.toISOString(), null, 'en-GB')).toContain(CLOCK);
    expect(appliedAt(AT.toISOString(), choices(null, null), 'en-GB')).toBe(
      appliedAt(AT.toISOString(), null, 'en-GB'),
    );
  });

  it("writes it in the account's date order and clock, where the person has chosen them", () => {
    expect(appliedAt(AT.toISOString(), choices('ymd_dash', '24'), 'en-GB')).toBe(`${DAY} ${CLOCK}`);
    // Only the order chosen: the page's own clock beside it.
    expect(appliedAt(AT.toISOString(), choices('ymd_dash', null), 'en-GB')).toBe(`${DAY} ${CLOCK}`);
    // Only the clock chosen: the page's own day beside it.
    expect(appliedAt(AT.toISOString(), choices(null, '12'), 'en-GB')).toMatch(
      /^\d{1,2} Sep 2026 \d{1,2}:\d\d\s?[ap]m$/i,
    );
  });

  it('leaves what is not a date as it came', () => {
    expect(appliedAt('not a date', choices('ymd_dash', '24'), 'en-GB')).toBe('not a date');
  });
});

describe('the site status, wired to this site (provideSiteStatus)', () => {
  let config: AnotokiMigrationsConfig;
  let auth: {
    user: ReturnType<typeof signal<AnotokiUser | null>>;
    signedIn: () => boolean;
    isAdmin: () => boolean;
    preferences: ReturnType<typeof signal<AnotokiPreferences | null>>;
    failure: ReturnType<typeof signal<null>>;
    login: ReturnType<typeof vi.fn>;
  };
  let i18n: TranslationService;

  function setup(roles: string[] | null): void {
    const user = signal<AnotokiUser | null>(
      roles === null
        ? null
        : {
            sub: '1',
            preferred_username: 'ada',
            email: null,
            email_verified: true,
            locale: 'sk',
            roles,
            preferences: null,
          },
    );
    auth = {
      user,
      signedIn: () => user() !== null,
      isAdmin: computed(() => (user()?.roles ?? []).includes('ADMIN')),
      preferences: signal<AnotokiPreferences | null>(null),
      failure: signal(null),
      login: vi.fn(async () => true),
    };
    TestBed.configureTestingModule({
      providers: [
        provideSiteStatus(),
        provideTestTranslations({}, { language: 'sk', english: {} }),
        { provide: AuthService, useValue: auth },
      ],
    });
    config = TestBed.inject(ANOTOKI_MIGRATIONS_CONFIG);
    i18n = TestBed.inject(TranslationService);
  }

  it("asks this site's status and its Migrations page's API, and shows the Migrations page on the admin panel's route", () => {
    setup(null);
    expect([config.statusUrl, config.apiBase, config.migrationsRoute]).toEqual([
      '/api/site-status',
      '/api/admin/migrations',
      '/admin/migrations',
    ]);
    // The setup page is the module's own default: offered only while the site was never installed.
    expect(config.setupUrl).toBeUndefined();
  });

  it("knows the site's ADMIN from the token's roles, and who is signed in", () => {
    setup(['MEMBER', 'ADMIN']);
    expect([config.isAdmin(), config.isSignedIn()]).toEqual([true, true]);

    auth.user.set({
      sub: '7',
      preferred_username: 'mona',
      email: null,
      email_verified: true,
      locale: null,
      roles: ['MEMBER', 'EDITOR'],
      preferences: null,
    });
    expect([config.isAdmin(), config.isSignedIn()]).toEqual([false, true]);

    auth.user.set(null);
    expect([config.isAdmin(), config.isSignedIn()]).toEqual([false, false]);
  });

  it("signs in at the IAM, back to the page the person is on, the IAM's pages in the language read here", async () => {
    setup(null);
    await config.signIn();
    // No address given: the sign-in comes back to the page it was started on (AuthService.login()).
    expect(auth.login).toHaveBeenCalledWith(undefined);
  });

  it("gives the language on the page: the reader's, and English in the admin panel", () => {
    setup(null);
    expect(config.language()).toBe('sk');
    i18n.noteAddress('/admin/migrations');
    expect(config.language()).toBe('en');
    i18n.noteAddress('/');
    expect(config.language()).toBe('sk');
  });

  it("writes the Migrations page's dates in the account's order and clock", () => {
    setup(['ADMIN']);
    expect(config.formatDate!(AT.toISOString())).toBe(appliedAt(AT.toISOString(), null, 'en-GB'));
    auth.preferences.set({ theme: 'auto', date_format: 'ymd_dash', time_format: '24' });
    expect(config.formatDate!(AT.toISOString())).toBe(`${DAY} ${CLOCK}`);
  });

  it("reads the site's words again once an Apply leaves nothing to apply: the strings may only now be there", async () => {
    setup(['ADMIN']);
    const reload = vi.spyOn(i18n, 'reload');
    await config.onUpToDate!();
    expect(reload).toHaveBeenCalledTimes(1);
  });
});
