<?php

namespace App\Commands;

use App\App;
use App\Cli;
use App\Repositories\Translations as Strings;
use App\Services\Translations\TranslationCheck;
use App\Services\Translations\TranslationEditor;
use App\Web\HttpError;
use JsonException;
use RuntimeException;

/**
 * translations.php: the site's own strings from the command line.
 *
 *   php translations.php --status               the keys the frontend uses against the database's
 *   php translations.php --export=CODE [FILE]   a language's own strings as flat JSON
 *   php translations.php --import=CODE FILE     and back in, checked as a save in the browser is
 *
 * --status needs the key scan (tests/support/translation_keys.php), which translations.php loads: it is for
 * a computer with the whole repository, and is never uploaded.
 */
final class Translations
{
    /**
     * @param  list<string>  $argv
     * @param  string|null  $frontend  the frontend's src/app, when it is not this repository's (the tests)
     */
    public static function run(App $app, array $argv, ?string $frontend = null): int
    {
        [$options, $positional] = Cli::arguments($argv);
        $command = array_values(array_intersect(['status', 'export', 'import'], array_keys($options)));
        if (count($command) > 1 || array_diff(array_keys($options), ['status', 'export', 'import']) !== []) {
            return self::usage();
        }
        $command = $command[0] ?? 'status';

        // --export=sk and --export sk are both a code; what follows is the file.
        $code = null;
        if ($command !== 'status') {
            $code = is_string($options[$command]) ? $options[$command] : array_shift($positional);
            if (! is_string($code) || $code === '') {
                return self::usage();
            }
        }

        $strings = $app->get(Strings::class);
        if (! $strings->available()) {
            Cli::error('The site\'s strings are not in the database '.$app->config->get('database.database').' yet: apply the migrations first (php migration.php).');

            return 1;
        }

        try {
            return match ($command) {
                'status' => self::status($app, $strings, $frontend),
                'export' => self::export($app, (string) $code, $positional[0] ?? null),
                'import' => isset($positional[0]) ? self::import($app, $strings, (string) $code, $positional[0]) : self::usage(),
            };
        } catch (HttpError $e) {
            // What the API would have answered: the sentence, and the keys it is about.
            Cli::error('  Refused ('.($e->extra['code'] ?? $e->status).'): '.$e->getMessage());
            foreach ($e->extra['keys'] ?? [] as $key) {
                Cli::error("    {$key}");
            }

            return 1;
        } catch (RuntimeException $e) {
            Cli::error('  '.$e->getMessage());

            return 1;
        }
    }

    private static function usage(): int
    {
        Cli::error('Usage: php translations.php --status | --export=CODE [FILE] | --import=CODE FILE');

        return 1;
    }

    /** The frontend's keys against the database's, and how much of each language there is. */
    private static function status(App $app, Strings $strings, ?string $frontend): int
    {
        if (! function_exists('translationKeysScan')) {
            throw new RuntimeException('The key scan is not here (tests/support/translation_keys.php): --status is for a computer with the whole repository.');
        }
        $root = $frontend ?? translationKeysFrontendRoot();
        if (! is_dir($root)) {
            throw new RuntimeException('There is no frontend here to read the keys from (frontend/src/app).');
        }

        $keys = $strings->keyNames();
        $scan = translationKeysScan($root, $keys);
        $noEnglish = $strings->keysWithout(TranslationCheck::FALLBACK);

        Cli::line("== build analyzer ({$app->config->get('database.database')}): the site's own strings");
        Cli::line();
        Cli::line(sprintf('  the database has     %d key%s', count($keys), count($keys) === 1 ? '' : 's'));
        Cli::line(sprintf('  the frontend uses    %d of them', count($scan['used'])));

        if ($scan['missing']) {
            Cli::line();
            Cli::error('  Used by the frontend, not in the database - the page shows the key itself:');
            foreach ($scan['missing'] as $key => $where) {
                Cli::line("    {$key}");
                foreach (array_unique($where) as $place) {
                    Cli::line("        {$place}");
                }
            }
        }
        if ($noEnglish) {
            Cli::line();
            Cli::error('  No English string, so nothing to fall back to:');
            foreach ($noEnglish as $key) {
                Cli::line("    {$key}");
            }
        }
        if ($scan['problems']) {
            Cli::line();
            Cli::error('  What the scan does not let through:');
            foreach ($scan['problems'] as $problem) {
                Cli::line("    {$problem}");
            }
        }
        if ($scan['unused']) {
            Cli::line();
            Cli::warn('  In the database, used nowhere - a migration deletes a key the code has stopped using:');
            foreach ($scan['unused'] as $key) {
                Cli::line("    {$key}");
            }
        }

        Cli::line();
        foreach ($strings->languages() as $language) {
            Cli::line(sprintf('  %-6s %-20s %4d / %d%s', $language['code'], $language['name'], $language['strings'], count($keys), $language['enabled'] ? '' : '   (not offered)'));
        }
        Cli::line();

        if ($scan['missing'] || $noEnglish || $scan['problems']) {
            Cli::error('  Not clean.');

            return 1;
        }
        Cli::info('  Clean.');

        return 0;
    }

    /** A language's own strings - not merged with English - to a file, or to the screen. */
    private static function export(App $app, string $code, ?string $file): int
    {
        $values = $app->get(TranslationEditor::class)->export($code);
        if ($file === null) {
            Cli::line(rtrim(self::fileText($values), "\n"));

            return 0;
        }
        self::writeFile($file, $values);
        Cli::info('  '.count($values).' string'.(count($values) === 1 ? '' : 's')." of \"{$code}\" written to {$file}");

        return 0;
    }

    /** A file of strings into a language: checked like a save in the browser, written by nobody (updated_by NULL). */
    private static function import(App $app, Strings $strings, string $code, string $file): int
    {
        $values = self::readFile($file);
        $before = TranslationCheck::codeOk($code) ? $strings->of($code) : [];
        $app->get(TranslationEditor::class)->import($code, $values, null);
        $changed = count(array_diff_assoc($strings->of($code), $before));

        Cli::info('  '.count($values).' string'.(count($values) === 1 ? '' : 's')." in {$file}: {$changed} changed in \"{$code}\", the rest as they were (or empty, and passed over).");

        return 0;
    }

    // ── A language's strings as a file ───────────────────────────────────────────────────────────

    /**
     * A language's strings as the text of a file: one flat JSON object, a key a line, the letters as they
     * are - so it can be worked on in an editor and compared line by line.
     *
     * @param  array<string, mixed>|object  $values
     */
    public static function fileText(array|object $values): string
    {
        return json_encode((object) (array) $values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /** @param  array<string, mixed>|object  $values */
    public static function writeFile(string $path, array|object $values): void
    {
        if (@file_put_contents($path, self::fileText($values)) === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }
    }

    /**
     * The strings in a file: a flat JSON object, {key: text}. What each value is is checked where the
     * strings are kept (TranslationCheck), not here.
     *
     * @return array<string, mixed>
     */
    public static function readFile(string $path): array
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException("There is no file {$path}.");
        }
        // An editor on Windows may have put a byte-order mark in front.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        try {
            $values = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$path} is not JSON: ".$e->getMessage().'.');
        }
        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new RuntimeException("{$path} does not hold one flat object, {key: text}.");
        }

        return $values;
    }
}
