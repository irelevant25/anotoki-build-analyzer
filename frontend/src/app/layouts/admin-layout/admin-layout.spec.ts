import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, provideRouter } from '@angular/router';
import { AuthService } from '../../core/auth';
import { SyncDriver } from '../../core/sync-driver';
import { provideTestTranslations } from '../../core/i18n/testing';
import { Wording } from '../../core/i18n/translation';
import { AdminLayout } from './admin-layout';

@Component({ selector: 'app-test-page', template: '<p class="the-page">the page</p>' })
class TestPage {}

interface Who {
  signedIn?: boolean;
  roles?: string[];
  lastError?: Wording | null;
}

async function open(
  who: Who,
): Promise<{ host: HTMLElement; auth: { login: ReturnType<typeof vi.fn> } }> {
  const roles = who.roles ?? [];
  const auth = {
    signedIn: signal(who.signedIn ?? true),
    isAdmin: signal(roles.includes('ADMIN')),
    isStaff: signal(roles.includes('ADMIN') || roles.includes('EDITOR')),
    available: signal(true),
    lastError: signal<Wording | null>(who.lastError ?? null),
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
  TestBed.configureTestingModule({
    providers: [
      // The layout is the component under test: the route only gives its outlet a page.
      provideRouter([{ path: 'admin/sources', component: TestPage }]),
      provideTestTranslations({}, { language: 'sk', english: {} }),
      { provide: AuthService, useValue: auth },
      { provide: MatSnackBar, useValue: { open: vi.fn() } },
      { provide: SyncDriver, useValue: { driving: signal(false) } },
    ],
  });
  const fixture = TestBed.createComponent(AdminLayout);
  await TestBed.inject(Router).navigateByUrl('/admin/sources');
  await fixture.whenStable();
  return { host: fixture.nativeElement, auth };
}

const text = (element: Element | null | undefined) => {
  const copy = element?.cloneNode(true) as Element | undefined;
  copy?.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
  return copy?.textContent?.replace(/\s+/g, ' ').trim();
};
const links = (host: HTMLElement) =>
  [...host.querySelectorAll<HTMLAnchorElement>('.topbar-nav a')].map((link) => [
    text(link),
    link.getAttribute('href'),
  ]);

describe("the admin panel's frame", () => {
  it("gives an editor the editors' sections in the bar, and the page - in English, whatever the reader reads", async () => {
    const { host } = await open({ roles: ['EDITOR'] });

    expect(links(host)).toEqual([
      ['Sources', '/admin/sources'],
      ['Translations', '/admin/translations'],
    ]);
    expect(host.querySelector('.admin')?.getAttribute('lang')).toBe('en');
    expect(host.querySelector('.no-access')).toBeNull();
    // The family's bar on top, the footer below - no sections of its own.
    expect(host.querySelector('app-top-bar')).not.toBeNull();
    expect(host.querySelector('app-footer')).not.toBeNull();
    expect(host.querySelector('.admin-nav')).toBeNull();
  });

  it('gives an admin every section, Languages, People and Migrations too', async () => {
    const { host } = await open({ roles: ['ADMIN'] });

    expect(links(host)).toEqual([
      ['Sources', '/admin/sources'],
      ['Translations', '/admin/translations'],
      ['Languages', '/admin/languages'],
      ['People', '/admin/people'],
      ['Migrations', '/admin/migrations'],
    ]);
  });

  it('tells somebody signed in without a role whose the area is, and the way back - and draws no section', async () => {
    const { host } = await open({ roles: ['MEMBER'] });

    expect(text(host.querySelector('h1'))).toBe('This part is for editors and admins');
    expect(host.querySelector('nav')).toBeNull();
    expect(host.querySelector('.no-access a')?.getAttribute('href')).toBe('/');
  });

  it('says a session that ended is signed out, and signs in again - back to this page', async () => {
    const { host, auth } = await open({ signedIn: false });

    expect(text(host.querySelector('h1'))).toBe('You are signed out');
    expect(host.querySelector('nav')).toBeNull();
    host.querySelector<HTMLButtonElement>('.no-access button')!.click();
    expect(auth.login).toHaveBeenCalledWith(undefined, { uiLocales: 'sk' });
  });

  it('says a sign-in that came back without one is not signed in', async () => {
    const { host } = await open({
      signedIn: false,
      lastError: { key: 'signIn.notCompleted', params: {} },
    });

    expect(text(host.querySelector('h1'))).toBe('You are not signed in');
  });
});
