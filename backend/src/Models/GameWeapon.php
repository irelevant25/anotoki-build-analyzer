<?php

namespace App\Models;

use App\Enums\Game;
use DateTimeImmutable;

/** A weapon. */
final class GameWeapon extends CatalogEntry
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
        public ?int $rarity = null,
        public ?string $weapon_type = null,
    ) {
        parent::__construct($id, $game, $key, $name, $external_id, $icon_url, $meta, $updated_at);
    }

    public static function table(): string
    {
        return 'game_weapons';
    }

    public static function fromRow(array $row): static
    {
        return new self(...self::common($row),
            rarity: isset($row['rarity']) ? (int) $row['rarity'] : null,
            weapon_type: $row['weapon_type'] ?? null,
        );
    }
}
