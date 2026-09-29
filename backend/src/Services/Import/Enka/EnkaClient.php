<?php

namespace App\Services\Import\Enka;

use App\Cache\Cache;
use App\Config;
use App\Enums\Game;
use App\Http\Fetcher;
use App\Services\Import\ImportException;
use App\Support\Log;
use RuntimeException;

/**
 * The showcase service's profile API (config/app.php "showcase"). Returns the characters a player shows in
 * their in-game Character Showcase. Answers are cached for the "ttl" the service sends, as it asks.
 *
 * The messages are shown in the import wizard as they are, so they call it "the showcase service" and never
 * by its name.
 */
class EnkaClient
{
    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    public static function isValidUid(string $uid): bool
    {
        return (bool) preg_match('/^\d{9,10}$/', $uid);
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(Game $game, string $uid): array
    {
        if (! self::isValidUid($uid)) {
            throw new ImportException('A UID is 9 or 10 digits long.');
        }

        $cacheKey = "showcase:{$game->value}:{$uid}";
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $base = rtrim((string) $this->config->get('showcase.api'), '/');
        $url = $game === Game::Genshin ? "{$base}/uid/{$uid}" : "{$base}/hsr/uid/{$uid}";

        try {
            $response = $this->fetcher->request($url, ['delay_ms' => 500, 'headers' => ['Accept' => 'application/json']]);
        } catch (RuntimeException $e) {
            Log::exception($e, 'Showcase import');

            throw new ImportException('The showcase service cannot be reached right now. Try again in a few minutes.');
        }

        $message = match ($response->status) {
            200 => null,
            400 => 'The showcase service did not accept the UID.',
            404 => 'No player with this UID exists (check the UID and the game).',
            424 => 'The game is under maintenance or just updated, so showcases cannot be read right now. Try again later.',
            429 => 'Too many showcase requests right now. Wait a minute and try again.',
            500, 503 => 'The showcase service is having problems right now. Try again later.',
            default => "The showcase service answered with HTTP {$response->status}.",
        };
        if ($message) {
            throw new ImportException($message);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new ImportException('The showcase service returned an unexpected answer.');
        }

        $ttl = max(60, (int) ($json['ttl'] ?? 60));
        $this->cache->put($cacheKey, $json, $ttl);

        return $json;
    }
}
