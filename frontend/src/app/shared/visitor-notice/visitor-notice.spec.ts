import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { MetaStore } from '../../core/meta-store';
import { VisitorNotice } from './visitor-notice';

const SLOVAK = {
  'visitor.notice':
    'Používaš stránku bez prihlásenia. Čo importuješ, uchováme pre tento prehliadač na našom serveri, kým od tvojej poslednej návštevy neuplynie toľko dní: {days}. Keď sa prihlásiš, zostane to v tvojom anotoki účte.',
  'common.signIn': 'Prihlásiť sa',
};

function setup(
  options: { signedIn?: boolean; available?: boolean; cannotStart?: boolean; days?: number } = {},
) {
  const failure = signal<{ code: string; returnTo: string | null } | null>(null);
  const auth = {
    signedIn: signal(options.signedIn ?? false),
    user: signal(null),
    available: signal(options.available ?? true),
    failure,
    login: vi.fn(async () => {
      if (options.cannotStart) {
        failure.set({ code: 'storage_unavailable', returnTo: '/' });
        return false;
      }
      return true;
    }),
  };
  TestBed.configureTestingModule({
    providers: [
      provideRouter([{ path: '**', children: [] }]),
      provideTestTranslations(SLOVAK, { language: 'sk', english: en }),
      { provide: AuthService, useValue: auth },
      { provide: MetaStore, useValue: { visitorDays: signal(options.days ?? 30) } },
    ],
  });
  return { auth };
}

const text = (element: Element | null | undefined) =>
  element?.textContent?.replace(/\s+/g, ' ').trim();

describe('the visitor notice', () => {
  it("says, in the reader's language, how long the server keeps what this browser imports - the days the server says", async () => {
    setup({ days: 14 });
    const fixture = TestBed.createComponent(VisitorNotice);
    await fixture.whenStable();
    const host: HTMLElement = fixture.nativeElement;

    expect(text(host.querySelector('.anotoki-alert__text'))).toBe(
      'Používaš stránku bez prihlásenia. Čo importuješ, uchováme pre tento prehliadač na našom serveri, kým od tvojej poslednej návštevy neuplynie toľko dní: 14. Keď sa prihlásiš, zostane to v tvojom anotoki účte.',
    );
    // The kit's note, part of the page: never announced as it is drawn.
    expect(host.querySelector('anotoki-alert')?.getAttribute('data-tone')).toBe('info');
    expect(host.querySelector('anotoki-alert')?.hasAttribute('role')).toBe(false);
    expect(text(host.querySelector('button'))).toBe('Prihlásiť sa');
  });

  it("signs in, back to this page - and says why on the failure's screen when the sign-in cannot start", async () => {
    const { auth } = setup();
    const fixture = TestBed.createComponent(VisitorNotice);
    await fixture.whenStable();
    fixture.nativeElement.querySelector('button').click();
    expect(auth.login).toHaveBeenCalledWith(undefined);

    TestBed.resetTestingModule();
    setup({ cannotStart: true });
    const again = TestBed.createComponent(VisitorNotice);
    await again.whenStable();
    again.nativeElement.querySelector('button').click();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await again.whenStable();
    expect(TestBed.inject(Router).url).toBe('/sign-in');
  });

  it('draws nothing for somebody signed in, and no button where there is no IAM to go to', async () => {
    setup({ signedIn: true });
    const signedIn = TestBed.createComponent(VisitorNotice);
    await signedIn.whenStable();
    expect(signedIn.nativeElement.querySelector('anotoki-alert')).toBeNull();

    TestBed.resetTestingModule();
    setup({ available: false });
    const offline = TestBed.createComponent(VisitorNotice);
    await offline.whenStable();
    expect(offline.nativeElement.querySelector('anotoki-alert')).not.toBeNull();
    expect(offline.nativeElement.querySelector('button')).toBeNull();
  });
});
