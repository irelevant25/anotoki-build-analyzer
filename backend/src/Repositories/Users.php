<?php

namespace App\Repositories;

use App\Db\Db;
use App\Models\User;
use App\Support\Clock;

final class Users
{
    public function __construct(private readonly Db $db) {}

    public function find(int $id): ?User
    {
        $row = $this->db->one('SELECT id, name FROM users WHERE id = ?', [$id]);

        return $row === null ? null : User::fromRow($row);
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT count(*) FROM users');
    }

    /**
     * The row for this id, made when it is not there yet. Two first requests at once cannot both insert
     * it: the second one's insert does nothing and it reads the first one's row.
     */
    public function ensure(int $id, string $name): User
    {
        $now = Clock::now();
        $this->db->run('INSERT INTO users (id, name, created_at, updated_at) VALUES (?, ?, ?, ?) ON CONFLICT (id) DO NOTHING', [$id, $name, $now, $now]);

        return $this->find($id) ?? new User($id, $name);
    }

    public function rename(int $id, string $name): void
    {
        $this->db->run('UPDATE users SET name = ?, updated_at = ? WHERE id = ?', [$name, Clock::now(), $id]);
    }
}
