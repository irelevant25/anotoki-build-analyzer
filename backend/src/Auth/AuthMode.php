<?php

namespace App\Auth;

use App\Config;

/**
 * How requests are signed in, from the installation's configuration (auth.local.php) only.
 */
enum AuthMode: string
{
    /** Every request needs an anotoki IAM access token. */
    case Iam = 'iam';

    /** No sign-in: every request from this computer acts as the owner (local_user_id). */
    case None = 'none';

    /**
     * The configured mode. Anything but an exact "none" counts as "iam", so a typo in the configuration
     * closes the app instead of opening it.
     */
    public static function of(Config $config): self
    {
        return $config->get('auth.mode') === self::None->value ? self::None : self::Iam;
    }
}
