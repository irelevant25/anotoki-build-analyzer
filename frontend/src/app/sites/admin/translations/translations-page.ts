import { Component, viewChild } from '@angular/core';
import { HoldsUnsavedChanges } from '@anotoki/lib/translations';
import { TranslationsPageComponent } from '@anotoki/lib/translations/admin';

/**
 * /admin/translations (EDITOR and ADMIN; English, like the whole admin panel) - what the site itself says, in
 * every language: the anotoki family's Translations page (@anotoki/lib/translations/admin, an entry point of
 * its own: it comes with this lazy route, never in the first load), drawn with the library's kit, in this admin
 * panel's frame. A key a row and a language a column; each language's coverage, a search, "Missing in X",
 * plural families as one block, a language's strings out as a file and back in; Save sends only the boxes
 * that changed, checked whole by the server (and by this site's own rule: no other site named). Its keys are
 * the code's - the migrations write them. Who may use it is this site's (translations.config.ts; the server
 * checks it again).
 *
 * What is typed and not saved is kept for the tab (the library's LocalizationDrafts); leaving the page with
 * some asks first - the route's guard asks this page, which asks the library's.
 */
@Component({
  selector: 'app-translations-page',
  imports: [TranslationsPageComponent],
  templateUrl: './translations-page.html',
  styleUrl: './translations-page.scss',
})
export class TranslationsPage implements HoldsUnsavedChanges {
  private readonly page = viewChild.required(TranslationsPageComponent);

  canLeave(): boolean | Promise<boolean> {
    return this.page().canLeave();
  }
}
