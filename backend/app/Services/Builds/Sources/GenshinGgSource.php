<?php

namespace App\Services\Builds\Sources;

use App\Services\Builds\BuildSource;
use App\Services\Builds\CharacterRef;
use App\Services\Http\Fetcher;
use App\Support\Key;
use RuntimeException;

/**
 * genshin.gg is a React app whose whole dataset (both games) ships in one JS bundle as
 * JSON.parse('…') literals. One download per sync covers every character, so this source is cheap.
 */
abstract class GenshinGgSource implements BuildSource
{
    /** @var array<string, list<array<string, mixed>>> bundle URL => decoded arrays (shared by both games) */
    private static array $bundles = [];

    /** @var array<string, array<string, mixed>> slug => raw character */
    private array $characters = [];

    public function __construct(protected readonly Fetcher $fetcher) {}

    public function name(): string
    {
        return 'genshin.gg';
    }

    public function baseUrl(): string
    {
        return 'https://genshin.gg';
    }

    public function delayMs(): int
    {
        return 1500;
    }

    /**
     * Does a decoded array look like this game's character list?
     */
    abstract protected function isCharacterList(array $first): bool;

    abstract protected function characterUrl(string $slug): string;

    public function listCharacters(): array
    {
        $list = null;
        foreach ($this->bundle() as $array) {
            if (isset($array[0]) && is_array($array[0]) && $this->isCharacterList($array[0])) {
                $list = $array;
                break;
            }
        }
        if ($list === null) {
            throw new RuntimeException('Character data not found in the genshin.gg bundle');
        }

        $refs = [];
        $this->characters = [];
        foreach ($list as $character) {
            if (empty($character['name'])) {
                continue;
            }
            $slug = Key::from($character['name']);
            $this->characters[$slug] = $character;
            $refs[] = new CharacterRef(
                slug: $slug,
                name: $character['name'],
                url: $this->characterUrl($slug),
                meta: ['element' => isset($character['type']) ? strtolower($character['type']) : (isset($character['element']) ? strtolower($character['element']) : null)],
            );
        }

        return $refs;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function raw(CharacterRef $character): ?array
    {
        if ($this->characters === []) {
            $this->listCharacters();
        }

        return $this->characters[$character->slug] ?? null;
    }

    /**
     * @return list<array<mixed>>
     */
    protected function bundle(): array
    {
        $options = ['browser' => true, 'delay_ms' => $this->delayMs(), 'cache_hours' => (int) config('hoyo.http.cache_hours')];
        $manifest = $this->fetcher->getJson($this->baseUrl().'/asset-manifest.json', $options);
        $path = $manifest['files']['main.js'] ?? throw new RuntimeException('main.js missing from genshin.gg asset manifest');
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl().'/'.ltrim($path, '/');

        return self::$bundles[$url] ??= self::decodeBundle($this->fetcher->get($url, $options));
    }

    /**
     * Decode every JSON.parse('…') literal in a JS bundle.
     *
     * @return list<array<mixed>>
     */
    public static function decodeBundle(string $js): array
    {
        $arrays = [];
        $offset = 0;
        while (($start = strpos($js, "JSON.parse('", $offset)) !== false) {
            $start += strlen("JSON.parse('");
            $end = $start;
            // Find the closing quote that is not escaped.
            while (($end = strpos($js, "')", $end)) !== false) {
                $backslashes = 0;
                for ($i = $end - 1; $i >= $start && $js[$i] === '\\'; $i--) {
                    $backslashes++;
                }
                if ($backslashes % 2 === 0) {
                    break;
                }
                $end++;
            }
            if ($end === false) {
                break;
            }

            $decoded = json_decode(self::jsStringToJson(substr($js, $start, $end - $start)), true);
            if (is_array($decoded)) {
                $arrays[] = $decoded;
            }
            $offset = $end + 2;
        }

        return $arrays;
    }

    /**
     * The literal is JSON text wrapped in a single-quoted JS string. Undo the JS escaping while keeping
     * escapes that JSON itself needs (\n, \t, \uXXXX ...), so json_decode accepts the result.
     */
    private static function jsStringToJson(string $literal): string
    {
        return preg_replace_callback('/\\\\(x[0-9a-fA-F]{2}|u[0-9a-fA-F]{4}|\r\n|[\s\S])/', function (array $m) {
            $escape = $m[1];

            return match (true) {
                $escape === '\\' => '\\',
                $escape === "'" => "'",
                $escape === '"' => '\\"',
                in_array($escape, ['n', 'r', 't', 'b', 'f'], true) => '\\'.$escape,
                $escape === 'v' => '\\u000b',
                $escape === '0' => '\\u0000',
                $escape[0] === 'x' => '\\u00'.substr($escape, 1),
                $escape[0] === 'u' => '\\'.$escape,
                $escape === "\n" || $escape === "\r\n" => '', // line continuation
                default => $escape,
            };
        }, $literal) ?? $literal;
    }

    /**
     * Some entries hold alternatives on separate lines ("Tenacity of the Millelith\nNoblesse Oblige");
     * each line is its own ranked option.
     *
     * @return list<string>
     */
    protected function lines(array $entries): array
    {
        $lines = [];
        foreach ($entries as $entry) {
            foreach (preg_split('/\R+/u', (string) $entry) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * "The Catch R5" => ["The Catch", 5].
     *
     * @return array{0: string, 1: int|null}
     */
    protected function splitRefinement(string $name): array
    {
        if (preg_match('/^(.*?)\s*\((?:R|S)([1-5])\)$|^(.*?)\s+(?:R|S)([1-5])$/', trim($name), $m)) {
            $base = $m[1] !== '' ? $m[1] : $m[3];
            $level = $m[2] !== '' ? $m[2] : $m[4];

            return [trim($base), (int) $level];
        }

        return [trim($name), null];
    }

    /**
     * "A" => 4pc A, "A + B" => 2pc A + 2pc B.
     */
    protected function setOption(string $text, string $kind): ?array
    {
        $names = array_values(array_filter(array_map('trim', preg_split('/\s*\+\s*/', $text) ?: [])));
        $names = array_map(fn ($name) => trim(preg_replace('/^\(?[24]\s*-?\s*p(?:c|iece)?\)?\s*/i', '', $name) ?? $name), $names);
        if ($names === []) {
            return null;
        }

        if ($kind === 'planar' || count($names) === 1) {
            return ['kind' => $kind, 'choose' => null, 'pieces' => [['names' => [$names[0]], 'label' => $names[0], 'count' => $kind === 'planar' ? 2 : 4]], 'note' => null];
        }

        return [
            'kind' => $kind,
            'choose' => null,
            'pieces' => array_map(fn ($name) => ['names' => [$name], 'label' => $name, 'count' => 2], array_slice($names, 0, 2)),
            'note' => null,
        ];
    }
}
