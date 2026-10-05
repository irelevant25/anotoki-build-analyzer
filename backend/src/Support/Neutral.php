<?php

namespace App\Support;

/**
 * Keeps other websites out of everything the API sends to be shown.
 *
 * The app reads builds from community sites, game data from a data provider and showcases from an API
 * service, and none of them may be named, linked or credited in the UI. What this code writes itself says
 * "Source 1", "the showcase service" and so on, but text that comes from elsewhere can still carry a
 * name or an address: an exception message ("HTTP 403 from https://..."), a curl error ("Could not
 * resolve host: ..."), a note copied from a build page, the log of a run written by an older version.
 * text() is applied to all of that on the way out (and to run logs on the way in as well).
 *
 * The backend's own code, configuration and docs name the sites freely; they are internal.
 */
final class Neutral
{
    /** Web addresses, with or without a scheme. */
    private const URL = '~\b(?:https?://|www\.)[^\s"\'<>()\[\]]+~i';

    /**
     * Names and host names of the sites, services and tools involved, longest first within a family so a
     * domain is replaced as a whole. Matched case-insensitively on word boundaries.
     */
    private const NAMES = [
        'genshin-builds.com', 'genshin-builds', 'genshinbuilds',
        'www.prydwen.gg', 'prydwen.gg', 'prydwen',
        'genshin.gg', 'genshin-gg',
        'enka.network', 'enka network', 'enkanetwork', 'enka',
        'gi.yatta.moe', 'sr.yatta.moe', 'yatta.moe', 'project amber', 'yatta',
        'raw.githubusercontent.com', 'githubusercontent', 'github.com', 'github',
        'game8', 'keqingmains', 'keqing mains', 'mobalytics',
        'irminsul', 'inventory kamera', 'inventory_kamera', 'goodscanner', 'genshin optimizer',
        'reliquary archiver', 'reliquary_archiver', 'reliquary-archiver', 'hsr-scanner', 'hsrscanner', 'yas-scanner',
        'fribbels', 'npcap',
    ];

    public static function text(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $clean = preg_replace(self::URL, 'the source page', $text) ?? $text;
        $clean = preg_replace(self::namesPattern(), 'the source', $clean) ?? $clean;
        // "The source page" at the start of a sentence reads better capitalised.
        $clean = preg_replace_callback('/(^|[.!?]\s+|\n\s*)(the source)/', fn (array $m) => $m[1].'The source', $clean) ?? $clean;

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

    /**
     * The names of other sites, services and tools found in a text, lowercased (tools/build.php runs
     * it over the built frontend, whose framework code carries web addresses of its own).
     *
     * @return list<string>
     */
    public static function namesIn(string $text): array
    {
        preg_match_all(self::namesPattern(), $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[0])));
    }

    private static function namesPattern(): string
    {
        static $pattern = null;

        return $pattern ??= '~(?<![\w.-])(?:'.implode('|', array_map(fn (string $name) => preg_quote($name, '~'), self::NAMES)).')(?![\w-])~i';
    }
}
