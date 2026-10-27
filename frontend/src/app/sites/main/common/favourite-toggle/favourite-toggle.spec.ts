import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { TooltipDirective } from '@anotoki/lib/ui/tooltip';
import { en } from '../../../../shared/i18n/en';
import { provideKit } from '../../../../shared/kit';
import { FavouriteToggle } from './favourite-toggle';

const SLOVAK = {
  ...en,
  'favourites.mark': 'Obľúbená postava: {name}',
  'favourites.add': 'Pridať medzi obľúbené',
  'favourites.remove': 'Odobrať z obľúbených',
};

describe('a character’s star (FavouriteToggle)', () => {
  let fixture: ComponentFixture<FavouriteToggle>;
  let asked: boolean[];

  const button = () => fixture.nativeElement.querySelector('button') as HTMLButtonElement;
  const tooltip = () => fixture.debugElement.query(By.css('button')).injector.get(TooltipDirective).text();

  async function star(favourite: boolean, busy = false): Promise<void> {
    TestBed.configureTestingModule({ providers: [provideTestTranslations(SLOVAK, { language: 'sk', english: en }), provideKit()] });
    fixture = TestBed.createComponent(FavouriteToggle);
    fixture.componentRef.setInput('favourite', favourite);
    fixture.componentRef.setInput('name', 'Furina');
    fixture.componentRef.setInput('busy', busy);
    asked = [];
    fixture.componentInstance.toggled.subscribe((wanted) => asked.push(wanted));
    await fixture.whenStable();
  }

  it('is a button pressed or not, named after the character, saying in its tooltip what a press does', async () => {
    await star(false);
    expect([button().getAttribute('aria-pressed'), button().getAttribute('aria-label'), tooltip()]).toEqual(['false', 'Obľúbená postava: Furina', 'Pridať medzi obľúbené']);
    expect(fixture.nativeElement.querySelector('svg.star')?.classList.contains('on')).toBe(false);

    fixture.componentRef.setInput('favourite', true);
    await fixture.whenStable();
    expect([button().getAttribute('aria-pressed'), tooltip()]).toEqual(['true', 'Odobrať z obľúbených']);
    expect(fixture.nativeElement.querySelector('svg.star')?.classList.contains('on')).toBe(true);
    // The drawing says nothing of its own: the button's name does.
    expect(fixture.nativeElement.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
  });

  it('asks for the other state when pressed - and, while a save is on its way, asks nothing, the button never disabled', async () => {
    await star(true);
    button().click();
    expect(asked).toEqual([false]);

    TestBed.resetTestingModule();
    await star(false, true);
    expect(button().disabled).toBe(false);
    button().click();
    expect(asked).toEqual([]);
  });
});
