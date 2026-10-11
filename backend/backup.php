<?php

/**
 * backup.php - the database's backups on the command line (the anotoki library's: Anotoki\Lib\Backups), the same
 * files the admin panel's Backups page lists (storage/backups/*.anotoki-backup). Never uploaded: there is no
 * shell on the host - there the page makes, downloads, deletes and restores them.
 *
 *   php backup.php --list
 *   php backup.php --create ["description"]
 *   php backup.php --inspect=<id>               its manifest: the tables and their rows
 *   php backup.php --extract=<id> <folder>      everything into a new folder, checked: manifest.json,
 *                                               schema/<set>/<file>.sql, data/NNN-<table>.copy - and how to load it
 *                                               into another database (psql \copy)
 *   php backup.php --restore=<id> --confirm=<the id's last 6 characters>
 *                                               in place, as the page restores (doing again what was deleted since):
 *                                               only a backup made here - sealed under this installation's key,
 *                                               storage/anotoki-lib.key - into a database whose migrations and
 *                                               columns are the backup's, a backup of it first
 *
 * A backup downloaded from the host is put into storage/backups/ here (FTP or a copy) and is then listed - and, made
 * by another installation (sealed under the host's key, or made before anotoki-lib 0.5.0 sealed none), read with
 * --inspect and --extract alone. What a command line does needs no proof - the shell is the owner's already - and
 * the audit log says it was nobody.
 */

require __DIR__.'/vendor/autoload.php';

use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Backups\Cli\BackupsCommand;
use App\Cli;

Cli::helpIfAsked($argv, __FILE__);

$app = Cli::app();

exit((new BackupsCommand($app->get(Backups::class)))->run(array_slice($argv, 1)));
