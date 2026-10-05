<?php

namespace Tests\Support;

use App\Auth\SignInPolicy;

/**
 * The IAM's "sign-in required" as a test sets it: false (the site open to everybody) unless a test says
 * otherwise; every test starts with false again (tests/lib.php). In place of App\Auth\IamSignInPolicy in
 * every test file, so that no test asks an IAM - and none counts a request it did not mean to send - but the
 * ones that try the real reader (auth.test.php with application_file, stand_in_iam.test.php against the
 * stand-in IAM).
 */
final class FixedSignInPolicy implements SignInPolicy
{
    public bool $required = false;

    /** How many times it was asked: a signed-in person's requests never ask. */
    public int $asked = 0;

    public function required(): bool
    {
        $this->asked++;

        return $this->required;
    }
}
