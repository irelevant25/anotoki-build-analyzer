<?php

namespace App\Services\Translations;

use App\Repositories\Translations;
use App\Web\HttpError;

/**
 * What the owner does to the site's words: adds, changes and deletes languages, saves reworded strings,
 * takes a language out as a file and puts one back in. The rules are TranslationCheck's; a refusal is an
 * HttpError with a code (and the key and language it is about).
 *
 * Bodies arrive as they were parsed (arrays, or whatever was sent instead of one) and are checked here,
 * strictly: "false" is not false, and "3" is not 3.
 */
final class TranslationEditor
{
    public function __construct(private readonly Translations $translations) {}

    // ── Languages ────────────────────────────────────────────────────────────────────────────────

    public static function languageNotFound(): HttpError
    {
        return new HttpError(404, 'There is no such language.', ['code' => 'not_found']);
    }

    /**
     * A new language, {code, name, native_name, enabled?}: after the ones there are, and offered at once
     * unless `enabled` says false - then it is there for its strings to be written, and readers are offered
     * it when somebody switches it on. It has no strings yet, so until then it would read in English.
     *
     * @return array<string, mixed> the language's row
     */
    public function createLanguage(mixed $body): array
    {
        if (! is_array($body)) {
            throw TranslationCheck::invalidLanguage('Send the language as {code, name, native_name}.');
        }
        $code = $body['code'] ?? null;
        if (! TranslationCheck::codeOk($code)) {
            throw TranslationCheck::invalidLanguage('A language\'s code is two small letters, or two and two with a dash between: "sk", "pt-br".');
        }
        $name = TranslationCheck::languageName($body['name'] ?? null, 'The name in English', 'name');
        $nativeName = TranslationCheck::languageName($body['native_name'] ?? null, 'The name as its speakers write it', 'native_name');
        $enabled = true;
        if (array_key_exists('enabled', $body)) {
            if (! is_bool($body['enabled'])) {
                throw TranslationCheck::invalidLanguage('"enabled" is true or false.');
            }
            $enabled = $body['enabled'];
        }

        return $this->translations->transaction(function () use ($code, $name, $nativeName, $enabled): array {
            // Whoever adds a language waits here for anybody else who is adding one: the count below is
            // then of rows that are really there, and the most the site keeps holds. (Two transactions that
            // only counted would each miss the other's row and both go past it.) Taken first, so nothing is
            // held while waiting.
            $this->translations->lockLanguages();

            $exists = static fn (): HttpError => new HttpError(409, "There is a language \"{$code}\" already.", ['code' => 'language_exists']);
            if ($this->translations->languageExists($code)) {
                throw $exists();
            }
            if ($this->translations->languageCount() >= TranslationCheck::LANGUAGES_MAX) {
                throw TranslationCheck::invalid('too_many_languages', 'The site keeps at most '.TranslationCheck::LANGUAGES_MAX.' languages. Delete one that is not used first.');
            }
            $sortOrder = min($this->translations->nextSortOrder(), TranslationCheck::SORT_MAX);
            // A row written past this method in this very moment: no row is added, and no error that would
            // end the transaction.
            if (! $this->translations->addLanguage($code, $name, $nativeName, $enabled, $sortOrder)) {
                throw $exists();
            }

            return $this->translations->language($code) ?? throw self::languageNotFound();
        });
    }

    /**
     * Changes a language: {name?, native_name?, enabled?, sort_order?} - what is not sent stays. English
     * cannot be switched off; any other language can, at any time: it keeps its strings and is only no
     * longer offered.
     *
     * @return array<string, mixed> the language's row
     */
    public function changeLanguage(string $code, mixed $body): array
    {
        $row = TranslationCheck::codeOk($code) ? $this->translations->language($code) : null;
        if ($row === null) {
            throw self::languageNotFound();
        }
        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            throw TranslationCheck::invalidLanguage('Send what changes as {name, native_name, enabled, sort_order}.');
        }

        $set = [];
        if (array_key_exists('name', $body)) {
            $set['name'] = TranslationCheck::languageName($body['name'], 'The name in English', 'name');
        }
        if (array_key_exists('native_name', $body)) {
            $set['native_name'] = TranslationCheck::languageName($body['native_name'], 'The name as its speakers write it', 'native_name');
        }
        if (array_key_exists('enabled', $body)) {
            if (! is_bool($body['enabled'])) {
                throw TranslationCheck::invalidLanguage('"enabled" is true or false.');
            }
            if ($code === TranslationCheck::FALLBACK && ! $body['enabled']) {
                throw new HttpError(409, 'English cannot be switched off: every other language falls back to it.', ['code' => 'fallback_language']);
            }
            $set['enabled'] = $body['enabled'];
        }
        if (array_key_exists('sort_order', $body)) {
            if (! is_int($body['sort_order']) || $body['sort_order'] < 0 || $body['sort_order'] > TranslationCheck::SORT_MAX) {
                throw TranslationCheck::invalidLanguage('The order is a whole number from 0 to '.TranslationCheck::SORT_MAX.'.');
            }
            $set['sort_order'] = $body['sort_order'];
        }
        if ($set === []) {
            return $row;
        }

        $this->translations->changeLanguage($code, $set);

        return $this->translations->language($code) ?? throw self::languageNotFound();
    }

    /**
     * Deletes a language and its strings, and says how many strings went. Never English, and never a
     * language the migrations write strings for.
     */
    public function deleteLanguage(string $code): int
    {
        if (! TranslationCheck::codeOk($code) || $this->translations->language($code) === null) {
            throw self::languageNotFound();
        }
        if ($code === TranslationCheck::FALLBACK) {
            throw new HttpError(409, 'English cannot be deleted: every other language falls back to it.', ['code' => 'fallback_language']);
        }
        if (in_array($code, TranslationCheck::SEEDED, true)) {
            throw new HttpError(409, 'This language is one the site is released in: every update brings strings for it, and would fail without it. Switch it off instead.', ['code' => 'seeded_language']);
        }

        return $this->translations->transaction(function () use ($code): int {
            // The language's row first, held: a save of strings in this language holds the row too, so this
            // waits until that save is kept - and the count below is of the strings that are really there.
            if (! $this->translations->holdLanguage($code)) {
                throw self::languageNotFound();
            }
            $strings = $this->translations->stringCount($code);
            $this->translations->deleteLanguage($code);

            return $strings;
        });
    }

    // ── Strings ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Keeps strings: {key: {language: text}}, only the ones that changed. All of it is checked first
     * (TranslationCheck::changes) and written in one transaction, or none of it. A string sent as it already
     * is stays as it was - with who wrote it and when. Two people changing the same string: the last save
     * is kept.
     *
     * @param  int|null  $userId  who is saving - the request's person - or null for the command line
     */
    public function save(mixed $values, ?int $userId): void
    {
        $this->translations->transaction(function () use ($values, $userId): void {
            $languages = $this->translations->languageNamesHeld();
            $changes = TranslationCheck::changes($values, $this->translations->keyNames(), $languages, $this->translations->of(TranslationCheck::FALLBACK));

            foreach ($changes as $change) {
                if ($change['value'] === null) {
                    $this->translations->remove($change['key'], $change['language']);
                } else {
                    $this->translations->put($change['key'], $change['language'], $change['value'], $userId);
                }
            }
        });
    }

    /**
     * A language's own strings as a flat [key => text] - not merged with English, and empty when it has none.
     *
     * @return array<string, string>
     */
    public function export(string $code): array
    {
        $this->known($code);

        return $this->translations->of($code);
    }

    /**
     * A language's strings back in, from a flat {key: text}: the same checks as a save. An empty string is
     * passed over - an import deletes nothing - and a key the site does not have is refused.
     */
    public function import(string $code, mixed $body, ?int $userId): void
    {
        $this->known($code);
        $this->save(TranslationCheck::flat($code, $body), $userId);
    }

    /** No query for what is not a code at all. */
    private function known(string $code): void
    {
        if (! TranslationCheck::codeOk($code) || ! $this->translations->languageExists($code)) {
            throw self::languageNotFound();
        }
    }
}
