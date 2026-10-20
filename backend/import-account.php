<?php

/**
 * import-account.php - import a game account from the command line
 *
 *   php import-account.php <game> <uid | path to an exported JSON file> --user=IAM_ID [--account=ID]   (game: genshin)
 *
 * A UID imports the Character Showcase; a file is an inventory export (GOOD). The account belongs to --user,
 * an anotoki IAM user id (required: there is nobody by default); --account merges a file into one of that user's accounts. (App\Commands\ImportAccount)
 */

require __DIR__ . '/vendor/autoload.php';

App\Cli::helpIfAsked($argv, __FILE__);

exit(App\Commands\ImportAccount::run(App\Cli::app(), $argv));
