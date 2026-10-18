<?php

namespace App\Services\Translations;

use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Translations\Cli\KeyScanner;
use Anotoki\Lib\Translations\Translations;
use Anotoki\Lib\Translations\TranslationsConfig;
use App\Support\Neutral;
use PDO;

/**
 * The site's words on the anotoki family's library (anotoki/lib: Anotoki\Lib\Translations). The library keeps
 * the languages, the keys and the strings - the bundle a page reads, the admin pages' grid and languages, the
 * checks of a string (the IAM's, in the IAM's order), the command line - on this site's three tables
 * (migrations/003), and nothing else the server does reads them. What is this site's own is here:
 *
 * - The languages it is released in, English and Slovak: its migrations write strings for both, so neither can
 *   be deleted in the browser (the next migration with strings would fail without it) - RELEASED. English is
 *   what every other language falls back to, key by key.
 * - Its last check of every string and of a language's two names: nothing the site shows names another
 *   website, service or tool, or holds a web address (App\Support\Neutral), reworded in the browser or not -
 *   422 names_another_site, with what was `found` (and, for a name, which `field`). The library asks it after
 *   its own checks, so what is wrong with the text itself is said first.
 * - How the frontend's keys are found (keyScanner()), for the command line and the tests.
 *
 * Who may do what is the routes' (api/routes/translations.php); which keys there are, and their English and
 * Slovak, are the migrations' - with the English compiled into the frontend (core/i18n/en/*.json) held to it.
 */
final class SiteTranslations
{
    /** The languages the migrations write strings for: never deleted (tests/translation_catalogue.test.php holds it true). */
    public const RELEASED = ['en', 'sk'];

    /** Where the keys put together at run time are declared, under the frontend's src/app. */
    public const DYNAMIC_KEYS = 'core/i18n/dynamic-keys.ts';

    public static function config(): TranslationsConfig
    {
        return new TranslationsConfig(releasedLanguages: self::RELEASED, extraCheck: self::namesAnotherSite(...));
    }

    /** The site's words, on a connection that throws on errors (App\Db\Db's). Built per request, as the Migrator is. */
    public static function make(PDO $pdo): Translations
    {
        return new Translations($pdo, self::config());
    }

    /**
     * The site's last check of a text (TranslationsConfig::$extraCheck): $field is 'value' for a string - $about
     * then says its key, language and the language's name - or 'name' / 'native_name' for one of a language's
     * two names. Null when the text names nobody.
     *
     * @param  array<string, mixed>  $about
     */
    public static function namesAnotherSite(string $field, string $text, array $about): ?Refusal
    {
        $found = self::otherSites($text);
        if ($found === []) {
            return null;
        }
        $sentence =
            ($found === ['a web address'] ? 'holds a web address.' : 'names another site (' . implode(', ', array_map(static fn(string $name): string => '"' . $name . '"', $found)) . ').') .
            ' Nothing the site shows names or links another website, service or tool.';

        if ($field === 'value') {
            return Refusal::invalid('names_another_site', "The {$about['language_name']} string of \"{$about['key']}\" {$sentence}", [
                'key' => $about['key'],
                'language' => $about['language'],
                'found' => $found,
            ]);
        }
        $what = $field === 'native_name' ? 'The name as its speakers write it' : 'The name in English';

        return Refusal::invalid('names_another_site', "{$what} {$sentence}", ['field' => $field, 'found' => $found]);
    }

    /**
     * The other websites, services and tools a text names, lowercased - or "a web address" when it holds one and
     * names none. Empty: the text is neutral.
     *
     * Neutral's expression works on bytes, so a letter with a diacritic does not count as part of a word before a
     * name: a Slovak word that ends like a site's name is found too ("pečenka"). Reword the string.
     *
     * @return list<string>
     */
    public static function otherSites(string $text): array
    {
        if (!Neutral::mentionsAny($text)) {
            return [];
        }

        return Neutral::namesIn($text) ?: ['a web address'];
    }

    /** The frontend's sources, from backend/src/Services/Translations. */
    public static function frontendRoot(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 4)) . '/frontend/src/app';
    }

    /**
     * The scan of the frontend's keys (the library's KeyScanner, as every anotoki site's): every .ts and .html
     * under src/app but the specs and the dictionary of the compiled English (core/i18n/en.ts, whose JSON parts
     * list every key and so say nothing of which are used); the
     * pipes translate and translatePlural, and the service's t / around / has and plural; the keys put together
     * at run time declared in core/i18n/dynamic-keys.ts; innerHTML never given a translated string. The
     * library's own keys (anotoki.*) are the library's to ask for, and are never looked for.
     */
    public static function keyScanner(?string $root = null): KeyScanner
    {
        return new KeyScanner($root ?? self::frontendRoot(), exclude: ['core/i18n/en.ts'], dynamicKeys: self::DYNAMIC_KEYS);
    }
}
