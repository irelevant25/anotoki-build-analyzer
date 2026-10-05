<?php

namespace App\Repositories;

use App\Db\Db;
use PDOException;

/**
 * The site's own words as the database holds them: the languages, the keys and the strings
 * (migrations/003_languages_and_translations.sql). Every query on those three tables is here, and nothing
 * else the server does reads them: until that migration is applied they do not exist, and the rest of the
 * site works all the same.
 *
 * A language row is always {code, name, native_name, enabled, sort_order, strings} - strings: how many it has.
 */
final class Translations
{
    private const LANGUAGE = 'l.code, l.name, l.native_name, l.enabled, l.sort_order,
        (SELECT count(*) FROM translations t WHERE t.language_code = l.code) AS strings';

    public function __construct(private readonly Db $db) {}

    /**
     * Are the strings there to be read? Between an upload and "Apply pending" on the Migrations page the
     * tables do not exist; the first query asks for the table in a way that never fails, the second whether
     * English - what everything falls back to - has a string. A database that cannot be asked is not available either.
     */
    public function available(): bool
    {
        try {
            if (! $this->db->value("SELECT to_regclass('public.translations') IS NOT NULL")) {
                return false;
            }

            return (bool) $this->db->value('SELECT EXISTS (SELECT 1 FROM translations WHERE language_code = ?)', ['en']);
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        return $this->db->transaction($work);
    }

    // ── Languages ────────────────────────────────────────────────────────────────────────────────

    /**
     * Every language, the ones switched off too, in the order a chooser shows them.
     *
     * @return list<array<string, mixed>>
     */
    public function languages(): array
    {
        return $this->db->all('SELECT '.self::LANGUAGE.' FROM languages l ORDER BY l.sort_order, l.name, l.code');
    }

    /**
     * The languages a reader is offered: {code, name, native_name}.
     *
     * @return list<array<string, mixed>>
     */
    public function offered(): array
    {
        return $this->db->all('SELECT code, name, native_name FROM languages WHERE enabled ORDER BY sort_order, name, code');
    }

    /** @return array<string, mixed>|null */
    public function language(string $code): ?array
    {
        return $this->db->one('SELECT '.self::LANGUAGE.' FROM languages l WHERE l.code = ?', [$code]);
    }

    /**
     * Every language's name in English by its code, the rows held (FOR SHARE) until the transaction ends:
     * no language is deleted between a save's check and its writing.
     *
     * @return array<string, string>
     */
    public function languageNamesHeld(): array
    {
        return array_column($this->db->all('SELECT code, name FROM languages FOR SHARE'), 'name', 'code');
    }

    /**
     * Whoever adds a language waits here for anybody else who is adding one, so a count made after it is of
     * rows that are really there. It lets the rows be read and held (a save of strings), and stops only what
     * writes the table. For the transaction it is called in.
     */
    public function lockLanguages(): void
    {
        $this->db->run('LOCK TABLE languages IN SHARE ROW EXCLUSIVE MODE');
    }

    public function languageExists(string $code): bool
    {
        return $this->db->value('SELECT 1 FROM languages WHERE code = ?', [$code]) !== null;
    }

    public function languageCount(): int
    {
        return (int) $this->db->value('SELECT count(*) FROM languages');
    }

    /** The place after the last language's. */
    public function nextSortOrder(): int
    {
        return (int) $this->db->value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM languages');
    }

    /** Adds a language; false when one with that code was written in this very moment. */
    public function addLanguage(string $code, string $name, string $nativeName, bool $enabled, int $sortOrder): bool
    {
        return $this->db->run(
            'INSERT INTO languages (code, name, native_name, enabled, sort_order) VALUES (?, ?, ?, ?, ?) ON CONFLICT (code) DO NOTHING',
            [$code, $name, $nativeName, $enabled, $sortOrder]
        ) === 1;
    }

    /**
     * @param  array{name?: string, native_name?: string, enabled?: bool, sort_order?: int}  $set
     */
    public function changeLanguage(string $code, array $set): void
    {
        $this->db->update('languages', array_intersect_key($set, array_flip(['name', 'native_name', 'enabled', 'sort_order'])), ['code' => $code]);
    }

    /** Holds the language's row (FOR UPDATE) until the transaction ends; false when it is gone. */
    public function holdLanguage(string $code): bool
    {
        return $this->db->value('SELECT 1 FROM languages WHERE code = ? FOR UPDATE', [$code]) !== null;
    }

    public function stringCount(string $code): int
    {
        return (int) $this->db->value('SELECT count(*) FROM translations WHERE language_code = ?', [$code]);
    }

    /** Deletes a language; its strings go with it. */
    public function deleteLanguage(string $code): void
    {
        $this->db->delete('languages', ['code' => $code]);
    }

    // ── Keys and strings ─────────────────────────────────────────────────────────────────────────

    /** @return list<string> every key, by name */
    public function keyNames(): array
    {
        return $this->db->column('SELECT name FROM translation_keys ORDER BY name COLLATE "C"');
    }

    /**
     * Every key with its description, by name - so the forms of a plural (.few, .one, .other) are together.
     *
     * @return list<array{name: string, description: ?string}>
     */
    public function keys(): array
    {
        return $this->db->all('SELECT name, description FROM translation_keys ORDER BY name COLLATE "C"');
    }

    /**
     * A language's own strings, by key: [key => text], in the keys' order.
     *
     * @return array<string, string>
     */
    public function of(string $code): array
    {
        $values = [];
        foreach ($this->db->all('SELECT key_name, value FROM translations WHERE language_code = ? ORDER BY key_name COLLATE "C"', [$code]) as $row) {
            $values[$row['key_name']] = $row['value'];
        }

        return $values;
    }

    /**
     * Every string there is: {key_name, language_code, value}.
     *
     * @return list<array{key_name: string, language_code: string, value: string}>
     */
    public function strings(): array
    {
        return $this->db->all('SELECT key_name, language_code, value FROM translations ORDER BY key_name COLLATE "C", language_code');
    }

    /**
     * The keys that have no string in a language.
     *
     * @return list<string>
     */
    public function keysWithout(string $code): array
    {
        return $this->db->column(
            'SELECT k.name FROM translation_keys k
              WHERE NOT EXISTS (SELECT 1 FROM translations t WHERE t.key_name = k.name AND t.language_code = ?)
              ORDER BY k.name COLLATE "C"',
            [$code]
        );
    }

    /**
     * Keeps one string. One sent as it already is stays as it was - with who wrote it and when.
     *
     * @param  int|null  $userId  who is saving, or null for the command line
     */
    public function put(string $key, string $code, string $value, ?int $userId): void
    {
        $this->db->run(
            'INSERT INTO translations (key_name, language_code, value, updated_by) VALUES (?, ?, ?, ?)
             ON CONFLICT (key_name, language_code) DO UPDATE
                SET value = EXCLUDED.value, updated_at = CURRENT_TIMESTAMP, updated_by = EXCLUDED.updated_by
              WHERE translations.value IS DISTINCT FROM EXCLUDED.value',
            [$key, $code, $value, $userId]
        );
    }

    public function remove(string $key, string $code): void
    {
        $this->db->delete('translations', ['key_name' => $key, 'language_code' => $code]);
    }

    /**
     * A person's data is deleted: the strings they saved stay, saved by nobody. Nothing to do while the
     * tables are not there yet. Answers how many strings it changed.
     */
    public function forgetWhoSaved(int $userId): int
    {
        if (! $this->db->value("SELECT to_regclass('public.translations') IS NOT NULL")) {
            return 0;
        }

        return $this->db->run('UPDATE translations SET updated_by = NULL WHERE updated_by = ?', [$userId]);
    }
}
