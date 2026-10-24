import { Injectable, Injector, inject } from '@angular/core';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { AuthService } from './auth';
import { BuildEditorChanges } from './build-editor-changes';
import { TranslationService } from './i18n/translation';
import { localizationDrafts } from './localization-drafts';

/**
 * The site's one Sign out: the public site's top bar takes it (sites/main/common/top-bar), and so does the admin
 * panel's frame (sites/admin/app.ts, the anotoki family's <anotoki-admin-frame>) - whichever button is pressed, the
 * same question and the same way out.
 */
@Injectable({ providedIn: 'root' })
export class SignOut {
  private readonly auth = inject(AuthService);
  private readonly i18n = inject(TranslationService);
  private readonly confirm = inject(ConfirmService);
  private readonly injector = inject(Injector);
  private readonly editorChanges = inject(BuildEditorChanges);

  /**
   * Signs out everywhere (the IAM ends its session). What is typed on the Translations and Languages pages and
   * not saved is asked about first, in one question in the language on the page: a sign-out asked for here gives
   * it up (the next person at this browser starts clean), where a session that ends by itself keeps it for the
   * person's return (the library's LocalizationDrafts, reached only when this tab holds some:
   * shared/localization-drafts.ts). So are the changes the builds editor holds and has not saved, in the admin
   * panel's English (shared/build-editor-changes.ts): signed out, they are lost.
   *
   * `beforeAsking` runs just before the question, when there is one: the top bar closes its menu then, so that the
   * question stands alone. A promise, so the button that asked waits ("Saving…" in the admin panel's frame) until
   * the sign-out is done.
   */
  async run(beforeAsking?: () => void): Promise<void> {
    const drafts = await localizationDrafts(this.injector);
    if (this.editorChanges.unsaved()) {
      // The builds editor is the admin panel's, which reads English: so are its words. One question for both.
      beforeAsking?.();
      const strings = drafts !== null && drafts.count() > 0;
      const sure = await this.confirm.ask({
        title: 'Sign out without saving?',
        message: strings
          ? 'The builds editor holds changes that are not saved, and so do the Translations or Languages pages. Signed out, they are lost.'
          : 'The builds editor holds changes that are not saved. Signed out, they are lost.',
        confirmLabel: 'Sign out anyway',
        cancelLabel: 'Stay',
        tone: 'danger',
      });
      if (!sure) {
        return;
      }
      this.editorChanges.giveUp();
      drafts?.clear();
      await this.auth.logout();
      return;
    }
    if (drafts && drafts.count() > 0) {
      // The question stands alone: the menu that asked goes first.
      beforeAsking?.();
      // Finished sentences when asked; a question is not worded again.
      const sure = await this.confirm.ask({
        title: this.i18n.t('topbar.signOutUnsaved.title'),
        message: this.i18n.t('topbar.signOutUnsaved.message'),
        confirmLabel: this.i18n.t('topbar.signOutUnsaved.confirm'),
        cancelLabel: this.i18n.t('topbar.signOutUnsaved.stay'),
        tone: 'danger',
      });
      if (!sure) {
        return;
      }
      drafts.clear();
    }
    await this.auth.logout();
  }
}
