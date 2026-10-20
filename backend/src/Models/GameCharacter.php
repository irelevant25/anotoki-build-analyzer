<?php

namespace App\Models;

use App\Db\Db;
use App\Enums\Game;
use DateTimeImmutable;

final class GameCharacter extends CatalogEntry
{
    public function __construct(
        int $id,
        Game $game,
        string $key,
        string $name,
        ?string $external_id = null,
        ?string $icon_url = null,
        array $meta = [],
        ?DateTimeImmutable $updated_at = null,
        public ?string $element = null,
        public ?int $rarity = null,
        /** The weapon type (Game::weaponTypes()). */
        public ?string $weapon_type = null,
        public ?DateTimeImmutable $released_at = null,
    ) {
        parent::__construct($id, $game, $key, $name, $external_id, $icon_url, $meta, $updated_at);
    }

    public static function table(): string
    {
        return 'game_characters';
    }

    public static function fromRow(array $row): static
    {
        return new self(...self::common($row),
            element: $row['element'] ?? null,
            rarity: isset($row['rarity']) ? (int) $row['rarity'] : null,
            weapon_type: $row['weapon_type'] ?? null,
            released_at: Db::time($row['released_at'] ?? null),
        );
    }
}
