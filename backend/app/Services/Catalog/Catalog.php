<?php

namespace App\Services\Catalog;

use App\Enums\Game;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Support\Key;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves names coming from imports and scraped builds to catalog rows.
 *
 * Resolution order: special cases (Travelers, Trailblazers, March 7th) -> configured aliases ->
 * exact key -> unique prefix / suffix / infix match. Unknown names can be created on the fly so
 * nothing is lost; they are reported via unmatched().
 */
class Catalog
{
    public const CHARACTER = 'characters';

    public const WEAPON = 'weapons';

    public const SET = 'sets';

    private const MODELS = [
        self::CHARACTER => GameCharacter::class,
        self::WEAPON => GameWeapon::class,
        self::SET => GameSet::class,
    ];

    /** @var array<string, array<string, Model>> "game:type" => key => model */
    private array $byKey = [];

    /** @var array<string, array<string, Model>> "game:type" => external id => model */
    private array $byExternalId = [];

    /** @var array<string, list<string>> "game:type" => names that could not be matched */
    private array $unmatched = [];

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
        return $this->resolve($game, self::SET, $name, $attributes + ['kind' => $game === Game::Genshin ? 'artifact' : 'relic'], $create);
    }

    public function byExternalId(Game $game, string $type, string|int $id): ?Model
    {
        $this->load($game, $type);

        return $this->byExternalId[$game->value.':'.$type][(string) $id] ?? null;
    }

    /**
     * Find without creating. Returns null when nothing matches.
     */
    public function find(Game $game, string $type, string $name): ?Model
    {
        $this->load($game, $type);
        $bucket = $game->value.':'.$type;
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

        $aliases = config("hoyo.aliases.{$game->value}.{$type}", []);

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

    private function resolve(Game $game, string $type, string $name, array $attributes, bool $create): ?Model
    {
        $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($name === '') {
            return null;
        }

        $found = $this->find($game, $type, $name);
        if ($found) {
            return $found;
        }

        $bucket = $game->value.':'.$type;
        $this->unmatched[$bucket][] = $name;

        if (! $create) {
            return null;
        }

        $class = self::MODELS[$type];
        /** @var Model $model */
        $model = $class::query()->firstOrCreate(
            ['game' => $game->value, 'key' => $this->normalize($game, $type, $name)],
            ['name' => $name] + $attributes,
        );

        $this->byKey[$bucket][$model->getAttribute('key')] = $model;

        return $model;
    }

    private function load(Game $game, string $type): void
    {
        $bucket = $game->value.':'.$type;
        if (isset($this->byKey[$bucket])) {
            return;
        }

        $this->byKey[$bucket] = [];
        $this->byExternalId[$bucket] = [];

        $class = self::MODELS[$type];
        foreach ($class::query()->where('game', $game->value)->get() as $model) {
            $this->byKey[$bucket][$model->getAttribute('key')] = $model;

            $ids = array_filter([$model->getAttribute('external_id'), ...($model->getAttribute('meta')['ids'] ?? [])]);
            foreach ($ids as $id) {
                $this->byExternalId[$bucket][(string) $id] ??= $model;
            }
        }
    }

    /**
     * Unique prefix, then suffix, then infix match ("kazuha" -> "kaedeharakazuha", "emblem" -> "emblemofseveredfate").
     */
    private function fuzzy(string $bucket, string $key): ?Model
    {
        if (strlen($key) < 4) {
            return null;
        }

        // Only ever match a *longer* catalog name: the reverse ("himekonova" -> "himeko") would silently
        // map a brand-new character onto an older one.
        $strategies = [
            fn (string $candidate) => str_starts_with($candidate, $key),
            fn (string $candidate) => str_ends_with($candidate, $key),
            fn (string $candidate) => str_contains($candidate, $key),
        ];

        foreach ($strategies as $matches) {
            $hits = array_filter(array_keys($this->byKey[$bucket] ?? []), fn ($candidate) => $matches((string) $candidate));
            if (count($hits) === 1) {
                return $this->byKey[$bucket][reset($hits)];
            }
        }

        return null;
    }

    private function specialCharacterKey(Game $game, string $key): string
    {
        if ($game === Game::Genshin) {
            $elements = 'anemo|geo|electro|dendro|hydro|pyro|cryo';
            if (preg_match("/^(?:traveler|traveller|aether|lumine)({$elements})$/", $key, $m)
                || preg_match("/^({$elements})(?:traveler|traveller|aether|lumine)$/", $key, $m)) {
                return 'traveler'.$m[1];
            }

            return $key;
        }

        $paths = 'destruction|preservation|harmony|remembrance|elation|nihility|erudition|abundance|hunt';
        if (preg_match("/^(?:trailblazer|caelus|stelle|mc)(?:the)?({$paths})$/", $key, $m)
            || preg_match("/^(?:the)?({$paths})(?:trailblazer|caelus|stelle|mc)$/", $key, $m)) {
            return 'trailblazer'.$m[1];
        }

        if (preg_match('/^march7th(?:thehunt|hunt|hunter|imaginary|swordmaster|shifu|sword)$/', $key)
            || preg_match('/^(?:thehunt|hunt|imaginary|swordmaster)march7th$/', $key)) {
            return 'march7thhunt';
        }

        if (preg_match('/^march7th(?:preservation|ice|thepreservation)$/', $key)) {
            return 'march7th';
        }

        return $key;
    }
}
