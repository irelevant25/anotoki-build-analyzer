import {
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  computed,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { MatSnackBar } from '@angular/material/snack-bar';
import { TranslatePipe } from '../../core/i18n/translate';
import { TranslationService } from '../../core/i18n/translation';

/**
 * The language the pages are read in, in the top bar: one round 40 px button with the current language's
 * code ("SK"), opening a menu of the languages on offer, each in the name its own speakers give it and
 * saying which language that name is in (`lang`), so a screen reader says "Slovenčina" as Slovak.
 *
 * A menu button like the bar's own menus (layouts/top-bar): focus onto the language in use when it opens,
 * the arrow keys, Home and End between the items, Escape and a click outside to close it (Escape gives
 * the focus back to the button), and Tab or Shift+Tab close it with the focus on the button - never left
 * to fall to <body> when the menu goes.
 *
 * The bar draws it only where there is a choice to make, and never in the admin panel, which is English.
 */
@Component({
  selector: 'app-language-switcher',
  imports: [MatIconModule, TranslatePipe],
  templateUrl: './language-switcher.html',
  styleUrl: './language-switcher.scss',
  host: {
    '(document:click)': 'onDocumentClick($event)',
    '(document:keydown.escape)': 'close(true)',
  },
})
export class LanguageSwitcher {
  protected readonly i18n = inject(TranslationService);
  private readonly snackBar = inject(MatSnackBar);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);

  protected readonly open = signal(false);
  /** The chosen language's strings are on their way. */
  protected readonly switching = signal(false);
  /** What the button shows ("SK") - and its accessible name says it too (WCAG 2.5.3, Label in Name: somebody who speaks to the computer says "click SK"). */
  protected readonly currentCode = computed(() => this.i18n.language().toUpperCase());
  /** The current language as its speakers write it; its code while the list does not name it. */
  protected readonly currentName = computed(() => {
    const code = this.i18n.language();
    return (
      this.i18n.languages().find((language) => language.code === code)?.native_name ??
      code.toUpperCase()
    );
  });

  /** Counts the choices made: only the last one's answer is acted on. */
  private choice = 0;

  private readonly button = viewChild<ElementRef<HTMLButtonElement>>('button');
  private readonly menu = viewChild<ElementRef<HTMLElement>>('menu');

  protected toggle(): void {
    if (this.open()) {
      this.close(true);
      return;
    }
    this.open.set(true);
    // Into the menu once it is drawn (with no zone, a microtask runs before that), onto the language in use.
    afterNextRender(
      () => {
        const items = this.items();
        (items.find((item) => item.getAttribute('aria-checked') === 'true') ?? items[0])?.focus();
      },
      { injector: this.injector },
    );
  }

  close(returnFocus: boolean): void {
    if (!this.open()) {
      return;
    }
    this.open.set(false);
    if (returnFocus) {
      this.button()?.nativeElement.focus();
    }
  }

  /**
   * Switches once the language's strings are here, and remembers the choice on this device; says so when
   * they could not be had, and stays as it was. The last choice wins: one it overtook comes back false for
   * having been overtaken, and that is nothing to tell anybody.
   */
  protected async choose(code: string): Promise<void> {
    this.close(true);
    if (code === this.i18n.language() && !this.switching()) {
      return;
    }
    const mine = ++this.choice;
    this.switching.set(true);
    const switched = await this.i18n.setLanguage(code);
    if (mine !== this.choice) {
      return;
    }
    this.switching.set(false);
    if (!switched) {
      // A finished sentence when raised, in the language the page is still in; a snack bar is not worded again.
      this.snackBar.open(this.i18n.t('language.notLoaded'), undefined, { duration: 6000 });
    }
  }

  protected onDocumentClick(event: MouseEvent): void {
    if (this.open() && !this.host.nativeElement.contains(event.target as Node)) {
      this.close(false);
    }
  }

  protected onMenuKeydown(event: KeyboardEvent): void {
    const items = this.items();
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
      // Out of the menu from its button: left to the browser, the focus would move to the next item of a
      // menu still drawn (with no zone it goes at the next render) and then fall to <body>.
      event.preventDefault();
      this.close(true);
      return;
    }
    if (next >= 0) {
      event.preventDefault();
      items[next]?.focus();
    }
  }

  private items(): HTMLElement[] {
    const menu = this.menu()?.nativeElement;
    return menu ? Array.from(menu.querySelectorAll<HTMLElement>('[role="menuitemradio"]')) : [];
  }
}
