import { EnvironmentProviders, inject } from '@angular/core';
import { provideAnotokiFeedback } from '@anotoki/lib/feedback';
import { AuthService } from './auth';
import { TranslationService } from './i18n/translation';

/**
 * The places of the site a message can be about: the value the server keeps (App\Admin\AdminTools::
 * FEEDBACK_SECTIONS - the admin panel's Feedback page shows them so), each worded by its key in the language on
 * the page.
 */
export const FEEDBACK_SECTIONS = [
  { value: 'Dashboard', key: 'feedback.section.dashboard' },
  { value: 'Import', key: 'feedback.section.import' },
  { value: 'Accounts', key: 'feedback.section.accounts' },
  { value: 'Builds', key: 'feedback.section.builds' },
] as const;

/**
 * The visitors' feedback form - the anotoki family's (@anotoki/lib/feedback/form, opened from the foot of every
 * page: shared/feedback-dialog) - set up for this site: in the language on the page (English in the admin panel),
 * an owner's rewording of its words (anotoki.feedback.*) from the database, about one of the site's places; for
 * somebody signed in, their account's address is written in as the address for an answer (they may type another)
 * and "send it without my name" is offered - which sends neither (the owner's decision of 2026-10-11: their
 * choice). It goes to POST /api/feedback, which mails nobody: the admin panel counts what is new.
 */
export function provideFeedback(): EnvironmentProviders {
  return provideAnotokiFeedback(() => {
    const i18n = inject(TranslationService);
    const auth = inject(AuthService);
    return {
      language: () => i18n.effectiveLanguage(),
      translate: (key: string) => i18n.libraryWord(key),
      sections: () =>
        FEEDBACK_SECTIONS.map((section) => ({ value: section.value, label: i18n.t(section.key) })),
      signedIn: () => auth.signedIn(),
      email: () => auth.user()?.email ?? null,
    };
  });
}
