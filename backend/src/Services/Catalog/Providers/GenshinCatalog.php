<?php

namespace App\Services\Catalog\Providers;

use App\Config;
use App\Enums\Game;
use App\Http\Fetcher;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\CatalogAnswer;
use App\Services\Catalog\CatalogProvider;
use App\Support\Clock;
use App\Support\Key;
use DateTimeImmutable;
use JsonException;
use Throwable;

/**
 * Genshin Impact's catalog from the owner's genshin.anotoki.eu: its public lists GET {api}/characters, /weapons and
 * /artifacts (config/app.php "catalog.genshin.api") - bare JSON lists of its live rows, with an ETag for a
 * conditional request (that site's catalog endpoint).
 *
 * How its rows become the catalog's:
 * - characters: the key from the name, the element lowercased (one the game does not have - "Adaptive" - none), the
 *   weapon type lowercased, rarity 1-5, region, version ("6.1") and version_name ("Luna II") as they are, released_at
 *   the release date, external_id the in-game ID. The Travelers (is_traveler: a row per twin and element) become one
 *   entry per element - "traveleranemo", "Traveler (Anemo)" - with external_id "{lowest in-game ID}-{element}" and
 *   both twins' in meta.ids, the way imports have always named them;
 * - weapons: the key from the name, the type lowercased, rarity; of two with one key (a quest copy) the lowest
 *   in-game ID;
 * - artifact sets: the key from the name, kind "artifact", meta.rarities and meta.bonuses [2-piece, 4-piece].
 * An icon is kept only on the API's own host: the icon route (api/routes/icons.php) fetches nothing else. A row the
 * catalog cannot read (no name) is left out; an answer larger than MAX_BYTES, or of more than MAX_ITEMS rows, is
 * refused as a whole.
 */
final class GenshinCatalog implements CatalogProvider
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_ITEMS = 5000;

    private const PATHS = [Catalog::CHARACTER => 'characters', Catalog::WEAPON => 'weapons', Catalog::SET => 'artifacts'];

    /** The icon columns' length (game_characters.icon_url and the others, VARCHAR(500)). */
    private const MAX_ICON_URL = 500;

    public function __construct(private readonly Fetcher $fetcher, private readonly Config $config) {}

    public function fetch(Game $game, string $list, ?string $etag): CatalogAnswer
    {
        $api = rtrim((string) $this->config->get("catalog.{$game->value}.api"), '/');
        $headers = ['Accept' => 'application/json'] + ($etag !== null && $etag !== '' ? ['If-None-Match' => $etag] : []);
        try {
            $response = $this->fetcher->request("{$api}/" . self::PATHS[$list], ['headers' => $headers]);
        } catch (Throwable) {
            return CatalogAnswer::none(CatalogAnswer::FAILED, 'The game data provider could not be reached.');
        }

        return match (true) {
            $response->status === 304 => CatalogAnswer::unchanged($etag),
            in_array($response->status, [401, 423, 503], true) => CatalogAnswer::none(CatalogAnswer::CLOSED, "The game data provider is closed for now (HTTP {$response->status})."),
            $response->status === 404 => CatalogAnswer::none(CatalogAnswer::UNAVAILABLE, 'The game data provider has no such list.'),
            $response->status !== 200 => CatalogAnswer::none(CatalogAnswer::FAILED, "The game data provider answered HTTP {$response->status}."),
            default => $this->read($response->body, $response->header('ETag'), $list, (string) parse_url($api, PHP_URL_HOST)),
        };
    }

    private function read(string $body, ?string $etag, string $list, string $host): CatalogAnswer
    {
        if (strlen($body) > self::MAX_BYTES) {
            return CatalogAnswer::none(CatalogAnswer::FAILED, 'The game data provider answered with more than ' . self::MAX_BYTES / 1048576 . ' MB.');
        }
        try {
            $items = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return CatalogAnswer::none(CatalogAnswer::FAILED, 'The game data provider answered with something that is not JSON.');
        }
        if (!is_array($items) || !array_is_list($items)) {
            return CatalogAnswer::none(CatalogAnswer::FAILED, 'The game data provider answered with something that is not a list.');
        }
        if (count($items) > self::MAX_ITEMS) {
            return CatalogAnswer::none(CatalogAnswer::FAILED, 'The game data provider answered with more than ' . self::MAX_ITEMS . ' entries.');
        }
        $items = array_values(array_filter($items, fn($item) => is_array($item) && self::text($item['name'] ?? null) !== null));

        return CatalogAnswer::changed(
            match ($list) {
                Catalog::CHARACTER => $this->characters($items, $host),
                Catalog::WEAPON => $this->weapons($items, $host),
                Catalog::SET => $this->sets($items, $host),
            },
            $etag,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{key: string, row: array<string, mixed>}>
     */
    private function characters(array $items, string $host): array
    {
        $rows = [];
        $travelers = [];
        foreach ($items as $item) {
            $name = (string) self::text($item['name']);
            $element = self::oneOf($item['element'] ?? null, Game::Genshin->elements());
            $gameId = self::gameId($item['game_id'] ?? null);
            $row = [
                'name' => $name,
                'element' => $element,
                'rarity' => self::rarity($item['rarity'] ?? null),
                'weapon_type' => self::oneOf($item['weapon_type'] ?? null, Game::Genshin->weaponTypes()),
                'region' => self::text($item['region'] ?? null, 50),
                'version' => self::version($item['version'] ?? null),
                'version_name' => self::text($item['version_name'] ?? null, 40),
                'released_at' => self::date($item['release_date'] ?? null),
                'icon_url' => self::icon($item['icon'] ?? null, $host),
                'external_id' => $gameId === null ? null : (string) $gameId,
            ];
            if (($item['is_traveler'] ?? false) === true) {
                // The twins are one entry per element; one with no element of the game's is none.
                if ($element !== null) {
                    $travelers[$element][] = ['id' => $gameId, 'row' => $row];
                }

                continue;
            }
            $rows[] = ['key' => Key::from($name), 'row' => $row];
        }

        foreach ($travelers as $element => $twins) {
            usort($twins, fn(array $a, array $b) => ($a['id'] ?? PHP_INT_MAX) <=> ($b['id'] ?? PHP_INT_MAX));
            $ids = array_values(array_unique(array_map(fn(array $twin) => "{$twin['id']}-{$element}", array_filter($twins, fn(array $twin) => $twin['id'] !== null))));
            $rows[] = [
                'key' => 'traveler' . $element,
                'row' => ['name' => 'Traveler (' . ucfirst($element) . ')', 'external_id' => $ids[0] ?? null, 'meta' => ['ids' => $ids]] + $twins[0]['row'],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{key: string, row: array<string, mixed>}>
     */
    private function weapons(array $items, string $host): array
    {
        // A weapon there twice under one key (a quest copy): the lowest in-game ID's.
        usort($items, fn(array $a, array $b) => (self::gameId($a['game_id'] ?? null) ?? PHP_INT_MAX) <=> (self::gameId($b['game_id'] ?? null) ?? PHP_INT_MAX));
        $rows = [];
        foreach ($items as $item) {
            $name = (string) self::text($item['name']);
            $key = Key::from($name);
            if (isset($rows[$key])) {
                continue;
            }
            $gameId = self::gameId($item['game_id'] ?? null);
            $rows[$key] = [
                'key' => $key,
                'row' => [
                    'name' => $name,
                    'rarity' => self::rarity($item['rarity'] ?? null),
                    'weapon_type' => self::oneOf($item['type'] ?? null, Game::Genshin->weaponTypes()),
                    'external_id' => $gameId === null ? null : (string) $gameId,
                    'icon_url' => self::icon($item['icon'] ?? null, $host),
                ],
            ];
        }

        return array_values($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{key: string, row: array<string, mixed>}>
     */
    private function sets(array $items, string $host): array
    {
        $rows = [];
        foreach ($items as $item) {
            $name = (string) self::text($item['name']);
            $gameId = self::gameId($item['game_id'] ?? null);
            $rarities = array_values(array_filter(array_map(fn($rarity) => self::rarity($rarity), is_array($item['rarities'] ?? null) ? $item['rarities'] : [])));
            $rows[] = [
                'key' => Key::from($name),
                'row' => [
                    'name' => $name,
                    'kind' => 'artifact',
                    'external_id' => $gameId === null ? null : (string) $gameId,
                    'icon_url' => self::icon($item['icon'] ?? null, $host),
                    'meta' => [
                        'rarities' => $rarities,
                        'bonuses' => array_values(array_filter([self::text($item['two_piece'] ?? null, 2000), self::text($item['four_piece'] ?? null, 2000)], fn($bonus) => $bonus !== null)),
                    ],
                ],
            ];
        }

        return $rows;
    }

    /** Text with something in it, its markup gone, at most $max characters; null otherwise. */
    private static function text(mixed $value, int $max = 100): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' || mb_strlen($text) > $max ? null : $text;
    }

    /**
     * A value of the game's set, as the catalog writes it ("Pyro" -> "pyro"); null for one it does not have.
     *
     * @param  list<string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : null;

        return in_array($value, $allowed, true) ? $value : null;
    }

    private static function gameId(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 && $value <= 2_147_483_647 ? $value : null;
    }

    private static function rarity(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 && $value <= 5 ? $value : null;
    }

    /** A version as the game numbers it ("6.1"); anything else none (a name like "Luna II" is version_name's). */
    private static function version(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{1,2}\.\d{1,2}$/D', $value) ? $value : null;
    }

    /** A day ("2021-03-02") as the moment it began, in UTC. */
    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, Clock::utc());

        return $day !== false && $day->format('Y-m-d') === $value ? $day : null;
    }

    /**
     * An icon's address on the API's own host (https; http only for this computer), or none. The icon route fetches
     * it as it is, so nothing two URL readers could read two ways passes: no space or character beyond ASCII, no
     * backslash, no "user@" before the host.
     */
    private static function icon(mixed $value, string $host): ?string
    {
        if (!is_string($value) || strlen($value) > self::MAX_ICON_URL || $host === '' || preg_match('~[^\x21-\x7E]|[\\\\@]~', $value)) {
            return null;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $iconHost = strtolower((string) parse_url($value, PHP_URL_HOST));
        $loopback = in_array($iconHost, ['localhost', '127.0.0.1', '::1', '[::1]'], true);

        return $iconHost === strtolower($host) && ($scheme === 'https' || ($scheme === 'http' && $loopback)) ? $value : null;
    }
}
