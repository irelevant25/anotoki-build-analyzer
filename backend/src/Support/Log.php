<?php

namespace App\Support;

use Throwable;

/**
 * The app's log: storage/logs/app.log, one line per event (UTC) - what the site did and decided: a sync's
 * or an import's failure it caught, visitors swept, migrations applied, a setting it passed over. What went
 * wrong in a request - an exception answered 500, PHP's warnings and fatal errors - is the error log's (the
 * anotoki library's, storage/logs/errors-*.jsonl: the admin panel's Error log page). The folder is never
 * served.
 *
 * Kept small: past MAX_BYTES the file becomes app.log.1 (the one before it goes) and a new one starts, so at
 * most about twice that is ever kept.
 */
final class Log
{
    /** The most one file grows to before the next line starts a new one. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    private static ?string $file = null;

    /** Where lines go; null (the tests' choice) keeps them out of any file. */
    public static function to(?string $file): void
    {
        self::$file = $file;
    }

    public static function info(string $message): void
    {
        self::write('info', $message);
    }

    public static function error(string $message): void
    {
        self::write('error', $message);
    }

    public static function exception(Throwable $e, string $context = ''): void
    {
        self::write('error', ($context !== '' ? $context . ': ' : '') . get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }

    private static function write(string $level, string $message): void
    {
        if (self::$file === null) {
            return;
        }

        $directory = dirname(self::$file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        clearstatcache(true, self::$file);
        if (@filesize(self::$file) > self::MAX_BYTES) {
            // Two requests may both find it full: the second rename finds nothing to move, and that is fine.
            @rename(self::$file, self::$file . '.1');
        }
        $line = gmdate('Y-m-d H:i:s') . ' ' . strtoupper($level) . ' ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
        if (@file_put_contents(self::$file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('[build-analyzer] ' . $message);
        }
    }
}
