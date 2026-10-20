<?php

namespace App\Services\Catalog;

use App\Config;
use App\Enums\Game;
use App\Models\CatalogEntry;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Repositories\CatalogRows;
use App\Support\Key;

/**
 * Resolves names coming from imports and scraped builds to catalog rows.
 *
 * Resolution order: special cases (the Travelers) -> configured aliases ->
 * exact key -> unique prefix / suffix / infix match. Unknown names can be created on the fly so
 * nothing is lost; they are reported via unmatched().
 *
 * The rows are cached in memory for the length of one request (or command).
 */
class Catalog
{
    public const CHARACTER = 'characters';

    public const WEAPON = 'weapons';

    public const SET = 'sets';

    /** @var array<string, array<string, CatalogEntry>> "game:type" => key => entry */
    private array $byKey = [];

    /** @var array<string, array<string, CatalogEntry>> "game:type" => external id => entry */
    private array $byExternalId = [];

    /** @var array<string, list<string>> "game:type" => names that could not be matched */
    private array $unmatched = [];

    public function __construct(private readonly CatalogRows $rows, private readonly Config $config) {}

    public function character(Game $game, string $name, array $attributes = [], bool $create = true): ?GameCharacter
    {
        /** @var GameCharacter|null */
        return $this->resolve($game, self::CHARACTER, $name, $attributes, $create);
    }

    public function weapon(Game $game, string $name, array $attributes = [], bool $create = true): ?GameWeapon
    {
        /** @var GameWeapon|null */
        return $this->resolve($game, self::WEAPON, $name, $attributes, $create);
    }

    public function set(Game $game, string $name, array $attributes = [], bool $create = true): ?GameSet
    {
        /** @var GameSet|null */
        return $this->resolve($game, self::SET, $name, $attributes + ['kind' => $game->setKinds()[0]], $create);
    }

    public function byExternalId(Game $game, string $type, string|int $id): ?CatalogEntry
    {
        $this->load($game, $type);

        return $this->byExternalId[$game->value . ':' . $type][(string) $id] ?? null;
    }

    /**
     * Find without creating. Returns null when nothing matches.
     */
    public function find(Game $game, string $type, string $name): ?CatalogEntry
    {
        $this->load($game, $type);
        $bucket = $game->value . ':' . $type;
        $key = $this->normalize($game, $type, $name);

        if ($key === '') {
            return null;
        }

        if (isset($this->byKey[$bucket][$key])) {
            return $this->byKey[$bucket][$key];
        }

        return $this->fuzzy($bucket, $key);
    }

    /**
     * Canonical key for a raw name (special cases + aliases applied), without looking at the catalog.
     */
    public function normalize(Game $game, string $type, string $name): string
    {
        $key = Key::from($name);

        if ($type === self::CHARACTER) {
            $key = $this->specialCharacterKey($game, $key);
        }

        $aliases = $this->config->get("aliases.{$game->value}.{$type}", []);

        return $aliases[$key] ?? $key;
    }

    /**
     * Names that had to be created because nothing in the catalog matched.
     *
     * @return array<string, list<string>>
     */
    public function unmatched(): array
    {
        return $this->unmatched;
    }

    public function resetUnmatched(): void
    {
        $this->unmatched = [];
    }

    /**
     * Forget cached rows (after a catalog sync).
     */
    public function flush(): void
    {
        $this->byKey = [];
        $this->byExternalId = [];
    }

    private function resolve(Game $game, string $type, string $name, array $attributes, bool $create): ?CatalogEntry
    {
        $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($name === '') {
            return null;
        }

        $found = $this->find($game, $type, $name);
        if ($found) {
            return $found;
        }

        $bucket = $game->value . ':' . $type;
        $this->unmatched[$bucket][] = $name;

        if (!$create) {
            return null;
        }

        $entry = $this->rows->firstOrCreate($game, $type, $this->normalize($game, $type, $name), ['name' => $name] + $attributes);
        $this->byKey[$bucket][$entry->key] = $entry;

        return $entry;
    }

    private function load(Game $game, string $type): void
    {
        $bucket = $game->value . ':' . $type;
        if (isset($this->byKey[$bucket])) {
            return;
        }

        $this->byKey[$bucket] = [];
        $this->byExternalId[$bucket] = [];

        foreach ($this->rows->all($game, $type) as $entry) {
            $this->byKey[$bucket][$entry->key] = $entry;

            foreach ($entry->externalIds() as $id) {
                $this->byExternalId[$bucket][$id] ??= $entry;
            }
        }
    }

    /**
     * Unique prefix, then suffix, then infix match ("kazuha" -> "kaedeharakazuha", "emblem" -> "emblemofseveredfate").
     */
    private function fuzzy(string $bucket, string $key): ?CatalogEntry
    {
        if (strlen($key) < 4) {
            return null;
        }

        // Only ever match a *longer* catalog name: the reverse ("himekonova" -> "himeko") would silently
        // map a brand-new character onto an older one.
        $strategies = [fn(string $candidate) => str_starts_with($candidate, $key), fn(string $candidate) => str_ends_with($candidate, $key), fn(string $candidate) => str_contains($candidate, $key)];

        foreach ($strategies as $matches) {
            $hits = array_filter(array_keys($this->byKey[$bucket] ?? []), fn($candidate) => $matches((string) $candidate));
            if (count($hits) === 1) {
                return $this->byKey[$bucket][reset($hits)];
            }
        }

        return null;
    }

    private function specialCharacterKey(Game $game, string $key): string
    {
        return match ($game) {
            // The Travelers: one row per element ("Aether (Anemo)", "AnemoTraveler", "traveler_anemo" ...).
            Game::Genshin => preg_match('/^(?:traveler|traveller|aether|lumine)(anemo|geo|electro|dendro|hydro|pyro|cryo)$/', $key, $m) ||
            preg_match('/^(anemo|geo|electro|dendro|hydro|pyro|cryo)(?:traveler|traveller|aether|lumine)$/', $key, $m)
                ? 'traveler' . $m[1]
                : $key,
        };
    }
}
