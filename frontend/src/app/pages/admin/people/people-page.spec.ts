import { HttpErrorResponse } from '@angular/common/http';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { MatSnackBar } from '@angular/material/snack-bar';
import { of, throwError } from 'rxjs';
import { Api } from '../../../core/api';
import { MetaStore } from '../../../core/meta-store';
import { PeopleList } from '../../../core/models';
import { PeoplePage } from './people-page';

const LIST: PeopleList = {
  people: [
    {
      id: 1,
      name: 'ada',
      accounts: 2,
      import_runs: 5,
      created_at: '2026-01-01T00:00:00Z',
      last_import_at: null,
    },
    {
      id: 7,
      name: 'mona',
      accounts: 1,
      import_runs: 1,
      created_at: '2026-02-01T00:00:00Z',
      last_import_at: null,
    },
    {
      id: 9,
      name: 'nobody-yet',
      accounts: 0,
      import_runs: 0,
      created_at: '2026-03-01T00:00:00Z',
      last_import_at: null,
    },
  ],
  visitors: { count: 3, accounts: 4 },
};

describe('People (ADMIN)', () => {
  let fixture: ComponentFixture<PeoplePage>;
  let host: HTMLElement;
  let api: { people: ReturnType<typeof vi.fn>; deletePerson: ReturnType<typeof vi.fn> };
  let snackBar: { open: ReturnType<typeof vi.fn> };
  let dialog: { open: ReturnType<typeof vi.fn> };
  let answer: boolean;

  const text = (element: Element | null | undefined) => {
    const copy = element?.cloneNode(true) as Element | undefined;
    copy?.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
    return copy?.textContent?.replace(/\s+/g, ' ').trim();
  };
  const people = () => [...host.querySelectorAll<HTMLElement>('li.person')];

  async function setup(list: PeopleList | HttpErrorResponse = LIST): Promise<void> {
    api = {
      people: vi.fn(() =>
        list instanceof HttpErrorResponse ? throwError(() => list) : of(structuredClone(list)),
      ),
      deletePerson: vi.fn(() => of({ accounts: 2, import_runs: 5 })),
    };
    snackBar = { open: vi.fn() };
    answer = true;
    dialog = { open: vi.fn(() => ({ afterClosed: () => of(answer) })) };
    TestBed.configureTestingModule({
      providers: [
        { provide: Api, useValue: api },
        { provide: MatSnackBar, useValue: snackBar },
        { provide: MatDialog, useValue: dialog },
        { provide: MetaStore, useValue: { visitorDays: signal(30) } },
      ],
    });
    fixture = TestBed.createComponent(PeoplePage);
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  it('lists everybody who signed in here with what they keep, and counts the visitors without listing them', async () => {
    await setup();

    const parts = (person: HTMLElement, selector: string) =>
      [...person.querySelector(selector)!.children].map(text);
    expect(people().map((person) => parts(person, '.who'))).toEqual([
      ['ada', 'anotoki id 1'],
      ['mona', 'anotoki id 7'],
      ['nobody-yet', 'anotoki id 9'],
    ]);
    expect(parts(people()[0], '.facts')).toEqual(['2 game accounts', '5 imports', 'no import yet']);
    expect(parts(people()[1], '.facts')).toEqual(['1 game account', '1 import', 'no import yet']);
    expect(text(host.querySelector('.visitors'))).toBe(
      '3 visitors with 4 game accounts - each deleted 30 days after its last visit.',
    );
    // Nothing to delete for somebody who has nothing here.
    expect(people()[2].querySelector<HTMLButtonElement>('button.delete')!.disabled).toBe(true);
    expect(host.querySelector('.page')?.getAttribute('lang')).toBe('en');
  });

  it("deletes a person's data after asking - saying what goes and that their anotoki account stays", async () => {
    await setup();
    people()[0].querySelector<HTMLButtonElement>('button.delete')!.click();
    await fixture.whenStable();

    const question = dialog.open.mock.calls[0][1].data;
    expect(question.title).toBe('Delete what ada has here?');
    expect(question.message).toBe(
      'Deletes 2 game accounts and 5 imports. The person keeps their anotoki account and can sign in again, with nothing here.',
    );
    expect(question.danger).toBe(true);
    expect(api.deletePerson).toHaveBeenCalledWith(1);
    expect(people().map((person) => text(person.querySelector('strong')))).toEqual([
      'mona',
      'nobody-yet',
    ]);
    expect(snackBar.open).toHaveBeenCalledWith(
      'Deleted 2 game accounts and 5 imports of ada.',
      undefined,
      { duration: 5000 },
    );
  });

  it('deletes nothing when the answer is no', async () => {
    await setup();
    answer = false;
    people()[1].querySelector<HTMLButtonElement>('button.delete')!.click();
    await fixture.whenStable();

    expect(api.deletePerson).not.toHaveBeenCalled();
    expect(people()).toHaveLength(3);
  });

  it('says what the server said when the delete or the list fails (the admin panel reads English)', async () => {
    await setup();
    api.deletePerson.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 403,
            error: { message: 'Only an admin can do this.', code: 'admin_only' },
          }),
      ),
    );
    people()[0].querySelector<HTMLButtonElement>('button.delete')!.click();
    await fixture.whenStable();
    expect(snackBar.open).toHaveBeenCalledWith('Only an admin can do this.', 'Close');
    expect(people()).toHaveLength(3);

    TestBed.resetTestingModule();
    await setup(
      new HttpErrorResponse({
        status: 503,
        error: { message: 'The database is not up to date.', code: 'server_error' },
      }),
    );
    expect([...host.querySelector('.load-error-text')!.children].map(text)).toEqual([
      'The people could not be loaded.',
      'The database is not up to date.',
    ]);
  });
});
