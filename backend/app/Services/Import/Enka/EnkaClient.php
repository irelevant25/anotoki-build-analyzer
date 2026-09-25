<?php

namespace App\Services\Import\Enka;

use App\Enums\Game;
use App\Services\Http\Fetcher;
use App\Services\Import\ImportException;
use Illuminate\Support\Facades\Cache;

/**
 * Enka.Network profile API. Returns the characters a player shows in their in-game Character Showcase.
 * Responses are cached for the "ttl" Enka sends, as its documentation asks.
 */
class EnkaClient
{
    public function __construct(private readonly Fetcher $fetcher) {}

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

        $cacheKey = "enka:{$game->value}:{$uid}";
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $base = rtrim((string) config('hoyo.enka.api'), '/');
        $url = $game === Game::Genshin ? "{$base}/uid/{$uid}" : "{$base}/hsr/uid/{$uid}";

        $response = $this->fetcher->request($url, ['delay_ms' => 500, 'headers' => ['Accept' => 'application/json']]);

        $message = match ($response->status()) {
            200 => null,
            400 => 'Enka.Network rejected the UID format.',
            404 => 'No player with this UID exists (check the UID and the game).',
            424 => 'The game is under maintenance or just updated; Enka.Network cannot read profiles right now. Try again later.',
            429 => 'Enka.Network is rate limiting requests. Wait a minute and try again.',
            500, 503 => 'Enka.Network is having problems right now. Try again later.',
            default => "Enka.Network answered with HTTP {$response->status()}.",
        };
        if ($message) {
            throw new ImportException($message);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new ImportException('Enka.Network returned an unexpected response.');
        }

        $ttl = max(60, (int) ($json['ttl'] ?? 60));
        Cache::put($cacheKey, $json, $ttl);

        return $json;
    }
}
