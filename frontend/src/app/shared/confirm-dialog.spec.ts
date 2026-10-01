import { ApplicationRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatDialog } from '@angular/material/dialog';
import { ConfirmQuestion, askConfirm } from './confirm-dialog';

const QUESTION: ConfirmQuestion = {
  title: 'Vymazať účet „Mona“ a všetko, čo sa preň importovalo?',
  message: 'Buildy zostanú.',
  confirmLabel: 'Vymazať',
  cancelLabel: 'Zrušiť',
  danger: true,
};

describe('the confirm dialog: a question worded by whoever asks it', () => {
  let dialog: MatDialog;

  const stable = () => TestBed.inject(ApplicationRef).whenStable();
  const overlay = () => document.querySelector<HTMLElement>('.cdk-overlay-container')!;
  const container = () => overlay().querySelector<HTMLElement>('mat-dialog-container');
  const buttons = () => [...overlay().querySelectorAll<HTMLButtonElement>('mat-dialog-actions button')];

  /** Asks, and waits until the question is on the page. The answer comes later: a promise, kept inside an object so that it is not waited for here. */
  async function ask(question: ConfirmQuestion = QUESTION): Promise<{ answer: Promise<boolean> }> {
    const answer = askConfirm(dialog, question);
    await stable();
    return { answer };
  }

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [{ provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } }] });
    dialog = TestBed.inject(MatDialog);
  });

  afterEach(async () => {
    dialog.closeAll();
    await stable();
  });

  it('shows the words it was given and has none of its own', async () => {
    await ask();

    expect(container()?.getAttribute('role')).toBe('alertdialog');
    expect(overlay().querySelector('h2')?.textContent?.trim()).toBe('Vymazať účet „Mona“ a všetko, čo sa preň importovalo?');
    expect(overlay().querySelector('.message')?.textContent?.trim()).toBe('Buildy zostanú.');
    expect(buttons().map((button) => button.textContent?.trim())).toEqual(['Zrušiť', 'Vymazať']);
    // Everything the dialog says is one of the four strings: put end to end, they are all its text.
    expect(container()?.textContent?.trim()).toBe('Vymazať účet „Mona“ a všetko, čo sa preň importovalo?' + 'Buildy zostanú.' + 'Zrušiť' + 'Vymazať');
  });

  it('puts no first, where the focus goes, and marks a yes that cannot be undone', async () => {
    await ask();

    expect(buttons()[0].hasAttribute('cdkFocusInitial')).toBe(true);
    expect(buttons()[1].classList.contains('danger')).toBe(true);
  });

  it('draws neither a message nor a warning that was not asked for', async () => {
    await ask({ title: 'Leave without saving?', confirmLabel: 'Leave', cancelLabel: 'Stay here' });

    expect(overlay().querySelector('mat-dialog-content')).toBeNull();
    expect(buttons().map((button) => button.textContent?.trim())).toEqual(['Stay here', 'Leave']);
    expect(buttons()[1].classList.contains('danger')).toBe(false);
  });

  it('answers yes only for the button that says yes', async () => {
    const { answer } = await ask();
    buttons()[1].click();

    expect(await answer).toBe(true);
    await stable();
    expect(container()).toBeNull();
  });

  it('answers no for the other button', async () => {
    const { answer } = await ask();
    buttons()[0].click();

    expect(await answer).toBe(false);
  });

  it('answers no when it is closed without an answer', async () => {
    const { answer } = await ask();
    dialog.closeAll();

    expect(await answer).toBe(false);
  });
});
