<?php

namespace App\Models;

use App\Db\Db;
use App\Enums\Game;
use App\Support\Json;
use DateTimeImmutable;

/**
 * A game account, owned by one user: nobody else sees or changes it.
 */
final class Account
{
    /** @var array{characters: int, weapons: int, items: int}|null loaded on demand */
    public ?array $counts = null;

    public function __construct(
        public int $id,
        public int $user_id,
        public Game $game,
        public ?string $uid = null,
        public ?string $nickname = null,
        public ?int $level = null,
        public ?int $world_level = null,
        public ?string $signature = null,
        public bool $has_full_inventory = false,
        public ?DateTimeImmutable $last_import_at = null,
        /** @var array<string, mixed> */
        public array $meta = [],
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $account = new self(
            id: (int) $row['id'],
            user_id: (int) $row['user_id'],
            game: Game::from((string) $row['game']),
            uid: isset($row['uid']) ? (string) $row['uid'] : null,
            nickname: $row['nickname'] ?? null,
            level: isset($row['level']) ? (int) $row['level'] : null,
            world_level: isset($row['world_level']) ? (int) $row['world_level'] : null,
            signature: $row['signature'] ?? null,
            has_full_inventory: (bool) ($row['has_full_inventory'] ?? false),
            last_import_at: Db::time($row['last_import_at'] ?? null),
            meta: Json::decode($row['meta'] ?? null) ?? [],
        );
        if (array_key_exists('characters_count', $row)) {
            $account->counts = [
                'characters' => (int) $row['characters_count'],
                'weapons' => (int) $row['weapons_count'],
                'items' => (int) $row['items_count'],
            ];
        }

        return $account;
    }
}
