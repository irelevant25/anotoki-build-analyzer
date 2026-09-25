<?php

namespace App\Models;

use App\Enums\Game;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'game' => Game::class,
            'level' => 'integer',
            'world_level' => 'integer',
            'has_full_inventory' => 'boolean',
            'last_import_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function characters(): HasMany
    {
        return $this->hasMany(AccountCharacter::class);
    }

    public function weapons(): HasMany
    {
        return $this->hasMany(AccountWeapon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccountItem::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ImportRun::class);
    }
}
