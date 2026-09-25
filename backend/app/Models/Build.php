<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Build extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'recommended' => 'boolean',
            'weapons' => 'array',
            'sets' => 'array',
            'main_stats' => 'array',
            'substats' => 'array',
            'talent_priority' => 'array',
            'stat_targets' => 'array',
            'teams' => 'array',
            'source_updated_at' => 'datetime',
            'fetched_at' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(BuildSource::class, 'build_source_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(GameCharacter::class, 'game_character_id');
    }
}
