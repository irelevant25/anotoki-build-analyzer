<?php

/**
 * sync-catalog.php - refresh the game catalog (characters, weapons, sets, icons), from the command line
 *
 *   php sync-catalog.php [game] [--force]   (game: genshin)
 *
 * Each list of each game, as the admin panel's Builds page refreshes them one at a time. Without --force a
 * catalog refreshed within refresh_days is left as it is. (App\Commands\SyncCatalog)
 */

require __DIR__ . '/vendor/autoload.php';

App\Cli::helpIfAsked($argv, __FILE__);

exit(App\Commands\SyncCatalog::run(App\Cli::app(), $argv));
