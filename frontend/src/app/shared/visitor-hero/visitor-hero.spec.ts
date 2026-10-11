import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AuthService } from '../../core/auth';
import { en } from '../../core/i18n/en';
import { VisitorHero } from './visitor-hero';
import { provideKit } from '../../core/kit';

const SLOVAK = {
  'common.signIn': 'Prihlásiť sa',
  'home.title': 'Každá postava v porovnaní s buildmi od komunity',
  'home.text': 'Importuj účet a pri každej postave uvidíš, čo zlepšiť.',
  'home.import': 'Importovať účet',
  'home.browse': 'Buildy pre hru {game}',
  'home.signInText':
    'Keď sa prihlásiš, tvoje účty zostanú pri tvojom anotoki účte na každom zariadení.',
};

function setup(options: { available?: boolean } = {}) {
  const auth = {
    signedIn: signal(false),
    user: signal(null),
    available: signal(options.available ?? true),
    failure: signal(null),
    login: vi.fn(async () => true),
  };
  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      provideTestTranslations(SLOVAK, { language: 'sk', english: en }),
      provideKit(),
      { provide: AuthService, useValue: auth },
    ],
  });
  return { auth };
}

const text = (element: Element | null | undefined) =>
  element?.textContent?.replace(/\s+/g, ' ').trim();

describe('the ways in for somebody new (visitor hero)', () => {
  it("offers the import, each game's builds and the sign-in, in the reader's language, the games' names as they are", async () => {
    const { auth } = setup();
    const fixture = TestBed.createComponent(VisitorHero);
    await fixture.whenStable();
    const host: HTMLElement = fixture.nativeElement;

    expect(text(host.querySelector('h2'))).toBe('Každá postava v porovnaní s buildmi od komunity');
    expect(
      [...host.querySelectorAll('a')].map((link) => [text(link), link.getAttribute('href')]),
    ).toEqual([
      ['Importovať účet', '/import'],
      ['Buildy pre hru Genshin Impact', '/builds/genshin'],
      ['Buildy pre hru Honkai: Star Rail', '/builds/hsr'],
    ]);
    host.querySelector<HTMLButtonElement>('button')!.click();
    expect(auth.login).toHaveBeenCalledWith(undefined);
    expect(host.textContent).not.toMatch(/\b(home|common)\.[A-Za-z]/);
  });

  it('offers no sign-in where there is no IAM to go to - the other ways on stay', async () => {
    setup({ available: false });
    const fixture = TestBed.createComponent(VisitorHero);
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('button')).toBeNull();
    expect(fixture.nativeElement.querySelectorAll('a')).toHaveLength(3);
  });
});
