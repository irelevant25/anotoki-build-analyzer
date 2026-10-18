import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Route, Router, RouterOutlet, Routes, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { routes } from './app.routes';
import { sitePagesGuard } from './core/auth.guards';
import { LEGAL_PAGE_PATHS } from './core/legal';
import { AppLayout } from './layouts/app-layout/app-layout';

@Component({
  selector: 'app-test-frame',
  imports: [RouterOutlet],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<router-outlet />',
})
class FrameStandIn {}

@Component({
  selector: 'app-test-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '',
})
class PageStandIn {}

/**
 * The app's routes as they are - their paths, their order, their nesting, their redirects - with a stand-in for
 * every frame and page and no guard, each knowing the route it stands for: what the router itself makes of an
 * address is what is checked.
 */
function standIns(list: Routes): Routes {
  return list.map((route): Route => {
    if (route.redirectTo !== undefined) {
      return route;
    }
    const standIn: Route = {
      path: route.path,
      pathMatch: route.pathMatch,
      matcher: route.matcher,
      data: { route },
    };
    return route.children ? { ...standIn, component: FrameStandIn, children: standIns(route.children) } : { ...standIn, component: PageStandIn };
  });
}

/** The app's routes the router took for the address it is at, from the top. */
function taken(): Route[] {
  const chain: Route[] = [];
  for (let node = TestBed.inject(Router).routerState.snapshot.root.firstChild; node; node = node.firstChild) {
    chain.push(node.data['route'] as Route);
  }
  return chain;
}

/**
 * Where an address goes (app.routes.ts): the legal pages are anybody's, each a path of its own with their frame as
 * its parent - and "/" is still the dashboard in everybody's pages' frame behind its guard, an unknown address
 * still the dashboard. One parent with an empty path holding the three would take "/" for itself (the router takes
 * an address it has read whole for such a parent when none of its children match): the empty frame would stand
 * there with no guard - the survey's lesson of 2026-10-17.
 */
describe('the routes: what the router makes of an address', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideRouter(standIns(routes))] });
  });

  it("\"/\" is the dashboard, in everybody's pages' frame under its guard - the family's personOrVisitorGuard, which sends anybody else to the IAM first while sign-in is required", async () => {
    await RouterTestingHarness.create('/');
    const [frame, page, ...more] = taken();
    expect([frame.path, frame.component, frame.canActivateChild]).toEqual(['', AppLayout, [sitePagesGuard]]);
    expect([page.path, page.pathMatch, more]).toEqual(['', 'full', []]);
    expect(((await page.loadComponent!()) as { name: string }).name).toContain('DashboardPage');
  });

  it("an unknown address goes to the dashboard - and so does one that only begins like a legal page's", async () => {
    const harness = await RouterTestingHarness.create();
    for (const address of ['/no-such-page', '/terms-of-use', '/privacy2', '/contact/me', '/terms/x']) {
      await harness.navigateByUrl(address);
      const [frame, page] = taken();
      expect([address, TestBed.inject(Router).url, frame.component, frame.canActivateChild, page.pathMatch]).toEqual([address, '/', AppLayout, [sitePagesGuard], 'full']);
    }
  });

  it('/terms, /privacy and /contact: the legal pages, each its own path in the legal frame - no guard on the way, the frame and the page loaded when opened', async () => {
    const harness = await RouterTestingHarness.create();
    for (const [address, title, page] of [
      ['/terms', 'title.terms', 'TermsPageComponent'],
      ['/privacy', 'title.privacy', 'PrivacyPageComponent'],
      ['/contact', 'title.contact', 'ContactPageComponent'],
    ]) {
      await harness.navigateByUrl(address);
      const [frame, child, ...more] = taken();
      expect([frame.path, frame.component, frame.canActivate, frame.canActivateChild, frame.canMatch, more]).toEqual([address.slice(1), undefined, undefined, undefined, undefined, []]);
      expect([child.path, child.title, child.canActivate]).toEqual(['', title, undefined]);
      expect(((await frame.loadComponent!()) as { name: string }).name).toContain('LegalLayout');
      expect(((await child.loadComponent!()) as { name: string }).name).toContain(page);
    }
  });

  it("puts the legal pages where the footer's links go - the library's paths - and before everybody's pages and the \"**\" at the end", () => {
    const top = routes.map((one) => one.path);
    expect(LEGAL_PAGE_PATHS).toEqual(['/terms', '/privacy', '/contact']);
    for (const path of LEGAL_PAGE_PATHS) {
      expect(top.indexOf(path.slice(1)), path).toBeGreaterThan(-1);
      expect(top.indexOf(path.slice(1)), path).toBeLessThan(top.indexOf(''));
      expect(top.indexOf(path.slice(1)), path).toBeLessThan(top.indexOf('**'));
    }
  });
});
