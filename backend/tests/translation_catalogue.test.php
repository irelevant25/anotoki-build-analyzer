<?php

/**
 * The site's own strings as the migrations leave them, against what the frontend asks for - so a page never
 * shows a key instead of a sentence, and no string is kept that nothing reads - the rules a string keeps
 * (App\Services\Translations\TranslationCheck), and the scan that finds the frontend's keys
 * (tests/support/translation_keys.php), tested on sources of its own.
 *
 * What is held from when: the migration with the tables (003) is there before its strings are. Until a
 * migration inserts keys, the checks that need strings have nothing to hold the frontend to: each of them
 * prints a line beginning "wait" saying what it does not check yet, and passes. From the first
 * "INSERT INTO translation_keys" in a migration on, stringMigrations() is not empty and every one of them
 * bites - nothing else has to be switched on.
 *
 * Nothing here changes what the migrations left.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use App\Db\Migrations;
use App\Repositories\Translations;
use App\Services\Analysis\BuildEvaluator;
use App\Services\Import\ImportKinds;
use App\Services\Translations\TranslationCheck;
use App\Web\HttpError;

bootTests();

/**
 * The migrations that write strings. Until the first of them is there the database has no keys.
 *
 * @return list<string>
 */
function stringMigrations(): array
{
    $files = [];
    foreach (glob(Migrations::directory().'/*.sql') ?: [] as $file) {
        if (preg_match('/^\s*INSERT\s+INTO\s+translation_keys\b/mi', (string) file_get_contents($file))) {
            $files[] = basename($file);
        }
    }
    sort($files);

    return $files;
}

/** Says what a test does not hold yet, and why: a line of its own above the test's. */
function waits(string $what): void
{
    echo "wait  {$what}\n";
}

/** @return list<string> every key the database has, by name */
function databaseKeys(): array
{
    return T::$app->get(Translations::class)->keyNames();
}

/** A list in the order of its values: for two lists that must hold the same, whatever order each was written in. */
function inOrder(array $list): array
{
    sort($list, SORT_STRING);

    return $list;
}

/**
 * The English compiled into the app, key => text: core/i18n/en.json, or the parts under core/i18n/en/ -
 * flat {"key": "English"} objects, a key in exactly one of them. Null while the frontend has none. Read as
 * JSON: no TypeScript is parsed.
 *
 * @return array<string, string>|null
 */
function compiledEnglish(): ?array
{
    $dir = translationKeysFrontendRoot().'/core/i18n';
    $files = array_merge(is_file("{$dir}/en.json") ? ["{$dir}/en.json"] : [], glob("{$dir}/en/*.json") ?: []);
    if ($files === []) {
        return null;
    }

    $english = [];
    foreach ($files as $file) {
        $name = basename($file);
        $part = json_decode((string) file_get_contents($file), true);
        check(is_array($part) && ($part === [] || ! array_is_list($part)), "{$name} of the compiled English is not one flat JSON object");
        foreach ($part as $key => $text) {
            $key = (string) $key;
            check(is_string($text), "{$name}: \"{$key}\" is not a string");
            check(! isset($english[$key]), "{$name}: \"{$key}\" is in another part of the compiled English too");
            $english[$key] = $text;
        }
    }
    ksort($english, SORT_STRING);

    return $english;
}

/** A small frontend to scan: $files as relative path => contents, written with Windows line endings. */
function sources(array $files): string
{
    $root = T::$storage.DIRECTORY_SEPARATOR.'sources-'.bin2hex(random_bytes(3));
    foreach ($files as $relative => $contents) {
        @mkdir(dirname("{$root}/{$relative}"), 0777, true);
        // Whatever line endings this file itself was checked out with.
        file_put_contents("{$root}/{$relative}", str_replace(["\r\n", "\n"], ["\n", "\r\n"], $contents));
    }

    return $root;
}

/** What TranslationCheck::changes() refuses $values with: [code, what it is about] - or the changes it would make. */
function checked(mixed $values, array $english = ['test.hello' => 'Hello, {name}!', 'test.bye' => 'Goodbye', 'test.count' => '{count} questions', 'test.step' => 'Step {n} of {m}']): array
{
    try {
        return TranslationCheck::changes($values, ['test.hello', 'test.bye', 'test.count', 'test.step', 'test.new'], ['en' => 'English', 'sk' => 'Slovak'], $english);
    } catch (HttpError $e) {
        same(422, $e->status, 'a refusal is a 422');

        return [$e->extra['code'], array_diff_key($e->extra, ['code' => 0])];
    }
}

/**
 * The keys a string migration names, place by place: the rows it inserts into translation_keys, the strings
 * it inserts for each language, and the number of keys its gate expects. Read from the file's text - the way
 * 003 says its strings are written is the pattern every later one follows.
 *
 * @return array{keys: list<string>, strings: array<string, list<string>>, expected: ?int}
 */
function migrationKeys(string $file): array
{
    return migrationKeysIn((string) file_get_contents(Migrations::directory().'/'.$file));
}

/**
 * The same, of a migration's text.
 *
 * @return array{keys: list<string>, strings: array<string, list<string>>, expected: ?int}
 */
function migrationKeysIn(string $sql): array
{
    $sql = str_replace("\r\n", "\n", $sql);
    $sql = (string) preg_replace('/^[ \t]*--.*$/m', '', $sql); // whole-line comments say how, and are not rows
    // The first thing of a row: its key, at the start of a line.
    $rows = static function (string $block): array {
        preg_match_all("/^\\s*\\('([^']+)',/m", $block, $m);

        return $m[1];
    };

    $found = ['keys' => [], 'strings' => [], 'expected' => null];
    if (preg_match('/INSERT\s+INTO\s+translation_keys\b[^;]*?\bVALUES\b(.*?)\nON CONFLICT/s', $sql, $m)) {
        $found['keys'] = $rows($m[1]);
    }
    preg_match_all("/INSERT\\s+INTO\\s+translations\\b[^;]*?SELECT\\s+v\\.key_name,\\s*'([a-z-]+)',[^;]*?\\bVALUES\\b(.*?)\nON CONFLICT/s", $sql, $blocks, PREG_SET_ORDER);
    foreach ($blocks as $block) {
        $found['strings'][$block[1]] = $rows($block[2]);
    }
    if (preg_match('/\bexpected_keys\s+CONSTANT\s+INTEGER\s*:=\s*(\d+)\s*;/', $sql, $m)) {
        $found['expected'] = (int) $m[1];
    }

    return $found;
}

// ── What the migrations leave ────────────────────────────────────────────────────────────────────

test('the two languages the site is released in, English first - and the keys, once a migration writes them', function () {
    same([
        ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'enabled' => true, 'sort_order' => 1],
        ['code' => 'sk', 'name' => 'Slovak', 'native_name' => 'Slovenčina', 'enabled' => true, 'sort_order' => 2],
    ], db()->all('SELECT code, name, native_name, enabled, sort_order FROM languages ORDER BY sort_order'), 'the languages');

    if (stringMigrations() === []) {
        same([], databaseKeys(), 'no migration writes strings yet, so there are none');
        same(false, T::$app->get(Translations::class)->available(), 'and with no English string the strings are not there to be read');
        waits('the keys are there, and the strings can be read - until a migration inserts into translation_keys');
    } else {
        check(databaseKeys() !== [], implode(', ', stringMigrations()).' left no keys');
        same(true, T::$app->get(Translations::class)->available(), 'the strings are there to be read');
    }

    // The database keeps its own rules, under the names the migration gives them.
    $refused = fn (string $sql): string => throws(PDOException::class, fn () => db()->run($sql), $sql)->getMessage();
    check(str_contains($refused("INSERT INTO languages (code, name, native_name) VALUES ('EN', 'x', 'x')"), 'languages_code_check'), 'a code in capitals');
    check(str_contains($refused("INSERT INTO translations (key_name, language_code, value) VALUES ('no.such', 'en', 'x')"), 'translations_key_name_foreign'), 'a string of no key');
    db()->run("INSERT INTO translation_keys (name) VALUES ('zz.probe')");
    try {
        check(str_contains($refused("INSERT INTO translations (key_name, language_code, value) VALUES ('zz.probe', 'xx', 'x')"), 'translations_language_code_foreign'), 'a string in no language');
        check(str_contains($refused("INSERT INTO translations (key_name, language_code, value) VALUES ('zz.probe', 'en', '')"), 'translations_value_check'), 'an empty string');

        // Who reworded a string is a number, not a row of users: emptying users (as every test does) keeps the strings.
        seedUser(77, 'somebody');
        db()->run("INSERT INTO translations (key_name, language_code, value, updated_by) VALUES ('zz.probe', 'sk', 'Skúška', 77)");
        resetDatabase();
        same(['Skúška', 77], array_values(db()->one("SELECT value, updated_by FROM translations WHERE key_name = 'zz.probe'") ?? []), 'the string outlives its writer\'s row');
    } finally {
        db()->run("DELETE FROM translation_keys WHERE name = 'zz.probe'");
    }
    same(0, (int) db()->value("SELECT count(*) FROM translations WHERE key_name = 'zz.probe'"), 'a deleted key takes its strings with it');

    same([
        'languages_code_check c', 'languages_pkey p', 'translation_keys_pkey p', 'translations_key_name_foreign f',
        'translations_language_code_foreign f', 'translations_pkey p', 'translations_value_check c',
    ], db()->column("SELECT c.conname::text || ' ' || c.contype::text FROM pg_constraint c
        WHERE c.conrelid IN ('languages'::regclass, 'translation_keys'::regclass, 'translations'::regclass) AND c.contype <> 'n' ORDER BY 1"), 'the constraints, by name');
});

test('every key has an English string, and a Slovak one', function () {
    $translations = T::$app->get(Translations::class);
    same([], $translations->keysWithout('en'), 'keys with no English string: nothing to fall back to');
    same([], $translations->keysWithout('sk'), 'keys with no Slovak string');
    if (stringMigrations() === []) {
        waits('every key has English and Slovak - there is no key yet');
    }
});

test('a translation uses no placeholder its English string does not have', function () {
    $translations = T::$app->get(Translations::class);
    $english = $translations->of(TranslationCheck::FALLBACK);
    $wrong = [];
    foreach ($translations->strings() as $row) {
        if ($row['language_code'] === TranslationCheck::FALLBACK) {
            continue;
        }
        $extra = array_diff(TranslationCheck::placeholders($row['value']), TranslationCheck::placeholders($english[$row['key_name']] ?? ''));
        if ($extra !== []) {
            $wrong[] = "{$row['key_name']} ({$row['language_code']}): {".implode('}, {', $extra).'}';
        }
    }
    same([], $wrong, 'the site fills in only the placeholders the English string has');
});

test('a plural family has .one, .few and .other, and nothing else ends in them', function () {
    $incomplete = function (array $keys): array {
        $families = [];
        foreach ($keys as $key) {
            if (preg_match('/^(.+)\.('.implode('|', TRANSLATION_PLURAL_FORMS).')$/D', $key, $m)) {
                $families[$m[1]] = true;
            }
        }
        $missing = [];
        foreach (array_keys($families) as $family) {
            foreach (TRANSLATION_PLURAL_FORMS as $form) {
                if (! in_array("{$family}.{$form}", $keys, true)) {
                    $missing[] = "{$family}.{$form}";
                }
            }
        }

        return $missing;
    };

    same(['b.things.few'], $incomplete(['a.title', 'b.things.one', 'b.things.other', 'c.all.one', 'c.all.few', 'c.all.other']), 'how a form that is missing is found');
    same([], $incomplete(databaseKeys()), 'a key ending in .one, .few or .other is a form of a plural, and a plural has all three');
    // "in both languages" is the test above: every key has both.
    same([], $incomplete(array_keys(compiledEnglish() ?? [])), 'and the same in the English compiled into the app');
});

test('no namespace is named after a file the app reads, and every key has the shape the scan finds', function () {
    // The frontend has the literals 'changelog.json' and 'pasted.json', which have the shape of a key: a
    // namespace of that name would make them keys nobody seeded.
    foreach (['the database' => databaseKeys(), 'the compiled English' => array_keys(compiledEnglish() ?? [])] as $where => $keys) {
        $namespaces = array_values(array_unique(array_map('translationKeyNamespace', $keys)));
        same([], array_values(array_intersect($namespaces, ['changelog', 'pasted'])), "{$where}: a namespace the frontend's own file names would be taken for");
        foreach ($keys as $key) {
            check(translationKeyShapeOk($key), "{$where}: {$key} is not the shape of a key, so the scan cannot find it");
        }
    }
});

test('every language a migration writes strings for is one that cannot be deleted', function () {
    $written = db()->column('SELECT DISTINCT language_code FROM translations ORDER BY language_code');
    same([], array_values(array_diff($written, TranslationCheck::SEEDED)), 'add them to TranslationCheck::SEEDED: deleting one would make the next string migration fail');
    same([], array_values(array_diff(TranslationCheck::SEEDED, db()->column('SELECT code FROM languages'))), 'and each of them is a language here');
    check(in_array(TranslationCheck::FALLBACK, TranslationCheck::SEEDED, true), 'English among them');

    if (stringMigrations() === []) {
        waits('TranslationCheck::SEEDED names exactly the languages the migrations write strings in - none writes a string yet');
    } else {
        same(inOrder(TranslationCheck::SEEDED), inOrder($written), 'the list names exactly the languages the migrations write');
    }
});

test('the English compiled into the app is, key for key, the seeded English', function () {
    $compiled = compiledEnglish();
    $seeded = T::$app->get(Translations::class)->of(TranslationCheck::FALLBACK);

    if (stringMigrations() === []) {
        waits('the keys and the English of core/i18n/en are exactly the seeded ones - until a migration inserts into translation_keys'
            .($compiled === null ? ' (and the frontend has no compiled English yet)' : ' ('.count($compiled).' keys are compiled now)'));

        return;
    }
    check($compiled !== null, 'the frontend has no compiled English (core/i18n/en.json, or parts under core/i18n/en/)');
    same([], array_values(array_diff(array_keys($compiled), array_keys($seeded))), 'compiled into the app, not seeded: the page would show the key once the database answers');
    same([], array_values(array_diff(array_keys($seeded), array_keys($compiled))), 'seeded, not compiled into the app: no English while the database does not answer');
    $different = [];
    foreach ($seeded as $key => $text) {
        if ($compiled[$key] !== $text) {
            $different[] = "{$key}: seeded \"{$text}\", compiled \"{$compiled[$key]}\"";
        }
    }
    same([], $different, 'the same English both ways');
});

test('every key the frontend uses is in the database, and every key in the database is used', function () {
    $scan = translationKeysScan(translationKeysFrontendRoot(), databaseKeys());
    same([], $scan['problems'], 'what the scan does not let through');

    if (stringMigrations() === []) {
        // Nothing is written yet, so nothing the frontend already asks for can be found: from the first
        // migration with strings on, all of it is held.
        waits('no key the frontend uses is missing from the database, and none in the database is unused - until a migration inserts into translation_keys');

        return;
    }
    same([], $scan['missing'], 'used by the frontend, not in the database - the page would show the key (php translations.php --status)');
    same([], $scan['unused'], 'in the database, used nowhere - delete them with a migration');
    check(is_file(translationKeysFrontendRoot().'/'.TRANSLATION_DYNAMIC_KEYS_FILE), 'the keys put together at run time are declared in '.TRANSLATION_DYNAMIC_KEYS_FILE);
});

test('what the server says as a kind is a key the frontend declares: recommendations, import errors, import warnings', function () {
    $kinds = ['recommendation.' => BuildEvaluator::KINDS, 'import.error.' => ImportKinds::ERRORS, 'import.warning.' => ImportKinds::WARNINGS];
    same([18, 23, 9], array_map('count', array_values($kinds)), 'how many kinds there are');

    $file = translationKeysFrontendRoot().'/'.TRANSLATION_DYNAMIC_KEYS_FILE;
    if (! is_file($file)) {
        check(stringMigrations() === [], 'the strings are seeded, and the frontend has no '.TRANSLATION_DYNAMIC_KEYS_FILE.' to declare the kinds in');
        waits('BuildEvaluator::KINDS, ImportKinds::ERRORS and ::WARNINGS equal their tails in '.TRANSLATION_DYNAMIC_KEYS_FILE.' - the frontend has no such file yet');

        return;
    }
    $declared = translationKeysDeclared(translationKeysFrontendRoot());
    same([], $declared['problems'], TRANSLATION_DYNAMIC_KEYS_FILE.' can be read');
    foreach ($kinds as $prefix => $byKind) {
        same(inOrder(array_keys($byKind)), inOrder($declared['prefixes'][$prefix] ?? []), "the tails of '{$prefix}' in ".TRANSLATION_DYNAMIC_KEYS_FILE.' are the server\'s kinds');
    }

    if (stringMigrations() === []) {
        waits('the placeholders of each kind\'s seeded English are among that kind\'s parameters - until a migration inserts into translation_keys');

        return;
    }
    // A sentence can only be filled with what the server sends for its kind.
    $english = T::$app->get(Translations::class)->of(TranslationCheck::FALLBACK);
    $wrong = [];
    foreach ($kinds as $prefix => $byKind) {
        foreach ($byKind as $kind => $params) {
            check(isset($english[$prefix.$kind]), "{$prefix}{$kind} has no English string");
            $extra = array_diff(TranslationCheck::placeholders($english[$prefix.$kind]), $params);
            if ($extra !== []) {
                $wrong[] = "{$prefix}{$kind}: {".implode('}, {', $extra).'}';
            }
        }
    }
    same([], $wrong, 'a placeholder the server sends no parameter for');
});

test('no string names another site, and every one passes the checks a save makes, as it is', function () {
    // Otherwise a string nobody could save again would be released: the grid sends a cell back whole, and a
    // rule the seed does not keep (a brace, a length, a space at its end, a site's name) would refuse it.
    $translations = T::$app->get(Translations::class);
    $languages = array_column($translations->languages(), 'name', 'code');
    $stored = [];
    $named = [];
    foreach ($translations->strings() as $row) {
        $stored[$row['key_name']][$row['language_code']] = $row['value'];
        $found = TranslationCheck::otherSites($row['value']);
        if ($found !== []) {
            $named[] = "{$row['key_name']} ({$row['language_code']}): ".implode(', ', $found);
        }
    }
    foreach ($translations->languages() as $language) {
        same([], array_merge(TranslationCheck::otherSites($language['name']), TranslationCheck::otherSites($language['native_name'])), "the names of \"{$language['code']}\"");
    }
    same([], $named, 'a seeded string that names another site, or holds a web address (reword it: the check is not changed)');

    $kept = [];
    foreach (TranslationCheck::changes($stored, databaseKeys(), $languages, $translations->of(TranslationCheck::FALLBACK)) as $change) {
        $kept[$change['key']][$change['language']] = $change['value'];
    }
    same($stored, $kept, 'every string comes back as it is kept');

    // The English compiled into the app is held to the same, from the day it is written: it is what gets seeded.
    $compiled = compiledEnglish() ?? [];
    $wrong = [];
    foreach ($compiled as $key => $text) {
        try {
            $change = TranslationCheck::changes([$key => ['en' => $text]], [$key], ['en' => 'English'], [$key => $text]);
            if (($change[0]['value'] ?? null) !== $text) {
                $wrong[] = "{$key}: not kept as it is written (a space or a control character at its end)";
            }
        } catch (HttpError $e) {
            $wrong[] = "{$key}: {$e->getMessage()}";
        }
    }
    same([], $wrong, 'a compiled English string a save would refuse or change');

    if (stringMigrations() === []) {
        waits('no seeded string, English or Slovak, names another site or is refused by a save - there is no seeded string yet'
            .($compiled === [] ? '' : ' (the '.count($compiled).' compiled English ones are checked)'));
    } else {
        check(count($stored) > 100, 'the strings are there to check');
    }
});

test('a migration with strings writes each key once, in both languages, and its gate counts what it seeds', function () {
    // The pattern itself, on a text of the test's own.
    $pattern = "-- INSERT INTO translation_keys (name, description) VALUES ('in.a.comment', 'x')\n"
        ."INSERT INTO translation_keys (name, description) VALUES\n    -- a namespace\n    ('a.one', 'It''s (one), here'),\n    ('a.two', NULL)\nON CONFLICT (name) DO NOTHING;\n\n"
        ."INSERT INTO translations (key_name, language_code, value)\nSELECT v.key_name, 'en', v.value FROM (VALUES\n    ('a.one', 'One; ''1'''),\n    ('a.two', 'Two')\n) AS v(key_name, value)\nON CONFLICT (key_name, language_code) DO NOTHING;\n\n"
        ."INSERT INTO translations (key_name, language_code, value)\nSELECT v.key_name, 'sk', v.value FROM (VALUES\n    ('a.one', 'Jeden')\n) AS v(key_name, value)\nON CONFLICT (key_name, language_code) DO NOTHING;\n\n"
        ."DO \$\$\nDECLARE\n    expected_keys CONSTANT INTEGER := 2;\nBEGIN\n    NULL;\nEND \$\$;\n";
    same(['keys' => ['a.one', 'a.two'], 'strings' => ['en' => ['a.one', 'a.two'], 'sk' => ['a.one']], 'expected' => 2], migrationKeysIn(str_replace("\n", "\r\n", $pattern)),
        'how a string migration is read: its keys, its strings by language, its gate\'s count');

    // The tables' own migration says how many keys it seeds, and that is how many it inserts.
    $tables = migrationKeys('003_languages_and_translations.sql');
    check($tables['expected'] !== null, '003 does not say how many keys it seeds (expected_keys CONSTANT INTEGER := n)');
    same(count($tables['keys']), $tables['expected'], 'the number of keys 003\'s gate expects is the number of keys it inserts');

    if (stringMigrations() === []) {
        same(0, $tables['expected'], '003 seeds no key yet');
        waits('each string migration writes every key once, with an English and a Slovak string - none writes a string yet');

        return;
    }
    $all = [];
    foreach (stringMigrations() as $file) {
        $found = migrationKeys($file);
        check($found['keys'] !== [], "{$file}: the keys it inserts could not be read");
        same($found['keys'], array_values(array_unique($found['keys'])), "{$file}: a key is inserted twice");
        same(inOrder(TranslationCheck::SEEDED), inOrder(array_keys($found['strings'])), "{$file}: one block of strings a language");
        foreach ($found['strings'] as $code => $keys) {
            same(inOrder($found['keys']), inOrder($keys), "{$file}: the keys with a string in \"{$code}\" are not the keys it inserts");
        }
        $all = array_merge($all, $found['keys']);
    }
    same([], array_values(array_diff(databaseKeys(), $all)), 'keys in the database that no migration\'s rows name');
});

// ── The rules a string keeps ─────────────────────────────────────────────────────────────────────

test('a language code: two small letters, or two and two - and nothing else reaches a query', function () {
    foreach (['en', 'sk', 'pt-br', 'zh-tw'] as $code) {
        check(TranslationCheck::codeOk($code), $code);
    }
    foreach (['', 'e', 'eng', 'EN', 'En', 'en-', 'en-GB', 'en_gb', 'en-gbr', 'e1', ' en', "en\n", "sk\0", "\xFF\xFE", 'en-gb-x', 5, null, ['en'], true] as $code) {
        check(! TranslationCheck::codeOk($code), 'not a code: '.json_encode($code, JSON_INVALID_UTF8_SUBSTITUTE));
    }
});

test('placeholders: {name}, the same expression as the frontend fills in', function () {
    same(['name', 'count'], TranslationCheck::placeholders('Hello, {name}! {count} of {count} - {name}'), 'each once, in their order');
    same(['a_b', 'A1'], TranslationCheck::placeholders('{a_b} {A1}'), 'letters, digits, an underscore');
    same([], TranslationCheck::placeholders('{} {1a} { name } {na-me} {_x} plain'), 'what is not one');
});

test('a save is checked whole: the shape, the key, the language, the text, its length, its placeholders', function () {
    same([], checked([]), 'nothing to change');
    same(
        [['key' => 'test.bye', 'language' => 'en', 'value' => 'Bye'], ['key' => 'test.hello', 'language' => 'sk', 'value' => 'Dobrý deň, {name}!']],
        checked(['test.hello' => ['sk' => "  Dobrý\x07 deň, {name}! \u{00A0}"], 'test.bye' => ['en' => 'Bye']]),
        'control characters dropped, the spaces around it gone'
    );
    same([['key' => 'test.hello', 'language' => 'sk', 'value' => null]], checked(['test.hello' => ['sk' => " \t "]]), 'an empty string in another language: that string goes');
    same([], checked(['test.hello' => []]), 'a key with nothing to change');

    // Whatever order the cells were typed in, they are written by key and then by language: two saves of the
    // same strings take their rows in one order, and neither waits for the other for ever.
    $ordered = static fn (array $values): array => array_map(static fn (array $change): string => $change['key'].' '.$change['language'], checked($values));
    same(['test.bye sk', 'test.hello en', 'test.hello sk'], $ordered(['test.hello' => ['sk' => 'A {name}', 'en' => 'B {name}'], 'test.bye' => ['sk' => 'C']]), 'by key, then by language');
    same(['test.bye sk', 'test.hello en', 'test.hello sk'], $ordered(['test.bye' => ['sk' => ''], 'test.hello' => ['en' => 'B {name}', 'sk' => '']]), 'the ones that go among the ones that are written');
    same($ordered(['test.step' => ['sk' => 'x'], 'test.count' => ['sk' => 'y'], 'test.bye' => ['sk' => 'z']]), $ordered(['test.bye' => ['sk' => 'z'], 'test.count' => ['sk' => 'y'], 'test.step' => ['sk' => 'x']]), 'one order, however they were sent');

    $invalid = [
        'nothing' => null, 'a text' => 'text', 'a number' => 5, 'a list' => ['a', 'b'],
        'a key with a text' => ['test.hello' => 'Hello'], 'a key with a list' => ['test.hello' => ['a', 'b']],
        'a number for a string' => ['test.hello' => ['sk' => 5]], 'a list for a string' => ['test.hello' => ['sk' => ['x']]], 'null for a string' => ['test.hello' => ['sk' => null]],
        '2001 characters' => ['test.bye' => ['sk' => str_repeat('á', 2001)]],
    ];
    foreach ($invalid as $what => $values) {
        same('invalid_value', checked($values)[0], $what);
    }
    same(2000, TranslationCheck::length(checked(['test.bye' => ['sk' => str_repeat('á', 2000)]])[0]['value']), '2000 characters, not bytes');

    same(['unknown_key', ['key' => 'no.such', 'keys' => ['no.such', '12']]], checked(['test.bye' => ['en' => 'Bye'], 'no.such' => ['en' => 'x'], '12' => ['sk' => 'y']]), 'every unknown key is named');
    same(['unknown_language', ['key' => 'test.bye', 'language' => 'de']], checked(['test.bye' => ['de' => 'Tschüss']]), 'a language there is not');
    same(['fallback_required', ['key' => 'test.bye', 'language' => 'en']], checked(['test.bye' => ['en' => "\x07 "]]), 'English is what the others fall back to');

    // The order of the checks: the key before the language, the language before the text.
    same('unknown_key', checked(['no.such' => ['xx' => 5]])[0], 'the key first');
    same('unknown_language', checked(['test.bye' => ['xx' => 5]])[0], 'then the language');
    same('invalid_value', checked(['no.such' => 'text'])[0], 'the shape before both');

    // One language as a file: an empty string is passed over, a key is never dropped from the check.
    same(['test.hello' => ['sk' => 'Ahoj'], 'test.bye' => [], 'no.such' => [], 'test.count' => ['sk' => 5]], TranslationCheck::flat('sk', ['test.hello' => 'Ahoj', 'test.bye' => " \u{200B}", 'no.such' => '', 'test.count' => 5]), 'a flat file as a save');
    same([], TranslationCheck::flat('sk', []), 'an empty file');
    foreach ([null, 'text', ['a', 'b']] as $body) {
        same('invalid_value', throws(HttpError::class, fn () => TranslationCheck::flat('sk', $body), 'not a flat object')->extra['code'], json_encode($body));
    }
});

test('a string with nothing in it to see is an empty one - and one too long to trim is too long, never empty', function () {
    // A zero-width space, a word joiner, a soft hyphen: trim() leaves each, and the page would draw an empty place.
    $unseen = ['a zero-width space' => "\u{200B}", 'a word joiner' => "\u{2060}", 'a soft hyphen' => "\u{00AD}", 'several, with spaces' => " \u{200B}\u{00A0}\u{200D}\t\u{FEFF} ", 'nothing' => '', 'spaces' => " \n "];
    foreach ($unseen as $what => $text) {
        check(TranslationCheck::blank($text), $what);
        same([['key' => 'test.bye', 'language' => 'sk', 'value' => null]], checked(['test.bye' => ['sk' => $text]]), "{$what} in another language: that string goes");
        same(['fallback_required', ['key' => 'test.bye', 'language' => 'en']], checked(['test.bye' => ['en' => $text]]), "{$what} in English");
        same('invalid_language', throws(HttpError::class, fn () => TranslationCheck::languageName($text, 'The name in English'), "{$what} as a language's name")->extra['code'], $what);
    }
    check(TranslationCheck::blank("\xFF\xFE"), 'bytes that are not text are nothing to read');

    // A character of that kind inside a word is part of the word: Persian and Indic spelling needs it.
    foreach (["Ah\u{200C}oj", "\u{200B}Ahoj", 'a', '.', "\u{0301}"] as $text) {
        check(! TranslationCheck::blank($text), json_encode($text));
    }
    same("Ah\u{200C}oj", checked(['test.bye' => ['sk' => " Ah\u{200C}oj "]])[0]['value'], 'kept as it was written');
    same("می\u{200C}خواهم", TranslationCheck::languageName("می\u{200C}خواهم", 'The name as its speakers write it'), 'in a name too');
    // trim() itself is the browser's trim(), and takes none of them away.
    same("\u{200B}", TranslationCheck::trim(" \u{200B} "), 'the browser\'s set of spaces');
    same('a', TranslationCheck::trim("\u{FEFF}\u{3000}\u{2003} a\u{00A0}\u{2028}\n"), 'every one of them');
    same("\u{0085}a\u{180E}", TranslationCheck::trim("\u{0085}a\u{180E}"), 'and not the two the browser leaves');

    // More spaces inside it than the expression can get through: the text comes back as it is, and is
    // refused for its length - not taken for an empty one, which would delete the stored string.
    $long = 'a'.str_repeat(' ', 1100000).'b';
    same($long, TranslationCheck::trim($long), 'as it is');
    check(! TranslationCheck::blank($long), 'not blank');
    same(['invalid_value', ['key' => 'test.bye', 'language' => 'sk']], checked(['test.bye' => ['sk' => $long]]), 'too long');
    same(['invalid_value', ['key' => 'test.bye', 'language' => 'en']], checked(['test.bye' => ['en' => $long]]), 'not fallback_required either');
    same('The name in English must be 1 to 50 characters long.', throws(HttpError::class, fn () => TranslationCheck::languageName($long, 'The name in English'), 'as a name')->getMessage(), 'nor a name');
    same('b', TranslationCheck::trim(str_repeat(' ', 1100000).'b'), 'a run at either end is still trimmed');
    same('a', TranslationCheck::trim('a'.str_repeat("\u{00A0}", 600000)), 'at the end too');
});

test('placeholders are the code\'s: English keeps exactly its own, a translation may leave one out but never add one', function () {
    same('Hi, {name}!', checked(['test.hello' => ['en' => 'Hi, {name}!']])[0]['value'], 'reworded, the placeholder kept');
    same(['placeholder_changed', ['key' => 'test.hello', 'language' => 'en', 'placeholder' => 'name']], checked(['test.hello' => ['en' => 'Hi!']]), 'one left out');
    same(['placeholder_changed', ['key' => 'test.hello', 'language' => 'en', 'placeholder' => 'who']], checked(['test.hello' => ['en' => 'Hi, {name} and {who}!']]), 'one added');
    same(['placeholder_changed', ['key' => 'test.bye', 'language' => 'en', 'placeholder' => 'name']], checked(['test.bye' => ['en' => 'Bye, {name}']]), 'one where there was none');

    same('Vitaj!', checked(['test.hello' => ['sk' => 'Vitaj!']])[0]['value'], 'a translation may say it without the placeholder');
    same(['unknown_placeholder', ['key' => 'test.hello', 'language' => 'sk', 'placeholder' => 'meno']], checked(['test.hello' => ['sk' => 'Ahoj, {name} {meno}!']]), 'but never with one of its own');
    // English sent with it does not change what the key has: the stored English string does.
    same('unknown_placeholder', checked(['test.hello' => ['sk' => 'Ahoj {who}', 'en' => 'Hi {who}']])[0], 'the stored English counts');
    same('placeholder_changed', checked(['test.hello' => ['en' => 'Hi {who}', 'sk' => 'Ahoj {who}']])[0], 'whichever comes first');

    // A key with no English string yet: the English sent with it is what counts.
    same(2, count(checked(['test.new' => ['sk' => 'Nové {a}', 'en' => 'New {a}']])), 'both kept');
    same('unknown_placeholder', checked(['test.new' => ['sk' => 'Nové {a}']])[0], 'with no English at all there is no placeholder to use');
    same(1, count(checked(['test.new' => ['sk' => 'Nové']])), 'a plain one is fine');
});

test('a brace is only ever part of a placeholder: one written any other way is refused, not shown to everybody', function () {
    // What the page would leave exactly as it is written: the name translated, a space inside, a brace too few or too many.
    $wrong = [
        'the name translated' => ['test.count', 'sk', '{počet} otázok'],
        'spaces inside' => ['test.count', 'sk', '{ count } otázok'],
        'never closed' => ['test.count', 'sk', '{count otázok'],
        'a space before it closes' => ['test.count', 'sk', '{count } otázok'],
        'two braces' => ['test.count', 'sk', '{{count}} otázok'],
        'one of two never closed' => ['test.step', 'sk', 'Krok {n / {m}'],
        'two braces in English' => ['test.step', 'en', 'Step {{n}} of {m}'],
        'a brace that only closes' => ['test.bye', 'sk', 'Dovidenia }'],
        'a brace in a string with no placeholder' => ['test.bye', 'en', 'Good {bye'],
        'an empty pair' => ['test.count', 'sk', '{count} {} otázok'],
    ];
    foreach ($wrong as $what => [$key, $code, $text]) {
        same(['invalid_placeholder', ['key' => $key, 'language' => $code]], checked([$key => [$code => $text]]), $what);
    }
    $refused = throws(HttpError::class, fn () => TranslationCheck::changes(['test.step' => ['sk' => 'Krok { n }']], ['test.step'], ['sk' => 'Slovak'], ['test.step' => 'Step {n} of {m}']), 'a space inside');
    same('The Slovak string of "test.step" has a { or } that is not part of a placeholder. A placeholder is written exactly as the English string has it, like {n}: no spaces, and its name is not translated.',
        $refused->getMessage(), 'it says how one is written, with one of the key\'s own');
    // A key with no placeholder is not sent looking for one it does not have.
    same('invalid_placeholder', checked(['test.bye' => ['sk' => 'Tvoje {účty']])[0], 'a brace where the key has no placeholder');
    $bare = throws(HttpError::class, fn () => TranslationCheck::changes(['test.bye' => ['sk' => 'Tvoje {účty']], ['test.bye'], ['sk' => 'Slovak'], ['test.bye' => 'Goodbye']), 'no placeholder at all');
    same('The Slovak string of "test.bye" has a { or } that is not part of a placeholder. This key has no placeholder, so its strings can hold no { or }.',
        $bare->getMessage(), 'it says the key has none, and suggests none');
    check(! str_contains($bare->getMessage(), '{count}'), 'no {count} offered');

    // Before the placeholders are compared: a well-made one the key does not have is still the other refusal.
    same('unknown_placeholder', checked(['test.count' => ['sk' => '{meno} otázok']])[0], 'well made, not the key\'s');
    same('unknown_placeholder', checked(['test.count' => ['sk' => '{Count} otázok']])[0], 'a placeholder\'s name is as it is written, capitals too');
    same('placeholder_changed', checked(['test.step' => ['en' => 'Step {n}']])[0], 'English with one fewer');
    // And what was fine is fine.
    same('{count} otázok', checked(['test.count' => ['sk' => '{count} otázok']])[0]['value'], 'one');
    same('Krok {n} / {m}', checked(['test.step' => ['sk' => 'Krok {n} / {m}']])[0]['value'], 'two');
});

test('a string names no other site and holds no web address - checked last, in any language, and in a language\'s names', function () {
    same(['names_another_site', ['key' => 'test.bye', 'language' => 'sk', 'found' => ['prydwen']]], checked(['test.bye' => ['sk' => 'Pozri Prydwen']]), 'a name');
    same(['names_another_site', ['key' => 'test.bye', 'language' => 'en', 'found' => ['a web address']]], checked(['test.bye' => ['en' => 'See https://elsewhere.example']]), 'an address, in English too');
    same(['names_another_site', ['key' => 'test.hello', 'language' => 'sk', 'found' => ['enka.network', 'game8']]], checked(['test.hello' => ['sk' => '{name}: enka.network, Game8, GAME8']]), 'every name, once');
    // After the other checks: what is wrong with the text itself is said first.
    same('unknown_placeholder', checked(['test.bye' => ['sk' => 'Pozri Prydwen {kde}']])[0], 'a placeholder first');
    same('invalid_value', checked(['test.bye' => ['sk' => str_repeat('Prydwen ', 300)]])[0], 'the length first');
    // The game's words and the site's own are nobody else's.
    foreach (['Genshin Impact', 'Honkai: Star Rail', 'Source 2', 'Enkanomiya', 'Build Analyzer', 'CRIT Rate: {count}'] as $text) {
        same([], TranslationCheck::otherSites($text), $text);
    }
    same(['a web address'], TranslationCheck::otherSites('www.elsewhere.example/page'), 'without a scheme');

    foreach (['name', 'native_name'] as $field) {
        $refused = throws(HttpError::class, fn () => TranslationCheck::languageName('Deutsch (GitHub)', 'The name', $field), 'a site in a name');
        same(['code' => 'names_another_site', 'field' => $field, 'found' => ['github']], $refused->extra, "which of the two names: {$field}");
        same(422, $refused->status, 'a 422');
    }
    same('Deutsch', TranslationCheck::languageName('  Deutsch ', 'The name'), 'a name that names nobody');
});

// ── The scan ─────────────────────────────────────────────────────────────────────────────────────

test('the scan: a quoted literal with a key\'s shape in a namespace the database has - never an attribute\'s value, a comment, a spec', function () {
    $root = sources([
        'pages/home/home-page.html' => <<<'HTML'
            <!-- {{ 'home.inAComment' | translate }} -->
            <h1>{{ 'home.title' | translate }}</h1>
            <p [text]="error.message" title="home.anAttribute" [class.on]="home.expression"></p>
            <button [attr.aria-label]="'common.close' | translate" (click)="close()">x</button>
            <span>{{ 'home.questions' | translatePlural: n }}</span>
            <span>{{ "home.doubleQuoted" | translate }}</span>
            <p>Don't stop: {{ 'home.afterAnApostrophe' | translate: { day: d } }} - it's fine</p>
            <!--
              'home.inALongComment'
            -->
            <em>{{ 'home.missing' | translate }}</em>
            HTML,
        'pages/home/home-page.ts' => <<<'TS'
            import { Home } from './home.model';
            import { Survey } from '../../core/survey/survey.model';
            // 'home.inALineComment' is what a comment explains
            /* 'home.inABlockComment'
               'home.stillInIt' */
            const address = 'http://example.test/'; const afterIt = 'home.afterAnAddress';
            const accept = 'image/*'; const afterAGlob = 'home.afterAGlob'; /* and 'home.afterTheGlob' is a comment */
            const quote = 'it\'s'; const afterAQuote = 'home.afterAnEscape';
            this.toasts.show(this.i18n.t('home.saved'), `${this.i18n.t("home.inATemplate")}`);
            const notOurs = ['survey.model', 'theme-boot.js', 'home', '.home.title', 'Home.title', 'home..title', 'home.ti tle', 'survey.', `survey.${kind}`];
            const inBackticks = `home.inBackticks`; const sentence = `home.title is ${what}, they say`;
            const label = word('aboutYou.gender.' + gender.key) + word(`aboutYou.age.${age.key}`);
            TS,
        'pages/home/home-page.spec.ts' => "expect(text).toBe('home.onlyInASpec');\n",
        'core/i18n/testing.ts' => "export const example = 'home.onlyInTesting';\n",
        'core/iam/anotoki-auth.d.ts' => "export const sdk = 'home.inTheSdk';\n",
        'core/i18n/en.ts' => "export const en = { 'home.onlyCompiled': 'Compiled' };\n",
        'core/i18n/en/core.ts' => "export const core = { 'home.onlyCompiledInAPart': 'Compiled' };\n",
        'core/i18n/en/core.json' => "{ \"home.onlyInTheJson\": \"Compiled\" }\n",
        'core/auth.ts' => "const key = 'home.inTheAuthService';\n",
        'pages/home/home-page.scss' => "/* 'home.inAStyle' */\n",
        'core/i18n/dynamic-keys.ts' => <<<'TS'
            /** The keys put together at run time: 'comment.': ['ignored']. */
            export const DYNAMIC_KEYS: Record<string, readonly string[]> = {
              // a comment: 'also.': ['ignored']
              'aboutYou.gender.': ['male', 'female'],
              'aboutYou.age.': [
                'under-18',
                "18-24",
              ],
              'answer.rule.': ['any', 'atLeast'],
              'error.': [],
            };
            TS,
    ]);
    $database = [
        'home.title', 'home.questions.one', 'home.questions.few', 'home.questions.other', 'home.doubleQuoted', 'home.afterAnApostrophe',
        'home.afterAnAddress', 'home.afterAGlob', 'home.afterAnEscape', 'home.saved', 'home.inATemplate', 'home.inTheAuthService', 'home.inBackticks',
        'home.inAComment', 'home.inALineComment', 'home.inABlockComment', 'home.onlyInASpec', 'home.onlyInTesting', 'home.inTheSdk', 'home.anAttribute', 'home.expression',
        'home.onlyCompiled', 'home.onlyCompiledInAPart', 'home.onlyInTheJson',
        'common.close', 'common.unused', 'error.message', 'error.unknown',
        'aboutYou.gender.male', 'aboutYou.gender.female', 'aboutYou.gender.other', 'aboutYou.age.under-18', 'aboutYou.age.18-24',
        'answer.rule.any', 'answer.rule.atLeast.one', 'answer.rule.atLeast.few', 'answer.rule.atLeast.other',
    ];
    $scan = translationKeysScan($root, $database);

    same([], $scan['problems'], 'nothing the scan does not let through');
    same(['home.missing' => ['pages/home/home-page.html:11']], $scan['missing'], 'asked for, and not there');
    same([
        'aboutYou.gender.other', 'common.unused', 'error.message', 'error.unknown',
        'home.anAttribute', 'home.expression', 'home.inABlockComment', 'home.inAComment', 'home.inALineComment', 'home.inTheSdk',
        'home.onlyCompiled', 'home.onlyCompiledInAPart', 'home.onlyInASpec', 'home.onlyInTesting', 'home.onlyInTheJson',
    ], $scan['unused'], 'an attribute\'s value is an expression; comments, specs, the test helper, the vendored SDK and the compiled English do not count; nor does a key under a declared prefix that is not listed');
    same(['pages/home/home-page.html:2'], $scan['used']['home.title'], 'with the file and the line');
    same(['pages/home/home-page.ts:7'], $scan['used']['home.afterAGlob'], 'the lines are the file\'s, comments or not');
    same(['pages/home/home-page.html:5'], $scan['used']['home.questions.few'], 'a plural is asked for by its family\'s name');
    same(['core/i18n/dynamic-keys.ts'], $scan['used']['answer.rule.atLeast.other'], 'a declared key may be a family too');
    same(['core/i18n/dynamic-keys.ts'], $scan['used']['aboutYou.age.18-24'], 'a declared key');
    same(['core/auth.ts:1'], $scan['used']['home.inTheAuthService'], 'a file beside the excluded ones is read');
    same(count($database) - count($scan['unused']), count($scan['used']), 'every key is one or the other');

    // A namespace the database does not have is nobody's key - 'survey.model' is a file.
    same([], translationKeysScan($root, [])['used'], 'with no keys, nothing is used');
    same(['aboutYou.age.18-24', 'aboutYou.age.under-18', 'aboutYou.gender.female', 'aboutYou.gender.male', 'answer.rule.any', 'answer.rule.atLeast'], array_keys(translationKeysScan($root, [])['missing']), 'but what is declared must exist, whatever the database has');
    same(['pages/home/home-page.ts:11'], $scan['used']['home.inBackticks'], 'a template literal with nothing put into it is a literal');
    same(['home.afterAGlob', 'home.afterAnAddress', 'home.afterAnApostrophe', 'home.afterAnEscape', 'home.doubleQuoted', 'home.inATemplate', 'home.inBackticks', 'home.inTheAuthService', 'home.missing', 'home.questions', 'home.saved', 'home.title'],
        array_values(array_filter(array_keys(translationKeysScan($root, ['home.x'])['missing']), static fn (string $key): bool => str_starts_with($key, 'home.'))), 'every literal of a namespace the database has');
});

test('the scan: a frontend with no dynamic-keys.ts declares nothing - and a key put together there is then a problem', function () {
    $root = sources([
        'pages/home/home-page.html' => "<h1>{{ 'home.title' | translate }}</h1>\n",
        'pages/home/home-page.ts' => "const kind = this.i18n.t('home.kind.' + kind);\n",
    ]);
    same(['prefixes' => [], 'keys' => [], 'problems' => []], translationKeysDeclared($root), 'nothing declared');
    $scan = translationKeysScan($root, ['home.title', 'home.kind.one']);
    same(['home.title' => ['pages/home/home-page.html:1']], $scan['used'], 'the literal keys are read as ever');
    same([], $scan['missing'], 'nothing is missing');
    same(['home.kind.one'], $scan['unused'], 'a key only ever put together is not seen to be used');
    same(["pages/home/home-page.ts:1: a key is put together from 'home.kind.', which core/i18n/dynamic-keys.ts does not declare"], $scan['problems'], 'and the putting together is said');
    same(['used' => [], 'missing' => [], 'unused' => [], 'problems' => []], translationKeysScan($root, []), 'with no keys in the database there is nothing to say');
});

test('the scan: a plural family with a form missing names the form', function () {
    $root = sources(['a.html' => "{{ 'take.steps' | translatePlural: n }}\n{{ 'take.step' | translate }}\n"]);
    $scan = translationKeysScan($root, ['take.steps.one', 'take.steps.other', 'take.step']);
    same(['take.steps.few' => ['a.html:1']], $scan['missing'], 'the form');
    same(['take.step', 'take.steps.one', 'take.steps.other'], array_keys($scan['used']), 'the others are used');
    same([], $scan['unused'], 'none unused');
    same([], $scan['problems'], 'a family with a form missing is a family: the form is what is missing');
});

test('the scan: a key asked for the wrong way - a family as one string, one string as a family - shows the key, and fails', function () {
    // Both "exist", so by the keys alone all is well - and t('take.minutes') finds no such key, plural('take.start') no take.start.other.
    $root = sources([
        'a.html' => <<<'HTML'
            <p>{{ 'take.minutes' | translate: { count: n } }}</p>
            <p>{{ "take.start" | translatePlural: n }}</p>
            <p [title]="'take.minutes' | translatePlural: n">{{ "take.start" | translate }}</p>
            <!-- {{ 'take.minutes' | translate }} -->
            <p>{{ 'other.minutes' | translate }} {{ (wide ? 'take.minutes' : 'take.start') | translate }}</p>
            HTML,
        'a.ts' => <<<'TS'
            const a = this.i18n.t('take.start') + this._i18n.plural("take.minutes", count);
            const b = this.i18n.t( "take.minutes", { count }) + i18n.plural(`take.start`, 2);
            const c = i18n.around('take.minutes', 'count') + (i18n.has('take.minutes') ? 1 : 0) + i18n.has('take.start');
            // i18n.t('take.minutes') in a comment
            const d = set.has('title') && map.t('survey.minutes') && i18n.plural(key, count) && i18n.t(TABLE[kind] ?? 'take.start');
            TS,
    ]);
    $database = ['take.minutes.one', 'take.minutes.few', 'take.minutes.other', 'take.start'];
    $scan = translationKeysScan($root, $database);
    same([
        "a.html:1: 'take.minutes' is a plural family and is asked for as one string - the page would show the key",
        "a.html:2: 'take.start' is asked for as a plural, and there is no take.start.one, .few or .other - the page would show the key",
        "a.ts:2: 'take.minutes' is a plural family and is asked for as one string - the page would show the key",
        "a.ts:2: 'take.start' is asked for as a plural, and there is no take.start.one, .few or .other - the page would show the key",
        "a.ts:3: 'take.minutes' is a plural family and is asked for as one string - the page would show the key",
        "a.ts:3: 'take.minutes' is a plural family and is asked for as one string - the page would show the key",
    ], $scan['problems'], 'each place it is asked for wrongly, in either quote; the right way round, a comment, another namespace and a key that comes through a variable are let be');
    same([], $scan['missing'], 'by the keys alone nothing is missing - which is why it has to be said');
    same([], $scan['unused'], 'nor unused');

    // Asked for as a plural with no key of that name at all: both are said.
    $none = translationKeysScan(sources(['a.html' => "{{ 'take.hours' | translatePlural: n }}\n"]), ['take.start']);
    same(['take.hours'], array_keys($none['missing']), 'missing');
    check(str_starts_with($none['problems'][0] ?? '', "a.html:1: 'take.hours' is asked for as a plural"), 'and asked for the wrong way');
});

test('the scan: a key put together at run time is put together from a declared prefix, or the scan fails', function () {
    $files = [
        'a.ts' => <<<'TS'
            const refused = this.i18n.t('take.refused.' + kind);
            const again = this.i18n.t(`take.refused.${kind}`) + this.i18n.t(`take.refused${suffix}`) + this.i18n.t("take." + part + '.title');
            const declared = word('aboutYou.gender.' + key) + word(`aboutYou.gender.${key}`);
            const whole = i18n.t(`take.inBackticks`) + i18n.t(`take.notThere`);
            const notOurs = ['survey.' + kind, `survey.${kind}`, `take.refused is ${kind}`, 'take', 'take. ', `${kind}.take.`];
            // 'take.commented.' + kind
            TS,
        'a.html' => "<p>{{ ('take.shown.' + kind) | translate }}</p>\n<p class=\"take.\" [title]=\"'take.title.' + kind\"></p>\n",
        'core/i18n/dynamic-keys.ts' => "export const DYNAMIC_KEYS = {\n  'aboutYou.gender.': ['male'],\n};\n",
    ];
    $database = ['take.start', 'take.inBackticks', 'aboutYou.gender.male'];
    $scan = translationKeysScan(sources($files), $database);
    same([
        "a.html:1: a key is put together from 'take.shown.', which core/i18n/dynamic-keys.ts does not declare",
        "a.html:2: a key is put together from 'take.title.', which core/i18n/dynamic-keys.ts does not declare",
        "a.ts:1: a key is put together from 'take.refused.', which core/i18n/dynamic-keys.ts does not declare",
        "a.ts:2: a key is put together from 'take.refused.', which core/i18n/dynamic-keys.ts does not declare",
        "a.ts:2: a key is put together from 'take.refused', which core/i18n/dynamic-keys.ts does not declare",
        "a.ts:2: a key is put together from 'take.', which core/i18n/dynamic-keys.ts does not declare",
    ], $scan['problems'], 'a literal ending in a dot, a template literal going on with ${...}; a declared prefix, another namespace, a sentence and a comment are let be');
    same(['take.notThere' => ['a.ts:4']], $scan['missing'], 'a key in backticks is asked for like any other');
    same(['a.ts:4'], $scan['used']['take.inBackticks'], 'and found');
    same(['take.start'], $scan['unused'], 'what nothing asks for');

    // Declared, the same sources pass - and the keys under the prefix are then held like any others.
    $files['core/i18n/dynamic-keys.ts'] = "export const DYNAMIC_KEYS = {\n  'aboutYou.gender.': ['male'],\n  'take.refused.': ['closed'],\n  'take.shown.': [],\n  'take.title.': [],\n  'take.': [],\n};\n";
    $declared = translationKeysScan(sources($files), $database);
    same(["a.ts:2: a key is put together from 'take.refused', which core/i18n/dynamic-keys.ts does not declare"], $declared['problems'], 'what is left is the one that is no prefix at all');
    same(['take.notThere', 'take.refused.closed'], array_keys($declared['missing']), 'and a declared key that is not in the database is missing');
});

test('the scan fails a template that gives innerHTML a translated string, on one line or several - and a list of tails it cannot read', function () {
    $root = sources([
        'a.html' => "<p [innerHTML]=\"text()\"></p>\n<p>{{ 'home.title' | translate }}</p>\n<p [innerHTML]=\"'home.title' | translate\"></p>\n<!-- <p [innerHTML]=\"'home.title' | translatePlural: 2\"></p> -->\n"
            // Broken over lines, as a formatter breaks a long binding.
            ."<div\n  class=\"rich\"\n  [innerHTML]=\"\n    'home.title'\n      | translate: { name: name() }\n  \"\n></div>\n"
            // A translated string beside the binding, not in it: drawn as text like any other.
            ."<p [innerHTML]=\"notes()\" [attr.aria-label]=\"'home.title' | translate\">{{ 'home.title' | translate }}</p>\n"
            ."<p [innerHtml]='i18n.t(\"home.title\")'></p>\n",
        'a.ts' => "el.innerHTML = this.i18n.translate('home.title');\n",
        'core/i18n/dynamic-keys.ts' => "export const DYNAMIC_KEYS = {\n  'aboutYou.gender.': GENDERS.map((g) => g.key),\n  'aboutYou.age.': [...AGES],\n  'error.': ['offline', 'not a key', ''],\n  'time.': ['justNow'],\n};\n",
    ]);
    $scan = translationKeysScan($root, ['home.title', 'time.justNow', 'error.offline']);
    same(7, count($scan['problems']), json_encode($scan['problems']));
    check(str_starts_with($scan['problems'][0], 'a.html:3: innerHTML is given a translated string - a string is text'), $scan['problems'][0]);
    check(str_starts_with($scan['problems'][1], 'a.html:7: innerHTML is given a translated string'), 'on the line the binding begins: '.$scan['problems'][1]);
    check(str_starts_with($scan['problems'][2], 'a.html:13: innerHTML is given a translated string'), 'asked for with t() in the template, however the attribute is written - line 12, with a translated string beside the binding, is let be: '.$scan['problems'][2]);
    check(str_contains($scan['problems'][3], "dynamic-keys.ts: the tails of 'aboutYou.gender.' are not written out"), $scan['problems'][3]);
    check(str_contains($scan['problems'][4], "the tails of 'aboutYou.age.' are not written out"), $scan['problems'][4]);
    check(str_contains($scan['problems'][5], "'error.' + 'not a key' is not a key"), $scan['problems'][5]);
    check(str_contains($scan['problems'][6], "'error.' + '' is not a key"), $scan['problems'][6]);
    same([], $scan['missing'], 'what could be read was read');
    same([], $scan['unused'], 'and used');

    $empty = translationKeysScan(sources(['core/i18n/dynamic-keys.ts' => "export const DYNAMIC_KEYS = makeKeys();\n"]), []);
    check(str_contains($empty['problems'][0] ?? '', 'could be read from it'), 'a file with nothing to read in it');
});

test('the scan reads the real frontend: only the files it should, and their comments are not keys', function () {
    $root = translationKeysFrontendRoot();
    check(is_dir($root), 'the frontend is beside the backend: '.$root);
    $files = translationKeySourceFiles($root);
    check(in_array('app.routes.ts', $files, true) && in_array('pages/dashboard/dashboard-page.html', $files, true), 'the sources are where the scan looks');
    foreach ($files as $file) {
        check((bool) preg_match('/\.(ts|html)$/', $file), $file);
        check(! preg_match('~\.spec\.ts$|^core/iam/|^core/i18n/en/|^core/i18n/en\.ts$|^core/i18n/testing\.ts$~', $file), "not read: {$file}");
    }
    same("a\n\n\nb 'c/*' \"d//\" `e\n//` f \ng", translationKeySourceText("a\r\n/* x\r\n 'y' */\r\nb 'c/*' \"d//\" `e\n//` f // 'z'\r\ng", false), 'comments out, strings as they are, line for line');
    same("a\n\nb", translationKeySourceText("a<!-- 'x'\n -->\nb", true), 'in a template too');
});

finishTests();
