<?php

namespace App\Auth;

use Anotoki\Lib\Auth\IamClient;
use App\Config;

/**
 * The anotoki IAM this site signs in through, as the anotoki library's auth module takes it (Anotoki\Lib\Auth\
 * IamClient): auth.local.php's issuer and client id - the same keys the IAM's PHP SDK read, so the file setup.php
 * wrote stays as it is - and the folder where what is read from the IAM is kept, storage/cache/iam: the IAM's key
 * set (an hour), its answer about whether this site requires signing in (a minute; the SDK kept it in the same
 * file, so nothing is asked anew after the move) and the people module's stamp. A folder only this site writes:
 * the kept answer is what opens the site to visitors, and the kept keys are what tokens are checked with.
 *
 * auth.local.php may also say what the library reads besides: leeway, application_ttl, application_timeout - and
 * the tests' seams, never on a host: jwks_file (the IAM's keys from a file), application_file (its answer about
 * signing in from a file), anonymized_file (the accounts it deleted, from a file).
 *
 * The issuer is https - http only on this computer (localhost, 127.0.0.1, [::1]): App\Config refuses anything
 * else as "not set up", before IamClient would throw.
 */
final class Iam
{
    private const OPTIONAL = ['jwks_url', 'jwks_file', 'cache_ttl', 'leeway', 'http_timeout', 'application_ttl',
        'application_timeout', 'application_file', 'anonymized_timeout', 'anonymized_file'];

    public static function client(Config $config): IamClient
    {
        $settings = [
            'issuer' => (string) $config->get('auth.issuer', ''),
            'client_id' => (string) $config->get('auth.client_id', ''),
            'cache_dir' => $config->storage('cache/iam'),
        ];
        foreach (self::OPTIONAL as $key) {
            $value = $config->get('auth.'.$key);
            if ($value !== null && $value !== '') {
                $settings[$key] = $value;
            }
        }

        return IamClient::fromArray($settings);
    }
}
