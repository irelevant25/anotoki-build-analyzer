<?php

namespace App\Models;

use App\Enums\Game;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameCharacter extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'game' => Game::class,
            'rarity' => 'integer',
            'released_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }
}
