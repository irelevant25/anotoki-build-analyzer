<?php

namespace App\Services\Import\Enka;

use App\Config;
use App\Http\Fetcher;
use App\Support\Log;
use Throwable;

/**
 * The showcase service's maintained game-data file for Genshin characters (config/app.php
 * "showcase.genshin_avatars"): it tells which skill ID is the Normal Attack / Skill / Burst and how
 * constellations boost them.
 */
class EnkaStore
{
    private ?array $genshinAvatars = null;

    public function __construct(private readonly Fetcher $fetcher, private readonly Config $config) {}

    /**
     * Avatar entry keyed like the service ("10000021", or "10000005-504" for Travelers). Null when unavailable.
     */
    public function genshinAvatar(string $key): ?array
    {
        if ($this->genshinAvatars === null) {
            try {
                $this->genshinAvatars = $this->fetcher->getJson((string) $this->config->get('showcase.genshin_avatars'), ['cache_hours' => 24, 'delay_ms' => 0]);
            } catch (Throwable $e) {
                Log::exception($e, 'Genshin character data');
                $this->genshinAvatars = [];
            }
        }

        return $this->genshinAvatars[$key] ?? null;
    }
}
