import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../core/auth';
import { SyncDriver } from '../../core/sync-driver';
import { AdminLayout } from './admin-layout';

@Component({ selector: 'app-test-page', template: '<p class="the-page">the page</p>' })
class TestPage {}

interface Who {
  signedIn?: boolean;
  roles?: string[];
  /** How many feedback messages nobody opened yet (FeedbackUnread), as the server would say. */
  unread?: number | null;
}

async function open(who: Who): Promise<{
  host: HTMLElement;
  auth: { login: ReturnType<typeof vi.fn> };
  unread: { count: ReturnType<typeof signal<number | null>>; refresh: ReturnType<typeof vi.fn> };
}> {
  const roles = who.roles ?? [];
  const auth = {
    signedIn: signal(who.signedIn ?? true),
    isAdmin: signal(roles.includes('ADMIN')),
    isStaff: signal(roles.includes('ADMIN') || roles.includes('EDITOR')),
    available: signal(true),
    failure: signal(null),
    user: signal(
      (who.signedIn ?? true)
        ? { sub: '7', email: 'mona@example.test', preferred_username: 'mona' }
        : null,
    ),
    displayName: () => ((who.signedIn ?? true) ? 'mona' : null),
    preferences: () => null,
    accountUrl: signal<string | null>(null),
    login: vi.fn(async () => true),
    logout: vi.fn(async () => undefined),
  };
  const count = signal<number | null>(null);
  const unread = {
    count,
    refresh: vi.fn(async () => count.set(who.unread ?? null)),
  };
  TestBed.configureTestingModule({
    providers: [
      // The layout is the component under test: the route only gives its outlet a page.
      provideRouter([{ path: 'admin/sources', component: TestPage }]),
      provideTestTranslations({}, { language: 'sk', english: {} }),
      { provide: AuthService, useValue: auth },
      { provide: FeedbackUnread, useValue: unread },
      { provide: SyncDriver, useValue: { driving: signal(false) } },
    ],
  });
  const fixture = TestBed.createComponent(AdminLayout);
  await TestBed.inject(Router).navigateByUrl('/admin/sources');
  await fixture.whenStable();
  return { host: fixture.nativeElement, auth, unread };
}

const text = (element: Element | null | undefined) =>
  element?.textContent?.replace(/\s+/g, ' ').trim();
/** The bar's sections: the family's bar's links, each its words (without a badge) and where it goes. */
const links = (host: HTMLElement) =>
  [...host.querySelectorAll<HTMLAnchorElement>('.anotoki-top-bar__nav a')].map((link) => [
    text(link)?.replace(/\s*\d+$/, ''),
    link.getAttribute('href'),
  ]);

describe("the admin panel's frame", () => {
  it("gives an editor the editors' sections in the bar, and the page - in English, whatever the reader reads", async () => {
    const { host, unread } = await open({ roles: ['EDITOR'] });

    expect(links(host)).toEqual([
      ['Sources', '/admin/sources'],
      ['Translations', '/admin/translations'],
    ]);
    expect(host.querySelector('.admin')?.getAttribute('lang')).toBe('en');
    expect(host.querySelector('.no-access')).toBeNull();
    // The family's frame: its bar on top, the page in <main>, the footer below - no side navigation.
    expect(host.querySelector('anotoki-shell app-top-bar anotoki-top-bar')).not.toBeNull();
    expect(host.querySelector('anotoki-shell main#main .the-page')).not.toBeNull();
    expect(host.querySelector('anotoki-shell app-footer')).not.toBeNull();
    expect(host.querySelector('.anotoki-shell__sidenav')).toBeNull();
    // The admin tools are the ADMIN's: nobody asks the server what is new for an editor.
    expect(unread.refresh).not.toHaveBeenCalled();
  });

  it("gives an admin every section - Languages, People, Migrations and the family's admin tools too", async () => {
    const { host } = await open({ roles: ['ADMIN'] });

    expect(links(host)).toEqual([
      ['Sources', '/admin/sources'],
      ['Translations', '/admin/translations'],
      ['Languages', '/admin/languages'],
      ['People', '/admin/people'],
      ['Migrations', '/admin/migrations'],
      ['Errors', '/admin/errors'],
      ['Audit', '/admin/audit'],
      ['Feedback', '/admin/feedback'],
      ['Backups', '/admin/backups'],
    ]);
  });

  it('shows the admin how much feedback nobody has opened yet, beside Feedback - asked once, and nothing when there is none', async () => {
    const { host, unread } = await open({ roles: ['ADMIN'], unread: 3 });

    expect(unread.refresh).toHaveBeenCalledTimes(1);
    const feedback = () =>
      [...host.querySelectorAll<HTMLAnchorElement>('.anotoki-top-bar__nav a')].find(
        (link) => link.getAttribute('href') === '/admin/feedback',
      )!;
    // The family's badge beside the words: "Feedback 3" to a screen reader too.
    expect(feedback().querySelector('.anotoki-top-bar__badge')?.textContent?.trim()).toBe('3');
    expect(text(feedback())).toBe('Feedback 3');

    // The Feedback page opened them all.
    unread.count.set(0);
    TestBed.tick();
    expect(feedback().querySelector('.anotoki-top-bar__badge')).toBeNull();
    expect(host.querySelectorAll('.anotoki-top-bar__badge').length).toBe(0);
  });

  it('tells somebody signed in without a role whose the area is, and the way back - and draws no section', async () => {
    const { host } = await open({ roles: ['MEMBER'] });

    expect(text(host.querySelector('h1'))).toBe('This part is for editors and admins');
    expect(host.querySelector('.anotoki-top-bar__nav')).toBeNull();
    expect(host.querySelector('.no-access a')?.getAttribute('href')).toBe('/');
  });

  it('says a session that ended is signed out, and signs in again - back to this page', async () => {
    const { host, auth } = await open({ signedIn: false });

    expect(text(host.querySelector('h1'))).toBe('You are signed out');
    expect(host.querySelector('.anotoki-top-bar__nav')).toBeNull();
    host.querySelector<HTMLButtonElement>('.no-access button')!.click();
    expect(auth.login).toHaveBeenCalledWith(undefined);
  });
});
