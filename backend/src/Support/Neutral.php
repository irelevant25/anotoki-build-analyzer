<?php

namespace App\Support;

use App\Config;

/**
 * Keeps other websites out of everything the API sends to be shown.
 *
 * The app takes its game data from a data provider, its showcases from an API service and its builds from a file
 * an editor imports, and none of the sites behind them may be named, linked or credited in the UI. What this code
 * writes itself says "the showcase service" and so on, but text that comes from elsewhere can still carry a name
 * or an address: an exception message ("HTTP 403 from https://..."), a curl error ("Could not resolve host:
 * ..."), a note in a builds file, an uploaded account file. text() is applied to all of that on the way out (and
 * to a builds file's texts on the way in as well).
 *
 * The names below are the providers and tools this code talks to or reads the files of. The repository names no
 * site the builds come from: those names live in the optional, untracked config/neutral.local.php (`names`; the
 * owner's own copy, uploaded to a host by hand), which adds to the list here - localNames(). Without it the list
 * here and every web address are still kept out.
 */
final class Neutral
{
    /** The optional settings file that adds names (App\Config::readOptionalFile()). */
    public const LOCAL_FILE = 'neutral.local.php';

    /**
     * Web addresses, with or without a scheme - but not the shape of one alone: a scheme or "www." followed by nothing
     * but an ellipsis ("https://…", "https://..."), which names and links no site. The anotoki library's HTML editor
     * says what a link may point at so (anotoki_translations/014: "A web address (https://…) or a mail address
     * (mailto:…)."); anything after the ellipsis - "https://…elsewhere.example" - is an address again. The regular
     * expression reads bytes: the ellipsis is its three in UTF-8.
     */
    private const URL = '~\b(?:https?://|www\.)(?!(?:\xE2\x80\xA6|\.\.\.)(?:[\s"\'<>()\[\].,;:!?]|$))[^\s"\'<>()\[\]]+~i';

    /**
     * Names and host names of the services and tools involved. Matched case-insensitively on word boundaries,
     * longest first (namesPattern()), so a domain is replaced as a whole.
     */
    private const NAMES = [
        'enka.network',
        'enka network',
        'enkanetwork',
        'enka',
        'gi.yatta.moe',
        'sr.yatta.moe',
        'yatta.moe',
        'project amber',
        'yatta',
        'raw.githubusercontent.com',
        'githubusercontent',
        'github.com',
        'github',
        'irminsul',
        'inventory kamera',
        'inventory_kamera',
        'goodscanner',
        'genshin optimizer',
        'reliquary archiver',
        'reliquary_archiver',
        'reliquary-archiver',
        'hsr-scanner',
        'hsrscanner',
        'yas-scanner',
        'fribbels',
        'npcap',
    ];

    /** @var list<string>|null The names config/neutral.local.php adds, read once; or what a test set. */
    private static ?array $localNames = null;

    private static ?string $pattern = null;

    public static function text(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $clean = preg_replace(self::URL, 'the source page', $text) ?? $text;
        $clean = preg_replace(self::namesPattern(), 'the source', $clean) ?? $clean;
        // "The source page" at the start of a sentence reads better capitalised.
        $clean = preg_replace_callback('/(^|[.!?]\s+|\n\s*)(the source)/', fn(array $m) => $m[1] . 'The source', $clean) ?? $clean;

        return $clean;
    }

    /**
     * Every string in a nested array (build notes, set notes, stat target texts ...).
     */
    public static function deep(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::text($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::deep($item);
            }
        }

        return $value;
    }

    /** True when text() would change something (the tests). */
    public static function mentionsAny(string $text): bool
    {
        return self::text($text) !== $text;
    }

    /** Whether a text holds a web address (with a scheme, or starting www.). */
    public static function holdsAddress(string $text): bool
    {
        return preg_match(self::URL, $text) === 1;
    }

    /**
     * The names of other sites, services and tools found in a text, lowercased (tools/check-release.php runs it over
     * the built frontend, whose framework code carries web addresses of its own).
     *
     * @return list<string>
     */
    public static function namesIn(string $text): array
    {
        preg_match_all(self::namesPattern(), $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0])));
    }

    /**
     * The names config/neutral.local.php adds (its `names`: strings with something in them), lowercased - none
     * without the file, or with one that cannot be read cleanly (App\Config::readOptionalFile()).
     *
     * @return list<string>
     */
    public static function localNames(): array
    {
        if (self::$localNames === null) {
            $value = Config::readOptionalFile(self::LOCAL_FILE);
            self::$localNames = self::clean(is_array($value) && is_array($value['names'] ?? null) ? $value['names'] : []);
            self::$pattern = null;
        }

        return self::$localNames;
    }

    /**
     * The names to add in place of the file's (the tests: [] keeps the owner's own file out of every result); null
     * reads the file again.
     *
     * @param list<string>|null $names
     */
    public static function setLocalNames(?array $names): void
    {
        self::$localNames = $names === null ? null : self::clean($names);
        self::$pattern = null;
    }

    /**
     * @param array<mixed> $names
     * @return list<string>
     */
    private static function clean(array $names): array
    {
        $clean = [];
        foreach ($names as $name) {
            if (is_string($name) && trim($name) !== '') {
                $clean[] = strtolower(trim($name));
            }
        }

        return array_values(array_unique($clean));
    }

    private static function namesPattern(): string
    {
        $local = self::localNames();
        if (self::$pattern === null) {
            $names = array_values(array_unique([...self::NAMES, ...$local]));
            // Longest first: a domain is replaced as a whole, never as the name inside it.
            usort($names, fn(string $a, string $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
            self::$pattern = '~(?<![\w.-])(?:' . implode('|', array_map(fn(string $name) => preg_quote($name, '~'), $names)) . ')(?![\w-])~i';
        }

        return self::$pattern;
    }
}
