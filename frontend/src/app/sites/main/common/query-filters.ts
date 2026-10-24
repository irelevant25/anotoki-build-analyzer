import { effect, inject, untracked, WritableSignal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Params, Router } from '@angular/router';

/**
 * A filter of a list page: the signal holding its value - text, as the kit's controls hold it - and the value it holds
 * while nothing is chosen (`''` for a text field or a select's "Any", `'all'` for a segmented control's "All"). That
 * value is the filter's absence: its parameter is left out of the address, and an address without it - or with it empty
 * - sets it.
 */
export type QueryFilter = readonly [value: WritableSignal<string>, none: string];

/**
 * A list page's filters kept in the address, both ways - the builds browser's, as genshin.anotoki.eu keeps its lists'.
 *
 * A filtered list is a place, not a mode: coming back from a character finds the list as it was left, and the address
 * is worth sending to somebody. Both come of one decision - the address holds the filters, the browser's history
 * restores them, and nothing of the site's is remembered or goes stale between visits.
 *
 * Called from a component's constructor (it needs the injection context): the signals take the address's values
 * before it returns, so the first render is filtered already. The anotoki library has no such helper yet - worth
 * asking it for.
 */
export function bindQueryFilters(filters: Record<string, QueryFilter>): void {
  const route = inject(ActivatedRoute);
  const router = inject(Router);
  const entries = Object.entries(filters);

  /** The query string the signals describe: null leaves a parameter out. */
  const fromSignals = (): Params => Object.fromEntries(entries.map(([key, [value, none]]) => [key, value() === none ? null : value()]));

  // The address is read as well as written, so a link opened wins over whatever the signals hold - also when only the
  // parameters change under a page already open, which reuses the component rather than making a new one. The first
  // run is at once, and seeds the page. The page's own writes come back here too: they set each signal to the value it
  // holds already, which a signal takes as no change, so the effect below does not run again and the two never chase
  // each other.
  route.queryParamMap.pipe(takeUntilDestroyed()).subscribe((params) => {
    for (const [key, [value, none]] of entries) {
      value.set(params.get(key) || none);
    }
  });

  effect(() => {
    const params = fromSignals();

    untracked(() => {
      // The seeding run leaves the signals agreeing with the address: without this every visit would open with a
      // navigation to the address it is at.
      const inAddress = route.snapshot.queryParams;
      if (entries.every(([key]) => (inAddress[key] ?? null) === params[key])) {
        return;
      }
      void router.navigate([], {
        relativeTo: route,
        queryParams: params,
        // Another parameter of the address is not the filters' to drop.
        queryParamsHandling: 'merge',
        // Replaced, not pushed: a filter is not somewhere one went. Pushed, every keystroke in the name field would be
        // a step of the history, and leaving the page would mean pressing Back past all of them.
        replaceUrl: true,
      });
    });
  });
}
