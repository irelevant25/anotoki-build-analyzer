import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { feedbackWords } from '@anotoki/lib/feedback';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../core/auth';
import { Changelog } from '../../core/changelog';
import { provideFeedback } from '../../core/feedback';
import { TranslationService } from '../../core/i18n/translation';
import { provideLegal } from '../../core/legal';
import { Footer } from './footer';

const ENGLISH = {
  'footer.version': 'Version {version}',
  'whatsNew.title': "What's new",
  'footer.feedback': 'Feedback',
  'footer.fanMade':
    'This site is a fan-made project and is not affiliated with or endorsed by HoYoverse.',
};
const SLOVAK = {
  'footer.version': 'Verzia {version}',
  'whatsNew.title': 'Čo je nové',
  'footer.feedback': 'Spätná väzba',
  'footer.fanMade':
    'Táto stránka je fanúšikovský projekt a nie je prepojená so spoločnosťou HoYoverse ani ňou schválená.',
};

describe('the footer', () => {
  async function draw(
    version: string | null,
    address = '/',
  ): Promise<{
    host: HTMLElement;
    open: ReturnType<typeof vi.fn>;
    settle: () => Promise<void>;
  }> {
    const open = vi.fn();
    TestBed.configureTestingModule({
      // The footer's feedback dialog is a @defer block: its code is resolved before the footer is made.
      imports: [Footer],
      providers: [
        provideRouter([]),
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideFeedback(),
        provideLegal(),
        { provide: AuthService, useValue: { signedIn: signal(false), user: signal(null) } },
        { provide: Changelog, useValue: { current: signal(version), open } },
      ],
    });
    await TestBed.compileComponents();
    TestBed.inject(TranslationService).noteAddress(address);
    const fixture = TestBed.createComponent(Footer);
    await fixture.whenStable();
    return { host: fixture.nativeElement, open, settle: () => fixture.whenStable() };
  }

  /** The legal links as they are drawn: [the words, where each goes]. */
  const legalLinks = (host: HTMLElement): string[][] =>
    [...host.querySelectorAll<HTMLAnchorElement>('anotoki-legal-links a')].map((link) => [
      link.textContent?.trim() ?? '',
      link.getAttribute('href') ?? '',
    ]);

  it("says the version and opens What's new - in the reader's language", async () => {
    const { host, open } = await draw('0.3');
    expect(host.querySelector('.version')?.textContent?.trim()).toBe('Verzia 0.3');
    const button = host.querySelector<HTMLButtonElement>('.whats-new')!;
    expect(button.textContent?.trim()).toBe('Čo je nové');
    expect(button.classList).toContain('anotoki-button--link');
    button.click();
    expect(open).toHaveBeenCalledWith();
  });

  it("opens the family's feedback form in the kit's dialog - its code loaded with the first press, not before - and takes it off the page when it closes", async () => {
    const { host, settle } = await draw('0.3');
    const button = host.querySelector<HTMLButtonElement>('.feedback')!;
    expect(button.textContent?.trim()).toBe('Spätná väzba');
    expect(button.getAttribute('aria-haspopup')).toBe('dialog');
    expect(host.querySelector('app-feedback-dialog')).toBeNull();

    button.click();
    await vi.waitFor(async () => {
      await settle();
      expect(
        host.querySelector('app-feedback-dialog anotoki-dialog anotoki-feedback-form'),
      ).not.toBeNull();
    });
    // Titled with the form's own words, in the language they are in; the form draws no heading of its own.
    const said = feedbackWords('sk');
    expect(host.querySelector('.anotoki-dialog__title')?.textContent?.trim()).toBe(
      said.words.title,
    );
    expect(host.querySelector('app-feedback-dialog anotoki-dialog')?.getAttribute('lang')).toBe(
      'sk',
    );
    expect(host.querySelector('anotoki-feedback-form .anotoki-feedback-form__title')).toBeNull();

    // Its Cancel closes it.
    host.querySelector<HTMLButtonElement>('anotoki-feedback-form [data-cancel]')!.click();
    await settle();
    expect(host.querySelector('app-feedback-dialog')).toBeNull();
  });

  it("puts Terms · Privacy · Contact under every page - the family's links to the legal pages, in the reader's language - and the fan clause", async () => {
    const { host } = await draw('0.3');
    const nav = host.querySelector('anotoki-legal-links nav')!;
    expect(nav.getAttribute('aria-label')).toBe('Právne informácie');
    expect(legalLinks(host)).toEqual([
      ['Podmienky', '/terms'],
      ['Súkromie', '/privacy'],
      ['Kontakt', '/contact'],
    ]);
    expect(host.querySelector('.footer__fan')?.textContent?.trim()).toBe(SLOVAK['footer.fanMade']);
    // Under the version's row, not in it.
    expect(host.querySelector('.footer__row anotoki-legal-links')).toBeNull();
  });

  it('reads English in the admin panel', async () => {
    const { host } = await draw('0.3', '/admin/sources');
    expect(
      [...host.querySelectorAll('.footer__row > *')].map((part) => part.textContent?.trim()),
    ).toEqual(['Version 0.3', '·', "What's new", '·', 'Feedback']);
    expect(legalLinks(host).map(([words]) => words)).toEqual(['Terms', 'Privacy', 'Contact']);
    expect(host.querySelector('.footer__fan')?.textContent?.trim()).toBe(ENGLISH['footer.fanMade']);
  });

  it("has Feedback alone without a changelog (a broken upload): no version, no What's new", async () => {
    const { host } = await draw(null);
    expect(
      [...host.querySelectorAll('.footer__row > *')].map((part) => part.textContent?.trim()),
    ).toEqual(['Spätná väzba']);
    expect(legalLinks(host)).toHaveLength(3);
  });
});
