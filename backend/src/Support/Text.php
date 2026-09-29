<?php

namespace App\Support;

/**
 * The few string helpers the app needs.
 */
final class Text
{
    /**
     * At most $limit characters, with "..." when something was cut.
     */
    public static function limit(string $text, int $limit, string $end = '...'): string
    {
        if (mb_strwidth($text, 'UTF-8') <= $limit) {
            return $text;
        }

        return rtrim(mb_strimwidth($text, 0, $limit, '', 'UTF-8')).$end;
    }

    /**
     * Everything after the first $search ("! unknown weapon: X" after "! " is "unknown weapon: X").
     */
    public static function after(string $text, string $search): string
    {
        $position = $search === '' ? false : strpos($text, $search);

        return $position === false ? $text : substr($text, $position + strlen($search));
    }
}
