import { Component, computed, inject, viewChild } from '@angular/core';
import { Router } from '@angular/router';
import { TopBarComponent as FamilyTopBar, TopBarBrand, TopBarLink, TopBarMenuItem, TopBarPerson } from '@anotoki/lib/shell';
import { AuthService } from '../../../../shared/auth';
import { TranslationKey } from '../../../../shared/i18n/en';
import { TranslatePipe } from '../../../../shared/i18n/translate';
import { TranslationService } from '../../../../shared/i18n/translation';
import { signInOrSayWhy } from '../../../../shared/sign-in';
import { SignOut } from '../../../../shared/sign-out';

/** A section in the bar: its words (a key), where it goes, and the address beginnings that belong to it too (an account and its characters are the dashboard's). */
export interface BarLink {
  key: TranslationKey;
  path: string;
  under: readonly string[];
}

/** Everybody's sections - the same for somebody not signed in, a visitor and a person. */
export const APP_LINKS: readonly BarLink[] = [
  { key: 'topbar.dashboard', path: '/', under: ['/accounts/'] },
  { key: 'topbar.import', path: '/import', under: [] },
  { key: 'topbar.builds', path: '/builds', under: ['/builds/'] },
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
 * The bar across the top of everybody's pages (the admin panel has the family's admin frame instead, with no bar:
 * sites/admin/app.ts): the anotoki family's one bar (@anotoki/lib/shell's <anotoki-top-bar>), told what is this
 * site's - its brand, its sections, who is signed in and what they may do. The family's bar draws the row in the
 * family's order - the brand, the sections, the language (with more than one on offer), the theme switch, and the
 * person's menu (name, address, role; their anotoki account; the way into the admin panel for staff; Sign out) or
 * Sign in - folds into one menu button up to the row's measured width (PHONE_BAR_MAX), and has the family's menus
 * and keys: the focus into a menu as it opens, the arrows, Home and End, Escape back to its button, and Tab or
 * Shift+Tab closing it with the focus on its button - never on <body>.
 *
 * Sign out is the site's one (shared/sign-out.ts), the admin panel's frame's too: it asks first, in the language on
 * the page (the kit's "Are you sure?"), when the Translations or Languages page holds what was typed and not saved.
 *
 * Every member word is a key. The family's own words - the role, "Your anotoki account", "Admin panel", the menus'
 * names, Sign out - are the library's keys (anotoki.topbar.*: migration 010 moved this site's wording of them
 * there), as every anotoki site words them.
 */
@Component({
  selector: 'app-top-bar',
  imports: [FamilyTopBar, TranslatePipe],
  templateUrl: './top-bar.html',
  styleUrl: './top-bar.scss',
})
export class TopBar {
  protected readonly auth = inject(AuthService);
  protected readonly i18n = inject(TranslationService);
  private readonly router = inject(Router);
  private readonly signOutService = inject(SignOut);

  private readonly bar = viewChild(FamilyTopBar);

  /** Where the bar folds: the member pages' row, measured. */
  protected readonly phoneMax = PHONE_BAR_MAX;

  protected readonly brand = computed<TopBarBrand>(() => ({
    area: this.i18n.t('brand.area'),
    short: this.i18n.t('brand.short'),
    link: '/',
    label: this.i18n.t('topbar.home'),
  }));

  /** Everybody's sections. */
  protected readonly links = computed<TopBarLink[]>(() =>
    APP_LINKS.map((link) => ({
      id: link.path,
      label: this.i18n.t(link.key),
      path: link.path,
      under: link.under,
    })),
  );

  /** The language chooser: with a choice to make. */
  protected readonly chooser = computed(() => !this.i18n.englishOnly() && this.i18n.languages().length > 1);

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

  /** Between the person's name and Sign out: their anotoki account (a new tab), and for an editor or an admin the way into the admin panel. */
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
      items.push({
        id: 'admin',
        label: this.i18n.t('anotoki.topbar.admin'),
        icon: 'shield',
        path: '/admin',
      });
    }
    return items;
  });

  /**
   * To the IAM and back to this page, its pages in the language read here; the failure's screen when it cannot
   * start. A promise: the bar's Sign in waits ("Saving…") while a visitor's save on its way gets its answer.
   */
  protected readonly signIn = computed(() => (this.auth.available() ? () => signInOrSayWhy(this.auth, this.router) : null));

  /** The site's one Sign out (shared/sign-out.ts): the menu that asked closes before a question, which then stands alone. */
  protected readonly signOut = (): Promise<void> => this.signOutService.run(() => this.bar()?.closeMenu(false));
}
