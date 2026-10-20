import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { SiteStatus } from '@anotoki/lib/migrations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../core/auth';
import { Changelog } from '../../core/changelog';
import { provideFeedback } from '../../core/feedback';
import { provideKit } from '../../core/kit';
import { provideLegal } from '../../core/legal';
import { AppLayout } from './app-layout';

@Component({ selector: 'app-test-page', template: '<p class="the-page">the page</p>' })
class TestPage {}

const ENGLISH = {
  'topbar.dashboard': 'Dashboard',
  'topbar.import': 'Import',
  'topbar.builds': 'Builds',
};
const SLOVAK = {
  'topbar.dashboard': 'Prehľad',
  'topbar.import': 'Import',
  'topbar.builds': 'Buildy',
  'feedback.section.dashboard': 'Prehľad',
  'feedback.section.import': 'Import účtu',
  'feedback.section.accounts': 'Tvoje účty a postavy',
  'feedback.section.builds': 'Buildy',
};

/** Who the page is shown to: a person signed in, a visitor (the IAM lets visitors in), or somebody signed out. */
type Who = 'person' | 'visitor' | 'signed-out';

interface Options {
  who?: Who;
  language?: 'en' | 'sk';
  /** The site is not ready (an update waits, the database out of reach). */
  blocked?: boolean;
  /** The changelog's newest entry - null without a changelog (a broken upload). */
  version?: string | null;
}

async function draw(options: Options = {}): Promise<{
  host: HTMLElement;
  changelog: { open: ReturnType<typeof vi.fn> };
  settle: () => Promise<void>;
}> {
  const who = options.who ?? 'person';
  const changelog = {
    current: signal(options.version === undefined ? '0.3' : options.version),
    open: vi.fn(),
  };
  TestBed.configureTestingModule({
    providers: [
      provideRouter([{ path: 'import', component: TestPage }]),
      options.language === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }) : provideTestTranslations(ENGLISH),
      provideHttpClient(),
      provideHttpClientTesting(),
      // The kit's words - the footer's among them - in the language on the page; the legal links; the form.
      provideKit(),
      provideLegal(),
      provideFeedback(),
      {
        provide: AuthService,
        useValue: {
          user: signal(who === 'person' ? { sub: '7', email: 'mona@example.test' } : null),
          preferences: () => null,
          available: signal(true),
          signedIn: signal(who === 'person'),
          visiting: signal(who === 'visitor'),
          displayName: () => (who === 'person' ? 'mona' : null),
          isAdmin: () => false,
          isStaff: () => false,
          accountUrl: signal(null),
          failure: signal(null),
        },
      },
      { provide: FeedbackUnread, useValue: { count: signal(null), refresh: vi.fn() } },
      { provide: Changelog, useValue: changelog },
      { provide: SiteStatus, useValue: { blocked: signal(options.blocked ?? false) } },
    ],
  });
  const fixture = TestBed.createComponent(AppLayout);
  await TestBed.inject(Router).navigateByUrl('/import');
  await fixture.whenStable();
  return { host: fixture.nativeElement, changelog, settle: () => fixture.whenStable() };
}

const text = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim();
/** The footer's first row, part by part (the dots between them too). */
const row = (host: HTMLElement) => [...host.querySelectorAll('anotoki-site-footer .anotoki-site-footer__row > *')].map(text);

describe("everybody's pages' frame", () => {
  it("is the family's frame: a skip link, the bar with the app's sections, the page in <main>, the footer", async () => {
    const { host } = await draw();
    const shell = host.querySelector('anotoki-shell')!;

    expect(shell.querySelector('.anotoki-shell__skip-link')?.getAttribute('href')).toBe('#main');
    expect(shell.querySelector('app-top-bar anotoki-top-bar')).not.toBeNull();
    expect([...shell.querySelectorAll('.anotoki-top-bar__nav a')].map((link) => link.textContent?.trim())).toEqual(['Dashboard', 'Import', 'Builds']);
    expect(shell.querySelector('main#main .the-page')).not.toBeNull();
    // The version reads "v0.3" on screen and "Version 0.3" to a screen reader (anotoki-lib 0.7.1): its text holds both.
    expect(text(shell.querySelector('anotoki-site-footer [data-version]'))).toBe('v0.3Version 0.3');
    // The footer under the page, outside <main>.
    expect(shell.querySelector('main anotoki-site-footer')).toBeNull();
  });

  it("has the family's footer in the reader's language: the version, What's new - the site's own -, Feedback; About · Support · Terms · Privacy · Contact; the fan clause", async () => {
    const { host, changelog } = await draw({ who: 'visitor', language: 'sk' });

    expect(row(host)).toEqual(['v0.3Verzia 0.3', '·', 'Čo je nové', '·', 'Spätná väzba']);
    expect([...host.querySelectorAll('anotoki-site-footer anotoki-legal-links a')].map((link) => [text(link), link.getAttribute('href')])).toEqual([
      ['O projekte', '/about'],
      ['Podporiť', '/support'],
      ['Podmienky', '/terms'],
      ['Súkromie', '/privacy'],
      ['Kontakt', '/contact'],
    ]);
    expect(text(host.querySelector('anotoki-site-footer [data-fan]'))).toBe('Táto stránka je fanúšikovský projekt a nie je prepojená so spoločnosťou HoYoverse ani ňou schválená.');
    host.querySelector<HTMLButtonElement>('anotoki-site-footer [data-whats-new]')!.click();
    expect(changelog.open).toHaveBeenCalledTimes(1);
  });

  it("opens the family's feedback form from the footer - its code with the press -, with this site's places in the reader's words", async () => {
    const { host, settle } = await draw({ who: 'visitor', language: 'sk' });

    host.querySelector<HTMLButtonElement>('anotoki-site-footer [data-open-feedback]')!.click();
    await vi.waitFor(async () => {
      await settle();
      expect(host.querySelector('[data-feedback-dialog] anotoki-feedback-form')).not.toBeNull();
    });
    // The dialog is named by the button's words; the form offers the site's places (core/feedback.ts).
    expect(text(host.querySelector('[data-feedback-dialog] .anotoki-dialog__title'))).toBe('Spätná väzba');
    expect([...host.querySelectorAll('anotoki-feedback-form [data-section] option:not([value=""])')].map(text)).toEqual(['Prehľad', 'Import účtu', 'Tvoje účty a postavy', 'Buildy']);
  });

  it('offers Feedback only while a message can be sent: to a person signed in and to a visitor - never to somebody signed out, nor while the site is not ready', async () => {
    const cases: [string, Options, boolean][] = [
      ['a person signed in', { who: 'person' }, true],
      ['a visitor, where the IAM lets visitors in', { who: 'visitor' }, true],
      ['somebody signed out, where the IAM requires signing in', { who: 'signed-out' }, false],
      ['a person, while the site is not ready', { who: 'person', blocked: true }, false],
    ];
    for (const [who, options, offered] of cases) {
      TestBed.resetTestingModule();
      const { host } = await draw(options);
      expect(host.querySelector('anotoki-site-footer [data-open-feedback]') !== null, who).toBe(offered);
      // The rest of the footer is everybody's.
      expect(row(host).slice(0, 3), who).toEqual(['v0.3Version 0.3', '·', "What's new"]);
      expect(host.querySelectorAll('anotoki-site-footer anotoki-legal-links a'), who).toHaveLength(5);
    }
  });

  it("has neither the version nor What's new without a changelog (a broken upload) - and no dot left over", async () => {
    expect(row((await draw({ version: null })).host)).toEqual(['Feedback']);
    TestBed.resetTestingModule();
    expect(row((await draw({ version: null, who: 'signed-out' })).host)).toEqual([]);
  });
});
