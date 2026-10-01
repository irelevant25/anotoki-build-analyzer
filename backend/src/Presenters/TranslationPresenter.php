<?php

namespace App\Presenters;

use App\Repositories\Translations;
use App\Services\Translations\TranslationCheck;

/**
 * The site's own words as the API sends them (mirrored in frontend/src/app/core/models.ts): a language, the
 * bundle a page reads its strings from, and the grid the owner edits them in.
 */
final class TranslationPresenter
{
    public function __construct(private readonly Translations $translations) {}

    /**
     * A language as the owner's pages read it; seeded: one the site is released in, which cannot be
     * deleted; strings: how many it has.
     *
     * @param  array<string, mixed>  $row
     */
    public static function language(array $row): array
    {
        return [
            'code' => $row['code'],
            'name' => $row['name'],
            'native_name' => $row['native_name'],
            'enabled' => (bool) $row['enabled'],
            'sort_order' => (int) $row['sort_order'],
            'seeded' => in_array($row['code'], TranslationCheck::SEEDED, true),
            'strings' => (int) ($row['strings'] ?? 0),
        ];
    }

    /** Every language, the ones switched off too, in the order a chooser shows them. */
    public function languages(): array
    {
        return array_map(self::language(...), $this->translations->languages());
    }

    /**
     * What the site reads its words from: GET /api/translations/{code}, for a code that is one
     * (TranslationCheck::codeOk). `values` is the language's strings with English under them, so a key with
     * no string in it reads in English; `languages` the ones offered; `english` the fallback's own strings,
     * for the pages that are always English - left out when that is what `values` is.
     *
     * `language` says which language it really is. A code with a region that is not offered itself is
     * answered by its language when that one is: "sk-sk" - a Slovak browser's first wish - by "sk", in this
     * one request rather than with English and a second request before the first page. An offered "pt-br"
     * is itself. Anything else the site does not have, or has switched off, is answered in English.
     *
     * @return array{language: string, languages: list<array{code: string, name: string, native_name: string}>, values: object, english?: object}
     */
    public function bundle(string $code): array
    {
        $offered = $this->translations->offered();
        $codes = array_column($offered, 'code');
        $base = substr($code, 0, 2); // of a code that is one: its language, without the region
        $language = match (true) {
            in_array($code, $codes, true) => $code,
            in_array($base, $codes, true) => $base,
            default => TranslationCheck::FALLBACK,
        };

        $english = $this->translations->of(TranslationCheck::FALLBACK);
        $bundle = [
            'language' => $language,
            'languages' => array_map(static fn (array $row): array => ['code' => $row['code'], 'name' => $row['name'], 'native_name' => $row['native_name']], $offered),
        ];
        if ($language === TranslationCheck::FALLBACK) {
            $bundle['values'] = self::map($english);

            return $bundle;
        }

        $values = $this->translations->of($language) + $english;
        ksort($values, SORT_STRING);
        $bundle['values'] = self::map($values);
        $bundle['english'] = self::map($english);

        return $bundle;
    }

    /**
     * Every key with its strings in every language, and the languages:
     * {languages: [all], keys: [{name, description, values: {en: "...", sk: "..."}}]}, the keys by name - so
     * the forms of a plural (.few, .one, .other) are together.
     */
    public function grid(): array
    {
        $keys = [];
        foreach ($this->translations->keys() as $row) {
            $keys[$row['name']] = ['name' => $row['name'], 'description' => (string) ($row['description'] ?? ''), 'values' => []];
        }
        foreach ($this->translations->strings() as $row) {
            $keys[$row['key_name']]['values'][$row['language_code']] = $row['value'];
        }

        return [
            'languages' => $this->languages(),
            'keys' => array_map(
                static fn (array $key): array => ['name' => $key['name'], 'description' => $key['description'], 'values' => self::map($key['values'])],
                array_values($keys)
            ),
        ];
    }

    /** A map that goes out as a JSON object, empty or not: PHP would write an empty one as []. */
    public static function map(array $map): object
    {
        return (object) $map;
    }

    /** The tag of an answer's body: what the browser sends back to ask "is it still this?". */
    public static function etag(string $body): string
    {
        return '"'.md5($body).'"';
    }

    /**
     * Does the browser hold this very answer already? If-None-Match compared the weak way, which is what a
     * conditional GET asks for: any of the tags it lists, or *. A tag comes back as whatever stood between
     * the site and the browser made of it, so both marks of a compressed answer are taken off first: W/ in
     * front (a proxy that compressed it weakens the tag), and -gzip, -br or -zstd inside the quotes (a web
     * server that compresses adds it, and cannot be told not to from an .htaccess). Without the second the
     * 304 would never be answered behind such a server. The tag itself is 32 hex digits, so it never ends
     * that way on its own.
     */
    public static function notModified(string $ifNoneMatch, string $etag): bool
    {
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            $candidate = (string) preg_replace('/-(gzip|br|zstd)"$/D', '"', $candidate);
            if ($candidate === '*' || $candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
