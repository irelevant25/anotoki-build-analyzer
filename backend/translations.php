<?php

/**
 * translations.php - the site's own strings, from the command line
 *
 *   php translations.php --status               the keys the frontend uses against the database's; exit 1
 *                                               when one is missing, has no English string, or is asked for
 *                                               in a way the scan does not let through; and the stored strings
 *                                               a save would refuse (a warning)
 *   php translations.php --export=CODE [FILE]   a language's own strings as flat JSON, to FILE or to the screen
 *   php translations.php --import=CODE FILE     and back in: checked as a save in the browser is, all of it
 *                                               or none of it; an empty string is passed over
 *
 * (--export CODE and --import CODE FILE too.) The anotoki family's command (anotoki/lib: TranslationsCommand),
 * on this site's words: App\Commands\Translations builds it.
 *
 * The strings live in the database so they can be reworded in the browser (the Translations page), which
 * means nothing stops a page asking for a key no migration wrote - it would show the key itself. --status is
 * the check for that, and the same scan runs in the tests (App\Services\Translations\SiteTranslations::keyScanner()
 * says what is read). An import writes as nobody: updated_by stays empty, as for a migration's strings. Every
 * command refuses while the tables are not there or a migration waits (php migration.php first).
 *
 * For a computer with a shell and the whole repository: it is never uploaded (the host's strings are edited
 * in the browser). It works on the database of config/database.local.php.
 */

require __DIR__.'/vendor/autoload.php';

App\Cli::helpIfAsked($argv, __FILE__);

exit(App\Commands\Translations::run(App\Cli::app(), $argv));
