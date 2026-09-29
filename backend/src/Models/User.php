<?php

namespace App\Models;

use App\Auth\Role;

/**
 * A person as the anotoki IAM knows them. The IAM owns identity: the row is only the IAM user id (the
 * token's "sub") and a copy of the preferred_username for display. Roles are never stored; they come with
 * each token (or, in auth mode "none", are all granted to the owner) and live on this object only.
 */
final class User
{
    /** @var list<string> this request's roles */
    private array $roles = [];

    public function __construct(
        public readonly int $id,
        public string $name,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self((int) $row['id'], (string) $row['name']);
    }

    /**
     * @param  list<string>  $roles
     */
    public function withRoles(array $roles): self
    {
        $this->roles = array_values(array_intersect(Role::ALL, $roles));

        return $this;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * ADMIN includes everything a MEMBER may do.
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true)
            || ($role === Role::MEMBER && in_array(Role::ADMIN, $this->roles, true));
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }
}
