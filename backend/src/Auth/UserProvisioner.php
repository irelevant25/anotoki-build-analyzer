<?php

namespace App\Auth;

use App\Models\User;
use App\Repositories\Users;

/**
 * Just-in-time users: the row for an IAM user id is created on their first request and its name
 * follows the token's preferred_username afterwards.
 */
final class UserProvisioner
{
    public function __construct(private readonly Users $users) {}

    /**
     * @param  string|null  $name  the current preferred_username; null keeps the stored name
     * @param  string|null  $nameIfNew  the name of a row created without one (default "user {id}")
     */
    public function ensure(int $id, ?string $name = null, ?string $nameIfNew = null): User
    {
        $name = $this->cleanName($name);
        $user = $this->users->find($id) ?? $this->users->ensure($id, $name ?? $nameIfNew ?? "user {$id}");

        if ($name !== null && $user->name !== $name) {
            $this->users->rename($id, $name);
            $user->name = $name;
        }

        return $user;
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 100);
    }
}
