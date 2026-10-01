import { CanDeactivateFn } from '@angular/router';

/** A page with changes that are kept only when saved: it says whether it may be left, asking first when it has some. */
export interface HoldsUnsavedChanges {
  canLeave(): boolean | Promise<boolean>;
}

/**
 * Leaving the Translations or the Languages page inside the app (a link, Back) asks the page first - the
 * page asks through the app's own dialog (shared/confirm-dialog). Closing the tab or reloading is the
 * browser's to ask: LocalizationDrafts, which holds what both pages have not saved, listens for
 * `beforeunload`.
 *
 * The router can run this with no page: a route whose outlet is not drawn (nothing was drawn for it, or
 * it was taken away without a navigation) is still the active one. There is nobody to ask then, and
 * nothing to ask about - the drafts are in LocalizationDrafts, not in the page - so the navigation goes
 * through. A guard that threw there would leave every link dead.
 */
export const unsavedChangesGuard: CanDeactivateFn<HoldsUnsavedChanges | null> = (page) => page?.canLeave() ?? true;
