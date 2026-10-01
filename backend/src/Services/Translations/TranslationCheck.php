<?php

namespace App\Services\Translations;

use App\Support\Neutral;
use App\Web\HttpError;

/**
 * The rules the site's own strings keep - one set for a save of the grid, an import and the command line.
 * It reads nothing itself, so it is tested without a database.
 *
 * A key is the code's: migrations write the keys and their first strings, the browser only ever changes a
 * string. English is what every other language falls back to, key by key, so it cannot be switched off,
 * deleted, or left without a string.
 *
 * A string is text and is drawn as text - never HTML. What stands in for a value is a {placeholder}, and
 * which ones a key has is fixed by its English string: the code fills them in, and a translation that
 * invented one would show it to the reader as it is written.
 *
 * And no string names another website, service or tool, or holds a web address (App\Support\Neutral): the
 * rule that covers everything the site shows covers a string reworded in the browser too.
 *
 * A refusal is an HttpError with the English sentence and, in its extra fields, a code and what it is about
 * (key, language, placeholder ...): the owner's pages show the sentence beside that key.
 */
final class TranslationCheck
{
    /** The language every other one falls back to. */
    public const FALLBACK = 'en';

    /**
     * The languages the migrations write strings for. They cannot be deleted: the next migration with
     * strings would fail on the missing language. tests/translation_catalogue.test.php keeps this list true.
     */
    public const SEEDED = ['en', 'sk'];

    /** The largest body a save or an import of strings may have: every string of a language is a few dozen kB. */
    public const BODY_LIMIT = 1024 * 1024;

    /** How long one string may be - the longest the site has is a few sentences. */
    public const VALUE_MAX = 2000;

    /** A placeholder in a string: {name}. The same expression as the frontend's (core/i18n). */
    public const PLACEHOLDER = '/\{([A-Za-z][A-Za-z0-9_]*)\}/';

    public const LANGUAGES_MAX = 20;

    public const NAME_MAX = 50;

    public const SORT_MAX = 1000;

    /**
     * What the browser counts as a space - the characters String.prototype.trim() takes away and \s matches,
     * character for character: tab, the line breaks, the space, the no-break space, the Unicode spaces, the
     * line and paragraph separators and the byte-order mark. Written out rather than as \s and \p{Z}: those
     * also take U+0085 and U+180E, which the browser does not, and then the two sides would disagree on
     * whether a text is blank.
     */
    private const SPACE = '[\t\n\x0B\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    // ── Languages ────────────────────────────────────────────────────────────────────────────────

    /**
     * A language code as this site writes them: "sk", "pt-br". Asked before any query that takes a code
     * from an address - anything else is no language, and bytes that are not UTF-8 never reach the database
     * (which would refuse them: a 500 for what is only a wrong address).
     */
    public static function codeOk(mixed $code): bool
    {
        return is_string($code) && preg_match('/^[a-z]{2}(-[a-z]{2})?$/D', $code) === 1;
    }

    /**
     * One of a language's two names: text of 1 to 50 characters, with something in it to see - a name of
     * nothing but a zero-width space would be an empty line in the chooser - and naming no other site.
     *
     * @param  string  $field  the field of the body it came in, for the refusal
     */
    public static function languageName(mixed $value, string $what, string $field = 'name'): string
    {
        $text = self::clean($value);
        if ($text === null) {
            throw self::invalidLanguage("{$what} is not text.");
        }
        $text = self::trim($text);
        if (self::blank($text) || self::length($text) > self::NAME_MAX) {
            throw self::invalidLanguage("{$what} must be 1 to ".self::NAME_MAX.' characters long.');
        }
        $found = self::otherSites($text);
        if ($found !== []) {
            throw self::invalid('names_another_site', "{$what} ".self::namesSentence($found), ['field' => $field, 'found' => $found]);
        }

        return $text;
    }

    public static function invalidLanguage(string $message): HttpError
    {
        return self::invalid('invalid_language', $message);
    }

    // ── Strings ──────────────────────────────────────────────────────────────────────────────────

    /**
     * The placeholders a string uses, each once, in the order they first appear.
     *
     * @return list<string>
     */
    public static function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The other websites, services and tools a text names, lowercased - or "a web address" when it holds
     * one and names none. Empty: the text is neutral.
     *
     * Neutral's expression works on bytes, so a letter with a diacritic does not count as part of a word
     * before a name: a Slovak word that ends like a site's name is found too. Reword the string.
     *
     * @return list<string>
     */
    public static function otherSites(string $text): array
    {
        if (! Neutral::mentionsAny($text)) {
            return [];
        }

        return Neutral::namesIn($text) ?: ['a web address'];
    }

    /**
     * Checks strings somebody wants kept - a save of the grid, an import, the command line - and says what
     * to write. Everything is checked before anything is written; the first thing wrong is refused (422),
     * with the key and the language it is about:
     *
     *   invalid_value         not {key: {language: text}}; a value that is not text, or longer than 2000 characters
     *   unknown_key           a key the site does not have (with `keys`: every such key sent)
     *   unknown_language      a language the site does not have
     *   fallback_required     an empty English string - every other language falls back to it
     *   invalid_placeholder   a { or } that is not part of a {placeholder}: "{ count }", "{počet}", "{count", "{{count}}"
     *   placeholder_changed   an English string whose {placeholders} are not exactly the stored English string's
     *   unknown_placeholder   another language's string with a {placeholder} the English one does not have
     *   names_another_site    a string that names another website, service or tool, or holds a web address
     *                         (with `found`: what was found)
     *
     * An empty string in another language means "no string of its own": the row goes, and the language
     * reads that key in English. Empty is a string with nothing in it to see (blank()): spaces, or a
     * zero-width space alone, which would otherwise be kept and drawn as an empty place.
     *
     * A translation may leave a placeholder out (Slovak often says it another way), but never add one: the
     * code fills in only the ones the English string has. For a key with no English string yet, the English
     * sent with it is what counts. And a brace is only ever part of a placeholder: the page fills in what is
     * written exactly as {name} and leaves anything else as it stands, so a placeholder with a space in it,
     * or with its name translated, would be read by everybody as braces and a word.
     *
     * What is to be written comes back by key and then by language, whatever order it was sent in: every
     * save takes its rows in that one order, so two saves of the same strings wait for each other instead
     * of each holding a row the other needs (which the database ends by failing one of them).
     *
     * @param  mixed  $values  {key: {language code: text}}
     * @param  list<string>  $keys  every key there is
     * @param  array<string, string>  $languages  every language there is: code => its name in English
     * @param  array<string, string>  $english  the stored English string of every key that has one
     * @return list<array{key: string, language: string, value: ?string}> value null: delete that string
     */
    public static function changes(mixed $values, array $keys, array $languages, array $english): array
    {
        $isMap = static fn (mixed $value): bool => is_array($value) && ($value === [] || ! array_is_list($value));
        if (! $isMap($values)) {
            throw self::invalid('invalid_value', 'Send the strings by key and language: {key: {language: text}}.');
        }

        $known = array_fill_keys($keys, true);
        // What is quoted back in a refusal is never longer than a key can be.
        $quote = static fn (string $text): string => '"'.self::clip($text, 200).'"';
        $changes = [];

        foreach ($values as $key => $cells) {
            $key = (string) $key; // PHP made a number of a key like "12"
            if (! $isMap($cells)) {
                throw self::invalid('invalid_value', 'The strings of '.$quote($key).' are not given by language: {language: text}.', ['key' => self::clip($key, 200)]);
            }
            if (! isset($known[$key])) {
                $unknown = array_values(array_filter(array_map('strval', array_keys($values)), static fn (string $name): bool => ! isset($known[$name])));
                throw self::invalid(
                    'unknown_key',
                    count($unknown) === 1
                        ? 'The site has no key '.$quote($key).'. Keys are made by the code, never here.'
                        : 'The site has no such keys: '.implode(', ', array_map($quote, array_slice($unknown, 0, 20))).(count($unknown) > 20 ? ' and '.(count($unknown) - 20).' more' : '').'. Keys are made by the code, never here.',
                    ['key' => self::clip($key, 200), 'keys' => array_map(static fn (string $name): string => self::clip($name, 200), $unknown)]
                );
            }

            // The placeholders the key has: its stored English string's - or, for a key that has none yet,
            // those of the English string sent with it.
            $stored = $english[$key] ?? null;
            $sentEnglish = self::clean($cells[self::FALLBACK] ?? null);
            $placeholders = self::placeholders($stored ?? $sentEnglish ?? '');

            foreach ($cells as $code => $value) {
                $code = (string) $code;
                $about = ['key' => $key, 'language' => self::clip($code, 20)];
                if (! isset($languages[$code])) {
                    throw self::invalid('unknown_language', 'The site has no language '.$quote($code).'.', $about);
                }
                $name = $languages[$code];

                $text = self::clean($value);
                if ($text === null) {
                    throw self::invalid('invalid_value', "The {$name} string of \"{$key}\" is not text.", $about);
                }
                $text = self::trim($text);
                if (self::blank($text)) {
                    if ($code === self::FALLBACK) {
                        throw self::invalid('fallback_required', "\"{$key}\" needs its English string: every other language falls back to it.", $about);
                    }
                    $changes[] = ['key' => $key, 'language' => $code, 'value' => null];

                    continue;
                }
                if (self::length($text) > self::VALUE_MAX) {
                    throw self::invalid('invalid_value', "The {$name} string of \"{$key}\" is longer than ".self::VALUE_MAX.' characters.', $about);
                }
                // With every placeholder taken out, no brace is left - in any language: what is left would
                // be shown as it is written.
                if (preg_match('/[{}]/', (string) preg_replace(self::PLACEHOLDER, '', $text)) === 1) {
                    throw self::invalid(
                        'invalid_placeholder',
                        "The {$name} string of \"{$key}\" has a { or } that is not part of a placeholder."
                            .($placeholders === []
                                ? ' This key has no placeholder, so its strings can hold no { or }.'
                                : ' A placeholder is written exactly as the English string has it, like {'.$placeholders[0].'}: no spaces, and its name is not translated.'),
                        $about
                    );
                }

                $used = self::placeholders($text);
                if ($code === self::FALLBACK) {
                    $changed = array_merge(array_diff($placeholders, $used), array_diff($used, $placeholders));
                    if ($changed !== []) {
                        $placeholder = $changed[0];
                        throw self::invalid(
                            'placeholder_changed',
                            in_array($placeholder, $placeholders, true)
                                ? "The English string of \"{$key}\" must keep {{$placeholder}}: the site puts a value there."
                                : "The English string of \"{$key}\" cannot use {{$placeholder}}: the site has no value for it.",
                            $about + ['placeholder' => $placeholder]
                        );
                    }
                } else {
                    $unknown = array_values(array_diff($used, $placeholders));
                    if ($unknown !== []) {
                        throw self::invalid(
                            'unknown_placeholder',
                            "The {$name} string of \"{$key}\" uses {{$unknown[0]}}, which its English string does not have.",
                            $about + ['placeholder' => $unknown[0]]
                        );
                    }
                }

                // Nothing the site shows names another website, service or tool - a reworded string either.
                $found = self::otherSites($text);
                if ($found !== []) {
                    throw self::invalid('names_another_site', "The {$name} string of \"{$key}\" ".self::namesSentence($found), $about + ['found' => $found]);
                }

                $changes[] = ['key' => $key, 'language' => $code, 'value' => $text];
            }
        }

        // One order for every save (strcmp: <=> would compare "10" and "9" as numbers).
        usort($changes, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']) ?: strcmp($a['language'], $b['language']));

        return $changes;
    }

    /**
     * A flat {key: text} of one language - an import, a file of the command line - as the {key: {language:
     * text}} a save takes. An empty string - one with nothing in it to see - is a key nobody has translated
     * yet, as an export filled in by hand leaves it, and is passed over: an import deletes nothing. A key
     * the site does not have is still refused, empty or not.
     */
    public static function flat(string $code, mixed $body): array
    {
        if (! is_array($body) || ($body !== [] && array_is_list($body))) {
            throw self::invalid('invalid_value', 'Send the strings as one flat object: {key: text}.');
        }

        $values = [];
        foreach ($body as $key => $text) {
            $clean = self::clean($text);
            $values[$key] = $clean !== null && self::blank($clean) ? [] : [$code => $text];
        }

        return $values;
    }

    /** @param array<string, mixed> $extra */
    public static function invalid(string $code, string $message, array $extra = []): HttpError
    {
        return new HttpError(422, $message, ['code' => $code] + $extra);
    }

    /** @param list<string> $found from otherSites() */
    private static function namesSentence(array $found): string
    {
        return ($found === ['a web address']
            ? 'holds a web address.'
            : 'names another site ('.implode(', ', array_map(static fn (string $name): string => '"'.$name.'"', $found)).').')
            .' Nothing the site shows names or links another website, service or tool.';
    }

    // ── Text, measured the way people see it ─────────────────────────────────────────────────────

    /**
     * A text field's value as it is kept: valid UTF-8 without control characters but tabs and line breaks -
     * or null for what is not text at all. The controls are dropped rather than refused: a soft line break
     * pasted from a word processor would otherwise make a string that can never be saved.
     */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('//u', $value) !== 1) {
            return null;
        }

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    /**
     * The text without the spaces around it - exactly the ones the browser's trim() takes away (SPACE), not
     * only the ASCII ones PHP's trim() knows.
     *
     * A text the expression cannot get through - a run of more than a million spaces inside it - comes back
     * as it is, never as an empty one: whoever measures it next refuses it as too long, instead of taking it
     * for nothing.
     */
    public static function trim(string $text): string
    {
        return preg_replace('/^'.self::SPACE.'+|'.self::SPACE.'+$/u', '', $text) ?? $text;
    }

    /**
     * Is there nothing in the text to see? True when it has no character but white space, separators and
     * format characters: a zero-width space, a word joiner or a soft hyphen alone shows as nothing at all,
     * though trim() leaves each of them. Bytes that are not UTF-8 are nothing to read either.
     */
    public static function blank(string $text): bool
    {
        return preg_match('/[^\s\p{Z}\p{Cf}]/u', $text) !== 1;
    }

    /** How many characters: code points, which is at most what the browser counts (UTF-16 units). */
    public static function length(string $text): int
    {
        return (int) preg_match_all('/./us', $text);
    }

    /** The first $max characters of a UTF-8 string. */
    public static function clip(string $text, int $max): string
    {
        return preg_match('/^.{0,'.$max.'}/us', $text, $m) ? $m[0] : '';
    }
}
