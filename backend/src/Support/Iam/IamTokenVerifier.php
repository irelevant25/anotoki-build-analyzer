<?php

namespace App\Support\Iam;

use App\Config;

// The anotoki IAM's verifier (anotoki-iam/sdk/php/anotoki_iam.php, v1.0.1), copied unchanged: plain
// functions, no dependencies, so an update is a clean copy. Required here rather than autoloaded because
// it declares no class.
require_once __DIR__.'/anotoki_iam.php';

/**
 * Checks anotoki IAM access tokens: RS256 only, typ at+jwt, the configured issuer and audience, expiry
 * with a little clock leeway, signed by a key from the IAM's published key set. The key set is downloaded
 * from {issuer}/.well-known/jwks.json and cached on disk for an hour (an unknown kid is worth one refetch,
 * at most every five minutes); the tests point jwks_file at a key set on disk instead.
 */
final class IamTokenVerifier
{
    /**
     * @param  array{issuer: string, client_id: string, jwks_file?: string|null, cache_dir?: string, leeway?: int}  $config
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(Config $config): self
    {
        return new self(array_filter([
            'issuer' => rtrim((string) $config->get('auth.issuer'), '/'),
            'client_id' => (string) $config->get('auth.client_id'),
            'jwks_file' => $config->get('auth.jwks_file') ?: null,
            'cache_dir' => $config->storage('cache/iam'),
            'leeway' => (int) $config->get('auth.leeway', 60),
        ], fn ($value) => $value !== null));
    }

    /**
     * The token from an Authorization header value ("Bearer ..."), or null for anything else.
     */
    public function bearer(?string $header): ?string
    {
        return anotoki_iam_bearer_token($header);
    }

    /**
     * The token's claims when every check passes, else null. $reason says which check failed; it is for the
     * log only, never for the client.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token, ?string &$reason = null): ?array
    {
        $cache = $this->config['cache_dir'] ?? null;
        if (is_string($cache) && ! is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        return anotoki_iam_verify_access_token($token, $this->config, $reason);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function userId(array $claims): ?int
    {
        return anotoki_iam_user_id($claims);
    }

    /**
     * This application's roles, uppercase and deduplicated.
     *
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    public function roles(array $claims): array
    {
        return anotoki_iam_roles($claims);
    }
}
