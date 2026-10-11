<?php

namespace App;

use Anotoki\Lib\Support\Log as LibraryLog;
use App\Support\Log;
use App\Web\AfterResponse;

/**
 * What the command-line scripts (migration.php, sync-builds.php, sync-catalog.php, import-account.php,
 * translations.php, backup.php) share: the app, set up or refusing to start, and a little output.
 */
final class Cli
{
    public static function app(): App
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(404);
            exit;
        }
        if (PHP_VERSION_ID < 80400) {
            self::fail('PHP 8.4 or newer is needed (this is '.PHP_VERSION.').');
        }

        $config = Config::load();
        if (! $config->isSetUp()) {
            self::fail("This installation is not set up yet:\n  ".implode("\n  ", $config->problems())
                ."\nRun setup.php first (composer start in backend/, then open http://localhost:8400/setup.php).");
        }
        Log::to($config->storage('logs/app.log'));
        // The anotoki library's own lines into the same file, as the API's (api/index.php).
        LibraryLog::to(static fn (string $line) => Log::info($line));
        // What a command leaves for "after the answer" (App\Web\AfterResponse) runs when the command ends, however
        // it ends (exit() too): there is no answer to wait for here, and work queued for one would never run - a
        // restore that could not check the IAM's deleted accounts gives back the upkeep's hourly claim that way,
        // so the next request checks again, as the restore says (People::afterRestore()'s `defer`).
        register_shutdown_function(static function (): void {
            AfterResponse::runUnlessHeld();
        });

        return new App($config, web: false);
    }

    /**
     * --name=value options (repeatable ones as lists) and positional arguments.
     *
     * @param  list<string>  $argv
     * @return array{0: array<string, string|true|list<string>>, 1: list<string>}
     */
    public static function arguments(array $argv): array
    {
        $options = [];
        $positional = [];
        foreach (array_slice($argv, 1) as $argument) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $m)) {
                $value = array_key_exists(2, $m) ? $m[2] : true;
                if (isset($options[$m[1]])) {
                    $options[$m[1]] = [...(array) $options[$m[1]], $value];
                } else {
                    $options[$m[1]] = $value;
                }
            } else {
                $positional[] = $argument;
            }
        }

        return [$options, $positional];
    }

    /**
     * --help or -h: prints the script's opening doc comment (its usage) and ends. Called before app(), so it
     * works on a copy that is not set up yet - and a script asked for help never starts any work.
     *
     * @param  list<string>  $argv
     */
    public static function helpIfAsked(array $argv, string $script): void
    {
        if (! in_array('--help', $argv, true) && ! in_array('-h', $argv, true)) {
            return;
        }
        if (preg_match('~/\*\*(.*?)\*/~s', (string) file_get_contents($script), $match)) {
            $lines = array_map(fn (string $line) => (string) preg_replace('~^\s*\* ?~', '', $line), explode("\n", trim($match[1])));
            self::line(trim(implode("\n", $lines)));
        }
        exit(0);
    }

    /** @var list<string>|null lines kept instead of printed (the tests read them) */
    private static ?array $captured = null;

    /** Tests: keep what is printed from now on, instead of printing it. */
    public static function capture(): void
    {
        self::$captured = [];
    }

    /** Tests: what was printed since capture(), and printing again. */
    public static function captured(): string
    {
        $lines = self::$captured ?? [];
        self::$captured = null;

        return implode(PHP_EOL, $lines);
    }

    public static function line(string $text = ''): void
    {
        self::write($text);
    }

    public static function info(string $text): void
    {
        self::write(self::colour(32, $text));
    }

    public static function warn(string $text): void
    {
        self::write(self::colour(33, $text));
    }

    public static function error(string $text): void
    {
        if (self::$captured !== null) {
            self::$captured[] = $text;

            return;
        }
        fwrite(STDERR, self::colour(31, $text).PHP_EOL);
    }

    private static function write(string $text): void
    {
        if (self::$captured !== null) {
            self::$captured[] = $text;

            return;
        }
        echo $text, PHP_EOL;
    }

    public static function fail(string $text): never
    {
        self::error($text);
        exit(1);
    }

    private static function colour(int $code, string $text): string
    {
        return self::$captured === null && stream_isatty(STDOUT) ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
