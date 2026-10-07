import { Component } from '@angular/core';
import { MigrationsPageComponent } from '@anotoki/lib/migrations';

/**
 * /admin/migrations (ADMIN, English like the whole admin panel) - the schema, for a host with no shell: the
 * anotoki family's Migrations page (@anotoki/lib/migrations) in this admin panel's frame. Pending in the order
 * they will run, Applied with their dates (the account's date order and clock where it has them:
 * core/site-status.ts) and each file's SQL, Apply pending after a confirmation; one apply at a time. On a
 * machine with a shell, `php migration.php` does the same.
 *
 * While an update waits it is the one page of the site that shows - to an ADMIN; everybody else reads that the
 * site is being updated. Once Apply leaves nothing to apply, the site opens again in this tab, and its words
 * are read again (a migration may bring strings or new keys).
 */
@Component({
  selector: 'app-migrations-page',
  imports: [MigrationsPageComponent],
  templateUrl: './migrations-page.html',
  styleUrl: './migrations-page.scss',
})
export class MigrationsPage {}
