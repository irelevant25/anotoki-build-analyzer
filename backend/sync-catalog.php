<?php

/**
 * sync-catalog.php - refresh the game catalog (characters, weapons, sets, icons), from the command line
 *
 *   php sync-catalog.php [game] [--list=characters|weapons|sets] [--force]   (game: genshin)
 *
 * Each list of each game from its provider, as the admin panel's Builds page refreshes them one at a time - a
 * conditional request, so a list as it was is left alone. Without --force a catalog refreshed within refresh_days
 * is not asked at all; --force asks every list in full. (App\Commands\SyncCatalog)
 */

require __DIR__ . '/vendor/autoload.php';

App\Cli::helpIfAsked($argv, __FILE__);

exit(App\Commands\SyncCatalog::run(App\Cli::app(), $argv));
