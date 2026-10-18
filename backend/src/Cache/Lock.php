<?php

namespace App\Cache;

use App\Db\Db;
use App\Support\Clock;

/**
 * Named locks in the `cache_locks` table: one sync step at a time, one start at a time.
 *
 * A lock is a row with an owner and an expiration. Taking one inserts the row, or takes it over only once
 * it has expired, so a request that died holding a lock blocks nothing past its time-to-live. Rows rather
 * than PostgreSQL advisory locks, because an advisory lock lives as long as the connection - and with
 * persistent connections that can be much longer than the request that took it. (Applying migrations is the
 * one advisory lock, anotoki-lib's: a pooled connection gives back what a request that died left held -
 * App\Db\Db::pdo().)
 */
final class Lock
{
    private ?string $owner = null;

    public function __construct(private readonly Db $db, public readonly string $name, private readonly int $seconds) {}

    /** True when this call took the lock. */
    public function get(): bool
    {
        $owner = bin2hex(random_bytes(12));
        $now = Clock::timestamp();

        $taken =
            $this->db->run(
                'INSERT INTO cache_locks (key, owner, expiration) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET owner = EXCLUDED.owner, expiration = EXCLUDED.expiration
             WHERE cache_locks.expiration <= ?',
                [$this->name, $owner, $now + max(1, $this->seconds), $now],
            ) === 1;

        if ($taken) {
            $this->owner = $owner;
        }

        return $taken;
    }

    /**
     * Waits up to $seconds for the lock, runs $work holding it, and releases it.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws LockTimeout when the lock did not come free in time
     */
    public function block(int $seconds, callable $work): mixed
    {
        $until = microtime(true) + $seconds;
        while (!$this->get()) {
            if (microtime(true) >= $until) {
                throw new LockTimeout("The {$this->name} lock is held elsewhere.");
            }
            usleep(250_000);
        }

        try {
            return $work();
        } finally {
            $this->release();
        }
    }

    public function release(): void
    {
        if ($this->owner !== null) {
            $this->db->run('DELETE FROM cache_locks WHERE key = ? AND owner = ?', [$this->name, $this->owner]);
            $this->owner = null;
        }
    }
}
