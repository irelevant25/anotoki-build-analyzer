import { Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Api, errorMessage } from '../../../core/api';
import { PeopleList, Person } from '../../../core/models';
import { MetaStore } from '../../../core/meta-store';
import { askConfirm } from '../../../shared/confirm-dialog';
import { TimeAgoPipe } from '../../../shared/stat-pipes';

/**
 * People (ADMIN, English like the whole admin panel): everybody who has signed in here, with how much they
 * keep - game accounts and imports - and a way to delete that. Their anotoki account is the IAM's and stays:
 * they can sign in again, with nothing here. Visitors are only counted (never listed, never deleted here):
 * each one goes by itself, `visitors.keep_days` after its last visit.
 */
@Component({
  selector: 'app-people-page',
  imports: [MatButtonModule, MatIconModule, MatProgressSpinnerModule, TimeAgoPipe],
  templateUrl: './people-page.html',
  styleUrl: './people-page.scss',
})
export class PeoplePage {
  private readonly api = inject(Api);
  private readonly dialog = inject(MatDialog);
  private readonly snackBar = inject(MatSnackBar);
  protected readonly meta = inject(MetaStore);

  protected readonly list = signal<PeopleList | null>(null);
  /** Why the list could not be loaded: the server's English sentence (this page is English). */
  protected readonly loadError = signal<string | null>(null);
  /** The person whose data is being deleted now. */
  protected readonly deleting = signal<number | null>(null);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loadError.set(null);
    this.api.people().subscribe({
      next: (list) => this.list.set(list),
      error: (err: unknown) => this.loadError.set(errorMessage(err)),
    });
  }

  protected async deletePerson(person: Person): Promise<void> {
    const sure = await askConfirm(this.dialog, {
      title: `Delete what ${person.name} has here?`,
      message:
        `Deletes ${count(person.accounts, 'game account')} and ${count(person.import_runs, 'import')}. ` +
        'The person keeps their anotoki account and can sign in again, with nothing here.',
      confirmLabel: 'Delete',
      cancelLabel: 'Cancel',
      danger: true,
    });
    if (!sure) {
      return;
    }
    this.deleting.set(person.id);
    this.api.deletePerson(person.id).subscribe({
      next: (deleted) => {
        this.deleting.set(null);
        this.list.update((list) =>
          list ? { ...list, people: list.people.filter((other) => other.id !== person.id) } : list,
        );
        this.snackBar.open(
          `Deleted ${count(deleted.accounts, 'game account')} and ${count(deleted.import_runs, 'import')} of ${person.name}.`,
          undefined,
          {
            duration: 5000,
          },
        );
      },
      error: (err: unknown) => {
        this.deleting.set(null);
        this.snackBar.open(errorMessage(err), 'Close');
      },
    });
  }

  protected count(n: number, noun: string): string {
    return count(n, noun);
  }
}

/** "1 game account", "3 imports". */
function count(n: number, noun: string): string {
  return `${n} ${noun}${n === 1 ? '' : 's'}`;
}
