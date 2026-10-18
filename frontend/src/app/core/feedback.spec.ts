import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Injector, signal, runInInjectionContext } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ANOTOKI_FEEDBACK_CONFIG } from '@anotoki/lib/feedback';
import { FeedbackFormComponent } from '@anotoki/lib/feedback/form';
import { SiteStatus } from '@anotoki/lib/migrations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from './auth';
import { FEEDBACK_SECTIONS, feedbackOffered, provideFeedback } from './feedback';
import { en } from './i18n/en';

const SLOVAK = {
  'feedback.section.dashboard': 'Prehľad',
  'feedback.section.import': 'Import účtu',
  'feedback.section.accounts': 'Tvoje účty a postavy',
  'feedback.section.builds': 'Buildy',
};

/** Who the page is shown to: a person signed in, a visitor (the IAM lets visitors in), or somebody signed out. */
type Who = 'person' | 'visitor' | 'signed-out';

function configure(who: Who = 'visitor', blocked = false): void {
  TestBed.configureTestingModule({
    providers: [
      provideTestTranslations(SLOVAK, { language: 'sk', english: en }),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideFeedback(),
      {
        provide: AuthService,
        useValue: {
          signedIn: signal(who === 'person'),
          visiting: signal(who === 'visitor'),
          user: signal(who === 'person' ? { sub: '7', email: 'mona@example.test' } : null),
        },
      },
      { provide: SiteStatus, useValue: { blocked: signal(blocked) } },
    ],
  });
}

describe("the family's feedback form, set up for this site (opened from the footer and the Contact page)", () => {
  let fixture: ComponentFixture<FeedbackFormComponent> | null = null;

  /** The form as the footer's dialog draws it: no heading of its own, with Cancel. */
  async function form(who: Who): Promise<HTMLElement> {
    configure(who);
    fixture = TestBed.createComponent(FeedbackFormComponent);
    fixture.componentRef.setInput('heading', null);
    await fixture.whenStable();
    return fixture.nativeElement;
  }

  afterEach(() => {
    TestBed.inject(HttpTestingController).verify();
    fixture = null;
  });

  it("offers the site's four places, in the reader's words, kept by the names the server knows - in the language on the page", () => {
    configure();
    const config = TestBed.inject(ANOTOKI_FEEDBACK_CONFIG);
    expect(config.sections?.()).toEqual([
      { value: 'Dashboard', label: 'Prehľad' },
      { value: 'Import', label: 'Import účtu' },
      { value: 'Accounts', label: 'Tvoje účty a postavy' },
      { value: 'Builds', label: 'Buildy' },
    ]);
    expect(FEEDBACK_SECTIONS.map((section) => section.value)).toEqual(['Dashboard', 'Import', 'Accounts', 'Builds']);
    expect(config.language?.()).toBe('sk');
  });

  it("writes in a signed-in person's address for an answer, and offers to send it without their name - neither to a visitor", async () => {
    const mona = await form('person');
    expect(mona.querySelector('[data-anonymous]')).not.toBeNull();
    expect(mona.querySelector<HTMLInputElement>('[data-email] input')?.value).toBe('mona@example.test');

    TestBed.resetTestingModule();
    const visitor = await form('visitor');
    expect(visitor.querySelector('[data-anonymous]')).toBeNull();
  });

  it('sends the message to the site, in the language on the page - and says thanks', async () => {
    const host = await form('visitor');
    const message = host.querySelector<HTMLTextAreaElement>('[data-message] textarea')!;
    message.value = 'The import stops at the second step.';
    message.dispatchEvent(new Event('input'));
    await fixture!.whenStable();
    host.querySelector<HTMLButtonElement>('[data-send]')!.click();
    await fixture!.whenStable();

    const sent = TestBed.inject(HttpTestingController).expectOne('/api/feedback');
    expect(sent.request.method).toBe('POST');
    expect(sent.request.body).toEqual(expect.objectContaining({ message: 'The import stops at the second step.', language: 'sk' }));
    sent.flush({ ok: true }, { status: 201, statusText: 'Created' });
    await vi.waitFor(async () => {
      await fixture!.whenStable();
      expect(host.querySelector('[data-thanks]')).not.toBeNull();
    });
  });
});

describe('whether a message can be sent now (feedbackOffered)', () => {
  const offered = (who: Who, blocked = false): boolean => {
    TestBed.resetTestingModule();
    configure(who, blocked);
    return runInInjectionContext(TestBed.inject(Injector), () => feedbackOffered())();
  };

  it('to a person signed in, and to a visitor while the IAM lets visitors in', () => {
    expect([offered('person'), offered('visitor')]).toEqual([true, true]);
  });

  it('never to somebody signed out (the IAM requires signing in here: the server refuses them), nor while the site is not ready', () => {
    expect([offered('signed-out'), offered('person', true), offered('visitor', true)]).toEqual([false, false, false]);
  });
});
