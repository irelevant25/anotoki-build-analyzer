<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An artifact (Genshin) or relic (HSR).
 */
class AccountItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rarity' => 'integer',
            'level' => 'integer',
            'main_value' => 'float',
            'substats' => 'array',
            'locked' => 'boolean',
        ];
    }

    public function set(): BelongsTo
    {
        return $this->belongsTo(GameSet::class, 'game_set_id');
    }

    public function equippedCharacter(): BelongsTo
    {
        return $this->belongsTo(GameCharacter::class, 'equipped_character_id');
    }
}
