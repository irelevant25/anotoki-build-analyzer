<?php

namespace App\Models;

use App\Enums\Game;
use DateTimeImmutable;

/** An artifact set. */
final class GameSet extends CatalogEntry
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
        /** Game::setKinds(): artifact */
        public string $kind = 'artifact',
    ) {
        parent::__construct($id, $game, $key, $name, $external_id, $icon_url, $meta, $updated_at);
    }

    public static function table(): string
    {
        return 'game_sets';
    }

    public static function fromRow(array $row): static
    {
        return new self(...self::common($row), kind: (string) ($row['kind'] ?? 'artifact'));
    }
}
