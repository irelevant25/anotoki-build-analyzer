import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideKit } from '../../../../shared/kit';
import { EditorAnnouncer } from '../editor-announcer';
import { MoveButtons } from './move-buttons';

describe('the move buttons: up, down and remove for an entry of an ordered list', () => {
  let fixture: ComponentFixture<MoveButtons>;
  let host: HTMLElement;
  let moves: number[];
  let removes: number;

  const button = (name: string) => host.querySelector<HTMLButtonElement>(`button.${name}`)!;

  async function setup(index: number, count: number, removable = true): Promise<void> {
    TestBed.configureTestingModule({ providers: [provideTestTranslations({}), provideKit()] });
    fixture = TestBed.createComponent(MoveButtons);
    fixture.componentRef.setInput('index', index);
    fixture.componentRef.setInput('count', count);
    fixture.componentRef.setInput('name', 'weapon Staff of Homa');
    fixture.componentRef.setInput('removable', removable);
    moves = [];
    removes = 0;
    // The list's owner moves the entry: its place follows.
    fixture.componentInstance.move.subscribe((delta) => {
      moves.push(delta);
      fixture.componentRef.setInput('index', fixture.componentInstance.index() + delta);
    });
    fixture.componentInstance.remove.subscribe(() => (removes += 1));
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  it('names each button with the whole entry, and keeps up off on the first entry, down on the last', async () => {
    await setup(0, 3);
    expect([button('move-up'), button('move-down'), button('remove')].map((one) => one.getAttribute('aria-label'))).toEqual([
      'Move weapon Staff of Homa up',
      'Move weapon Staff of Homa down',
      'Remove weapon Staff of Homa',
    ]);
    expect([button('move-up').disabled, button('move-down').disabled]).toEqual([true, false]);

    fixture.componentRef.setInput('index', 2);
    await fixture.whenStable();
    expect([button('move-up').disabled, button('move-down').disabled]).toEqual([false, true]);
  });

  it('moves the entry, keeps the focus on the button pressed - on the other where it can go no further - and says where it went', async () => {
    await setup(0, 3);
    const announcer = TestBed.inject(EditorAnnouncer);

    button('move-down').click();
    await fixture.whenStable();
    expect(moves).toEqual([1]);
    expect(document.activeElement).toBe(button('move-down'));
    await vi.waitFor(() => expect(announcer.message()).toBe('Weapon Staff of Homa moved down: 2 of 3.'));

    button('move-down').click();
    await fixture.whenStable();
    // The last place: down is off, the focus on up.
    expect(document.activeElement).toBe(button('move-up'));
    await vi.waitFor(() => expect(announcer.message()).toBe('Weapon Staff of Homa moved down: 3 of 3.'));

    // Pressed where it cannot go: nothing.
    button('move-down').click();
    await fixture.whenStable();
    expect(moves).toEqual([1, 1]);
  });

  it('moves an entry of a list whose entries stand side by side left and right', async () => {
    await setup(1, 3);
    fixture.componentRef.setInput('axis', 'horizontal');
    await fixture.whenStable();
    expect([button('move-up'), button('move-down')].map((one) => one.getAttribute('aria-label'))).toEqual(['Move weapon Staff of Homa left', 'Move weapon Staff of Homa right']);
    const announcer = TestBed.inject(EditorAnnouncer);
    button('move-down').click();
    await fixture.whenStable();
    await vi.waitFor(() => expect(announcer.message()).toBe('Weapon Staff of Homa moved right: 3 of 3.'));
  });

  it('asks its owner to remove the entry, and has no remove where the entry cannot be removed', async () => {
    await setup(1, 3);
    button('remove').click();
    expect(removes).toBe(1);

    TestBed.resetTestingModule();
    await setup(1, 3, false);
    expect(host.querySelector('button.remove')).toBeNull();
  });
});
