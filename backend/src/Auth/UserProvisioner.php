<?php

namespace App\Auth;

use App\Models\User;
use App\Repositories\Users;
use InvalidArgumentException;

/**
 * Just-in-time users: the row for an IAM user id is created on their first request and its name
 * follows the token's preferred_username afterwards.
 */
final class UserProvisioner
{
    public function __construct(private readonly Users $users) {}

    /**
     * @param  int  $id  an IAM user id - always positive (anotoki_iam_user_id())
     * @param  string|null  $name  the current preferred_username; null keeps the stored name
     */
    public function ensure(int $id, ?string $name = null): User
    {
        if ($id <= 0) {
            throw new InvalidArgumentException("An IAM user id is a positive number, not {$id}.");
        }

        $name = $this->cleanName($name);
        $user = $this->users->find($id) ?? $this->users->ensure($id, $name ?? "user {$id}");

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
