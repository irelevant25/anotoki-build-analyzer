<?php

namespace App\Web;

use App\Support\Log;
use Throwable;

/**
 * Work that belongs to a request but not to its answer - the visitors' clean-up - done after the answer.
 *
 * Served by the web server (api/index.php), the work waits until the answer has gone out: PHP-FPM and
 * LiteSpeed close the connection first (fastcgi_finish_request(), litespeed_finish_request()), and where
 * PHP cannot, the work runs once the answer is written and the person waits a moment longer. In the tests
 * and on the development router nothing holds it, and it runs as soon as the answer is ready
 * (api/app.php's outermost middleware); on the command line it runs when the command ends (App\Cli::app()).
 * Each piece runs on its own: a failure is logged and never reaches the answer, nor stops the next piece.
 */
final class AfterResponse
{
    /** @var list<callable(): void> */
    private static array $work = [];

    private static bool $held = false;

    /** api/index.php: run the work only once the answer has been sent (runHeld()). */
    public static function hold(): void
    {
        self::$held = true;
    }

    /** @param callable(): void $work */
    public static function add(callable $work): void
    {
        self::$work[] = $work;
    }

    /** The end of a request's handling: the work, unless it is held for after the answer. */
    public static function runUnlessHeld(): void
    {
        if (! self::$held) {
            self::run();
        }
    }

    /** api/index.php, after Slim has written the answer. */
    public static function runHeld(): void
    {
        if (self::$work === []) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            @ob_flush();
            flush();
        }
        self::run();
    }

    private static function run(): void
    {
        while (($work = array_shift(self::$work)) !== null) {
            try {
                $work();
            } catch (Throwable $e) {
                Log::exception($e, 'After the answer');
            }
        }
    }
}
