<?php

namespace App\Cache;

use App\Db\Db;
use App\Support\Clock;

/**
 * A small key-value cache in the `cache` table. The host has no shared memory between requests and no
 * daemon, so whatever has to outlive one request - a site's last request time, retry counters, showcase
 * answers, the import rate limit - is kept here. Values are JSON; an entry past its expiration is gone.
 */
final class Cache
{
    public function __construct(private readonly Db $db) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->db->one('SELECT value FROM cache WHERE key = ? AND expiration > ?', [$key, Clock::timestamp()]);

        return $row === null ? $default : json_decode((string) $row['value'], true);
    }

    public function put(string $key, mixed $value, int $seconds): void
    {
        $this->db->run(
            'INSERT INTO cache (key, value, expiration) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, expiration = EXCLUDED.expiration',
            [$key, json_encode($value, JSON_THROW_ON_ERROR), Clock::timestamp() + max(1, $seconds)],
        );
    }

    /**
     * Stores the value only when the key is not there (or has expired); true when this call stored it.
     * Atomic, so of two requests racing for the same key exactly one wins.
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        $now = Clock::timestamp();

        return $this->db->run(
            'INSERT INTO cache (key, value, expiration) VALUES (?, ?, ?)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, expiration = EXCLUDED.expiration
             WHERE cache.expiration <= ?',
            [$key, json_encode($value, JSON_THROW_ON_ERROR), $now + max(1, $seconds), $now],
        ) === 1;
    }

    /**
     * Adds one to a counter that starts over when it expires, and returns the new count: a fixed window of
     * $seconds that opens with the first hit.
     */
    public function hit(string $key, int $seconds): int
    {
        $now = Clock::timestamp();

        return (int) $this->db->value(
            "INSERT INTO cache (key, value, expiration) VALUES (?, '1', ?)
             ON CONFLICT (key) DO UPDATE SET
                 value = CASE WHEN cache.expiration <= ? THEN '1' ELSE ((cache.value)::BIGINT + 1)::TEXT END,
                 expiration = CASE WHEN cache.expiration <= ? THEN EXCLUDED.expiration ELSE cache.expiration END
             RETURNING value",
            [$key, $now + max(1, $seconds), $now, $now],
        );
    }

    /**
     * Takes back one hit() of a counter that is still running (a new visitor whose first import failed);
     * never below zero, and an expired or missing counter stays as it is.
     */
    public function giveBack(string $key): void
    {
        $this->db->run(
            'UPDATE cache SET value = GREATEST((value)::BIGINT - 1, 0)::TEXT WHERE key = ? AND expiration > ?',
            [$key, Clock::timestamp()],
        );
    }

    public function forget(string $key): void
    {
        $this->db->run('DELETE FROM cache WHERE key = ?', [$key]);
    }

    /** Deletes what has expired; cheap, so it can run now and then. */
    public function prune(): int
    {
        return $this->db->run('DELETE FROM cache WHERE expiration <= ?', [Clock::timestamp()]);
    }
}
