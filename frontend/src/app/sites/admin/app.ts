import { Component, computed, effect, inject, untracked } from '@angular/core';
import { Router, RouterLink, RouterOutlet } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { AdminFrameAction, AdminFrameBrand, AdminFrameComponent, AdminFrameLink, AdminFramePerson, SideNavGroup } from '@anotoki/lib/admin/frame';
import { SiteFooterComponent } from '@anotoki/lib/footer';
import { SiteStatus } from '@anotoki/lib/migrations';
import { ShellComponent, ThemeToggleComponent } from '@anotoki/lib/shell';
import { PageNoticeComponent } from '@anotoki/lib/site-settings';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { AuthService } from '../../shared/auth';
import { Changelog } from '../../shared/changelog';
import { feedbackOffered } from '../../shared/feedback';
import { TranslationService } from '../../shared/i18n/translation';
import { signInOrSayWhy } from '../../shared/sign-in';
import { SignOut } from '../../shared/sign-out';

/** A page of the admin panel in the frame's column: its words (English, as the panel is), where it is, its icon (the kit's or the site's, shared/icons.ts). */
export interface PanelPage {
  label: string;
  path: string;
  icon: string;
  /** For ADMIN alone (the server refuses an editor there anyway: 403 admin_only). */
  admin?: boolean;
  /** A count beside it: the feedback nobody has opened yet (the anotoki family's FeedbackUnread). */
  badge?: 'feedback';
}

/** A group of the column's pages, under its heading - the first, the panel's home, under none. */
export interface PanelGroup {
  id: string;
  label?: string;
  pages: readonly PanelPage[];
}

/**
 * The admin panel's pages, in the one shape every anotoki admin panel has (the owner's request of 2026-10-25): the
 * site's own at the top with no heading - Builds, the panel's home (a character's builds editor is under it) -; then
 * Localization - Translations, Languages -; then System, the family's pages in the family's order and words: Site
 * settings, People, Feedback (with the count nobody opened yet), Error log, Audit log, Backups, Migrations. An editor
 * has Builds and Translations; the rest is the ADMIN's (the server refuses anybody else: 403 admin_only).
 */
export const PANEL_GROUPS: readonly PanelGroup[] = [
  { id: 'builds', pages: [{ label: 'Builds', path: '/admin/builds', icon: 'layers' }] },
  {
    id: 'localization',
    label: 'Localization',
    pages: [
      { label: 'Translations', path: '/admin/translations', icon: 'languages' },
      { label: 'Languages', path: '/admin/languages', icon: 'globe', admin: true },
    ],
  },
  {
    id: 'system',
    label: 'System',
    pages: [
      { label: 'Site settings', path: '/admin/site-settings', icon: 'sliders', admin: true },
      { label: 'People', path: '/admin/people', icon: 'users', admin: true },
      { label: 'Feedback', path: '/admin/feedback', icon: 'message', admin: true, badge: 'feedback' },
      { label: 'Error log', path: '/admin/errors', icon: 'alert', admin: true },
      { label: 'Audit log', path: '/admin/audit', icon: 'history', admin: true },
      { label: 'Backups', path: '/admin/backups', icon: 'save', admin: true },
      { label: 'Migrations', path: '/admin/migrations', icon: 'database', admin: true },
    ],
  },
];

/** While a database update waits: the Migrations page alone - the one page of the site that works then, the ADMIN's. */
export const UPDATE_GROUPS: readonly PanelGroup[] = [{ id: 'update', pages: [{ label: 'Migrations', path: '/admin/migrations', icon: 'database', admin: true }] }];

/**
 * The admin panel (`/admin/...`, English): for the people who run the site - EDITOR (the builds files, the builds
 * editor and the game catalog on the Builds page, the site's strings) and ADMIN (also the site settings, database
 * updates on the Migrations page, languages, people, and the anotoki family's admin tools: the error log, the audit
 * log, feedback and backups). Its frame is the anotoki family's admin frame (<anotoki-admin-frame>, @anotoki/lib/admin/frame,
 * anotoki-lib 0.10.0 - the owner's request of 2026-10-24: every admin panel looks like the genshin site's, so it is
 * never mistaken for the site): no top bar - the bar is everybody's pages' (sites/main/common/top-bar). On a computer
 * a column on the left - the panel's name ("Analyzer · admin panel", as its bar said it), a link to Builds; who is
 * signed in; "Back to Build Analyzer"; Sign out (the site's one, shared/sign-out.ts - the bar's too: it asks first
 * about the builds editor's changes and the strings not saved); the theme (the family's switch: the account's theme,
 * as on every page); then the panel's pages, an editor's or the ADMIN's (PANEL_GROUPS: the family's one shape), with
 * the count of new feedback beside Feedback - or, while a database update waits, Migrations alone (UPDATE_GROUPS: the
 * one page of the site that works then; applied, every page again) - and the page on the right, the family's footer
 * under it, in English as the panel is. While the ADMIN has closed the site for maintenance (the site settings), they
 * use it as it is, the family's line above each page saying so (<anotoki-page-notice adminFrameNotice>: in this frame
 * the maintenance line alone - no row of Page access governs /admin). On a phone (up to 860 px, the frame's) a row with
 * the name and Menu, everything of the column in the drawer. Between them, one of three:
 *
 * - staff: the page in the frame;
 * - nobody signed in, with a Sign in button: a session that ended with an admin page open ("You are signed out" -
 *   what was typed on the Translations and Languages pages is kept for the tab);
 * - somebody signed in with no role here: whose the area is, and the way back to the dashboard.
 *
 * The last two are a page of their own, without the frame's column (as the genshin site's page that tells somebody
 * the panel is not theirs): the family's page frame with no bar, the card, the footer.
 *
 * It only hides: every admin route checks the role on the server (403 editor_only / admin_only).
 */
@Component({
  selector: 'app-admin-root',
  imports: [RouterOutlet, RouterLink, AdminFrameComponent, ShellComponent, ThemeToggleComponent, ButtonComponent, CardComponent, IconComponent, SiteFooterComponent, PageNoticeComponent],
  templateUrl: './app.html',
  styleUrl: './app.scss',
  // The panel reads English whatever the reader chose (the translations answer in English under /admin); `.admin`
  // gives the family's pages routed straight into it their frame (styles.scss).
  host: { class: 'admin', lang: 'en' },
})
export class AdminApp {
  protected readonly auth = inject(AuthService);
  protected readonly changelog = inject(Changelog);
  /** Feedback in the footer: while a message can be sent - never on "You are signed out", nor while an update waits. */
  protected readonly feedback = feedbackOffered();
  private readonly i18n = inject(TranslationService);
  private readonly router = inject(Router);
  private readonly unread = inject(FeedbackUnread);
  private readonly signOutService = inject(SignOut);
  /** Where the site stands (the family's SiteStatus): while an update waits, the column has Migrations alone. */
  private readonly siteStatus = inject(SiteStatus);

  /**
   * The panel's name as its bar said it: the site's short name and "admin panel" (brand.short, brand.admin - the
   * site's words, read in English here), a link to its first page: one line of the column (the whole "anotoki Build
   * Analyzer · admin panel" would take three). Reworded longer, it breaks after the dot: each side is held together
   * (no-break spaces).
   */
  protected readonly brand = computed<AdminFrameBrand>(() => ({
    name: `${this.i18n.t('brand.short')}\u00a0· ${this.i18n.t('brand.admin').replace(/ /g, '\u00a0')}`,
    path: '/admin',
  }));

  /** Who is signed in: their name (worded only when the token names nobody) and their address. */
  protected readonly person = computed<AdminFramePerson | null>(() => {
    const user = this.auth.user();
    return user ? { name: this.auth.displayName() ?? this.i18n.t('common.userFallback', { sub: user.sub }), email: user.email } : null;
  });

  /** Out of the panel: back to the rest of the site. */
  protected readonly back = computed<AdminFrameLink>(() => ({ label: this.i18n.t('topbar.backToApp'), path: '/' }));

  /** The site's one Sign out, the top bar's too (shared/sign-out.ts): the column's button waits on it. */
  protected readonly signOut: AdminFrameAction = () => this.signOutService.run();

  /**
   * The pages this editor or admin may open, the count of new feedback beside Feedback for the ADMIN; a group with none
   * of them goes. While a database update waits, the ADMIN's column links Migrations alone.
   */
  protected readonly groups = computed<SideNavGroup[]>(() => {
    const admin = this.auth.isAdmin();
    const unread = admin ? this.unread.count() || null : null;
    const groups = admin && this.siteStatus.state() === 'update-pending' ? UPDATE_GROUPS : PANEL_GROUPS;
    return groups
      .map((group) => ({
        id: group.id,
        label: group.label ?? null,
        links: group.pages
          .filter((page) => !page.admin || admin)
          .map((page) => ({ id: page.path, label: page.label, path: page.path, icon: page.icon, badge: page.badge === 'feedback' ? unread : null })),
      }))
      .filter((group) => group.links.length > 0);
  });

  constructor() {
    // The new feedback, for the count beside Feedback: asked once the ADMIN is in the panel and the site runs for
    // them (no mail is sent for a message - this is how the site's ADMIN hears of one) - never while an update waits,
    // when the column has no Feedback and the server would answer 503; the Feedback page keeps it in step from then on.
    effect(() => {
      if (this.auth.isAdmin() && !this.siteStatus.blocked()) {
        untracked(() => void this.unread.refresh());
      }
    });
  }

  /** To the IAM and back to this page - its pages in the language the person reads the site in. */
  protected signIn(): void {
    void signInOrSayWhy(this.auth, this.router);
  }
}
