import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { bindQueryFilters } from './query-filters';

/** A list page of two filters: a name, and a choice whose "all" is its absence. */
@Component({
  selector: 'app-test-list',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '',
})
class FilteredList {
  readonly name = signal('');
  readonly element = signal('all');

  constructor() {
    bindQueryFilters({ name: [this.name, ''], element: [this.element, 'all'] });
  }
}

describe('a list page’s filters, kept in the address', () => {
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideRouter([{ path: 'list', component: FilteredList }])] });
    router = TestBed.inject(Router);
  });

  it('takes them from the address before the first render, and goes nowhere for it', async () => {
    const harness = await RouterTestingHarness.create();
    const navigate = vi.spyOn(router, 'navigate');
    const page = await harness.navigateByUrl('/list?name=hu&element=pyro', FilteredList);

    expect([page.name(), page.element()]).toEqual(['hu', 'pyro']);
    await harness.fixture.whenStable();
    expect(router.url).toBe('/list?name=hu&element=pyro');
    expect(navigate).not.toHaveBeenCalled();
  });

  it('reads an empty parameter as no filter, and leaves it out of the address', async () => {
    const harness = await RouterTestingHarness.create();
    const page = await harness.navigateByUrl('/list?name=&element=&tab=2', FilteredList);

    expect([page.name(), page.element()]).toEqual(['', 'all']);
    await harness.fixture.whenStable();
    expect(router.url).toBe('/list?tab=2');
  });

  it('writes them back, replacing the address - a filter is no step of the history -, the value of none left out, another parameter kept', async () => {
    const harness = await RouterTestingHarness.create();
    const page = await harness.navigateByUrl('/list?tab=2', FilteredList);
    const navigate = vi.spyOn(router, 'navigate');

    page.element.set('hydro');
    await harness.fixture.whenStable();
    expect(router.url).toBe('/list?tab=2&element=hydro');
    expect(navigate).toHaveBeenCalledTimes(1);
    expect(navigate.mock.calls[0][1]).toMatchObject({ replaceUrl: true, queryParamsHandling: 'merge' });

    // Two changes at once: one navigation.
    page.name.set('Fur');
    page.element.set('all');
    await harness.fixture.whenStable();
    expect(router.url).toBe('/list?tab=2&name=Fur');
    expect(navigate).toHaveBeenCalledTimes(2);
  });

  it('follows the address when only its parameters change under the page: a link opened there wins', async () => {
    const harness = await RouterTestingHarness.create();
    const page = await harness.navigateByUrl('/list?element=pyro', FilteredList);

    await harness.navigateByUrl('/list?name=xi');
    expect([page.name(), page.element()]).toEqual(['xi', 'all']);
    expect(router.url).toBe('/list?name=xi');
  });
});
