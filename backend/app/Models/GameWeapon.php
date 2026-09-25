<?php

namespace App\Models;

use App\Enums\Game;
use Illuminate\Database\Eloquent\Model;

class GameWeapon extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'game' => Game::class,
            'rarity' => 'integer',
            'meta' => 'array',
        ];
    }
}
