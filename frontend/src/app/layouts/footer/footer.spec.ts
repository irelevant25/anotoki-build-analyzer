import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { Changelog } from '../../core/changelog';
import { TranslationService } from '../../core/i18n/translation';
import { FeedbackDialog } from '../../shared/feedback-dialog/feedback-dialog';
import { Footer } from './footer';

const ENGLISH = {
  'footer.version': 'Version {version}',
  'whatsNew.title': "What's new",
  'footer.feedback': 'Feedback',
};
const SLOVAK = {
  'footer.version': 'Verzia {version}',
  'whatsNew.title': 'Čo je nové',
  'footer.feedback': 'Spätná väzba',
};

describe('the footer', () => {
  async function draw(
    version: string | null,
    address = '/',
  ): Promise<{
    host: HTMLElement;
    open: ReturnType<typeof vi.fn>;
    dialog: { open: ReturnType<typeof vi.fn> };
  }> {
    const open = vi.fn();
    const dialog = { open: vi.fn() };
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        { provide: Changelog, useValue: { current: signal(version), open } },
        { provide: MatDialog, useValue: dialog },
      ],
    });
    TestBed.inject(TranslationService).noteAddress(address);
    const fixture = TestBed.createComponent(Footer);
    await fixture.whenStable();
    return { host: fixture.nativeElement, open, dialog };
  }

  it("says the version and opens What's new - in the reader's language", async () => {
    const { host, open } = await draw('0.3');
    expect(host.querySelector('.version')?.textContent?.trim()).toBe('Verzia 0.3');
    const button = host.querySelector<HTMLButtonElement>('.whats-new')!;
    expect(button.textContent?.trim()).toBe('Čo je nové');
    button.click();
    expect(open).toHaveBeenCalledWith();
  });

  it('opens the feedback form in a dialog - its code loaded with the first press, not before', async () => {
    const { host, dialog } = await draw('0.3');
    const button = host.querySelector<HTMLButtonElement>('.feedback')!;
    expect(button.textContent?.trim()).toBe('Spätná väzba');
    expect(button.getAttribute('aria-haspopup')).toBe('dialog');
    button.click();
    await vi.waitFor(() => expect(dialog.open).toHaveBeenCalledTimes(1));
    expect(dialog.open).toHaveBeenCalledWith(
      FeedbackDialog,
      expect.objectContaining({ autoFocus: 'first-tabbable', restoreFocus: true }),
    );
  });

  it('reads English in the admin panel', async () => {
    const { host } = await draw('0.3', '/admin/sources');
    expect(
      [...host.querySelectorAll('.footer > *')].map((part) => part.textContent?.trim()),
    ).toEqual(['Version 0.3', '·', "What's new", '·', 'Feedback']);
  });

  it("has Feedback alone without a changelog (a broken upload): no version, no What's new", async () => {
    const { host } = await draw(null);
    expect(
      [...host.querySelectorAll('.footer > *')].map((part) => part.textContent?.trim()),
    ).toEqual(['Spätná väzba']);
  });
});
