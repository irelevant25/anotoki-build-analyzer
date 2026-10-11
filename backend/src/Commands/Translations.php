<?php

namespace App\Commands;

use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Translations\Cli\TranslationsCommand;
use Anotoki\Lib\Translations\Translations as SiteWords;
use App\App;
use App\Db\Migrations;
use App\Services\Translations\SiteTranslations;

/**
 * translations.php: the site's own strings from the command line - the anotoki family's command
 * (anotoki/lib: TranslationsCommand) on this site's words and its frontend.
 *
 *   php translations.php --status               the keys the frontend uses against the database's
 *   php translations.php --export=CODE [FILE]   a language's own strings as flat JSON (--export CODE too)
 *   php translations.php --import=CODE FILE     and back in, checked as a save in the browser is
 *
 * --status reads the frontend (SiteTranslations::keyScanner()): it is for a computer with the whole repository,
 * and is never uploaded. Every command refuses while the tables are not there or a migration waits: the strings
 * of a database that needs an update are not the release's yet.
 */
final class Translations
{
    /**
     * The command on this app's database.
     *
     * @param  string|null  $frontend  the frontend's src/app, when it is not this repository's (the tests)
     * @param  resource|null  $out  where it writes (STDOUT)
     * @param  resource|null  $err  where it says why not (STDERR)
     */
    public static function command(App $app, ?string $frontend = null, $out = null, $err = null): TranslationsCommand
    {
        return new TranslationsCommand(
            fn (): SiteWords => $app->get(SiteWords::class),
            SiteTranslations::keyScanner($frontend),
            pending: fn (): array => array_map(
                static fn (array $file): string => ($file['set'] === Migrations::SET ? '' : $file['set'].'/').$file['name'],
                $app->get(Migrator::class)->applicable()
            ),
            title: "build analyzer ({$app->config->get('database.database')}): the site's own strings",
            out: $out,
            err: $err,
        );
    }

    /**
     * Runs one command and answers the exit code. One command at a time: an argument that is a second command
     * (--export=sk --import=sk file) is refused, never taken for the name of a file to write.
     *
     * @param  list<string>  $argv  as PHP gives them: the script first
     * @param  resource|null  $out
     * @param  resource|null  $err
     */
    public static function run(App $app, array $argv, ?string $frontend = null, $out = null, $err = null): int
    {
        $flags = array_filter(array_slice($argv, 1), static fn (string $argument): bool => str_starts_with($argument, '-'));
        if (count($flags) > 1) {
            fwrite($err ?? STDERR, "One command at a time: php translations.php --status | --export=CODE [FILE] | --import=CODE FILE\n");

            return 1;
        }

        return self::command($app, $frontend, $out, $err)->run($argv);
    }
}
