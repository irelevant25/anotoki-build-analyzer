<?php

/**
 * migration.php - builds the database from its migrations, and nothing else
 *
 *   php migration.php              create the database if missing, apply what is pending
 *   php migration.php --status     say what has run and what has not
 *   php migration.php --dry-run    say what would be applied, and apply nothing
 *
 * The migrations are the schema: there is no separate schema file, and a database is what you get by
 * applying migrations/001 and everything after it in order - and then the anotoki library's own files
 * (anotoki/lib's translations set, named here as anotoki_translations/001_...: they check the tables of the
 * site's words and add the library's words). A file already recorded as applied is never run again, so
 * editing one that has been applied changes nothing where it ran - write another instead.
 *
 * A database made by the first (Laravel) version of the app is recognised and brought forward by
 * 001_adopt_laravel_database.sql, with every row it holds (accounts that belonged to nobody yet go to IAM
 * user 1).
 *
 * The engine (the anotoki family's, anotoki/lib, on this site's files: App\Db\Migrations) is shared with
 * "Apply pending" on the admin panel's Migrations page and with setup.php, so a database built any of the
 * three ways is the same database - and one apply runs at a time, whichever starts it: another is told so.
 */

require __DIR__.'/vendor/autoload.php';

use App\Cli;
use App\Db\Db;
use App\Db\Migrations;

Cli::helpIfAsked($argv, __FILE__);

$app = Cli::app();
[$options] = Cli::arguments($argv);
$statusOnly = isset($options['status']);
$dryRun = isset($options['dry-run']);
$config = (array) $app->config->get('database');
$name = (string) $config['database'];

/** A file as this command names it: the site's by its name, the library's with its set in front. */
$label = static fn (array $file): string => ($file['set'] === Migrations::SET ? '' : $file['set'].'/').$file['name'];

Cli::line("== build analyzer database ({$name})");

try {
    if (! $statusOnly && ! $dryRun && Db::ensureDatabase($config, $name)) {
        Cli::info('  Database created.');
    }
    $pdo = Db::pdo($config, $name, false);
    $migrator = Migrations::migrator($pdo);
    $laravel = Migrations::isLaravelDatabase($pdo);
    // Reads only: nothing is created by looking.
    $status = $migrator->status();
    $files = [];
    foreach (Migrations::sets() as $set) {
        foreach ($migrator->files($set) as $file) {
            $files[] = $label(['set' => $set->name, 'name' => $file]);
        }
    }
} catch (Throwable $e) {
    Cli::fail('  Cannot reach it: '.$e->getMessage());
}

if ($laravel) {
    Cli::warn('  This database was made by the first (Laravel) version of the app; 001 brings it forward.');
}
foreach ($status['missing'] as $file) {
    Cli::warn("  {$label($file)} is recorded as applied, but the file is not there.");
}

$pending = array_map($label, $status['pending']);

if ($statusOnly) {
    foreach ($files as $file) {
        $done = ! in_array($file, $pending, true);
        Cli::line('  '.($done ? 'v' : '.').' '.$file.($done ? '' : '   pending'));
    }
    Cli::line(sprintf('  %d applied, %d pending', count($files) - count($pending), count($pending)));
    exit(0);
}

if ($pending === []) {
    Cli::info('  Up to date - '.count($files).' migrations.');
    exit(0);
}

if ($dryRun) {
    foreach ($pending as $file) {
        Cli::warn('  would apply '.$file);
    }
    exit(0);
}

try {
    $result = $migrator->apply();
} catch (Throwable $e) {
    // Only the connection (or moving a Laravel table aside) fails this way; a failing file is in $result.
    Cli::fail('  The update could not be applied: '.$e->getMessage());
}

if ($result['busy']) {
    Cli::error('  Another update is being applied to this database right now (the Migrations page, setup.php or another migration.php).');
    Cli::warn('  Nothing was applied. Wait a moment, then run this again.');
    exit(1);
}
foreach ($result['applied'] as $file) {
    Cli::info("  {$label($file)} ... done");
}
if ($result['error'] !== null) {
    Cli::error('  '.($result['failed'] !== null ? $label($result['failed']) : 'The update').' ... FAILED');
    Cli::error('    '.$result['error']);
    Cli::warn('    Nothing from this file was applied. The migrations before it stand.');
    exit(1);
}

exit(0);
