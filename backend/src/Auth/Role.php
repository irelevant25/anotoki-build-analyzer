<?php

namespace App\Auth;

/**
 * This application's roles in the anotoki IAM (the access token's "roles" claim).
 */
final class Role
{
    /** Runs the build and catalog syncs, switches sources on and off, applies migrations. */
    public const ADMIN = 'ADMIN';

    /** Imports accounts and sees their analysis. */
    public const MEMBER = 'MEMBER';

    public const ALL = [self::ADMIN, self::MEMBER];
}
