import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../core/auth';
import { Changelog } from '../../core/changelog';
import { SyncDriver } from '../../core/sync-driver';
import { AppLayout } from './app-layout';

@Component({ selector: 'app-test-page', template: '<p class="the-page">the page</p>' })
class TestPage {}

describe("everybody's pages' frame", () => {
  it("is the family's frame: a skip link, the bar with the app's sections, the page in <main>, the footer", async () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'import', component: TestPage }]),
        provideTestTranslations({
          'topbar.dashboard': 'Dashboard',
          'topbar.import': 'Import',
          'topbar.builds': 'Builds',
          'footer.version': 'Version {version}',
        }),
        {
          provide: AuthService,
          useValue: {
            user: signal(null),
            preferences: () => null,
            available: signal(true),
            signedIn: () => false,
            displayName: () => null,
            isAdmin: () => false,
            isStaff: () => false,
            accountUrl: signal(null),
            failure: signal(null),
          },
        },
        { provide: SyncDriver, useValue: { driving: signal(false) } },
        { provide: FeedbackUnread, useValue: { count: signal(null), refresh: vi.fn() } },
        { provide: Changelog, useValue: { current: signal('0.3'), open: vi.fn() } },
      ],
    });
    const fixture = TestBed.createComponent(AppLayout);
    await TestBed.inject(Router).navigateByUrl('/import');
    await fixture.whenStable();
    const host: HTMLElement = fixture.nativeElement;
    const shell = host.querySelector('anotoki-shell')!;

    expect(shell.querySelector('.anotoki-shell__skip-link')?.getAttribute('href')).toBe('#main');
    expect(shell.querySelector('app-top-bar anotoki-top-bar')).not.toBeNull();
    expect(
      [...shell.querySelectorAll('.anotoki-top-bar__nav a')].map((link) =>
        link.textContent?.trim(),
      ),
    ).toEqual(['Dashboard', 'Import', 'Builds']);
    expect(shell.querySelector('main#main .the-page')).not.toBeNull();
    expect(shell.querySelector('app-footer .version')?.textContent?.trim()).toBe('Version 0.3');
    // The footer under the page, outside <main>.
    expect(shell.querySelector('main app-footer')).toBeNull();
  });
});
