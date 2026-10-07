<?php

namespace App\Db;

use App\Support\Clock;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * The query layer: a PDO connection to PostgreSQL and a handful of helpers around prepared statements.
 *
 * Values always travel as parameters. Table and column names are never taken from a request: they are
 * literals in the calling code, and insert() / update() refuse anything that is not a plain identifier,
 * so a key of a data array cannot smuggle SQL in either.
 *
 * Every connection runs in UTC, so the TIMESTAMPTZ columns read back as UTC and the app's own clock
 * (App\Support\Clock) is what goes in.
 */
final class Db
{
    private int $depth = 0;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param  array{host: string, port: string|int, database: string, username: string, password?: string|null, persistent?: bool}  $config
     */
    public static function connect(array $config, ?string $database = null): self
    {
        return new self(self::pdo($config, $database ?? (string) $config['database']));
    }

    /**
     * A bare PDO connection (the installer and the migration runner connect before any config file exists).
     *
     * A pooled connection may still be inside a transaction its last request died in, hence the ROLLBACK,
     * which PostgreSQL answers with a warning when there is nothing to roll back - and may still hold the
     * advisory lock an apply of migrations takes for its whole run (anotoki-lib's Migrator, which gives it back
     * in a `finally` a request that dies never reaches): that is given back too, or every later apply would be
     * told the database is busy while the site stays closed.
     */
    public static function pdo(array $config, string $database, ?bool $persistent = null, int $timeout = 5): PDO
    {
        $persistent ??= ! empty($config['persistent']);
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $database);
        $options = [PDO::ATTR_TIMEOUT => $timeout];
        if ($persistent) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        $pdo = new PDO($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), $options);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(($persistent ? 'ROLLBACK; SELECT pg_advisory_unlock_all(); ' : '')."SET TIME ZONE 'UTC'; SET client_encoding = 'UTF8'");

        return $pdo;
    }

    /**
     * Makes the database when it is not there, and answers whether it had to (migration.php, setup.php, the
     * tests). On most shared hosting the application's user cannot CREATE DATABASE at all; there it is made in
     * the control panel first and this finds it already there.
     *
     * @param  array{host: string, port: string|int, username: string, password?: string|null}  $config
     */
    public static function ensureDatabase(array $config, string $name): bool
    {
        $server = self::pdo($config, 'postgres', false);
        $exists = $server->prepare('SELECT count(*) FROM pg_catalog.pg_database WHERE datname = ?');
        $exists->execute([$name]);

        if ((int) $exists->fetchColumn() > 0) {
            return false;
        }

        // The name is the installation's own configuration, never anything a request reaches, and CREATE
        // DATABASE takes no parameters: quoting it as an identifier is the whole of what can be done.
        $server->exec('CREATE DATABASE "'.str_replace('"', '""', $name).'"');

        return true;
    }

    public function raw(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param  array<int|string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    /**
     * @param  array<int|string, mixed>  $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @param  array<int|string, mixed>  $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->execute($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param  array<int|string, mixed>  $params
     * @return list<mixed>
     */
    public function column(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * A statement that returns nothing; the number of rows it touched.
     *
     * @param  array<int|string, mixed>  $params
     */
    public function run(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params)->rowCount();
    }

    /**
     * One row; its new id.
     *
     * @param  array<string, mixed>  $row
     */
    public function insert(string $table, array $row, string $returning = 'id'): mixed
    {
        self::identifier($table);
        self::identifier($returning);
        $columns = array_keys($row);
        array_map([self::class, 'identifier'], $columns);

        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s) RETURNING %s', $table,
            implode(', ', array_map(fn (string $column) => '"'.$column.'"', $columns)),
            implode(', ', array_fill(0, count($columns), '?')), $returning);

        return $this->value($sql, array_values($row));
    }

    /**
     * Many rows with the same columns, a few hundred parameters per statement.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertMany(string $table, array $rows, int $chunk = 100): void
    {
        if ($rows === []) {
            return;
        }
        self::identifier($table);
        $columns = array_keys($rows[0]);
        array_map([self::class, 'identifier'], $columns);
        $tuple = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $quoted = implode(', ', array_map(fn (string $column) => '"'.$column.'"', $columns));

        foreach (array_chunk($rows, $chunk) as $part) {
            $params = [];
            foreach ($part as $row) {
                foreach ($columns as $column) {
                    $params[] = $row[$column] ?? null;
                }
            }
            $this->run("INSERT INTO {$table} ({$quoted}) VALUES ".implode(', ', array_fill(0, count($part), $tuple)), $params);
        }
    }

    /**
     * UPDATE $table SET $set WHERE every $where column equals its value; the rows touched.
     *
     * @param  array<string, mixed>  $set
     * @param  array<string, mixed>  $where
     */
    public function update(string $table, array $set, array $where): int
    {
        if ($set === []) {
            return 0;
        }
        self::identifier($table);
        $assignments = [];
        foreach (array_keys($set) as $column) {
            self::identifier($column);
            $assignments[] = '"'.$column.'" = ?';
        }
        [$condition, $params] = self::where($where);

        return $this->run("UPDATE {$table} SET ".implode(', ', $assignments)." WHERE {$condition}", [...array_values($set), ...$params]);
    }

    /** @param  array<string, mixed>  $where */
    public function delete(string $table, array $where): int
    {
        self::identifier($table);
        [$condition, $params] = self::where($where);

        return $this->run("DELETE FROM {$table} WHERE {$condition}", $params);
    }

    /**
     * Runs $work in a transaction and returns what it returns. Nested calls use savepoints, so an inner
     * failure rolls back only the inner part when the outer code catches it.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $savepoint = 'sp'.($this->depth + 1);
        if ($this->depth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT {$savepoint}");
        }
        $this->depth++;

        try {
            $result = $work();
            $this->depth--;
            if ($this->depth === 0) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }

            return $result;
        } catch (\Throwable $e) {
            $this->depth--;
            if ($this->depth === 0) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } else {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            }

            throw $e;
        }
    }

    /**
     * "?, ?, ?" for an IN list, and its parameters. An empty list yields "NULL", which matches nothing.
     *
     * @param  list<mixed>  $values
     * @return array{0: string, 1: list<mixed>}
     */
    public static function in(array $values): array
    {
        $values = array_values($values);

        return [$values === [] ? 'NULL' : implode(', ', array_fill(0, count($values), '?')), $values];
    }

    /**
     * A time column read back.
     */
    public static function time(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? Clock::parse($value) : null;
    }

    /**
     * @param  array<int|string, mixed>  $params
     */
    private function execute(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_map([self::class, 'param'], $params));

        return $statement;
    }

    /**
     * PDO sends every parameter as a string: false would arrive as "" (which PostgreSQL refuses as a
     * boolean), and a DateTime not at all.
     */
    private static function param(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)->setTimezone(Clock::utc())->format('Y-m-d H:i:s.uP'),
            $value instanceof \BackedEnum => $value->value,
            is_array($value) || is_object($value) => throw new InvalidArgumentException('Encode arrays and objects as JSON before binding them.'),
            default => $value,
        };
    }

    /**
     * @param  array<string, mixed>  $where
     * @return array{0: string, 1: list<mixed>}
     */
    private static function where(array $where): array
    {
        if ($where === []) {
            throw new InvalidArgumentException('An UPDATE or DELETE needs a condition.');
        }
        $conditions = [];
        $params = [];
        foreach ($where as $column => $value) {
            self::identifier($column);
            if ($value === null) {
                $conditions[] = '"'.$column.'" IS NULL';
            } else {
                $conditions[] = '"'.$column.'" = ?';
                $params[] = $value;
            }
        }

        return [implode(' AND ', $conditions), $params];
    }

    private static function identifier(string $name): void
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Not a plain identifier: {$name}");
        }
    }
}
