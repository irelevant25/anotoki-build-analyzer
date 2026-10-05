<?php

/**
 * migration.php - builds the database from its migrations, and nothing else
 *
 *   php migration.php              create the database if missing, apply what is pending
 *   php migration.php --status     say what has run and what has not
 *   php migration.php --dry-run    say what would be applied, and apply nothing
 *
 * The migrations are the schema: there is no separate schema file, and a database is what you get by
 * applying migrations/001 and everything after it in order. A file already recorded as applied is never run
 * again, so editing one that has been applied changes nothing where it ran - write another instead.
 *
 * A database made by the first (Laravel) version of the app is recognised and brought forward by
 * 001_adopt_laravel_database.sql, with every row it holds (accounts that belonged to nobody yet go to IAM
 * user 1).
 *
 * The engine (App\Db\Migrator) is shared with "Apply pending" on the admin panel's Migrations page and with
 * setup.php, so a database built any of the three ways is the same database.
 */

require __DIR__.'/vendor/autoload.php';

use App\Cli;
use App\Db\Db;
use App\Db\Migrator;

Cli::helpIfAsked($argv, __FILE__);

$app = Cli::app();
[$options] = Cli::arguments($argv);
$statusOnly = isset($options['status']);
$dryRun = isset($options['dry-run']);
$config = (array) $app->config->get('database');
$name = (string) $config['database'];

Cli::line("== build analyzer database ({$name})");

try {
    if (! $statusOnly && ! $dryRun && Migrator::ensureDatabase($config, $name)) {
        Cli::info('  Database created.');
    }
    $migrator = new Migrator(Db::pdo($config, $name, false), Migrator::directory());
    $laravel = $migrator->isLaravelDatabase();
    $pending = $migrator->pending();
    $files = $migrator->files();
} catch (Throwable $e) {
    Cli::fail('  Cannot reach it: '.$e->getMessage());
}

if ($laravel) {
    Cli::warn('  This database was made by the first (Laravel) version of the app; 001 brings it forward.');
}

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

foreach ($pending as $file) {
    echo '  ', $file, ' ... ';
    try {
        // false: another run (the Migrations page, setup.php) applied it while this one waited.
        Cli::info($migrator->apply($file) ? 'done' : 'already applied by another run');
    } catch (Throwable $e) {
        Cli::error('FAILED');
        Cli::error('    '.$e->getMessage());
        Cli::warn('    Nothing from this file was applied. The migrations before it stand.');
        exit(1);
    }
}

exit(0);
