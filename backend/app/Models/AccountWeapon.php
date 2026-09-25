<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountWeapon extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'ascension' => 'integer',
            'refinement' => 'integer',
            'locked' => 'boolean',
        ];
    }

    public function weapon(): BelongsTo
    {
        return $this->belongsTo(GameWeapon::class, 'game_weapon_id');
    }

    public function equippedCharacter(): BelongsTo
    {
        return $this->belongsTo(GameCharacter::class, 'equipped_character_id');
    }
}
