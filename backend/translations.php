<?php

/**
 * translations.php - the site's own strings, from the command line
 *
 *   php translations.php --status               the keys the frontend uses against the database's; exit 1
 *                                               when one is missing, has no English string, or is asked for
 *                                               in a way the scan does not let through
 *   php translations.php --export=CODE [FILE]   a language's own strings as flat JSON, to FILE or to the screen
 *   php translations.php --import=CODE FILE     and back in: checked as a save in the browser is, all of it
 *                                               or none of it; an empty string is passed over
 *
 * The strings live in the database so they can be reworded in the browser (the Translations page), which
 * means nothing stops a page asking for a key no migration wrote - it would show the key itself. --status is
 * the check for that, and the same scan runs in the tests (tests/support/translation_keys.php says how a key
 * is recognised). An import writes as nobody: updated_by stays empty, as for a migration's strings.
 *
 * For a computer with a shell and the whole repository: it is never uploaded (the host's strings are edited
 * in the browser). It works on the database of config/database.local.php. (App\Commands\Translations)
 */

require __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/tests/support/translation_keys.php';

App\Cli::helpIfAsked($argv, __FILE__);

exit(App\Commands\Translations::run(App\Cli::app(), $argv));
