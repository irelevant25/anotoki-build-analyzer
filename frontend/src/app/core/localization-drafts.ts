import { Injector } from '@angular/core';
import type { LocalizationDrafts } from '@anotoki/lib/translations/admin';
import { LANGUAGE_STORAGE } from './i18n/translations.config';

/*
 * What is typed on the admin Translations and Languages pages and not saved yet is held by the anotoki library
 * (LocalizationDrafts) - outside the pages, so that it outlives them, with whose it is (the token's `sub`: only
 * another person signing in lets go of it), mirrored to this tab's sessionStorage while there is something to
 * keep, and asked about once before the tab closes. It lives in the pages' own entry point
 * (@anotoki/lib/translations/admin), which never comes with the first load: the rest of the site tells from the
 * tab's storage whether there are drafts, and loads that entry point only then.
 */

/** Whether this tab holds drafts of the Translations and Languages pages: their mirror in its sessionStorage. */
export function draftsInTab(): boolean {
  try {
    return globalThis.sessionStorage?.getItem(LANGUAGE_STORAGE.drafts) != null;
  } catch {
    return false;
  }
}

/**
 * The library's drafts, made now - with the admin pages' entry point, loaded for them - when this tab holds
 * any; else null, and nothing is loaded. Made, they read what the tab kept: the signed-in person's drafts come
 * back (and the browser asks before the tab closes with them), somebody else's go.
 */
export async function localizationDrafts(injector: Injector): Promise<LocalizationDrafts | null> {
  if (!draftsInTab()) {
    return null;
  }
  const { LocalizationDrafts } = await import('@anotoki/lib/translations/admin');
  return injector.get(LocalizationDrafts);
}
