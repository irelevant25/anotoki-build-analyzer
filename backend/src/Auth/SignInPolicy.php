<?php

namespace App\Auth;

/**
 * Whether this site requires everybody to sign in - the IAM's switch for the application ("Sign-in
 * required", on its admin panel), not a setting of the site's own.
 *
 * Required: nobody is let in without a token - every own-data and public route but /api/meta, the strings,
 * the icons and /api/site-status answers 401 sign_in_required, and a visitor key is refused before it is
 * looked up (api/middleware/auth.php). Not required: the site as it has always been - the builds browser for
 * everybody, visitors with their own accounts.
 *
 * The app's one is IamSignInPolicy (the IAM's answer, through its PHP SDK); the tests put a fixed one in
 * its place (tests/support/FixedSignInPolicy.php), so no test asks an IAM unless it means to.
 */
interface SignInPolicy
{
    public function required(): bool;
}
