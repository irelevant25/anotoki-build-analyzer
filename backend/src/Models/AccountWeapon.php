<?php

namespace App\Models;

/**
 * A weapon of a game account, with its catalog entry.
 */
final class AccountWeapon
{
    public function __construct(
        public int $id,
        public int $account_id,
        public GameWeapon $weapon,
        public int $level = 1,
        public ?int $ascension = null,
        /** Refinement / Superimposition. */
        public int $refinement = 1,
        public ?int $equipped_character_id = null,
        public bool $locked = false,
    ) {}

    /**
     * A row of account_weapons joined with its game_weapons row (columns prefixed "w_").
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            account_id: (int) $row['account_id'],
            weapon: GameWeapon::fromRow(AccountCharacter::prefixed($row, 'w_')),
            level: (int) $row['level'],
            ascension: isset($row['ascension']) ? (int) $row['ascension'] : null,
            refinement: (int) $row['refinement'],
            equipped_character_id: isset($row['equipped_character_id']) ? (int) $row['equipped_character_id'] : null,
            locked: (bool) $row['locked'],
        );
    }
}
