<?php

namespace App\Presenters;

use App\Models\CatalogEntry;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;

/**
 * Where the UI loads a catalog icon from: this site's own /api/icons/{type}/{id}, never the game data
 * provider the catalog got it from. The address it came from stays in the database (icon_url) and only the
 * icon route (api/routes/icons.php) ever fetches it.
 */
final class Icons
{
    /** URL letter => catalog list. */
    public const TYPES = ['c' => 'characters', 'w' => 'weapons', 's' => 'sets'];

    public static function of(?CatalogEntry $entry): ?string
    {
        if ($entry === null || $entry->icon_url === null || $entry->icon_url === '') {
            return null;
        }

        $letter = match (true) {
            $entry instanceof GameCharacter => 'c',
            $entry instanceof GameWeapon => 'w',
            $entry instanceof GameSet => 's',
        };

        return "/api/icons/{$letter}/{$entry->id}";
    }

    /**
     * The image type of a body, by its first bytes; null for anything that is not an image.
     */
    public static function type(string $body): ?string
    {
        return match (true) {
            str_starts_with($body, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($body, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($body, 'GIF8') => 'image/gif',
            self::isAvif($body) => 'image/avif',
            default => null,
        };
    }

    /**
     * An AVIF image (genshin.anotoki.eu's icons): its first box is an "ftyp" whose major brand, or one of its
     * compatible brands, is "avif" (a still image) or "avis" (a sequence). The box's size must fit in the body and be
     * a box's: a few dozen bytes, never more than a few kilobytes.
     */
    private static function isAvif(string $body): bool
    {
        if (strlen($body) < 16 || substr($body, 4, 4) !== 'ftyp') {
            return false;
        }
        $size = unpack('N', substr($body, 0, 4))[1];
        if ($size < 16 || $size > 4096 || $size > strlen($body) || $size % 4 !== 0) {
            return false;
        }
        // The major brand, then the minor version, then the compatible brands, four bytes each.
        $brands = [substr($body, 8, 4)];
        for ($at = 16; $at + 4 <= $size; $at += 4) {
            $brands[] = substr($body, $at, 4);
        }

        return array_intersect($brands, ['avif', 'avis']) !== [];
    }
}
