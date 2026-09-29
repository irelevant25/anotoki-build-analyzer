<?php

namespace App\Support;

use voku\helper\ASCII;

/**
 * Canonical keys: lowercase ASCII letters and digits only.
 *
 * "Kaedehara Kazuha", "KaedeharaKazuha" (GOOD) and "kaedehara-kazuha" (URL slug) all become "kaedeharakazuha",
 * which lets data from every source meet on the same key.
 *
 * The transliteration is portable-ascii's, called exactly as the first version of this app called it, so a
 * name with accents still makes the key already stored for it.
 */
final class Key
{
    public static function from(string $name): string
    {
        $ascii = ASCII::to_ascii(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'en', replace_single_chars_only: false);

        return preg_replace('/[^a-z0-9]+/', '', strtolower($ascii)) ?? '';
    }
}
