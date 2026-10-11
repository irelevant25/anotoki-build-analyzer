import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ANOTOKI_FEEDBACK_CONFIG, feedbackWords } from '@anotoki/lib/feedback';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../core/auth';
import { FEEDBACK_SECTIONS, provideFeedback } from '../../core/feedback';
import { en } from '../../core/i18n/en';
import { FeedbackDialog } from './feedback-dialog';

const SLOVAK = {
  'feedback.section.dashboard': 'Prehľad',
  'feedback.section.import': 'Import účtu',
  'feedback.section.accounts': 'Tvoje účty a postavy',
  'feedback.section.builds': 'Buildy',
};

describe('the feedback form in a dialog (the foot of every page)', () => {
  let fixture: ComponentFixture<FeedbackDialog>;
  let host: HTMLElement;
  let requests: HttpTestingController;
  let closed: ReturnType<typeof vi.fn<() => void>>;

  async function open(
    who: { email: string } | null = null,
    language: 'sk' | 'en' = 'sk',
  ): Promise<void> {
    closed = vi.fn<() => void>();
    TestBed.configureTestingModule({
      providers: [
        language === 'sk'
          ? provideTestTranslations(SLOVAK, { language: 'sk', english: en })
          : provideTestTranslations(en),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideFeedback(),
        {
          provide: AuthService,
          useValue: {
            signedIn: signal(who !== null),
            user: signal(who === null ? null : { sub: '7', ...who }),
          },
        },
      ],
    });
    requests = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(FeedbackDialog);
    fixture.componentInstance.closed.subscribe(() => closed());
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  afterEach(() => requests.verify());

  it("is titled with the form's own words in the reader's language - the form under it draws no heading of its own", async () => {
    await open();
    const words = feedbackWords('sk').words;
    expect(host.querySelector('anotoki-dialog .anotoki-dialog__title')?.textContent?.trim()).toBe(
      words.title,
    );
    expect(host.querySelector('anotoki-dialog')?.getAttribute('lang')).toBe('sk');
    expect(host.querySelector('anotoki-feedback-form .anotoki-feedback-form__title')).toBeNull();
    expect(host.querySelector('anotoki-feedback-form [data-message]')).not.toBeNull();
    // Nobody signed in: no "without my name".
    expect(host.querySelector('[data-anonymous]')).toBeNull();
  });

  it("closes on Cancel, and on the dialog's own close button", async () => {
    await open();
    host.querySelector<HTMLButtonElement>('[data-cancel]')!.click();
    expect(closed).toHaveBeenCalledTimes(1);
    host.querySelector<HTMLButtonElement>('.anotoki-dialog__close')!.click();
    expect(closed).toHaveBeenCalledTimes(2);
  });

  it("says the form's words are English where the page's language has none of them", async () => {
    await open(null, 'en');
    expect(host.querySelector('anotoki-dialog')?.getAttribute('lang')).toBe('en');
    expect(host.querySelector('anotoki-dialog .anotoki-dialog__title')?.textContent?.trim()).toBe(
      feedbackWords('en').words.title,
    );
  });

  it("offers the site's four places, in the reader's words, kept by the names the server knows", async () => {
    await open();
    const config = TestBed.inject(ANOTOKI_FEEDBACK_CONFIG);
    expect(config.sections?.()).toEqual([
      { value: 'Dashboard', label: 'Prehľad' },
      { value: 'Import', label: 'Import účtu' },
      { value: 'Accounts', label: 'Tvoje účty a postavy' },
      { value: 'Builds', label: 'Buildy' },
    ]);
    expect(FEEDBACK_SECTIONS.map((section) => section.value)).toEqual([
      'Dashboard',
      'Import',
      'Accounts',
      'Builds',
    ]);
    expect(config.language?.()).toBe('sk');
  });

  it("writes in a signed-in person's address for an answer, and offers to send it without their name", async () => {
    await open({ email: 'mona@example.test' });
    expect(host.querySelector('[data-anonymous]')).not.toBeNull();
    expect(host.querySelector<HTMLInputElement>('[data-email] input')?.value).toBe(
      'mona@example.test',
    );
  });

  it('sends the message to the site, in the language on the page - and says thanks', async () => {
    await open();
    const message = host.querySelector<HTMLTextAreaElement>('[data-message] textarea')!;
    message.value = 'The import stops at the second step.';
    message.dispatchEvent(new Event('input'));
    await fixture.whenStable();
    host.querySelector<HTMLButtonElement>('[data-send]')!.click();
    await fixture.whenStable();

    const sent = requests.expectOne('/api/feedback');
    expect(sent.request.method).toBe('POST');
    expect(sent.request.body).toEqual(
      expect.objectContaining({ message: 'The import stops at the second step.', language: 'sk' }),
    );
    sent.flush({ ok: true }, { status: 201, statusText: 'Created' });
    await vi.waitFor(async () => {
      await fixture.whenStable();
      expect(host.querySelector('[data-thanks]')).not.toBeNull();
    });
    // Close, after the thanks, closes the dialog.
    host.querySelector<HTMLButtonElement>('[data-close]')!.click();
    expect(closed).toHaveBeenCalledTimes(1);
  });
});
