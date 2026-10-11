import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatSnackBar } from '@angular/material/snack-bar';
import { provideRouter } from '@angular/router';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { Wording } from '../../core/i18n/translation';
import { MetaStore } from '../../core/meta-store';
import { VisitorNotice } from './visitor-notice';

const SLOVAK = {
  'visitor.notice':
    'Používaš stránku bez prihlásenia. Čo importuješ, uchováme pre tento prehliadač na našom serveri, kým od tvojej poslednej návštevy neuplynie toľko dní: {days}. Keď sa prihlásiš, zostane to v tvojom anotoki účte.',
  'common.signIn': 'Prihlásiť sa',
  'common.close': 'Zavrieť',
  'signIn.storageUnavailable': 'Tento prehliadač nedovolí stránke zapamätať si prihlásenie.',
};

function setup(
  options: { signedIn?: boolean; available?: boolean; cannotStart?: boolean; days?: number } = {},
) {
  const lastError = signal<Wording | null>(null);
  const auth = {
    signedIn: signal(options.signedIn ?? false),
    user: signal(null),
    available: signal(options.available ?? true),
    lastError,
    login: vi.fn(async () => {
      if (options.cannotStart) {
        lastError.set({ key: 'signIn.storageUnavailable', params: {} });
        return false;
      }
      return true;
    }),
  };
  const snackBar = { open: vi.fn() };
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      provideTestTranslations(SLOVAK, { language: 'sk', english: en }),
      { provide: AuthService, useValue: auth },
      { provide: MetaStore, useValue: { visitorDays: signal(options.days ?? 30) } },
      { provide: MatSnackBar, useValue: snackBar },
    ],
  });
  return { auth, snackBar };
}

const text = (element: Element | null | undefined) => {
  const copy = element?.cloneNode(true) as Element | undefined;
  copy?.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
  return copy?.textContent?.replace(/\s+/g, ' ').trim();
};

describe('the visitor notice', () => {
  it("says, in the reader's language, how long the server keeps what this browser imports - the days the server says", async () => {
    setup({ days: 14 });
    const fixture = TestBed.createComponent(VisitorNotice);
    await fixture.whenStable();
    const host: HTMLElement = fixture.nativeElement;

    expect(text(host.querySelector('p'))).toBe(
      'Používaš stránku bez prihlásenia. Čo importuješ, uchováme pre tento prehliadač na našom serveri, kým od tvojej poslednej návštevy neuplynie toľko dní: 14. Keď sa prihlásiš, zostane to v tvojom anotoki účte.',
    );
    expect(host.querySelector('aside')?.getAttribute('role')).toBe('note');
    expect(text(host.querySelector('button'))).toBe('Prihlásiť sa');
  });

  it("signs in in the reader's language, back to this page - and says why when the sign-in cannot start", async () => {
    const { auth } = setup();
    const fixture = TestBed.createComponent(VisitorNotice);
    await fixture.whenStable();
    fixture.nativeElement.querySelector('button').click();
    expect(auth.login).toHaveBeenCalledWith(undefined, { uiLocales: 'sk' });

    TestBed.resetTestingModule();
    const failing = setup({ cannotStart: true });
    const again = TestBed.createComponent(VisitorNotice);
    await again.whenStable();
    again.nativeElement.querySelector('button').click();
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(failing.snackBar.open).toHaveBeenCalledWith(
      'Tento prehliadač nedovolí stránke zapamätať si prihlásenie.',
      'Zavrieť',
      expect.anything(),
    );
  });

  it('draws nothing for somebody signed in, and no button where there is no IAM to go to', async () => {
    setup({ signedIn: true });
    const signedIn = TestBed.createComponent(VisitorNotice);
    await signedIn.whenStable();
    expect(signedIn.nativeElement.querySelector('aside')).toBeNull();

    TestBed.resetTestingModule();
    setup({ available: false });
    const offline = TestBed.createComponent(VisitorNotice);
    await offline.whenStable();
    expect(offline.nativeElement.querySelector('aside')).not.toBeNull();
    expect(offline.nativeElement.querySelector('button')).toBeNull();
  });
});
