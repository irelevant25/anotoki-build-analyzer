import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Router, provideRouter } from '@angular/router';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../core/auth';
import { Changelog } from '../../core/changelog';
import { SyncDriver } from '../../core/sync-driver';
import { AppLayout } from './app-layout';

@Component({ selector: 'app-test-page', template: '<p class="the-page">the page</p>' })
class TestPage {}

describe("everybody's pages' frame", () => {
  it("is the bar with the app's sections, the page, and the footer", async () => {
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
        { provide: Changelog, useValue: { current: signal('0.3'), open: vi.fn() } },
        { provide: MatSnackBar, useValue: { open: vi.fn() } },
      ],
    });
    const fixture = TestBed.createComponent(AppLayout);
    await TestBed.inject(Router).navigateByUrl('/import');
    await fixture.whenStable();
    const host: HTMLElement = fixture.nativeElement;

    expect([...host.children].map((child) => child.tagName.toLowerCase())).toEqual([
      'app-top-bar',
      'main',
      'app-footer',
    ]);
    expect(
      [...host.querySelectorAll('.topbar-nav a')].map((link) => link.textContent?.trim()),
    ).toEqual(['Dashboard', 'Import', 'Builds']);
    expect(host.querySelector('main .the-page')).not.toBeNull();
    expect(host.querySelector('app-footer .version')?.textContent?.trim()).toBe('Version 0.3');
  });
});
