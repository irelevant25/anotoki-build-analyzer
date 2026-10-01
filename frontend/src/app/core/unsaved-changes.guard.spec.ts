import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, Router, RouterOutlet, RouterStateSnapshot, provideRouter } from '@angular/router';
import { HoldsUnsavedChanges, unsavedChangesGuard } from './unsaved-changes.guard';

/** The guard as the router calls it: with the page the route drew - or with none. */
function asked(page: HoldsUnsavedChanges | null) {
  const route = {} as ActivatedRouteSnapshot;
  const state = {} as RouterStateSnapshot;
  return TestBed.runInInjectionContext(() => unsavedChangesGuard(page, route, state, state));
}

describe('unsavedChangesGuard', () => {
  it('lets the navigation through when there is no page to ask: the layout took it away, or never drew it', () => {
    expect(asked(null)).toBe(true);
  });

  it('answers what the page answers: a page that may not be left is not left, one with nothing unsaved is', () => {
    expect(asked({ canLeave: () => false })).toBe(false);
    expect(asked({ canLeave: () => true })).toBe(true);
  });

  it('hands back the question the page is asking, to be waited for', async () => {
    const asking = Promise.resolve(false);
    const answer = asked({ canLeave: () => asking });

    expect(answer).toBe(asking);
    await expect(answer).resolves.toBe(false);
  });

  // The way it happens on the site: a layout that draws its outlet only for
  // some, as the admin layout does for staff, over a route the guard is on.
  describe('under a layout that takes its page away', () => {
    const allowed = signal(true);
    const canLeave = vi.fn<() => boolean>();

    // The spec's own stand-ins for the layout and the pages: inline templates only here.
    @Component({
      selector: 'app-test-layout',
      imports: [RouterOutlet],
      changeDetection: ChangeDetectionStrategy.OnPush,
      template: `
        @if (allowed()) {
          <router-outlet />
        }
      `,
    })
    class LayoutComponent {
      protected readonly allowed = allowed;
    }

    @Component({ selector: 'app-test-page', changeDetection: ChangeDetectionStrategy.OnPush, template: 'the page' })
    class PageComponent implements HoldsUnsavedChanges {
      canLeave(): boolean {
        return canLeave();
      }
    }

    @Component({ selector: 'app-test-elsewhere', changeDetection: ChangeDetectionStrategy.OnPush, template: 'elsewhere' })
    class ElsewhereComponent {}

    @Component({ selector: 'app-test-root', imports: [RouterOutlet], changeDetection: ChangeDetectionStrategy.OnPush, template: '<router-outlet />' })
    class RootComponent {}

    async function site(): Promise<Router> {
      TestBed.configureTestingModule({
        providers: [
          provideRouter([
            { path: 'admin', component: LayoutComponent, children: [{ path: 'translations', component: PageComponent, canDeactivate: [unsavedChangesGuard] }] },
            { path: '', pathMatch: 'full', component: ElsewhereComponent },
          ]),
        ],
      });
      const fixture = TestBed.createComponent(RootComponent);
      const router = TestBed.inject(Router);
      expect(await router.navigateByUrl('/admin/translations')).toBe(true);
      await fixture.whenStable();
      return router;
    }

    beforeEach(() => {
      allowed.set(true);
      canLeave.mockReset();
    });

    it('asks the page while it is drawn, and stays when it says so', async () => {
      const router = await site();
      canLeave.mockReturnValue(false);

      expect(await router.navigateByUrl('/')).toBe(false);
      expect(canLeave).toHaveBeenCalledTimes(1);
      expect(router.url).toBe('/admin/translations');
    });

    it('leaves once the session ended and the page is gone: nothing is asked, nothing thrown', async () => {
      const router = await site();
      canLeave.mockReturnValue(false);

      // The session ends: the layout takes the outlet, and the page with it, away - no navigation.
      allowed.set(false);
      TestBed.tick();

      expect(await router.navigateByUrl('/')).toBe(true);
      expect(canLeave).not.toHaveBeenCalled();
      expect(router.url).toBe('/');
    });

    it('leaves a route whose page was never drawn: somebody signed in without a role', async () => {
      allowed.set(false);
      const router = await site();

      expect(await router.navigateByUrl('/')).toBe(true);
      expect(canLeave).not.toHaveBeenCalled();
      expect(router.url).toBe('/');
    });
  });
});
