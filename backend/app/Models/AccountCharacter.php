<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountCharacter extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'ascension' => 'integer',
            'rank' => 'integer',
            'talents' => 'array',
            'final_stats' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(GameCharacter::class, 'game_character_id');
    }
}
