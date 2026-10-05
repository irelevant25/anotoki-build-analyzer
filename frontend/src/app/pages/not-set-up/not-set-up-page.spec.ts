import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { provideTestTranslations } from '../../core/i18n/testing';
import { NotSetUpPage } from './not-set-up-page';

/** The page's Slovak, as the catalogue has it; English underneath, as the server merges it. */
const SLOVAK = {
  ...en,
  'notSetUp.title': 'Zatiaľ nenastavené',
  'notSetUp.lead': 'Aplikácia Build Analyzer je nainštalovaná, ale ešte nie je nastavená: nepozná svoju databázu ani spôsob, akým sa ľudia prihlasujú. Dovtedy tu nie je čo zobraziť.',
  'notSetUp.owner': 'Ak je to tvoj server, otvor stránku nastavenia, vyplň nastavenia databázy a prihlasovania a ulož ich. Potom sa vráť sem.',
  'notSetUp.open': 'Otvoriť stránku nastavenia',
  'notSetUp.tryAgain': 'Skúsiť znova',
  'notSetUp.notOwner': 'Nie je to tvoj server? Nastavenie musí dokončiť jeho vlastník.',
};

describe('the page shown while the server is not set up', () => {
  /** What an element says, without the names of its icons. */
  const text = (element: Element | null | undefined) => {
    const copy = element?.cloneNode(true) as Element | undefined;
    copy?.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
    return copy?.textContent?.replace(/\s+/g, ' ').trim();
  };

  /** 'none': no bundle at all - the real service, as on a server that cannot be asked for its words. */
  async function page(language: 'sk' | 'none'): Promise<HTMLElement> {
    TestBed.configureTestingModule({
      providers: [
        language === 'sk' ? provideTestTranslations(SLOVAK, { language: 'sk', english: en }) : [],
        { provide: AuthService, useValue: { available: signal(false), user: signal(null) } },
      ],
    });
    const fixture = TestBed.createComponent(NotSetUpPage);
    fixture.componentRef.setInput('setupUrl', '/setup.php');
    await fixture.whenStable();
    return fixture.nativeElement;
  }

  it('reads the compiled English while there are no strings from the server - word for word, never a key', async () => {
    const host = await page('none');

    expect(text(host.querySelector('h1'))).toBe('Not set up yet');
    expect([...host.querySelectorAll('p')].map(text)).toEqual([en['notSetUp.lead'], en['notSetUp.owner'], en['notSetUp.notOwner']]);
    expect(text(host.querySelector('a'))).toBe('Open the setup page');
    expect(host.querySelector('a')?.getAttribute('href')).toBe('/setup.php');
    expect(text(host.querySelector('button'))).toBe('Try again');
    expect(host.textContent).not.toMatch(/\bnotSetUp\.[A-Za-z]/);
    expect(host.querySelector('[role="alert"]')).not.toBeNull();
  });

  it('reads the strings this browser kept, in the reader’s language', async () => {
    const host = await page('sk');

    expect(text(host.querySelector('h1'))).toBe('Zatiaľ nenastavené');
    expect([...host.querySelectorAll('p')].map(text)).toEqual([SLOVAK['notSetUp.lead'], SLOVAK['notSetUp.owner'], SLOVAK['notSetUp.notOwner']]);
    expect(text(host.querySelector('a'))).toBe('Otvoriť stránku nastavenia');
    expect(text(host.querySelector('button'))).toBe('Skúsiť znova');
  });
});
