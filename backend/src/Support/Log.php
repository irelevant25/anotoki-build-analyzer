<?php

namespace App\Support;

use Throwable;

/**
 * The app's log: storage/logs/app.log, one line per event (UTC). On an FTP-only host that file is the
 * only way to see what went wrong in a request, so refusals (a token that did not verify) and failures
 * (an exception a sync step or an import caught) are written here; the folder is never served.
 */
final class Log
{
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
        self::write('error', ($context !== '' ? $context.': ' : '').get_class($e).': '.$e->getMessage()
            .' in '.basename($e->getFile()).':'.$e->getLine());
    }

    private static function write(string $level, string $message): void
    {
        if (self::$file === null) {
            return;
        }

        $directory = dirname(self::$file);
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        $line = gmdate('Y-m-d H:i:s').' '.strtoupper($level).' '.str_replace(["\r", "\n"], ' ', $message).PHP_EOL;
        if (@file_put_contents(self::$file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log('[build-analyzer] '.$message);
        }
    }
}
