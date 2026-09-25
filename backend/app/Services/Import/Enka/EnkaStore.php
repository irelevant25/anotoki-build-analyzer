<?php

namespace App\Services\Import\Enka;

use App\Services\Http\Fetcher;
use Throwable;

/**
 * Enka's maintained game-data store (store/gi/*). Only the Genshin avatar file is needed: it tells which
 * skill ID is the Normal Attack / Skill / Burst and how constellations boost them.
 */
class EnkaStore
{
    private const GENSHIN_AVATARS = 'https://raw.githubusercontent.com/EnkaNetwork/API-docs/master/store/gi/avatars.json';

    private ?array $genshinAvatars = null;

    public function __construct(private readonly Fetcher $fetcher) {}

    /**
     * Avatar entry keyed like Enka ("10000021", or "10000005-504" for Travelers). Null when unavailable.
     */
    public function genshinAvatar(string $key): ?array
    {
        if ($this->genshinAvatars === null) {
            try {
                $this->genshinAvatars = $this->fetcher->getJson(self::GENSHIN_AVATARS, ['cache_hours' => 24, 'delay_ms' => 0]);
            } catch (Throwable $e) {
                report($e);
                $this->genshinAvatars = [];
            }
        }

        return $this->genshinAvatars[$key] ?? null;
    }
}
