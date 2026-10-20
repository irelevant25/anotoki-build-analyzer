<?php

namespace App\Auth;

/**
 * This application's roles in the anotoki IAM (the access token's "roles" claim). Anybody with an anotoki
 * account may sign in; a signed-in person with neither role imports and reads their own accounts.
 */
final class Role
{
    /**
     * Everything an EDITOR may do, and the system's own: applies database updates, adds, renames, switches
     * and deletes languages, deletes a person's data, and has the admin tools.
     */
    public const ADMIN = 'ADMIN';

    /** Imports and exports the builds files, refreshes the catalog, rewords the site's strings. */
    public const EDITOR = 'EDITOR';

    /** The admin panel: an EDITOR or an ADMIN. */
    public const STAFF = [self::ADMIN, self::EDITOR];
}
