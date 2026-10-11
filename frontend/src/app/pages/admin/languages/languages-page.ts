import { Component, viewChild } from '@angular/core';
import { HoldsUnsavedChanges } from '@anotoki/lib/translations';
import { LanguagesPageComponent } from '@anotoki/lib/translations/admin';

/**
 * /admin/languages (ADMIN; English, like the whole admin panel) - the languages the site can be read in: the
 * anotoki family's Languages page (@anotoki/lib/translations/admin, an entry point of its own: it comes with
 * this lazy route, never in the first load), drawn with the library's kit, in this admin panel's frame. Each
 * row's names and place with its own Save; offered or hidden at once; a new language at the end, hidden
 * until somebody switches it on; English always offered, and neither English nor Slovak - the languages the
 * site is released in - ever deleted; a language deleted only after its strings are offered as a file.
 *
 * What is typed and not saved is kept for the tab (the library's LocalizationDrafts); leaving the page with
 * some asks first - the route's guard asks this page, which asks the library's.
 */
@Component({
  selector: 'app-languages-page',
  imports: [LanguagesPageComponent],
  templateUrl: './languages-page.html',
  styleUrl: './languages-page.scss',
})
export class LanguagesPage implements HoldsUnsavedChanges {
  private readonly page = viewChild.required(LanguagesPageComponent);

  canLeave(): boolean | Promise<boolean> {
    return this.page().canLeave();
  }
}
