<?php

namespace App\Auth;

use App\Config;
use App\Support\Log;

// The IAM's SDK (src/Support/Iam/anotoki_iam.php, copied unchanged): plain functions, no class to autoload.
require_once __DIR__.'/../Support/Iam/anotoki_iam.php';

/**
 * Whether the IAM says this site requires sign-in: anotoki_iam_login_required() of the IAM's PHP SDK, which
 * holds the whole rule - GET {issuer}/api/public/applications/{client_id}; a 200 about this client is its
 * login_required, a 404 (an unknown or switched-off application) is true; no answer, a 5xx or anything else
 * is the last value read, or true when none ever was (fail closed). The answer is kept for a minute in
 * storage/cache/iam (a folder only the site writes: a kept "false" is what opens the site to visitors), and
 * while one request asks the IAM the others use the last value. Nothing here fetches or caches by itself.
 *
 * Asked once a request (the App's scope is one request) and only when somebody without a token is let in
 * or turned away - a signed-in person's requests never need it - and by /api/meta, which relays it.
 *
 * auth.local.php may set what the SDK reads besides the issuer and client id: application_ttl,
 * application_timeout, and application_file (the IAM's answer from a file - the tests' seam; never on a host).
 */
final class IamSignInPolicy implements SignInPolicy
{
    private ?bool $required = null;

    /**
     * @param  array<string, mixed>  $config  the SDK's configuration (issuer, client_id, cache_dir ...)
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(Config $config): self
    {
        $optional = [
            'application_ttl' => $config->get('auth.application_ttl'),
            'application_timeout' => $config->get('auth.application_timeout'),
            'application_file' => $config->get('auth.application_file'),
        ];

        return new self([
            'issuer' => rtrim((string) $config->get('auth.issuer'), '/'),
            'client_id' => (string) $config->get('auth.client_id'),
            'cache_dir' => $config->storage('cache/iam'),
        ] + array_filter($optional, fn ($value) => $value !== null && $value !== ''));
    }

    public function required(): bool
    {
        if ($this->required === null) {
            // A new request (the SDK remembers its answer per PHP process; the tests hand one process many).
            anotoki_iam_login_required_reset();
            $this->required = anotoki_iam_login_required($this->config, $reason);
            if (in_array($reason, ['unknown_application', 'stale', 'never_read', 'not_configured'], true)) {
                Log::info('IAM sign-in answer: '.$reason.' - sign-in '.($this->required ? 'required' : 'not required'));
            }
        }

        return $this->required;
    }
}
