<?php

/**
 * import-account.php - import a game account from the command line
 *
 *   php import-account.php <genshin|hsr> <uid | path to an exported JSON file> [--account=ID] [--user=IAM_ID]
 *
 * A UID imports the Character Showcase; a file is an inventory export (GOOD for Genshin, the scanner format
 * for Star Rail). The account belongs to --user (an anotoki IAM user id; default the local_user_id of
 * config/auth.local.php); --account merges a file into one of that user's accounts. (App\Commands\ImportAccount)
 */

require __DIR__.'/vendor/autoload.php';

App\Cli::helpIfAsked($argv, __FILE__);

exit(App\Commands\ImportAccount::run(App\Cli::app(), $argv));
