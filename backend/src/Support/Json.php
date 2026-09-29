<?php

namespace App\Support;

/**
 * JSON in and out of the database and the API.
 *
 * encode() keeps PHP's default flags on purpose: a build's content hash is a hash of its json_encode()d
 * data, so the stored hashes of every existing build only stay comparable while the encoding does not
 * change (14.0 is written as 14, "/" as "\/").
 */
final class Json
{
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /** A JSON column (null stays null). */
    public static function column(mixed $value): ?string
    {
        return $value === null ? null : self::encode($value);
    }

    /**
     * A JSON column read back: an array, or null for NULL / anything that is not an object or array.
     *
     * @return array<mixed>|null
     */
    public static function decode(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
