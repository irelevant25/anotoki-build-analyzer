import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { Changelog } from '../../core/changelog';
import { TranslationService } from '../../core/i18n/translation';
import { Footer } from './footer';

const ENGLISH = { 'footer.version': 'Version {version}', 'whatsNew.title': "What's new" };
const SLOVAK = { 'footer.version': 'Verzia {version}', 'whatsNew.title': 'Čo je nové' };

describe('the footer', () => {
  async function draw(
    version: string | null,
    address = '/',
  ): Promise<{ host: HTMLElement; open: ReturnType<typeof vi.fn> }> {
    const open = vi.fn();
    TestBed.configureTestingModule({
      providers: [
        provideTestTranslations(SLOVAK, { language: 'sk', english: ENGLISH }),
        { provide: Changelog, useValue: { current: signal(version), open } },
      ],
    });
    TestBed.inject(TranslationService).noteAddress(address);
    const fixture = TestBed.createComponent(Footer);
    await fixture.whenStable();
    return { host: fixture.nativeElement, open };
  }

  it("says the version and opens What's new - in the reader's language", async () => {
    const { host, open } = await draw('0.3');
    expect(host.querySelector('.version')?.textContent?.trim()).toBe('Verzia 0.3');
    const button = host.querySelector<HTMLButtonElement>('.whats-new')!;
    expect(button.textContent?.trim()).toBe('Čo je nové');
    button.click();
    expect(open).toHaveBeenCalledWith();
  });

  it('reads English in the admin panel', async () => {
    const { host } = await draw('0.3', '/admin/sources');
    expect(
      [...host.querySelectorAll('.footer > *')].map((part) => part.textContent?.trim()),
    ).toEqual(['Version 0.3', '·', "What's new"]);
  });

  it('is not there without a changelog', async () => {
    const { host } = await draw(null);
    expect(host.querySelector('.footer')).toBeNull();
  });
});
