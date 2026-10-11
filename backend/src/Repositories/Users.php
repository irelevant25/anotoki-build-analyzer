<?php

namespace App\Repositories;

use App\Db\Db;
use App\Models\User;
use App\Support\Clock;

/**
 * The rows of users: the people the anotoki IAM signed in here (their IAM id, always positive) and the
 * visitors of browsers (negative ids from the sequence visitor_ids, migration 004).
 */
final class Users
{
    public function __construct(private readonly Db $db) {}

    public function find(int $id): ?User
    {
        $row = $this->db->one('SELECT id, name FROM users WHERE id = ?', [$id]);

        return $row === null ? null : User::fromRow($row);
    }

    /**
     * Inside the caller's transaction, takes this row (a person's or a visitor's) FOR UPDATE, so that what
     * writes one owner's accounts - a game import, an import of their accounts file, the move at sign-in -
     * takes turns instead of interleaving. False when there is no such row.
     */
    public function lockForWrite(int $id): bool
    {
        return $this->db->value('SELECT id FROM users WHERE id = ? FOR UPDATE', [$id]) !== null;
    }

    /** The people the IAM signed in here (visitors are counted by visitorCounts()). */
    public function count(): int
    {
        return (int) $this->db->value('SELECT count(*) FROM users WHERE id > 0');
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

    /**
     * The people the IAM has signed in here (positive ids - an IAM id always is), with how much each has:
     * the People page.
     *
     * @return list<array{id: int, name: string, accounts: int, import_runs: int, created_at: mixed, last_import_at: mixed}>
     */
    public function withCounts(): array
    {
        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'accounts' => (int) $row['accounts'],
            'import_runs' => (int) $row['import_runs'],
            'created_at' => $row['created_at'],
            'last_import_at' => $row['last_import_at'],
        ], $this->db->all(
            'SELECT u.id, u.name, u.created_at,
                    (SELECT count(*) FROM accounts a WHERE a.user_id = u.id) AS accounts,
                    (SELECT count(*) FROM import_runs r WHERE r.user_id = u.id) AS import_runs,
                    (SELECT max(r.created_at) FROM import_runs r WHERE r.user_id = u.id) AS last_import_at
               FROM users u
              WHERE u.id > 0
              ORDER BY lower(u.name), u.id'
        ));
    }

    /**
     * The rows that are no IAM person's - a browser's visitor has a negative id - counted apart: how many,
     * and how many accounts they hold.
     *
     * @return array{count: int, accounts: int}
     */
    public function visitorCounts(): array
    {
        $row = $this->db->one('SELECT (SELECT count(*) FROM users WHERE id < 0) AS count, (SELECT count(*) FROM accounts WHERE user_id < 0) AS accounts');

        return ['count' => (int) ($row['count'] ?? 0), 'accounts' => (int) ($row['accounts'] ?? 0)];
    }

    /**
     * Deletes an IAM person's row and, by the foreign keys, their accounts with everything imported into
     * them and their import runs; the syncs they started stay, started by nobody. Answers what went, or null
     * when there is no such person (a visitor's row is never one).
     *
     * Inside the caller's transaction, which it neither begins nor ends: the People page's delete runs in the
     * anotoki library's (People::delete(), App\People\SitePeople::deleteData()), on whatever connection that is
     * - the page's, or a restore's doing the delete again.
     *
     * @return array{accounts: int, import_runs: int}|null
     */
    public function deletePerson(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->db->one(
            'SELECT (SELECT count(*) FROM accounts a WHERE a.user_id = u.id) AS accounts,
                    (SELECT count(*) FROM import_runs r WHERE r.user_id = u.id) AS import_runs
               FROM users u WHERE u.id = ? FOR UPDATE',
            [$id]
        );
        if ($row === null) {
            return null;
        }
        $this->db->run('DELETE FROM users WHERE id = ?', [$id]);

        return ['accounts' => (int) $row['accounts'], 'import_runs' => (int) $row['import_runs']];
    }

    // ── Visitors (App\Auth\Visitors is the only caller: nothing else writes a visitor's row) ─────────────

    /**
     * Whether the database knows visitors yet: migration 004 adds their columns. Between an upload and
     * "Apply" it does not, and the visitor paths answer 503 visitors_unavailable instead of failing.
     */
    public function visitorsReady(): bool
    {
        return (bool) $this->db->value(
            "SELECT EXISTS (SELECT 1 FROM information_schema.columns
                             WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'visitor_key_hash')"
        );
    }

    /** A new visitor with this key's hash, seen now: the next negative id. */
    public function makeVisitor(string $hash): User
    {
        $now = Clock::now();
        $id = (int) $this->db->value(
            "INSERT INTO users (id, name, visitor_key_hash, last_seen_at, created_at, updated_at)
             VALUES (nextval('visitor_ids'), 'visitor', ?, ?, ?, ?) RETURNING id",
            [$hash, $now, $now, $now],
        );

        return new User($id, 'visitor');
    }

    /**
     * The visitor whose key has this hash, and when it was last seen - or null.
     *
     * @return array{user: User, last_seen_at: \DateTimeImmutable|null}|null
     */
    public function findVisitor(string $hash): ?array
    {
        $row = $this->db->one('SELECT id, name, last_seen_at FROM users WHERE visitor_key_hash = ? AND id < 0', [$hash]);

        return $row === null ? null : ['user' => User::fromRow($row), 'last_seen_at' => Db::time($row['last_seen_at'])];
    }

    /** Seen now - written only when the last time is older than $olderThan. */
    public function touchVisitor(int $id, \DateTimeImmutable $now, \DateTimeImmutable $olderThan): void
    {
        $this->db->run('UPDATE users SET last_seen_at = ? WHERE id = ? AND id < 0 AND last_seen_at < ?', [$now, $id, $olderThan]);
    }

    /** A visitor's row, and by the foreign keys everything it imported. */
    public function deleteVisitor(int $id): void
    {
        $this->db->run('DELETE FROM users WHERE id = ? AND id < 0', [$id]);
    }

    public function visitorCount(): int
    {
        return (int) $this->db->value('SELECT count(*) FROM users WHERE id < 0');
    }

    /**
     * Deletes at most $limit visitors last seen before $before, oldest first, with what they imported (by
     * the foreign keys) - one statement allowed $timeoutMs. A visitor that another request holds (a move, an
     * import that is writing) is skipped, never waited for: the next clean-up takes it.
     */
    public function sweepVisitors(\DateTimeImmutable $before, int $limit, int $timeoutMs): int
    {
        return $this->db->transaction(function () use ($before, $limit, $timeoutMs): int {
            $this->db->value("SELECT set_config('statement_timeout', ?, true)", [max(1, $timeoutMs).'ms']);

            return $this->db->run(
                'DELETE FROM users WHERE id IN (
                    SELECT id FROM users WHERE id < 0 AND last_seen_at < ? ORDER BY last_seen_at LIMIT ? FOR UPDATE SKIP LOCKED)',
                [$before, max(1, $limit)],
            );
        });
    }

    /**
     * The move of a visitor's data into a person's, in one transaction (App\Auth\Visitors::moveInto()):
     * both rows locked - the visitor's first, then the person's, so neither's imports write meanwhile; a game
     * account both have (the same game and UID) stays the person's as it is, and the visitor's copy goes
     * with its import runs; every other account and import run becomes the person's; the visitor's row goes.
     * Null when no visitor has this hash (moved already, swept, never there); false, with nothing moved,
     * when the person's row is not there (an admin deleted their data on the People page while the move
     * waited for its lock). With $onlyIntoNothing (the move after a sign-in nobody clicked), nothing moves
     * when the person already has data here - a game account or an import run, asked under both locks -
     * and the answer is ['held' => true]: the visitor stays as it is. A lock not had within $lockTimeoutMs
     * fails the statement (PDOException 55P03) and rolls everything back.
     *
     * @return array{moved: int, kept: int, imports: int}|array{held: true}|null|false
     */
    public function moveVisitor(string $hash, int $personId, int $lockTimeoutMs, bool $onlyIntoNothing = false): array|null|false
    {
        return $this->db->transaction(function () use ($hash, $personId, $lockTimeoutMs, $onlyIntoNothing): array|null|false {
            $this->db->value("SELECT set_config('lock_timeout', ?, true)", [max(1, $lockTimeoutMs).'ms']);

            $visitor = $this->db->value('SELECT id FROM users WHERE visitor_key_hash = ? AND id < 0 FOR UPDATE', [$hash]);
            if ($visitor === null) {
                return null;
            }
            $visitor = (int) $visitor;
            if ($this->db->value('SELECT id FROM users WHERE id = ? AND id > 0 FOR UPDATE', [$personId]) === null) {
                return false;
            }
            if ($onlyIntoNothing && (bool) $this->db->value(
                'SELECT EXISTS (SELECT 1 FROM accounts WHERE user_id = ?) OR EXISTS (SELECT 1 FROM import_runs WHERE user_id = ?)',
                [$personId, $personId],
            )) {
                return ['held' => true];
            }

            $kept = $this->db->run(
                'DELETE FROM accounts v USING accounts m
                  WHERE v.user_id = ? AND m.user_id = ? AND m.game = v.game AND m.uid = v.uid',
                [$visitor, $personId],
            );
            $moved = $this->db->run('UPDATE accounts SET user_id = ? WHERE user_id = ?', [$personId, $visitor]);
            $imports = $this->db->run('UPDATE import_runs SET user_id = ? WHERE user_id = ?', [$personId, $visitor]);
            $this->db->run('DELETE FROM users WHERE id = ?', [$visitor]);

            return ['moved' => $moved, 'kept' => $kept, 'imports' => $imports];
        });
    }
}
