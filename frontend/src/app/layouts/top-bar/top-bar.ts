import { NgTemplateOutlet } from '@angular/common';
import {
  Component,
  DestroyRef,
  ElementRef,
  Injector,
  Signal,
  afterNextRender,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatTooltipModule } from '@angular/material/tooltip';
import { NavigationEnd, Router, RouterLink } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { filter, map } from 'rxjs';
import { AuthService } from '../../core/auth';
import { TranslationKey } from '../../core/i18n/en';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslationService } from '../../core/i18n/translation';
import { localizationDrafts } from '../../core/localization-drafts';
import { signInOrSayWhy } from '../../core/sign-in';
import { SyncDriver } from '../../core/sync-driver';
import { Brand } from '../../shared/brand/brand';
import { askConfirm } from '../../shared/confirm-dialog';
import { LanguageSwitcher } from '../../shared/language-switcher/language-switcher';
import { ThemeToggle } from '../../shared/theme-toggle/theme-toggle';

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
  { label: 'Sources', path: '/admin/sources', under: [] },
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
 * site's own number, measured in the browser with the real labels (verify-ui's run.mjs --measure): the row
 * without the person's name needs 772 px in English and 781 px in Slovak signed out, and 799 px for an
 * admin with a sync running. Above 880 px the whole row fits and only the person's name gives way (an
 * ellipsis); it shows from 1081 px (PHONE_BAR_MAX + 200).
 */
export const PHONE_BAR_MAX = 880;

/**
 * The same for the admin panel, whose row is longer: the ADMIN has nine sections - the anotoki family's admin
 * tools among them, the count of new feedback beside Feedback. Its brand is short for it, as the IAM's -
 * "anotoki admin panel" (`brand.admin`), the app's name in the brand link's accessible name
 * (`topbar.homeAdmin`). Measured as above (the panel reads English whichever language is chosen): 1244 px
 * for the ADMIN with a count beside Feedback, 1294 px with a sync running - and room for a scrollbar: a 1366 px
 * screen has the whole row, a 1280 px one the menu button. The person's name shows from 1521 px
 * (ADMIN_PHONE_BAR_MAX + 200).
 */
export const ADMIN_PHONE_BAR_MAX = 1320;

/**
 * The admin panel's row for an editor, who has two sections (Sources, Translations): measured as above, 635 px,
 * 685 px with a sync running - and room for a scrollbar. The person's name shows from 921 px
 * (EDITOR_PHONE_BAR_MAX + 200).
 */
export const EDITOR_PHONE_BAR_MAX = 720;

/** Which of the bar's two menus is open: the person's (account) or the phone's (links and account). */
type OpenMenu = 'account' | 'phone' | null;

/**
 * The bar across the top of every page - the anotoki family's one bar (topbar-spec), in one row and this
 * order: the brand (the mark, "anotoki" and the app's name, leading home); the area's sections; then - for
 * an editor or an admin while this tab drives a sync - the sync's spinner, the language (where members
 * read, with more than one on offer; never in the admin panel, which is English), the theme, and the
 * person's menu (name, email, role; their anotoki account; the way into the admin panel for staff, or back
 * from it; Sign out) - or Sign in.
 *
 * On a phone (up to PHONE_BAR_MAX) the brand is the mark and the app's short name, and the sections, the
 * sync and the person's menu (or Sign in) are inside one menu button after the language and the theme.
 *
 * The menus are this app's own markup, not mat-menu: mat-menu closes on Tab and gives the focus back to
 * its trigger, but leaves the Tab to the browser, which then moves the focus past the trigger (a spec test
 * of exactly that decided it). Here, as in the survey's bar: the focus goes into a menu when it opens, the
 * arrow keys, Home and End move between its items, Escape and a click outside close it (Escape back to the
 * button), and Tab or Shift+Tab from any item close it with the focus on its button - the browser's own
 * move is stopped, so the next Tab moves on from there and the focus never falls to <body>. A change
 * across the breakpoint closes what is open.
 *
 * Every member word is a key; under /admin the translation service answers in English (englishOnly), so
 * the same keys read English there with no case here.
 */
@Component({
  selector: 'app-top-bar',
  imports: [
    NgTemplateOutlet,
    RouterLink,
    MatButtonModule,
    MatIconModule,
    MatTooltipModule,
    Brand,
    LanguageSwitcher,
    ThemeToggle,
    TranslatePipe,
  ],
  templateUrl: './top-bar.html',
  styleUrl: './top-bar.scss',
  host: {
    '(document:click)': 'onDocumentClick($event)',
    '(document:keydown.escape)': 'closeMenu(true)',
  },
})
export class TopBar {
  /** Which part of the site the page is in: where the brand leads, the links, and which way the Admin item points. */
  readonly area = input<'app' | 'admin'>('app');
  /** A narrower row, for pages whose content is narrow too. */
  readonly width = input<'wide' | 'narrow'>('wide');

  protected readonly auth = inject(AuthService);
  protected readonly i18n = inject(TranslationService);
  protected readonly sync = inject(SyncDriver);
  private readonly router = inject(Router);
  private readonly dialog = inject(MatDialog);
  private readonly unread = inject(FeedbackUnread);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);

  protected readonly openMenu = signal<OpenMenu>(null);
  protected readonly signingOut = signal(false);
  private readonly appPhone = this.matches(`(max-width: ${PHONE_BAR_MAX}px)`);
  private readonly adminPhone = this.matches(`(max-width: ${ADMIN_PHONE_BAR_MAX}px)`);
  private readonly editorPhone = this.matches(`(max-width: ${EDITOR_PHONE_BAR_MAX}px)`);
  private readonly appNameless = this.matches(`(max-width: ${PHONE_BAR_MAX + 200}px)`);
  private readonly adminNameless = this.matches(`(max-width: ${ADMIN_PHONE_BAR_MAX + 200}px)`);
  private readonly editorNameless = this.matches(`(max-width: ${EDITOR_PHONE_BAR_MAX + 200}px)`);
  /**
   * The bar of a phone: the links and the person's menu inside the menu button. Each row has its own width: the
   * member pages', and the admin panel's - the ADMIN's nine sections, or an editor's two.
   */
  protected readonly phone = computed(() =>
    this.area() === 'admin'
      ? this.auth.isAdmin()
        ? this.adminPhone()
        : this.editorPhone()
      : this.appPhone(),
  );
  /** A row wide enough for the links but not for the person's name as well: the round button alone. */
  protected readonly nameless = computed(() =>
    this.area() === 'admin'
      ? this.auth.isAdmin()
        ? this.adminNameless()
        : this.editorNameless()
      : this.appNameless(),
  );

  /** The area's sections: everybody's in the app; in the admin panel the ones this editor or admin may open, none for anybody else. */
  protected readonly links = computed(() =>
    this.area() === 'admin'
      ? this.auth.isStaff()
        ? ADMIN_LINKS.filter((link) => !link.admin || this.auth.isAdmin())
        : []
      : APP_LINKS,
  );
  /** The language chooser: where members read, with a choice to make. */
  protected readonly chooser = computed(
    () => this.area() === 'app' && !this.i18n.englishOnly() && this.i18n.languages().length > 1,
  );
  /** The sync this tab drives, for the people who may run one. */
  protected readonly syncing = computed(() => this.auth.isStaff() && this.sync.driving());
  /** The feedback nobody has opened yet - beside Feedback, for the site's ADMIN; none when there is none (or no answer). */
  protected readonly newFeedback = computed(() =>
    this.auth.isAdmin() ? this.unread.count() || null : null,
  );
  /** The person's email address, when it is not already their name. */
  protected readonly email = computed(() => {
    const user = this.auth.user();
    return user?.email && user.email !== this.auth.displayName() ? user.email : null;
  });
  protected readonly initial = computed(() =>
    (this.auth.displayName()?.trim()[0] ?? '?').toUpperCase(),
  );

  /** The address of the page, without its query and hash: which link is the page's. */
  private readonly path = toSignal(
    this.router.events.pipe(
      filter((event) => event instanceof NavigationEnd),
      map(() => this.router.url),
    ),
    { initialValue: this.router.url },
  );
  private readonly here = computed(() => this.path().split(/[?#]/)[0] || '/');

  private readonly accountButton = viewChild<ElementRef<HTMLButtonElement>>('accountButton');
  private readonly phoneButton = viewChild<ElementRef<HTMLButtonElement>>('phoneButton');
  private readonly menu = viewChild<ElementRef<HTMLElement>>('menu');

  constructor() {
    // From a phone's bar to a desktop's (a turned tablet, a wider window): the open menu would belong to neither.
    effect(() => {
      this.phone();
      untracked(() => this.closeMenu(false));
    });
  }

  /** The page is this link's own address. */
  protected isPage(link: BarLink): boolean {
    return this.here() === link.path;
  }

  /** The count beside a link: the new feedback beside Feedback; null for none. */
  protected badgeOf(link: BarLink): number | null {
    return link.badge === 'feedback' ? this.newFeedback() : null;
  }

  /** The page is in this link's section: its own address or one under it. */
  protected inSection(link: BarLink): boolean {
    const here = this.here();
    return here === link.path || link.under.some((start) => here.startsWith(start));
  }

  /** To the IAM and back to this page, its pages in the language read here; the failure's screen when it cannot start. */
  protected signIn(): void {
    this.closeMenu(false);
    void signInOrSayWhy(this.auth, this.router);
  }

  protected toggleMenu(which: 'account' | 'phone'): void {
    if (this.openMenu() === which) {
      this.closeMenu(true);
      return;
    }
    this.openMenu.set(which);
    // Into the menu once it is drawn, as a menu button is expected to do. Drawn means after the next
    // render: with no zone, a microtask runs before it.
    afterNextRender(() => this.menuItems()[0]?.focus(), { injector: this.injector });
  }

  /** Closes the open menu; `returnFocus`: back to the button that opened it (Escape, Tab, the button again). */
  closeMenu(returnFocus: boolean): void {
    const open = this.openMenu();
    if (!open) {
      return;
    }
    this.openMenu.set(null);
    if (returnFocus) {
      (open === 'account' ? this.accountButton() : this.phoneButton())?.nativeElement.focus();
    }
  }

  protected onDocumentClick(event: MouseEvent): void {
    const open = this.openMenu();
    if (!open) {
      return;
    }
    const root = this.host.nativeElement.querySelector(
      open === 'account' ? '.user-menu' : '.phone-menu',
    );
    if (!root || !root.contains(event.target as Node)) {
      this.closeMenu(false);
    }
  }

  protected onMenuKeydown(event: KeyboardEvent): void {
    const items = this.menuItems();
    const index = items.indexOf(document.activeElement as HTMLElement);
    let next = -1;
    if (event.key === 'ArrowDown') {
      next = (index + 1) % items.length;
    } else if (event.key === 'ArrowUp') {
      next = (index - 1 + items.length) % items.length;
    } else if (event.key === 'Home') {
      next = 0;
    } else if (event.key === 'End') {
      next = items.length - 1;
    } else if (event.key === 'Tab') {
      // Tab (or Shift+Tab) leaves the menu from its button. Left to the browser, the focus would move to
      // the next item of a menu still drawn - with no zone it is removed only at the next render - and
      // then fall to <body>.
      event.preventDefault();
      this.closeMenu(true);
      return;
    }
    if (next >= 0) {
      event.preventDefault();
      items[next]?.focus();
    }
  }

  /**
   * Signs out everywhere (the IAM ends its session). What is typed on the Translations and Languages pages
   * and not saved is asked about first, in one question in the language on the page: a sign-out asked for
   * here gives it up (the next person at this browser starts clean), where a session that ends by itself
   * keeps it for the person's return (the library's LocalizationDrafts, reached only when this tab holds some:
   * core/localization-drafts.ts).
   */
  protected async signOut(): Promise<void> {
    this.signingOut.set(true);
    const drafts = await localizationDrafts(this.injector);
    if (drafts && drafts.count() > 0) {
      this.closeMenu(false);
      // Finished sentences when asked; a question is not worded again.
      const sure = await askConfirm(this.dialog, {
        title: this.i18n.t('topbar.signOutUnsaved.title'),
        message: this.i18n.t('topbar.signOutUnsaved.message'),
        confirmLabel: this.i18n.t('topbar.signOutUnsaved.confirm'),
        cancelLabel: this.i18n.t('topbar.signOutUnsaved.stay'),
        danger: true,
      });
      if (!sure) {
        this.signingOut.set(false);
        return;
      }
      drafts.clear();
    }
    await this.auth.logout();
  }

  /** Whether the screen matches a media query, kept current while the bar is there. */
  private matches(media: string): Signal<boolean> {
    const matches = signal(false);
    const query = globalThis.matchMedia?.(media);
    if (query) {
      const update = () => matches.set(query.matches);
      update();
      query.addEventListener('change', update);
      inject(DestroyRef).onDestroy(() => query.removeEventListener('change', update));
    }
    return matches.asReadonly();
  }

  private menuItems(): HTMLElement[] {
    const menu = this.menu()?.nativeElement;
    return menu ? Array.from(menu.querySelectorAll<HTMLElement>('[role="menuitem"]')) : [];
  }
}
