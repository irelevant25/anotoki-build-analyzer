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
use App\Support\Stats;
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
 * - artifact sets: the key from the name, kind "artifact", meta.rarities and meta.bonuses [2-piece, 4-piece];
 * - a character's and a weapon's base stats (that site's `stats`, since 2026-10-26: HP, ATK and DEF at level 1 and
 *   at level 80 ascended with the ascension stat, a weapon's base ATK at both with its secondary stat) in
 *   meta.stats, the stats' labels read as the catalog's keys - what the stat targets are worked out from
 *   (App\Support\MaxLevelStats takes them to level 90); none where a number or the stat is missing. While a list
 *   carries no `stats` at all (that site's release from before 2026-10-26), the same numbers are read from the
 *   site's own API ("catalog.genshin.site_api": its characters and weapons with their ascension phases, phase 0
 *   and phase 6 of each - the way its catalog endpoint reads them) and matched by the game's id; a phase list
 *   that cannot be read leaves the rows without stats, the list written all the same.
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
        // A list from before the stats: the same numbers from the site's own phase lists, by the game's id.
        if ($list !== Catalog::SET && $items !== [] && !array_any($items, fn(array $item) => array_key_exists('stats', $item))) {
            $stats = $this->phaseStats($list);
            foreach ($items as &$item) {
                $gameId = self::gameId($item['game_id'] ?? null);
                $item['stats'] = $gameId === null ? null : $stats[$gameId] ?? null;
            }
            unset($item);
        }

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
     * The base stats of a list's rows from the site's own API, by the game's id, shaped as its catalog lists shape
     * them since 2026-10-26 (characters: base, lv80, ascension; weapons: base_atk, lv80_atk, secondary): the live
     * rows (`/characters` with their ascension set, `/weapons` with their secondary stat's label) and their
     * phases (`/characters-ascensions` by set, `/weapons-ascensions` by weapon), phase 0 (level 1) and phase 6
     * (level 80 ascended), the lowest id of each. Nothing while the site's API is not named, and none for a list
     * that cannot be read.
     *
     * @return array<int, array<string, mixed>>
     */
    private function phaseStats(string $list): array
    {
        $site = $this->config->get('catalog.genshin.site_api');
        if (!is_string($site) || $site === '') {
            return [];
        }
        $site = rtrim($site, '/');
        $rows = $this->siteList("{$site}/" . ($list === Catalog::CHARACTER ? 'characters' : 'weapons'));
        $phases = $this->siteList("{$site}/" . ($list === Catalog::CHARACTER ? 'characters-ascensions' : 'weapons-ascensions'));
        if ($rows === null || $phases === null) {
            return [];
        }

        // Phase 0 and 6 per set (or weapon), the lowest id of each, deleted rows left out.
        $owner = $list === Catalog::CHARACTER ? 'ascension_set_id' : 'weapon_id';
        $byOwner = [];
        foreach ($phases as $phase) {
            if (($phase['deleted'] ?? false) === true || !in_array((int) ($phase['phase'] ?? -1), [0, 6], true) || self::gameId($phase[$owner] ?? null) === null) {
                continue;
            }
            $byOwner[(int) $phase[$owner]][(int) $phase['phase']] ??= $phase;
        }
        $number = fn(mixed $value) => is_int($value) || is_float($value) ? round((float) $value, 2) : null;

        $stats = [];
        foreach ($rows as $row) {
            $gameId = self::gameId($row['game_id'] ?? null);
            $ownerId = self::gameId($row[$list === Catalog::CHARACTER ? 'ascension_set_id' : 'id'] ?? null);
            if (($row['deleted'] ?? false) === true || $gameId === null || $ownerId === null || !isset($byOwner[$ownerId][0], $byOwner[$ownerId][6]) || isset($stats[$gameId])) {
                continue;
            }
            [$first, $last] = [$byOwner[$ownerId][0], $byOwner[$ownerId][6]];
            if ($list === Catalog::CHARACTER) {
                $level = fn(array $phase) => [
                    'hp' => $number($phase['start_level_hp'] ?? null),
                    'atk' => $number($phase['start_level_atk'] ?? null),
                    'def' => $number($phase['start_level_def'] ?? null),
                ];
                $stats[$gameId] = [
                    'base' => $level($first),
                    'lv80' => $level($last),
                    'ascension' => ['stat' => $last['primary_stat'] ?? null, 'value' => $number($last['primary_stat_value'] ?? null)],
                ];
            } else {
                $secondary = $number($first['secondary_stat_value'] ?? null);
                $stats[$gameId] = [
                    'base_atk' => $number($first['primary_stat_value'] ?? null),
                    'lv80_atk' => $number($last['primary_stat_value'] ?? null),
                    'secondary' => is_string($row['secondary_stat'] ?? null) && $secondary !== null && $secondary > 0 ? ['stat' => $row['secondary_stat'], 'value' => $secondary] : null,
                ];
            }
        }

        return $stats;
    }

    /**
     * A list of the site's own API, read as its catalog lists are read (the same limits); null when it cannot be.
     *
     * @return list<array<string, mixed>>|null
     */
    private function siteList(string $url): ?array
    {
        try {
            $response = $this->fetcher->request($url, ['headers' => ['Accept' => 'application/json']]);
            if ($response->status !== 200 || strlen($response->body) > self::MAX_BYTES) {
                return null;
            }
            $items = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($items) || !array_is_list($items) || count($items) > self::MAX_ITEMS) {
            return null;
        }

        return array_values(array_filter($items, 'is_array'));
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
                'meta' => self::withStats([], self::characterStats($item['stats'] ?? null)),
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
                'row' =>
                    ['name' => 'Traveler (' . ucfirst($element) . ')', 'external_id' => $ids[0] ?? null, 'meta' => self::withStats(['ids' => $ids], $twins[0]['row']['meta']['stats'] ?? null)] +
                    $twins[0]['row'],
            ];
        }

        return $rows;
    }

    /**
     * The row's meta with its stats, where the provider gave some the catalog can read; without the key otherwise.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $stats
     * @return array<string, mixed>
     */
    private static function withStats(array $meta, ?array $stats): array
    {
        return $stats === null ? $meta : $meta + ['stats' => $stats];
    }

    /**
     * A character's base stats as that site's phases hold them (its `stats`): HP, ATK and DEF at level 1 and at
     * level 80 ascended, and the ascension stat - its label read as the catalog's key - with its whole value.
     * MaxLevelStats takes them to level 90. None unless every number is there and the ascension stat is one the
     * game has.
     *
     * @return array{base: array{hp: float, atk: float, def: float}, lv80: array{hp: float, atk: float, def: float}, ascension: array{stat: string, value: float}}|null
     */
    private static function characterStats(mixed $stats): ?array
    {
        if (!is_array($stats)) {
            return null;
        }
        $base = self::levelStats($stats['base'] ?? null);
        $lv80 = self::levelStats($stats['lv80'] ?? null);
        $ascension = self::statValue($stats['ascension'] ?? null);

        return $base === null || $lv80 === null || $ascension === null ? null : ['base' => $base, 'lv80' => $lv80, 'ascension' => $ascension];
    }

    /**
     * A weapon's base ATK at level 1 and at level 80 ascended, and its secondary stat at level 1 - its label read
     * as the catalog's key; none for a weapon without one, or with one the game does not have.
     *
     * @return array{base_atk: float, lv80_atk: float, secondary: array{stat: string, value: float}|null}|null
     */
    private static function weaponStats(mixed $stats): ?array
    {
        if (!is_array($stats)) {
            return null;
        }
        $base = self::number($stats['base_atk'] ?? null);
        $lv80 = self::number($stats['lv80_atk'] ?? null);

        return $base === null || $lv80 === null ? null : ['base_atk' => $base, 'lv80_atk' => $lv80, 'secondary' => self::statValue($stats['secondary'] ?? null)];
    }

    /** @return array{hp: float, atk: float, def: float}|null */
    private static function levelStats(mixed $level): ?array
    {
        if (!is_array($level)) {
            return null;
        }
        $hp = self::number($level['hp'] ?? null);
        $atk = self::number($level['atk'] ?? null);
        $def = self::number($level['def'] ?? null);

        return $hp === null || $atk === null || $def === null ? null : ['hp' => $hp, 'atk' => $atk, 'def' => $def];
    }

    /**
     * A stat under that site's label ("Crit DMG %", "Elemental Mastery") with its value, as the catalog's key; none
     * for a label the game's stats do not answer to, or no value.
     *
     * @return array{stat: string, value: float}|null
     */
    private static function statValue(mixed $stat): ?array
    {
        if (!is_array($stat) || !is_string($stat['stat'] ?? null)) {
            return null;
        }
        $keys = Stats::parse(Game::Genshin, str_replace('%', '', $stat['stat']));
        $value = self::number($stat['value'] ?? null);

        return count($keys) !== 1 || $value === null ? null : ['stat' => $keys[0], 'value' => $value];
    }

    /** A number of the stats, nought or more, as a float; null for anything else. */
    private static function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite($value) && $value >= 0 && $value <= 1_000_000 ? round((float) $value, 2) : null;
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
                    'meta' => self::withStats([], self::weaponStats($item['stats'] ?? null)),
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
