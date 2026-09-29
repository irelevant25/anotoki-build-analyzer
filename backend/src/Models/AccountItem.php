<?php

namespace App\Models;

use App\Support\Json;

/**
 * An artifact (Genshin) or relic (HSR) of a game account, with its set.
 */
final class AccountItem
{
    public function __construct(
        public int $id,
        public int $account_id,
        public GameSet $set,
        public string $slot,
        public int $rarity,
        public int $level,
        public string $main_stat,
        public ?float $main_value,
        /** @var list<array{key: string, value: float|int, rolls?: int|null}> */
        public array $substats = [],
        public ?int $equipped_character_id = null,
        public bool $locked = false,
    ) {}

    /**
     * A row of account_items joined with its game_sets row (columns prefixed "s_").
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            account_id: (int) $row['account_id'],
            set: GameSet::fromRow(AccountCharacter::prefixed($row, 's_')),
            slot: (string) $row['slot'],
            rarity: (int) $row['rarity'],
            level: (int) $row['level'],
            main_stat: (string) $row['main_stat'],
            main_value: isset($row['main_value']) ? (float) $row['main_value'] : null,
            substats: Json::decode($row['substats'] ?? null) ?? [],
            equipped_character_id: isset($row['equipped_character_id']) ? (int) $row['equipped_character_id'] : null,
            locked: (bool) $row['locked'],
        );
    }
}
