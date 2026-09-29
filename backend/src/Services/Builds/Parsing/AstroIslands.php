<?php

namespace App\Services\Builds\Parsing;

use Dom\HTMLDocument;

/**
 * Reads the props of Astro "islands" (<astro-island component-url="…" props="…">).
 *
 * Astro serializes props as [type, value] tuples: 0 = plain value (objects recurse), 1 = array.
 */
final class AstroIslands
{
    /**
     * @return list<array{component: string, props: array<string, mixed>}>
     */
    public static function parse(string $html): array
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $islands = [];

        foreach ($document->querySelectorAll('astro-island') as $element) {
            $raw = $element->getAttribute('props');
            $props = $raw ? json_decode($raw, true) : null;
            $islands[] = [
                'component' => (string) $element->getAttribute('component-url'),
                'props' => is_array($props) ? self::reviveObject($props) : [],
            ];
        }

        return $islands;
    }

    /**
     * Props of the first island whose component URL contains $component (e.g. "CharacterBuildTabs").
     */
    public static function find(string $html, string $component): ?array
    {
        foreach (self::parse($html) as $island) {
            if (str_contains($island['component'], $component)) {
                return $island['props'];
            }
        }

        return null;
    }

    private static function reviveObject(array $object): array
    {
        $result = [];
        foreach ($object as $key => $value) {
            $result[$key] = self::revive($value);
        }

        return $result;
    }

    private static function revive(mixed $value): mixed
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return $value;
        }

        $type = $value[0];
        $inner = $value[1] ?? null;

        return match ($type) {
            0 => is_array($inner) && ! array_is_list($inner) ? self::reviveObject($inner) : $inner,
            1 => is_array($inner) ? array_map(self::revive(...), $inner) : [],
            default => $inner,
        };
    }
}
