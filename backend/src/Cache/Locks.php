<?php

namespace App\Cache;

use App\Db\Db;

/** Makes locks. */
final class Locks
{
    public function __construct(private readonly Db $db) {}

    public function lock(string $name, int $seconds): Lock
    {
        return new Lock($this->db, $name, $seconds);
    }
}
