<?php

namespace App\Services\Builds\Parsing;

/**
 * Reads the React Server Components payload that Next.js App Router pages embed as
 * <script>self.__next_f.push([1,"…"])</script> chunks.
 *
 * The chunks concatenate into newline-separated rows "<id>:<json>"; component props are the 4th element
 * of ["$", "$L1f", null, {props}] arrays.
 */
final class NextFlight
{
    public static function payload(string $html): string
    {
        preg_match_all('/self\.__next_f\.push\((\[.*?\])\)\s*<\/script>/s', $html, $matches);

        $payload = '';
        foreach ($matches[1] as $chunk) {
            $decoded = json_decode($chunk, true);
            if (is_array($decoded) && ($decoded[0] ?? null) === 1 && is_string($decoded[1] ?? null)) {
                $payload .= $decoded[1];
            }
        }

        return $payload;
    }

    /**
     * Find the first props object (anywhere in the payload) that has the given key, e.g. "statBuilds".
     */
    public static function findProps(string $html, string $key): ?array
    {
        foreach (self::rowsContaining(self::payload($html), '"' . $key . '"') as $row) {
            $found = self::search($row, $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Split the payload into rows. Most rows are "<hex id>:<json>\n", but text rows are
     * "<hex id>:T<hex byte length>,<text>" with no newline after the text, so they must be read by length.
     *
     * @return array<string, string> row id => raw row content (text rows prefixed with "T:")
     */
    public static function rows(string $payload): array
    {
        $rows = [];
        $position = 0;
        $length = strlen($payload);

        while ($position < $length) {
            $colon = strpos($payload, ':', $position);
            if ($colon === false) {
                break;
            }
            $id = trim(substr($payload, $position, $colon - $position));
            $position = $colon + 1;

            if (($payload[$position] ?? '') === 'T' && preg_match('/\GT([0-9a-f]+),/A', $payload, $m, 0, $position)) {
                $size = (int) hexdec($m[1]);
                $start = $position + strlen($m[0]);
                $rows[$id] = 'T:' . substr($payload, $start, $size);
                $position = $start + $size;

                continue;
            }

            $newline = strpos($payload, "\n", $position);
            $end = $newline === false ? $length : $newline;
            $rows[$id] = substr($payload, $position, $end - $position);
            $position = $end + 1;
        }

        return $rows;
    }

    /**
     * @return iterable<mixed>
     */
    private static function rowsContaining(string $payload, string $needle): iterable
    {
        foreach (self::rows($payload) as $row) {
            // Skip text rows and module/hint rows ("I[...]", "HL[...]"); only JSON rows carry props.
            if (str_starts_with($row, 'T:') || !str_contains($row, $needle)) {
                continue;
            }
            $json = json_decode($row, true);
            if ($json !== null) {
                yield $json;
            }
        }
    }

    /**
     * Depth-first search for an associative array containing $key.
     */
    private static function search(mixed $node, string $key, int $depth = 0): ?array
    {
        if (!is_array($node) || $depth > 40) {
            return null;
        }
        if (!array_is_list($node) && array_key_exists($key, $node)) {
            return $node;
        }
        foreach ($node as $child) {
            $found = self::search($child, $key, $depth + 1);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
