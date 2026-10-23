import { SiteNote } from '../models';
import { Worded } from './translation';

/**
 * A note of the site's own - a build's, a team's (builds.site_notes, written in the admin panel's builds editor) - as
 * a page shows it: in the language on the page where an editor wrote it, else in English, which every note has -
 * marked `lang="en"` while the page is in another language. Null for no note.
 *
 *   @if (noteText(build.notes); as note) { <p [attr.lang]="note.lang">{{ note.text }}</p> }
 *
 * `language` is the language on the page (TranslationService.effectiveLanguage()): read inside a template or a
 * computed(), the note follows the language.
 */
export function siteNoteText(note: SiteNote | null | undefined, language: string): Worded | null {
  if (!note) {
    return null;
  }
  const own = language === 'en' ? undefined : (note as unknown as Record<string, string | undefined>)[language];
  if (own) {
    return { text: own, lang: null };
  }
  return { text: note.en, lang: language === 'en' ? null : 'en' };
}
