<?php

namespace App\Models;

use App\Enums\Game;
use Illuminate\Database\Eloquent\Model;

class GameSet extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'game' => Game::class,
            'meta' => 'array',
        ];
    }
}
