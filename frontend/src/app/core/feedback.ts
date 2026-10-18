import { EnvironmentProviders, Signal, computed, inject } from '@angular/core';
import { provideAnotokiFeedback } from '@anotoki/lib/feedback';
import { SiteStatus } from '@anotoki/lib/migrations';
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
 * The visitors' feedback form - the anotoki family's (@anotoki/lib/feedback/form, opened by Feedback in the family's
 * footer of every page, <anotoki-site-footer> in layouts/app-layout and layouts/admin-layout, and by the Contact
 * page) - set up for this site: in the language on the page (English in the admin panel), an owner's rewording of
 * its words (anotoki.feedback.*) from the database, about one of the site's places; for somebody signed in, their
 * account's address is written in as the address for an answer (they may type another) and "send it without my
 * name" is offered - which sends neither (the owner's decision of 2026-10-11: their choice). It goes to
 * POST /api/feedback, which mails nobody: the admin panel counts what is new.
 */
export function provideFeedback(): EnvironmentProviders {
  return provideAnotokiFeedback(() => {
    const i18n = inject(TranslationService);
    const auth = inject(AuthService);
    return {
      language: () => i18n.effectiveLanguage(),
      translate: (key: string) => i18n.libraryWord(key),
      sections: () => FEEDBACK_SECTIONS.map((section) => ({ value: section.value, label: i18n.t(section.key) })),
      signedIn: () => auth.signedIn(),
      email: () => auth.user()?.email ?? null,
    };
  });
}

/**
 * Whether a message can be sent now: Feedback in the footer and the Contact page's form are offered only then
 * (layouts/app-layout, layouts/admin-layout, core/legal-facts.ts). Call it in an injection context.
 *
 * The form's request says nothing of whom it is for, so the auth module's interceptor sends it as the page is
 * shown: with the person's token while somebody is signed in, and as the visitor's while the page is shown to one
 * (AuthService.visiting) - the server takes a message from anybody while the IAM lets visitors in here
 * (POST /api/feedback behind optionalSignIn()), and from a signed-in person only while it requires a sign-in. So
 * never to somebody signed out while the IAM requires signing in (a legal page, the sign-in failure's screen, the
 * admin panel's "You are signed out"): their request would go as a person's with no token - never sent, "Sign in
 * to send a message" -, the page following the session that ended. Nor while the site is not ready (the ADMIN on the
 * Migrations page while an update waits): the site gate answers it 503.
 */
export function feedbackOffered(): Signal<boolean> {
  const auth = inject(AuthService);
  const site = inject(SiteStatus);
  return computed(() => (auth.signedIn() || auth.visiting()) && !site.blocked());
}
