import { Component, Injector, computed, inject, input, viewChild } from '@angular/core';
import { Router } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { TopBarComponent as FamilyTopBar, TopBarBrand, TopBarLink, TopBarMenuItem, TopBarPerson, TopBarPhoneMark } from '@anotoki/lib/shell';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { AuthService } from '../../core/auth';
import { TranslationKey } from '../../core/i18n/en';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslationService } from '../../core/i18n/translation';
import { localizationDrafts } from '../../core/localization-drafts';
import { signInOrSayWhy } from '../../core/sign-in';

/**
 * A section in the bar: its words, where it goes, and the address beginnings that belong to it too (an
 * account and its characters are the dashboard's). A member section's words are a key; the admin panel's
 * are English, written here, like the panel itself.
 */
export interface BarLink {
  key?: TranslationKey;
  label?: string;
  path: string;
  under: readonly string[];
  /** For ADMIN alone (the server refuses EDITOR there anyway). */
  admin?: boolean;
  /** A count beside it: the feedback nobody has opened yet (the anotoki family's FeedbackUnread). */
  badge?: 'feedback';
}

/** Everybody's sections - the same for somebody not signed in, a visitor and a person. */
export const APP_LINKS: readonly BarLink[] = [
  { key: 'topbar.dashboard', path: '/', under: ['/accounts/'] },
  { key: 'topbar.import', path: '/import', under: [] },
  { key: 'topbar.builds', path: '/builds', under: ['/builds/'] },
];

/**
 * The admin panel's sections (English). Languages, People, Migrations and the anotoki family's admin tools -
 * the error log, the audit log, feedback (with the count of new messages) and backups - are ADMIN's.
 */
export const ADMIN_LINKS: readonly BarLink[] = [
  { label: 'Builds', path: '/admin/builds', under: [] },
  { label: 'Translations', path: '/admin/translations', under: [] },
  { label: 'Languages', path: '/admin/languages', under: [], admin: true },
  { label: 'People', path: '/admin/people', under: [], admin: true },
  { label: 'Migrations', path: '/admin/migrations', under: [], admin: true },
  { label: 'Errors', path: '/admin/errors', under: [], admin: true },
  { label: 'Audit', path: '/admin/audit', under: [], admin: true },
  { label: 'Feedback', path: '/admin/feedback', under: [], admin: true, badge: 'feedback' },
  { label: 'Backups', path: '/admin/backups', under: [], admin: true },
];

/**
 * Up to this width the bar is a phone's: the links and the person's menu fold into one menu button. This
 * site's own number, measured in the browser with the real labels in the family's bar (verify-ui's run.mjs
 * --measure): the row without the person's name needs 793 px in English and 803 px in Slovak signed out - and
 * room for a scrollbar, and for a language added on the Languages page whose words run longer. Above it the whole
 * row fits and only the person's name gives way; it shows from PHONE_BAR_MAX + 201 (the family's bar's own rule).
 */
export const PHONE_BAR_MAX = 880;

/**
 * The same for the admin panel, whose row is longer: the ADMIN has nine sections - the anotoki family's admin
 * tools among them, the count of new feedback beside Feedback. Its brand is the family's "anotoki Build Analyzer
 * · admin panel", short at every width there ("Analyzer" with "admin panel" under it, ADMIN_SHORT_BRAND_MAX): the
 * whole wordmark does not fit beside nine sections in the family's row (84rem). Measured as above (the panel
 * reads English whichever language is chosen): 1171 px with a count beside Feedback - and room for a scrollbar and
 * a count of two digits. A 1280 px screen has the whole row.
 */
export const ADMIN_PHONE_BAR_MAX = 1270;

/**
 * The admin panel's row for an editor, who has two sections (Builds, Translations): 545 px measured as above - and
 * room for a scrollbar.
 */
export const EDITOR_PHONE_BAR_MAX = 640;

/** No screen is this wide: in the admin panel the brand is the short one at every width (the bar's shortBrandMax). */
export const ADMIN_SHORT_BRAND_MAX = 100000;

/**
 * The bar across the top of every page: the anotoki family's one bar (@anotoki/lib/shell's <anotoki-top-bar>),
 * told what is this site's - its brand, its sections, who is signed in and what they may do. The family's bar draws
 * the row in the family's order - the brand, the sections, the language (where members read, with more than one on
 * offer; never in the admin panel, which is English), the theme switch, and the person's menu (name, address, role;
 * their anotoki account; the way into the admin panel for staff, or back from it; Sign out) or Sign in - folds into
 * one menu button up to the row's measured width (PHONE_BAR_MAX, and the admin panel's), and has the family's menus
 * and keys: the focus into a menu as it opens, the arrows, Home and End, Escape back to its button, and Tab or
 * Shift+Tab closing it with the focus on its button - never on <body>.
 *
 * On a phone the count of new feedback is a badge on the menu button in the admin panel. Signing out asks first, in
 * the language on the page (the kit's "Are you sure?"), when the Translations or Languages page holds what was typed
 * and not saved: signed out, it is lost.
 *
 * Every member word is a key; under /admin the translation service answers in English (the library's English
 * areas), so the same keys read English there. The family's own words - the role, "Your anotoki account",
 * "Admin panel", the menus' names, Sign out - are the library's keys (anotoki.topbar.*: migration 010 moved this
 * site's wording of them there), as every anotoki site words them.
 */
@Component({
  selector: 'app-top-bar',
  imports: [FamilyTopBar, TranslatePipe],
  templateUrl: './top-bar.html',
  styleUrl: './top-bar.scss',
})
export class TopBar {
  /** Which part of the site the page is in: where the brand leads, the links, and which way the Admin item points. */
  readonly area = input<'app' | 'admin'>('app');

  protected readonly auth = inject(AuthService);
  protected readonly i18n = inject(TranslationService);
  private readonly router = inject(Router);
  private readonly unread = inject(FeedbackUnread);
  private readonly confirm = inject(ConfirmService);
  private readonly injector = inject(Injector);

  private readonly bar = viewChild(FamilyTopBar);

  /** Where the bar folds: each row has its own width - the member pages', and the admin panel's (the ADMIN's nine sections, or an editor's two). */
  protected readonly phoneMax = computed(() => (this.area() === 'admin' ? (this.auth.isAdmin() ? ADMIN_PHONE_BAR_MAX : EDITOR_PHONE_BAR_MAX) : PHONE_BAR_MAX));
  /** Up to where the brand is the short one: the family's default (the fold) on the member pages, always in the admin panel. */
  protected readonly shortBrandMax = computed(() => (this.area() === 'admin' ? ADMIN_SHORT_BRAND_MAX : null));

  protected readonly brand = computed<TopBarBrand>(() =>
    this.area() === 'admin'
      ? {
          area: this.i18n.t('brand.area'),
          short: this.i18n.t('brand.short'),
          panel: this.i18n.t('brand.admin'),
          link: '/admin',
          label: this.i18n.t('topbar.homeAdmin'),
        }
      : {
          area: this.i18n.t('brand.area'),
          short: this.i18n.t('brand.short'),
          link: '/',
          label: this.i18n.t('topbar.home'),
        },
  );

  /** The feedback nobody has opened yet - beside Feedback, for the site's ADMIN; none when there is none (or no answer). */
  private readonly newFeedback = computed(() => (this.auth.isAdmin() ? this.unread.count() || null : null));

  /** The area's sections: everybody's in the app; in the admin panel the ones this editor or admin may open, none for anybody else. */
  protected readonly links = computed<TopBarLink[]>(() => {
    const links = this.area() === 'admin' ? (this.auth.isStaff() ? ADMIN_LINKS.filter((link) => !link.admin || this.auth.isAdmin()) : []) : APP_LINKS;
    return links.map((link) => ({
      id: link.path,
      label: link.key ? this.i18n.t(link.key) : (link.label ?? ''),
      path: link.path,
      under: link.under,
      badge: link.badge === 'feedback' ? this.newFeedback() : null,
    }));
  });

  /** The language chooser: where members read, with a choice to make. */
  protected readonly chooser = computed(() => this.area() === 'app' && !this.i18n.englishOnly() && this.i18n.languages().length > 1);

  /** Who is signed in: their name (worded only when the token names nobody), the address when it is not the name, the role. */
  protected readonly person = computed<TopBarPerson | null>(() => {
    const user = this.auth.user();
    if (!user) {
      return null;
    }
    const name = this.auth.displayName() ?? this.i18n.t('common.userFallback', { sub: user.sub });
    return {
      name,
      email: user.email && user.email !== name ? user.email : null,
      role: this.auth.isAdmin() ? this.i18n.t('anotoki.topbar.roleAdmin') : this.auth.isStaff() ? this.i18n.t('anotoki.topbar.roleEditor') : null,
    };
  });

  /**
   * Between the person's name and Sign out: their anotoki account (a new tab), and for an editor or an admin the way
   * into the admin panel, or back from it.
   */
  protected readonly menuItems = computed<TopBarMenuItem[]>(() => {
    const items: TopBarMenuItem[] = [];
    const account = this.auth.user() ? this.auth.accountUrl() : null;
    if (account) {
      items.push({
        id: 'account',
        label: this.i18n.t('anotoki.topbar.account'),
        icon: 'user',
        href: account,
        newTab: true,
      });
    }
    if (this.auth.isStaff()) {
      items.push(
        this.area() === 'admin'
          ? { id: 'back', label: this.i18n.t('topbar.backToApp'), icon: 'arrowLeft', path: '/' }
          : {
              id: 'admin',
              label: this.i18n.t('anotoki.topbar.admin'),
              icon: 'shield',
              path: '/admin',
            },
      );
    }
    return items;
  });

  /** On a phone's menu button: the count of new feedback in the admin panel. */
  protected readonly phoneMark = computed<TopBarPhoneMark | null>(() => {
    const count = this.area() === 'admin' ? this.newFeedback() : null;
    return count ? { badge: count, label: `Menu: ${count} new feedback` } : null;
  });

  /**
   * To the IAM and back to this page, its pages in the language read here; the failure's screen when it cannot
   * start. A promise: the bar's Sign in waits ("Saving…") while a visitor's save on its way gets its answer.
   */
  protected readonly signIn = computed(() => (this.auth.available() ? () => signInOrSayWhy(this.auth, this.router) : null));

  /**
   * Signs out everywhere (the IAM ends its session). What is typed on the Translations and Languages pages and
   * not saved is asked about first, in one question in the language on the page: a sign-out asked for here gives
   * it up (the next person at this browser starts clean), where a session that ends by itself keeps it for the
   * person's return (the library's LocalizationDrafts, reached only when this tab holds some:
   * core/localization-drafts.ts).
   */
  protected readonly signOut = async (): Promise<void> => {
    const drafts = await localizationDrafts(this.injector);
    if (drafts && drafts.count() > 0) {
      // The question stands alone: the menu that asked goes first.
      this.bar()?.closeMenu(false);
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
  };
}
