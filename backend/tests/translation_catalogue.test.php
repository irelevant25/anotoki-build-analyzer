<?php

/**
 * The site's own strings as the migrations leave them, against what the frontend asks for - so a page never
 * shows a key instead of a sentence, and no string is kept that nothing reads - the rules every stored string
 * keeps (the library's checks with this site's own last one), and the compiled English the pages read while
 * the database cannot be: the anotoki library's tools (Anotoki\Lib\Translations: KeyScanner, EnglishDictionary,
 * StringCheck) on this site's sources and migrations.
 *
 * The library's own words (anotoki.*) are the library's: its migration set writes them, its tests hold them to
 * its words file, and its code asks for them - nothing here counts them, but where this site moved its own
 * strings under them (006: the language button's two; 010: the top bar's, when the family's bar took its place; 013:
 * the footer's, when the family's footer took its place).
 *
 * Nothing here changes what the migrations left.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use Anotoki\Lib\Support\Refusal;
use Anotoki\Lib\Translations\Cli\EnglishDictionary;
use Anotoki\Lib\Translations\Cli\KeyScanner;
use Anotoki\Lib\Translations\Placeholders;
use Anotoki\Lib\Translations\StringCheck;
use Anotoki\Lib\Translations\Translations;
use App\Db\Migrations;
use App\Services\Analysis\BuildEvaluator;
use App\Services\Import\ImportKinds;
use App\Services\Translations\SiteTranslations;

bootTests();

/** The file that moved this site's two words of the library's under the library's keys. */
const ADOPTION = '006_strings_through_the_library.sql';

/** The file that moved the top bar's words under the library's keys, when the family's bar took the site's place. */
const BAR_WORDS = '010_top_bar_words_through_the_library.sql';

/** The file that moved the footer's words under the library's keys, when the family's footer took the site's place. */
const FOOTER_WORDS = '013_footer_words_through_the_library.sql';

/** The files that copy strings of the site's keys under the library's names, and insert no key of the site's own. */
const MOVED_TO_LIBRARY = [ADOPTION, BAR_WORDS, FOOTER_WORDS];

/** The forms of a plural family, as Intl.PluralRules names the ones English and Slovak have. */
const PLURAL_FORMS = ['one', 'few', 'other'];

/** The site's words, on this file's database. */
function words(): Translations
{
    T::$app->resetScope();

    return T::$app->get(Translations::class);
}

/** Is it a key of the library's own namespace? */
function isLibraryKey(string $key): bool
{
    return str_starts_with($key, 'anotoki.');
}

/** @return list<string> every key of the site's own the database has, by name - the library's left out */
function siteKeys(): array
{
    return array_values(array_filter(words()->keyNames(), static fn(string $key): bool => !isLibraryKey($key)));
}

/** @return array<string, string> a language's own strings of the site's own keys, by key */
function siteStrings(string $code): array
{
    return array_filter((array) words()->export($code)['values'], static fn(string $key): bool => !isLibraryKey($key), ARRAY_FILTER_USE_KEY);
}

/** A list in the order of its values: for two lists that must hold the same, whatever order each was written in. */
function inOrder(array $list): array
{
    sort($list, SORT_STRING);

    return $list;
}

/**
 * The English compiled into the app, key => text: the parts under shared/i18n/en/ - flat {"key": "English"}
 * objects, a key in exactly one of them (the library's reader refuses anything else).
 *
 * @return array<string, string>
 */
function compiledEnglish(): array
{
    $files = glob(SiteTranslations::frontendRoot() . '/shared/i18n/en/*.json') ?: [];
    check($files !== [], 'the frontend has a compiled English (shared/i18n/en/*.json)');
    $english = EnglishDictionary::merge(...array_map(static fn(string $file): array => EnglishDictionary::read($file), $files));
    ksort($english, SORT_STRING);

    return $english;
}

/** The migrations that write strings: every one that inserts keys. */
function stringMigrations(): array
{
    $files = [];
    foreach (glob(Migrations::directory() . '/*.sql') ?: [] as $file) {
        if (preg_match('/^\s*INSERT\s+INTO\s+translation_keys\b/mi', (string) file_get_contents($file))) {
            $files[] = basename($file);
        }
    }
    sort($files);

    return $files;
}

/**
 * The keys a string migration names, place by place: the rows it inserts into translation_keys, the strings
 * it inserts for each language, and the number of keys its gate expects. Read from the file's text - the way
 * 003 says its strings are written is the pattern every later one follows (006, which moves strings, aside).
 *
 * @return array{keys: list<string>, strings: array<string, list<string>>, expected: ?int}
 */
function migrationKeys(string $file): array
{
    return migrationKeysIn((string) file_get_contents(Migrations::directory() . '/' . $file));
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

/** What StringCheck::changes() refuses $values with under this site's rules: [code, what it is about] - or the changes it would make. */
function checked(mixed $values, array $english = ['test.hello' => 'Hello, {name}!', 'test.bye' => 'Goodbye']): array
{
    $result = StringCheck::changes($values, ['test.hello', 'test.bye'], ['en' => 'English', 'sk' => 'Slovak'], $english, SiteTranslations::config());
    if ($result instanceof Refusal) {
        same(422, $result->status, 'a refusal is a 422');

        return [$result->code, $result->extra];
    }

    return $result;
}

// ── What the migrations leave ────────────────────────────────────────────────────────────────────

test('the two languages the site is released in, English first, the tables under their names - and the words there to be read', function () {
    same(
        [
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'enabled' => true, 'sort_order' => 1],
            ['code' => 'sk', 'name' => 'Slovak', 'native_name' => 'Slovenčina', 'enabled' => true, 'sort_order' => 2],
        ],
        db()->all('SELECT code, name, native_name, enabled, sort_order FROM languages ORDER BY sort_order'),
        'the languages',
    );
    same(true, words()->ready(), 'the tables are there');
    same('en', words()->bundle('en')['language'] ?? null, 'and the words can be read');

    // The database keeps its own rules, under the names 003 gave them (the library's 001 made nothing here).
    $refused = fn(string $sql): string => throws(PDOException::class, fn() => db()->run($sql), $sql)->getMessage();
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

    same(
        [
            'languages_code_check c',
            'languages_pkey p',
            'translation_keys_pkey p',
            'translations_key_name_foreign f',
            'translations_language_code_foreign f',
            'translations_pkey p',
            'translations_value_check c',
        ],
        db()->column("SELECT c.conname::text || ' ' || c.contype::text FROM pg_constraint c
        WHERE c.conrelid IN ('languages'::regclass, 'translation_keys'::regclass, 'translations'::regclass) AND c.contype <> 'n' ORDER BY 1"),
        'the constraints, by name',
    );
});

test('every key has an English string, and every key of the site\'s own a Slovak one', function () {
    same([], words()->keysWithout('en'), 'keys with no English string: nothing to fall back to');
    same([], array_values(array_filter(words()->keysWithout('sk'), static fn(string $key): bool => !isLibraryKey($key))), 'the site\'s keys with no Slovak string');
    check(count(siteKeys()) > 400, 'the site\'s keys are there: ' . count(siteKeys()));
});

test('a plural family has .one, .few and .other, and nothing else ends in them', function () {
    $incomplete = function (array $keys): array {
        $families = [];
        foreach ($keys as $key) {
            if (preg_match('/^(.+)\.(' . implode('|', PLURAL_FORMS) . ')$/D', $key, $m)) {
                $families[$m[1]] = true;
            }
        }
        $missing = [];
        foreach (array_keys($families) as $family) {
            foreach (PLURAL_FORMS as $form) {
                if (!in_array("{$family}.{$form}", $keys, true)) {
                    $missing[] = "{$family}.{$form}";
                }
            }
        }

        return $missing;
    };

    same(['b.things.few'], $incomplete(['a.title', 'b.things.one', 'b.things.other', 'c.all.one', 'c.all.few', 'c.all.other']), 'how a form that is missing is found');
    same([], $incomplete(siteKeys()), 'a key ending in .one, .few or .other is a form of a plural, and a plural has all three');
    // "in both languages" is the test above: every key has both.
    same([], $incomplete(array_keys(compiledEnglish())), 'and the same in the English compiled into the app');
});

test('no namespace is named after a file the app reads or is the library\'s, and every key has the shape the scan finds', function () {
    // The frontend has the literals 'changelog.json' and 'pasted.json', which have the shape of a key: a
    // namespace of that name would make them keys nobody seeded. And `anotoki.` is the library's.
    foreach (['the database' => siteKeys(), 'the compiled English' => array_keys(compiledEnglish())] as $where => $keys) {
        $namespaces = array_values(array_unique(array_map(KeyScanner::namespaceOf(...), $keys)));
        same([], array_values(array_intersect($namespaces, ['changelog', 'pasted', 'anotoki'])), "{$where}: a namespace the frontend's own file names would be taken for, or the library's");
        foreach ($keys as $key) {
            check(KeyScanner::shapeOk($key), "{$where}: {$key} is not the shape of a key, so the scan cannot find it");
        }
    }
});

test('every language a migration writes strings for is one that cannot be deleted', function () {
    $written = db()->column('SELECT DISTINCT language_code FROM translations ORDER BY language_code');
    same(inOrder(SiteTranslations::RELEASED), inOrder($written), 'SiteTranslations::RELEASED names exactly the languages the migrations write: deleting one would make the next string migration fail');
    same(SiteTranslations::RELEASED, SiteTranslations::config()->releasedLanguages, 'and the library is told so');
    same([], array_values(array_diff(SiteTranslations::RELEASED, db()->column('SELECT code FROM languages'))), 'each of them is a language here');
    check(in_array('en', SiteTranslations::RELEASED, true), 'English among them');
});

test('the English compiled into the app is, key for key, the seeded English of the site\'s own keys', function () {
    $compiled = compiledEnglish();
    $seeded = siteStrings('en');
    same([], array_values(array_diff(array_keys($compiled), array_keys($seeded))), 'compiled into the app, not seeded: the page would show the key once the database answers');
    same([], array_values(array_diff(array_keys($seeded), array_keys($compiled))), 'seeded, not compiled into the app: no English while the database does not answer');
    $different = [];
    foreach ($seeded as $key => $text) {
        if (($compiled[$key] ?? null) !== $text) {
            $different[] = "{$key}: seeded \"{$text}\", compiled \"" . ($compiled[$key] ?? '') . '"';
        }
    }
    same([], $different, 'the same English both ways');
});

test('every key the frontend uses is in the database, and every key of the site\'s own in the database is used', function () {
    $scan = SiteTranslations::keyScanner()->scan(words()->keyNames());
    same([], $scan['problems'], 'what the scan does not let through');
    same([], $scan['missing'], 'used by the frontend, not in the database - the page would show the key (php translations.php --status)');
    same([], $scan['unused'], 'in the database, used nowhere - delete them with a migration');
    check(count($scan['used']) > 400, 'the keys are found where they are used: ' . count($scan['used']));
    check(is_file(SiteTranslations::frontendRoot() . '/' . SiteTranslations::DYNAMIC_KEYS), 'the keys put together at run time are declared in ' . SiteTranslations::DYNAMIC_KEYS);
    foreach (array_keys($scan['used']) as $key) {
        check(!isLibraryKey($key), "{$key}: the library's keys are the library's to ask for");
    }
});

test('what the server says as a kind is a key the frontend declares: recommendations, import errors, import warnings', function () {
    $kinds = ['recommendation.' => BuildEvaluator::KINDS, 'import.error.' => ImportKinds::ERRORS, 'import.warning.' => ImportKinds::WARNINGS];
    same([18, 18, 7], array_map('count', array_values($kinds)), 'how many kinds there are');

    $declared = KeyScanner::dynamic((string) file_get_contents(SiteTranslations::frontendRoot() . '/' . SiteTranslations::DYNAMIC_KEYS));
    same([], $declared['problems'], SiteTranslations::DYNAMIC_KEYS . ' can be read');
    foreach ($kinds as $prefix => $byKind) {
        same(inOrder(array_keys($byKind)), inOrder($declared['prefixes'][$prefix] ?? []), "the tails of '{$prefix}' in " . SiteTranslations::DYNAMIC_KEYS . ' are the server\'s kinds');
    }

    // A sentence can only be filled with what the server sends for its kind.
    $english = siteStrings('en');
    $wrong = [];
    foreach ($kinds as $prefix => $byKind) {
        foreach ($byKind as $kind => $params) {
            check(isset($english[$prefix . $kind]), "{$prefix}{$kind} has no English string");
            $extra = array_diff(Placeholders::names($english[$prefix . $kind]), $params);
            if ($extra !== []) {
                $wrong[] = "{$prefix}{$kind}: {" . implode('}, {', $extra) . '}';
            }
        }
    }
    same([], $wrong, 'a placeholder the server sends no parameter for');
});

test('every stored string passes the checks a save makes, as it is - no placeholder its English lacks, no other site named', function () {
    // Otherwise a string nobody could save again would be released: the grid sends a cell back whole, and a
    // rule the seed does not keep (a brace, a length, a space at its end, a site's name) would refuse it.
    same([], words()->storedRefusals(), 'a stored string a save would refuse (reword it: the check is not changed)');
    check(count(db()->column('SELECT key_name FROM translations')) > 800, 'the strings are there to check');
    foreach (words()->languages() as $language) {
        same([], array_merge(SiteTranslations::otherSites($language['name']), SiteTranslations::otherSites($language['native_name'])), "the names of \"{$language['code']}\"");
    }

    // The English compiled into the app is held to the same, from the day it is written: it is what gets seeded.
    $wrong = [];
    foreach (compiledEnglish() as $key => $text) {
        $change = StringCheck::changes([$key => ['en' => $text]], [$key], ['en' => 'English'], [$key => $text], SiteTranslations::config());
        if ($change instanceof Refusal) {
            $wrong[] = "{$key}: {$change->message}";
        } elseif (($change[0]['value'] ?? null) !== $text) {
            $wrong[] = "{$key}: not kept as it is written (a space or a control character at its end)";
        }
    }
    same([], $wrong, 'a compiled English string a save would refuse or change');
});

test('a migration with strings writes each key once, in both languages; the gates count the site\'s own keys', function () {
    // The pattern itself, on a text of the test's own.
    $pattern =
        "-- INSERT INTO translation_keys (name, description) VALUES ('in.a.comment', 'x')\n" .
        "INSERT INTO translation_keys (name, description) VALUES\n    -- a namespace\n    ('a.one', 'It''s (one), here'),\n    ('a.two', NULL)\nON CONFLICT (name) DO NOTHING;\n\n" .
        "INSERT INTO translations (key_name, language_code, value)\nSELECT v.key_name, 'en', v.value FROM (VALUES\n    ('a.one', 'One; ''1'''),\n    ('a.two', 'Two')\n) AS v(key_name, value)\nON CONFLICT (key_name, language_code) DO NOTHING;\n\n" .
        "INSERT INTO translations (key_name, language_code, value)\nSELECT v.key_name, 'sk', v.value FROM (VALUES\n    ('a.one', 'Jeden')\n) AS v(key_name, value)\nON CONFLICT (key_name, language_code) DO NOTHING;\n\n" .
        "DO \$\$\nDECLARE\n    expected_keys CONSTANT INTEGER := 2;\nBEGIN\n    NULL;\nEND \$\$;\n";
    same(
        ['keys' => ['a.one', 'a.two'], 'strings' => ['en' => ['a.one', 'a.two'], 'sk' => ['a.one']], 'expected' => 2],
        migrationKeysIn(str_replace("\n", "\r\n", $pattern)),
        'how a string migration is read: its keys, its strings by language, its gate\'s count',
    );

    // The tables' own migration says how many keys it seeds, and that is how many it inserts.
    $tables = migrationKeys('003_languages_and_translations.sql');
    check($tables['expected'] !== null, '003 does not say how many keys it seeds (expected_keys CONSTANT INTEGER := n)');
    same(count($tables['keys']), $tables['expected'], 'the number of keys 003\'s gate expects is the number of keys it inserts');

    same(MOVED_TO_LIBRARY, array_values(array_intersect(stringMigrations(), MOVED_TO_LIBRARY)), 'the files that moved strings under the library\'s keys are among them');
    $all = [];
    $gates = [];
    foreach (stringMigrations() as $file) {
        $found = migrationKeys($file);
        $gates[$file] = $found['expected'];
        if (in_array($file, MOVED_TO_LIBRARY, true)) {
            // They insert no key of the site's own: they copy keys' strings under the library's names.
            same(['keys' => [], 'strings' => []], array_intersect_key($found, ['keys' => 0, 'strings' => 0]), $file . ' writes no row of its own words');
            continue;
        }
        check($found['keys'] !== [], "{$file}: the keys it inserts could not be read");
        same($found['keys'], array_values(array_unique($found['keys'])), "{$file}: a key is inserted twice");
        same([], array_values(array_filter($found['keys'], 'isLibraryKey')), "{$file}: a key under anotoki., which is the library's");
        same(inOrder(SiteTranslations::RELEASED), inOrder(array_keys($found['strings'])), "{$file}: one block of strings a language");
        foreach ($found['strings'] as $code => $keys) {
            same(inOrder($found['keys']), inOrder($keys), "{$file}: the keys with a string in \"{$code}\" are not the keys it inserts");
        }
        $all = array_merge($all, $found['keys']);
    }
    same([], array_values(array_diff(siteKeys(), $all)), 'keys of the site\'s own in the database that no migration\'s rows name');

    // The running total: the last gate of any migration counts the site's own keys - never the library's, which the
    // library adds and words itself. (A migration that only retires keys, 015, inserts none: its gate counts all the same.)
    $last = null;
    foreach (glob(Migrations::directory() . '/*.sql') ?: [] as $path) {
        $expected = migrationKeysIn((string) file_get_contents($path))['expected'];
        if ($expected !== null) {
            $last = [basename($path), $expected];
        }
    }
    check($last !== null && $gates !== [], 'no migration counts the keys');
    same(count(siteKeys()), $last[1], "{$last[0]}'s gate expects as many keys of the site's own as there are");
    foreach (MOVED_TO_LIBRARY as $file) {
        check(str_contains((string) file_get_contents(Migrations::directory() . '/' . $file), "WHERE name NOT LIKE 'anotoki.%'"), $file . '\'s gate leaves the library\'s keys out of its count');
    }
});

// ── The rules this site adds ─────────────────────────────────────────────────────────────────────

test('a string names no other site and holds no web address - checked last, in any language, and in a language\'s names', function () {
    same(['names_another_site', ['key' => 'test.bye', 'language' => 'sk', 'found' => ['github']]], checked(['test.bye' => ['sk' => 'Pozri GitHub']]), 'a name');
    same(
        ['names_another_site', ['key' => 'test.bye', 'language' => 'en', 'found' => ['a web address']]],
        checked(['test.bye' => ['en' => 'See https://elsewhere.example']]),
        'an address, in English too',
    );
    same(
        ['names_another_site', ['key' => 'test.hello', 'language' => 'sk', 'found' => ['enka.network', 'fribbels']]],
        checked(['test.hello' => ['sk' => '{name}: enka.network, Fribbels, FRIBBELS']]),
        'every name, once',
    );
    // After the other checks: what is wrong with the text itself is said first.
    same('unknown_placeholder', checked(['test.bye' => ['sk' => 'Pozri GitHub {kde}']])[0], 'a placeholder first');
    same('invalid_value', checked(['test.bye' => ['sk' => str_repeat('GitHub ', 300)]])[0], 'the length first');
    same([['key' => 'test.bye', 'language' => 'sk', 'value' => 'Dovidenia']], checked(['test.bye' => ['sk' => ' Dovidenia ']]), 'a text that names nobody is kept');
    // The game's words and the site's own are nobody else's.
    foreach (['Genshin Impact', 'Honkai: Star Rail', 'Source 2', 'Enkanomiya', 'Build Analyzer', 'CRIT Rate: {count}'] as $text) {
        same([], SiteTranslations::otherSites($text), $text);
    }
    same(['a web address'], SiteTranslations::otherSites('www.elsewhere.example/page'), 'without a scheme');

    $config = SiteTranslations::config();
    foreach (['name' => 'The name in English', 'native_name' => 'The name as its speakers write it'] as $field => $what) {
        $refused = StringCheck::languageName('Deutsch (GitHub)', $what, $field, $config, 'de');
        check($refused instanceof Refusal, "a site in a name: {$field}");
        same([422, 'names_another_site', ['field' => $field, 'found' => ['github']]], [$refused->status, $refused->code, $refused->extra], "which of the two names: {$field}");
        same("{$what} names another site (\"github\"). Nothing the site shows names or links another website, service or tool.", $refused->message, 'the sentence');
    }
    same('Deutsch', StringCheck::languageName('  Deutsch ', 'The name', 'name', $config, 'de'), 'a name that names nobody');
});

test('the legal pages\' strings (011) hold no address of any kind: where a link goes is the code\'s, never a string an administrator rewords', function () {
    // The 0.6.0 review of the library: the anotoki account's pages, the hosting company's, the operator's - every
    // address the pages link to is given by the code (sites/main/legal/legal-facts.ts); a string only words what is around it.
    // The footer's fan clause, 011's footer.fanMade, is under the library's key since 013.
    $strings = db()->all("SELECT key_name, language_code, value FROM translations
        WHERE key_name LIKE 'legal.%' OR key_name LIKE 'title.%' OR key_name = 'anotoki.footer.fanHoyoverse' ORDER BY key_name, language_code");
    check(count($strings) >= 104, 'the legal pages\' strings are there, in both languages: ' . count($strings));
    $addresses = [];
    foreach ($strings as $row) {
        if (preg_match('~://|\bwww\.|@|\bmailto:|\b[a-z0-9-]+\.(?:eu|sk|com|net|org|io|gg|moe|network)\b~i', (string) $row['value']) === 1) {
            $addresses[] = "{$row['key_name']} ({$row['language_code']}): {$row['value']}";
        }
    }
    same([], $addresses, 'a string that holds an address');
});

// ── The scan ─────────────────────────────────────────────────────────────────────────────────────

test('the scan reads the real frontend: only the files it should, and the library\'s keys it leaves to the library', function () {
    $scanner = SiteTranslations::keyScanner();
    $root = SiteTranslations::frontendRoot();
    check(is_dir($root), 'the frontend is beside the backend: ' . $root);
    same($root, $scanner->root(), 'it reads the frontend\'s src/app');
    $files = $scanner->sourceFiles();
    check(in_array('app.routes.ts', $files, true) && in_array('sites/main/dashboard/dashboard-page.html', $files, true), 'the sources are where the scan looks');
    check(in_array('sites/main/common/top-bar/top-bar.ts', $files, true), 'the top bar is read - it asks for the library\'s words (anotoki.topbar.*)');
    foreach ($files as $file) {
        check((bool) preg_match('/\.(ts|html)$/', $file), $file);
        check(!preg_match('~\.spec\.ts$|^shared/i18n/en\.ts$~', $file), "not read: {$file}");
    }

    // The library's words are asked for by their keys, and are never a key this site must have.
    $scan = $scanner->scan(['anotoki.language.button', 'topbar.home']);
    check(!isset($scan['used']['anotoki.language.button']) && !isset($scan['missing']['anotoki.language.button']), 'never used, never missing');
    same(['topbar.home'], array_keys(array_intersect_key($scan['used'], ['topbar.home' => 0])), 'the site\'s own as ever');

    // The words 006, 010 and 013 moved are asked for by the library's names, where the site asks for them at all (the
    // family's bar and footer ask for most themselves). Their old keys are gone from the database, so the scan would
    // take an old literal for no key at all: they are looked for here.
    $sources = implode("\n", array_map(static fn(string $file): string => (string) file_get_contents("{$root}/{$file}"), $files));
    foreach (
        [
            'language.button',
            'language.notLoaded',
            'topbar.phoneMenu',
            'topbar.menuButton',
            'topbar.menu',
            'topbar.roleAdmin',
            'topbar.roleEditor',
            'topbar.account',
            'topbar.admin',
            'common.signOut',
            'theme.label',
            'theme.light',
            'theme.dark',
            'theme.system',
            'stepper.optional',
            'stepper.editable',
            'footer.version',
            'footer.feedback',
            'footer.fanMade',
        ]
        as $old
    ) {
        check(preg_match('/[\'"`]' . preg_quote($old, '/') . '[\'"`]/', $sources) !== 1, "nothing asks for {$old}: it is gone (or the library's now)");
    }
    foreach (['anotoki.topbar.roleAdmin', 'anotoki.topbar.roleEditor', 'anotoki.topbar.account', 'anotoki.topbar.admin'] as $library) {
        check(str_contains($sources, "'{$library}'"), "{$library} is asked for (the person's menu in the family's bar)");
    }
});

finishTests();
