import { HttpErrorResponse } from '@angular/common/http';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Observable, of, throwError } from 'rxjs';
import { Api } from '../../../core/api';
import { TranslationService } from '../../../core/i18n/translation';
import { MigrationResult, MigrationStatus } from '../../../core/models';
import { MigrationsPage } from './migrations-page';

const BEHIND: MigrationStatus = {
  applied: [
    { name: '001_adopt_laravel_database.sql', applied_at: '2026-09-01T08:00:00+00:00' },
    { name: '002_schema.sql', applied_at: '2026-09-01T08:00:01+00:00' },
    { name: '003_languages_and_translations.sql', applied_at: '2026-09-20T12:30:00+00:00' },
  ],
  pending: [{ name: '004_visitors_and_family_bar.sql' }, { name: '005_next.sql' }],
};

const CURRENT: MigrationStatus = {
  applied: [
    ...BEHIND.applied,
    { name: '004_visitors_and_family_bar.sql', applied_at: '2026-10-04T09:00:00+00:00' },
    { name: '005_next.sql', applied_at: '2026-10-04T09:00:01+00:00' },
  ],
  pending: [],
};

describe('Migrations (ADMIN)', () => {
  let fixture: ComponentFixture<MigrationsPage>;
  let host: HTMLElement;
  let api: { migrations: ReturnType<typeof vi.fn>; applyMigrations: ReturnType<typeof vi.fn> };
  let snackBar: { open: ReturnType<typeof vi.fn> };
  let dialog: { open: ReturnType<typeof vi.fn> };
  let reload: ReturnType<typeof vi.fn>;
  let answer: boolean;

  /** An element's words: its text nodes without the icons' ligatures, one space between them. */
  const text = (element: Element | null | undefined) => {
    const copy = element?.cloneNode(true) as Element | undefined;
    copy?.querySelectorAll('mat-icon').forEach((icon) => icon.remove());
    if (!copy) {
      return undefined;
    }
    const walker = document.createTreeWalker(copy, NodeFilter.SHOW_TEXT);
    const words: string[] = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      words.push(node.textContent ?? '');
    }
    return words.join(' ').replace(/\s+/g, ' ').trim();
  };
  const files = (selector: string) => [...host.querySelectorAll(`${selector} .files li`)].map(text);
  const applyButton = () => host.querySelector<HTMLButtonElement>('button.apply')!;

  async function setup(
    statuses: (MigrationStatus | HttpErrorResponse)[],
    apply?: Observable<MigrationResult>,
  ): Promise<void> {
    const answers = [...statuses];
    api = {
      migrations: vi.fn(() => {
        const next = answers.length > 1 ? answers.shift()! : answers[0];
        return next instanceof HttpErrorResponse
          ? throwError(() => next)
          : of(structuredClone(next));
      }),
      applyMigrations: vi.fn(() => apply ?? of({ applied: [], failed: null, error: null })),
    };
    snackBar = { open: vi.fn() };
    reload = vi.fn(async () => undefined);
    answer = true;
    dialog = { open: vi.fn(() => ({ afterClosed: () => of(answer) })) };
    TestBed.configureTestingModule({
      providers: [
        { provide: Api, useValue: api },
        { provide: MatSnackBar, useValue: snackBar },
        { provide: MatDialog, useValue: dialog },
        { provide: TranslationService, useValue: { reload } },
      ],
    });
    fixture = TestBed.createComponent(MigrationsPage);
    host = fixture.nativeElement;
    await fixture.whenStable();
  }

  async function pressApply(): Promise<void> {
    applyButton().click();
    await fixture.whenStable();
    await fixture.whenStable();
  }

  it('lists what waits in the order it will run, and what was applied with when - in English', async () => {
    await setup([BEHIND]);
    expect(text(host.querySelector('h1'))).toBe('Migrations');
    expect(files('.pending')).toEqual([
      '004_visitors_and_family_bar.sql pending',
      '005_next.sql pending',
    ]);
    expect(text(host.querySelector('.pending .lead'))).toBe('In the order they will run.');
    expect(files('.applied').map((line) => line?.split('.sql')[0])).toEqual([
      '001_adopt_laravel_database',
      '002_schema',
      '003_languages_and_translations',
    ]);
    expect(files('.applied')[2]).toMatch(
      /^003_languages_and_translations\.sql \d{1,2} Sep 2026, \d\d:\d\d$/,
    );
    expect(text(host.querySelector('.applied .lead'))).toBe('3 files');
    expect(applyButton().disabled).toBe(false);
    expect(host.querySelector('.page')?.getAttribute('lang')).toBe('en');
  });

  it('says there is nothing pending, and cannot be pressed then', async () => {
    await setup([CURRENT]);
    expect(text(host.querySelector('.pending .nothing'))).toBe(
      'Nothing pending The database is up to date with the files.',
    );
    expect(applyButton().disabled).toBe(true);
  });

  it('applies after asking - how many, and to make sure of a backup - then says so and reads the strings and the lists again', async () => {
    await setup(
      [BEHIND, CURRENT],
      of({
        applied: ['004_visitors_and_family_bar.sql', '005_next.sql'],
        failed: null,
        error: null,
      }),
    );
    await pressApply();

    const question = dialog.open.mock.calls[0][1].data;
    expect(question.title).toBe('Apply 2 pending migrations?');
    expect(question.message).toBe(
      'They run now, against the live database, in order - stopping at the first one that fails. Make sure there is a fresh backup.',
    );
    expect(question.danger).toBe(true);
    expect(api.applyMigrations).toHaveBeenCalledTimes(1);
    expect(text(host.querySelector('.outcome'))).toBe(
      'Up to date Applied: 004_visitors_and_family_bar.sql, 005_next.sql.',
    );
    expect(snackBar.open).toHaveBeenCalledWith('Migrations applied.', undefined, {
      duration: 5000,
    });
    expect(reload).toHaveBeenCalledTimes(1);
    expect(api.migrations).toHaveBeenCalledTimes(2);
    expect(files('.pending')).toEqual([]);
    expect(applyButton().disabled).toBe(true);
  });

  it('applies nothing when the question is answered no', async () => {
    await setup([BEHIND]);
    answer = false;
    await pressApply();
    expect(api.applyMigrations).not.toHaveBeenCalled();
    expect(host.querySelector('.outcome')).toBeNull();
  });

  it("says where it stopped, what went before, and the database's error - the failed file rolled back whole", async () => {
    await setup(
      [
        BEHIND,
        { applied: [...BEHIND.applied, CURRENT.applied[3]], pending: [{ name: '005_next.sql' }] },
      ],
      of({
        applied: ['004_visitors_and_family_bar.sql'],
        failed: '005_next.sql',
        error: 'statement 2 of 9: SQLSTATE[42P07]: relation "x" already exists',
      }),
    );
    await pressApply();

    const outcome = host.querySelector('.outcome')!;
    expect(outcome.getAttribute('role')).toBe('alert');
    expect(text(outcome.querySelector('strong'))).toBe('Stopped at 005_next.sql');
    expect(text(outcome.querySelector('p'))).toBe(
      'Applied first: 004_visitors_and_family_bar.sql. The failed file was rolled back as a whole.',
    );
    expect(text(outcome.querySelector('pre'))).toBe(
      'statement 2 of 9: SQLSTATE[42P07]: relation "x" already exists',
    );
    expect(snackBar.open).not.toHaveBeenCalled();
    // 004 was applied: its strings may be there now.
    expect(reload).toHaveBeenCalledTimes(1);
    expect(files('.pending')).toEqual(['005_next.sql pending']);
  });

  it('says there was nothing to apply when another apply got there first', async () => {
    await setup([BEHIND, CURRENT], of({ applied: [], failed: null, error: null }));
    await pressApply();
    expect(text(host.querySelector('.outcome'))).toBe('There was nothing to apply.');
    expect(reload).not.toHaveBeenCalled();
    expect(snackBar.open).not.toHaveBeenCalled();
  });

  it('says what the server said when it refuses - one apply at a time', async () => {
    const busy = new HttpErrorResponse({
      status: 409,
      error: { message: 'The migrations are being applied already.', code: 'migrations_busy' },
    });
    await setup(
      [BEHIND],
      throwError(() => busy),
    );
    await pressApply();
    expect(snackBar.open).toHaveBeenCalledWith(
      'The migrations are being applied already.',
      'Close',
    );
    expect(applyButton().disabled).toBe(false);
    expect(host.querySelector('.outcome')).toBeNull();
  });

  it('says why the lists could not be read, and reads them again on Try again', async () => {
    await setup([
      new HttpErrorResponse({
        status: 403,
        error: { message: 'Only an admin can do this.', code: 'admin_only' },
      }),
      BEHIND,
    ]);
    expect(text(host.querySelector('.load-error-text'))).toBe(
      'The migrations could not be read. Only an admin can do this.',
    );
    expect(applyButton().disabled).toBe(true);
    host.querySelector<HTMLButtonElement>('.load-error button')!.click();
    await fixture.whenStable();
    expect(files('.pending')).toEqual([
      '004_visitors_and_family_bar.sql pending',
      '005_next.sql pending',
    ]);
  });
});
