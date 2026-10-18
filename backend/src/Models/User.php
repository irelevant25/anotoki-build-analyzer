<?php

namespace App\Models;

use Anotoki\Lib\Auth\Person;
use Anotoki\Lib\People\PeopleRecorder;
use App\Auth\Role;

/**
 * A person as the anotoki IAM knows them. The IAM owns identity: the row is only the IAM user id (the
 * token's "sub") and a copy of the preferred_username for display (kept by the anotoki library's
 * RecordPerson, api/middleware/auth.php). Roles are never stored; they come with each token and live on this
 * object only.
 *
 * Or a visitor: somebody using the site without signing in, whose imports are kept under a row with a
 * negative id (App\Auth\Visitors) - an IAM id is always positive. A visitor never has a role.
 */
final class User
{
    /** @var list<string> this request's roles */
    private array $roles = [];

    public function __construct(public readonly int $id, public string $name) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self((int) $row['id'], (string) $row['name']);
    }

    /**
     * The person a verified access token names (the library's Identify), with this application's roles: the
     * name as their row keeps it - the token's preferred_username clipped to the column, or "user {id}" for a
     * token without one.
     */
    public static function fromPerson(Person $person): self
    {
        return (new self($person->sub, PeopleRecorder::clip($person->name, 100) ?? "user {$person->sub}"))->withRoles($person->roles);
    }

    /**
     * This application's roles of the token: ADMIN and EDITOR, nothing else (a leftover MEMBER, or a role
     * name from elsewhere, means nothing here).
     *
     * @param  list<string>  $roles
     */
    public function withRoles(array $roles): self
    {
        $this->roles = $this->isVisitor() ? [] : array_values(array_intersect(Role::STAFF, $roles));

        return $this;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * ADMIN includes everything an EDITOR may do.
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true) || ($role === Role::EDITOR && in_array(Role::ADMIN, $this->roles, true));
    }

    /** An EDITOR or an ADMIN: the admin panel. */
    public function isStaff(): bool
    {
        return $this->hasRole(Role::EDITOR);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }

    /** A browser's visitor (a negative id), not a person the IAM signed in. */
    public function isVisitor(): bool
    {
        return $this->id < 0;
    }
}
