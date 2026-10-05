import { DatePipe } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSnackBar } from '@angular/material/snack-bar';
import { Api, errorMessage } from '../../../core/api';
import { TranslationService } from '../../../core/i18n/translation';
import { MigrationResult, MigrationStatus } from '../../../core/models';
import { askConfirm } from '../../../shared/confirm-dialog';

/**
 * /admin/migrations (ADMIN, English like the whole admin panel) - the schema, for a host with no shell: the
 * IAM's Migrations page, in this site's own pieces. On a machine with one, `php migration.php` does the same.
 *
 * The pending files run in order, each in its own transaction, and the run stops at the first that fails
 * (every migration assumes the ones before it ran): that one is rolled back whole, and the page says where
 * it stopped, what was applied before it and the database's error. One apply at a time: the server answers
 * 409 migrations_busy while another runs. A migration may bring the site's strings or new keys, so after an
 * apply that applied something this tab reads them again.
 */
@Component({
  selector: 'app-migrations-page',
  imports: [DatePipe, MatButtonModule, MatIconModule, MatProgressSpinnerModule],
  templateUrl: './migrations-page.html',
  styleUrl: './migrations-page.scss',
})
export class MigrationsPage {
  private readonly api = inject(Api);
  private readonly dialog = inject(MatDialog);
  private readonly snackBar = inject(MatSnackBar);
  private readonly i18n = inject(TranslationService);

  protected readonly status = signal<MigrationStatus | null>(null);
  /** What the last Apply did, shown above the lists until the next one. */
  protected readonly result = signal<MigrationResult | null>(null);
  /** Why the lists could not be read: the server's English sentence (this page is English). */
  protected readonly loadError = signal<string | null>(null);
  protected readonly applying = signal(false);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loadError.set(null);
    this.api.migrations().subscribe({
      next: (status) => this.status.set(status),
      error: (err: unknown) => this.loadError.set(errorMessage(err)),
    });
  }

  protected async apply(): Promise<void> {
    const pending = this.status()?.pending.length ?? 0;
    const sure = await askConfirm(this.dialog, {
      title: pending === 1 ? 'Apply 1 pending migration?' : `Apply ${pending} pending migrations?`,
      message:
        'They run now, against the live database, in order - stopping at the first one that fails. ' +
        'Make sure there is a fresh backup.',
      confirmLabel: 'Apply',
      cancelLabel: 'Cancel',
      danger: true,
    });
    if (!sure) {
      return;
    }
    this.applying.set(true);
    this.result.set(null);
    this.api.applyMigrations().subscribe({
      next: (result) => {
        this.applying.set(false);
        this.result.set(result);
        if (result.applied.length > 0) {
          // The site's strings may only now exist, or have new keys: this tab reads them again.
          void this.i18n.reload();
        }
        if (!result.failed && result.applied.length > 0) {
          this.snackBar.open('Migrations applied.', undefined, { duration: 5000 });
        }
        this.load();
      },
      error: (err: unknown) => {
        this.applying.set(false);
        this.snackBar.open(errorMessage(err), 'Close');
      },
    });
  }
}
