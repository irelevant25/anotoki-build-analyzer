<?php

namespace App\Models;

use App\Enums\Game;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuildSource extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'game' => Game::class,
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
