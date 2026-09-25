<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Canonical keys: lowercase ASCII letters and digits only.
 *
 * "Kaedehara Kazuha", "KaedeharaKazuha" (GOOD) and "kaedehara-kazuha" (URL slug) all become "kaedeharakazuha",
 * which lets data from every source meet on the same key.
 */
final class Key
{
    public static function from(string $name): string
    {
        $ascii = Str::ascii(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return preg_replace('/[^a-z0-9]+/', '', strtolower($ascii)) ?? '';
    }
}
