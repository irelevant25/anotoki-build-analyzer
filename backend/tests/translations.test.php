<?php

/**
 * The site's own strings through the API and the command line: anybody reads a language's strings with
 * English underneath; an admin keeps the languages and rewords the strings - checked whole, the changed ones
 * only, never a placeholder the code does not fill, never the name of another site; nobody deletes English
 * or a language the site is released in.
 *
 * The keys here are the test's own (ownStrings()): the migration writes the real ones, and what it leaves is
 * held to the frontend in translation_catalogue.test.php. Every test starts from the state the migrations
 * left - the languages, the keys and the strings are put back before each.
 */

declare(strict_types=1);

require __DIR__.'/lib.php';

use Anotoki\Lib\Migrations\Migrator;
use App\Auth\Role;
use App\Cli;
use App\Commands\Translations as TranslationsCommand;
use App\Db\Db;
use App\Db\Migrations;
use App\Presenters\TranslationPresenter;
use App\Services\Translations\TranslationCheck;
use App\Services\Translations\TranslationEditor;
use Tests\Support\Answer;
use Tests\Support\Client;

bootTests();

$server = require __DIR__.'/../config/database.local.php';

const OWNER = 1;   // ADMIN
const MONA = 2;    // signed in, no role here
const EDITOR_ID = 3;   // EDITOR

const ENGLISH = ['code' => 'en', 'name' => 'English', 'native_name' => 'English'];
const SLOVAK = ['code' => 'sk', 'name' => 'Slovak', 'native_name' => 'Slovenčina'];

// What the migrations left, put back before every test: a test deletes keys, adds languages, rewords strings.
$left = [
    'languages' => db()->all('SELECT code, name, native_name, enabled, sort_order FROM languages ORDER BY code'),
    'translation_keys' => db()->all('SELECT name, description, created_at FROM translation_keys ORDER BY name'),
    'translations' => db()->all('SELECT key_name, language_code, value, updated_at, updated_by FROM translations ORDER BY key_name, language_code'),
];
beforeEach(function () use ($left): void {
    db()->run('RESET lock_timeout');
    db()->run('TRUNCATE languages, translation_keys, translations');
    foreach ($left as $table => $rows) {
        db()->insertMany($table, $rows);
    }
});

function owner(): Client
{
    return (new Client)->asIam(OWNER, [Role::ADMIN]);
}

function mona(): Client
{
    return (new Client)->asIam(MONA, []);
}

function editor(): Client
{
    return (new Client)->asIam(EDITOR_ID, [Role::EDITOR]);
}

/**
 * The test's own keys in place of the site's: what a migration would have written, with its first strings.
 * What the routes do is then said in a handful of keys, and does not change with every string the site gains.
 */
function ownStrings(): void
{
    db()->run('DELETE FROM translation_keys');
    db()->run("INSERT INTO translation_keys (name, description) VALUES
        ('test.hello', 'A greeting; {name} is who is greeted'), ('test.bye', NULL), ('test.count', 'How far; {count} of {total}'), ('test.new', 'A key with no string yet')");
    db()->run("INSERT INTO translations (key_name, language_code, value) VALUES
        ('test.hello', 'en', 'Hello, {name}!'), ('test.hello', 'sk', 'Dobrý deň, {name}!'), ('test.bye', 'en', 'Goodbye'), ('test.count', 'en', '{count} of {total}')");
}

/** A language added straight into the database, offered. */
function language(string $code, string $name, int $sortOrder = 500): void
{
    db()->run('INSERT INTO languages (code, name, native_name, sort_order) VALUES (?, ?, ?, ?)', [$code, $name, $name, $sortOrder]);
}

/** A save of the grid's changed strings, as the Translations page sends it. */
function save(array $values, ?Client $as = null): Answer
{
    return ($as ?? owner())->request('PUT', '/api/admin/translations', ['values' => $values]);
}

function import(string $code, array $strings, ?Client $as = null): Answer
{
    return ($as ?? owner())->request('PUT', "/api/admin/translations/{$code}/import", null, ['body' => json_encode($strings === [] ? new stdClass : $strings)]);
}

/** One string as the database has it: [value, updated_by, updated_at] - or null. */
function stored(string $key, string $code): ?array
{
    $row = db()->one('SELECT value, updated_by, updated_at FROM translations WHERE key_name = ? AND language_code = ?', [$key, $code]);

    return $row === null ? null : [$row['value'], $row['updated_by'] === null ? null : (int) $row['updated_by'], $row['updated_at']];
}

/** Every string the database has, as "key code" => "value by whom": to see that a refused save wrote nothing. */
function everyString(): array
{
    return array_column(db()->all("SELECT key_name || ' ' || language_code AS cell, value || ' by ' || COALESCE(updated_by::text, 'nobody') AS value FROM translations ORDER BY 1"), 'value', 'cell');
}

function languageRows(): array
{
    return db()->all('SELECT * FROM languages ORDER BY code');
}

/** The answer is this refusal: its status and its code. */
function refused(Answer $answer, int $status, string $code, string $what = ''): Answer
{
    same([$status, $code], [$answer->status, $answer->json('code')], ($what === '' ? $code : $what).' ('.substr($answer->body, 0, 300).')');

    return $answer;
}

/** The command line on this file's database: [exit code, what it printed]. */
function cli(string ...$arguments): array
{
    T::$app->resetScope();
    Cli::capture();
    try {
        $code = TranslationsCommand::run(T::$app, ['translations.php', ...$arguments], $GLOBALS['frontend'] ?? null);
    } finally {
        $output = str_replace("\r\n", "\n", Cli::captured());
    }

    return [$code, $output];
}

/** A small frontend for --status to read: $files as relative path => contents. */
function frontend(array $files): string
{
    $root = T::$storage.DIRECTORY_SEPARATOR.'frontend-'.bin2hex(random_bytes(3));
    foreach ($files as $relative => $contents) {
        @mkdir(dirname("{$root}/{$relative}"), 0777, true);
        file_put_contents("{$root}/{$relative}", $contents);
    }

    return $root;
}

/** A database that answers no query at all: a PDO whose every statement fails with $failure. */
function brokenDatabase(Throwable $failure): Db
{
    return new Db(new class($failure) extends PDO
    {
        public function __construct(private readonly Throwable $failure) {}

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            throw $this->failure;
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            throw $this->failure;
        }

        public function exec(string $statement): int|false
        {
            throw $this->failure;
        }
    });
}

/**
 * Runs $body with the app on another database object, and puts the real one back. The site gate, which asks
 * before any route whether an update waits, keeps asking the real database: what is tested here is the routes'
 * own use of it.
 */
function withDatabase(Db $database, callable $body): void
{
    $real = T::$app->db();
    T::$app->set(Db::class, $database);
    T::$app->set(Migrator::class, Migrations::migrator($real->raw()));
    try {
        $body();
    } finally {
        T::$app->set(Db::class, $real);
        T::$app->forget(Migrator::class);
        T::$app->resetScope();
    }
}

// ── The strings a page reads ─────────────────────────────────────────────────────────────────────

test('the bundle: a language\'s strings with English underneath, the same for everybody', function () {
    ownStrings();
    $english = ['test.bye' => 'Goodbye', 'test.count' => '{count} of {total}', 'test.hello' => 'Hello, {name}!'];
    $inSlovak = ['test.bye' => 'Goodbye', 'test.count' => '{count} of {total}', 'test.hello' => 'Dobrý deň, {name}!'];

    $slovak = (new Client)->get('/api/translations/sk')->status(200);
    same('application/json; charset=utf-8', $slovak->header('Content-Type'), 'JSON');
    same(['language', 'languages', 'values', 'english'], array_keys($slovak->json()), 'the fields');
    same('sk', $slovak->json('language'), 'which language it is');
    same([ENGLISH, SLOVAK], $slovak->json('languages'), 'the languages on offer, with only what a chooser shows');
    same($inSlovak, $slovak->json('values'), 'a key with no Slovak string comes in English, and the keys are in their order');
    same($english, $slovak->json('english'), 'English itself, for the pages that are always English');
    check(! array_key_exists('test.new', $slovak->json('values')), 'a key with no string at all is not there: the page shows the key');

    $own = (new Client)->get('/api/translations/en')->status(200);
    same(['language', 'languages', 'values'], array_keys($own->json()), 'no second copy of English');
    same($english, $own->json('values'), 'English');

    // A language the site does not have: answered in English, and told so.
    $unknown = (new Client)->get('/api/translations/de')->status(200)->path('language', 'en');
    same($own->body, $unknown->body, 'the English bundle itself');
    same($own->body, (new Client)->get('/api/translations/pt-br')->body, 'with a region too');

    // A code with a region that is not offered itself is answered by its language, when that one is: a
    // Slovak browser's first wish is "sk-sk", and it gets Slovak at once - not English and a second request.
    $regional = (new Client)->get('/api/translations/sk-sk')->status(200)->path('language', 'sk');
    same($slovak->body, $regional->body, 'the Slovak bundle itself');
    same($slovak->header('ETag'), $regional->header('ETag'), 'under the same tag');
    same($own->body, (new Client)->get('/api/translations/en-gb')->body, 'and English by English');
    (new Client)->get('/api/translations/de-at')->status(200)->path('language', 'en');

    // Nobody is asked who they are: a token is not looked at - not a good one, not a bad one.
    same($slovak->body, (new Client)->asIam(555, [])->get('/api/translations/sk')->body, 'with a token');
    same(0, (int) db()->value('SELECT count(*) FROM users WHERE id = 555'), 'nobody was recorded as having been here');
    same($slovak->body, (new Client)->withToken('not.a.token')->get('/api/translations/sk')->status(200)->body, 'a sign-in that ran out still reads in its language');
    same($slovak->body, (new Client)->asIam(7, [])->get('/api/translations/sk')->status(200)->body, 'and somebody with no role here');
    same($slovak->body, (new Client('203.0.113.9'))->withHeaders(['Origin' => 'https://elsewhere.example'])->get('/api/translations/sk')->status(200)->body, 'from anywhere');

    // A language with no string of its own reads in English - and says which language it is.
    language('de', 'German');
    $german = (new Client)->get('/api/translations/de')->status(200)->path('language', 'de');
    same($german->json('english'), $german->json('values'), 'English under nothing');
    same(['en', 'sk', 'de'], array_column($german->json('languages'), 'code'), 'offered');
    same('de', (new Client)->get('/api/translations/de-at')->json('language'), 'and it answers for its regions');
    same('"'.md5($german->body).'"', $german->header('ETag'), 'the tag is the body\'s');
});

test('a code that is not one is 404, and the database never hears of it', function () {
    ownStrings();
    $codes = ['EN', 'Sk', 'e', 'eng', 'en-', 'en-GB', 'sk-SK', 'en_gb', 'en-gbr', 'sk%0A', '%20sk', 'sk%27', '%FF', '%C3%28', 'en.json'];

    // With a database that fails on any query: a wrong address is answered without one.
    withDatabase(brokenDatabase(new LogicException('a query was made')), function () use ($codes) {
        foreach ($codes as $code) {
            same(['message' => 'There is no such language.', 'code' => 'not_found'], (new Client)->get("/api/translations/{$code}")->status(404)->json(), $code);
        }
    });

    (new Client)->get('/api/translations/en/more')->status(404);
    (new Client)->get('/api/translations')->status(404); // there is no list of its own: the bundle carries it
    (new Client)->get('/api/languages')->status(404);
    (new Client)->request('PUT', '/api/translations/sk', ['values' => ['test.bye' => 'x']])->status(405); // nothing is written here

    // The owner's routes: a code that is not one is no language. Bytes that are not text in a query would
    // be the database's refusal and a 500.
    foreach (['%FF', 'SK', 'eng'] as $code) {
        refused(owner()->request('PUT', "/api/admin/languages/{$code}", ['name' => 'X']), 404, 'not_found', $code);
        refused(owner()->delete("/api/admin/languages/{$code}"), 404, 'not_found', $code);
        refused(owner()->get("/api/admin/translations/{$code}/export"), 404, 'not_found', $code);
        refused(import($code, ['test.bye' => 'x']), 404, 'not_found', $code);
    }

    // The same for whoever calls the editor: no query for what is not a code at all.
    $editor = T::$app->get(TranslationEditor::class);
    foreach ([fn () => $editor->changeLanguage("\xFF", []), fn () => $editor->deleteLanguage("\xFF"), fn () => $editor->export("\xFF"), fn () => $editor->import("\xFF", [], null)] as $call) {
        same(404, throws(App\Web\HttpError::class, $call, 'bytes that are not text')->status, 'no such language');
    }
});

test('asked again: 304 for the tag the browser holds - compared weakly - with the headers a cache needs', function () {
    ownStrings();
    $ask = fn (string $held, string $code = 'sk'): Answer => (new Client)->withHeaders(['If-None-Match' => $held])->get("/api/translations/{$code}");

    $first = (new Client)->get('/api/translations/sk')->status(200);
    $etag = $first->header('ETag');
    same('"'.md5($first->body).'"', $etag, 'the tag');
    same('no-cache', $first->header('Cache-Control'), 'kept, and asked about every time');

    // As the tag comes back from behind a server that compressed the answer: weakened (a proxy), or marked
    // inside the quotes (a web server that compresses) - without the second the 304 would never be answered there.
    $marked = fn (string $suffix): string => substr($etag, 0, -1).$suffix.'"';
    foreach ([$etag, "W/{$etag}", "\"other\", W/{$etag}", "\"a\" , {$etag} , \"b\"", '*', $marked('-gzip'), 'W/'.$marked('-gzip'), $marked('-br'), '"other-gzip", '.$marked('-zstd')] as $held) {
        $again = $ask($held);
        same([304, ''], [$again->status, $again->body], $held);
        same([$etag, 'no-cache'], [$again->header('ETag'), $again->header('Cache-Control')], "the 304's own headers ({$held}): never no-store");
    }
    foreach (['"other"', 'W/"other"', trim($etag, '"'), '"'.md5('x').'"', '"'.md5('x').'-gzip"', $marked('-gzipped'), $marked('-gzip-gzip')] as $held) {
        $again = $ask($held);
        same([200, $first->body], [$again->status, $again->body], $held);
    }
    check($etag !== (new Client)->get('/api/translations/en')->header('ETag'), 'another language, another tag');

    // A string changes: the tag the browser holds is no longer the answer's.
    db()->run("UPDATE translations SET value = 'Bye' WHERE key_name = 'test.bye' AND language_code = 'en'");
    $changed = $ask($etag)->status(200);
    same('Bye', $changed->json('values')['test.bye'], 'the new string');
    check($changed->header('ETag') !== $etag, 'a new tag');
    db()->run("UPDATE translations SET value = 'Goodbye' WHERE key_name = 'test.bye' AND language_code = 'en'");
    same($etag, (new Client)->get('/api/translations/sk')->header('ETag'), 'the same strings, the same tag');

    // The comparison itself.
    foreach (['"abc"', 'W/"abc"', '"x", "abc"', '"x" ,W/"abc" , "y"', '*', ' "abc" ', '"abc-gzip"', 'W/"abc-gzip"', '"abc-br"', 'W/"abc-br"', '"abc-zstd"', '"x", "abc-gzip"', '"x-gzip" , W/"abc-gzip"'] as $header) {
        check(TranslationPresenter::notModified($header, '"abc"'), "held: {$header}");
    }
    foreach (['', 'abc', '"abd"', 'W/"x"', '"abc', 'w/"abc"', '"abd-gzip"', '"abc-gzipx"', '"abc-deflate"', 'abc-gzip"', '"abc-gzip', '"x-gzip"', '"abc-gzip-gzip"', '"abc"-gzip'] as $header) {
        check(! TranslationPresenter::notModified($header, '"abc"'), "not held: {$header}");
    }
});

test('the strings are not there to be read: 503 translations_unavailable on every route, never a 500', function () {
    $routes = [
        ['GET', '/api/translations/en', null], ['GET', '/api/translations/sk', null],
        ['GET', '/api/admin/languages', null], ['POST', '/api/admin/languages', ['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch']],
        ['PUT', '/api/admin/languages/sk', ['enabled' => false]], ['DELETE', '/api/admin/languages/sk', null],
        ['GET', '/api/admin/translations', null], ['PUT', '/api/admin/translations', ['values' => []]],
        ['GET', '/api/admin/translations/sk/export', null], ['PUT', '/api/admin/translations/sk/import', ['test.bye' => 'x']],
    ];
    $unavailable = function (string $what) use ($routes): void {
        foreach ($routes as [$method, $url, $body]) {
            $answer = owner()->request($method, $url, $body);
            same(503, $answer->status, "{$what}: {$method} {$url} ({$answer->body})");
            same(['message', 'code'], array_keys($answer->json()), "{$method} {$url}: the fields");
            same('translations_unavailable', $answer->json('code'), "{$what}: {$method} {$url}");
        }
    };

    // The tables with no English string in them: what everything falls back to is not there.
    db()->run('DELETE FROM translation_keys');
    $unavailable('no string at all');
    db()->run("INSERT INTO translation_keys (name) VALUES ('test.only')");
    db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.only', 'sk', 'Len po slovensky')");
    $unavailable('no English string');
    same(['en', 'sk'], array_column(languageRows(), 'code'), 'and nothing was done');
    same(true, (bool) db()->one("SELECT enabled FROM languages WHERE code = 'sk'")['enabled'], 'Slovak is still on');

    // One English string, and they are.
    db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.only', 'en', 'English only')");
    (new Client)->get('/api/translations/sk')->status(200)->path('language', 'sk');
    owner()->get('/api/admin/translations')->status(200);

    // A database that cannot be asked: the page's strings answer 503 too - the app has its compiled English,
    // and a failure on every page load would fill the log.
    withDatabase(brokenDatabase(new PDOException('SQLSTATE[08006] the server closed the connection')), function () {
        foreach (['en', 'sk', 'de-at'] as $code) {
            $answer = (new Client)->get("/api/translations/{$code}");
            same([503, 'translations_unavailable', '60'], [$answer->status, $answer->json('code'), $answer->header('Retry-After')], $code);
        }
    });
});

// ── Who may do what ──────────────────────────────────────────────────────────────────────────────

test('the strings are for editors, the languages themselves for admins; the token says who, and nothing else does', function () {
    ownStrings();
    language('de', 'German');
    $before = [everyString(), languageRows()];
    $strings = [
        ['GET', '/api/admin/languages', null],
        ['GET', '/api/admin/translations', null],
        ['PUT', '/api/admin/translations', ['values' => ['test.bye' => ['en' => 'Changed']]]],
        ['GET', '/api/admin/translations/sk/export', null],
        ['PUT', '/api/admin/translations/sk/import', ['test.bye' => 'Changed']],
    ];
    $languages = [
        ['POST', '/api/admin/languages', ['code' => 'xx', 'name' => 'Nobody\'s', 'native_name' => 'Nobody\'s']],
        ['PUT', '/api/admin/languages/sk', ['enabled' => false, 'name' => 'Changed']],
        ['DELETE', '/api/admin/languages/de', null],
    ];
    foreach ([...$strings, ...$languages] as [$method, $url, $body]) {
        $admins = in_array([$method, $url, $body], $languages, true);
        $notAllowed = $admins ? 'admin_only' : 'editor_only';
        refused((new Client)->request($method, $url, $body), 401, 'sign_in_required', "{$method} {$url}, signed out");
        refused(mona()->request($method, $url, $body), 403, $notAllowed, "{$method} {$url}, a person with no role");
        refused((new Client)->asIam(7, ['MEMBER'])->request($method, $url, $body), 403, $notAllowed, "{$method} {$url}, a role left over from before");
        refused((new Client)->withToken(iamToken(OWNER, [Role::ADMIN], ['exp' => time() - 120]))->request($method, $url, $body), 401, 'sign_in_required', "{$method} {$url}, a token that ran out");
        // A role in the address or a header of the request is nobody's role.
        refused(mona()->withHeaders(['X-Roles' => 'ADMIN'])->request($method, $url.'?role=ADMIN', $body), 403, $notAllowed, "{$method} {$url}, a role in the request");
        if ($admins) {
            refused(editor()->request($method, $url, $body), 403, 'admin_only', "{$method} {$url}, an editor");
        }
    }
    same($before, [everyString(), languageRows()], 'and nothing of it was done');

    owner()->get('/api/admin/languages')->status(200);
    owner()->get('/api/admin/translations')->status(200);
    owner()->get('/api/admin/translations/sk/export')->status(200);

    // Who saved a string is the token's person, whatever the body says.
    owner()->request('PUT', '/api/admin/translations', ['values' => ['test.bye' => ['sk' => 'Dovidenia']], 'user_id' => 42, 'updated_by' => 42])->status(200);
    same(['Dovidenia', OWNER], array_slice(stored('test.bye', 'sk'), 0, 2), 'by the owner');
});

test('an editor rewords the strings and moves a language\'s strings out and back in - and keeps the languages as they are', function () {
    ownStrings();
    language('de', 'German');

    same(['en', 'sk', 'de'], array_column(editor()->get('/api/admin/languages')->status(200)->json('languages'), 'code'), 'the languages, to translate into');
    editor()->get('/api/admin/translations')->status(200);
    editor()->request('PUT', '/api/admin/translations', ['values' => ['test.bye' => ['sk' => 'Dovidenia']]])->status(200);
    same(['Dovidenia', EDITOR_ID], array_slice(stored('test.bye', 'sk'), 0, 2), 'saved by the editor');
    same(['test.bye' => 'Dovidenia', 'test.hello' => 'Dobrý deň, {name}!'], editor()->get('/api/admin/translations/sk/export')->status(200)->json(), 'out as a file');
    editor()->request('PUT', '/api/admin/translations/de/import', ['test.bye' => 'Auf Wiedersehen'])->status(200);
    same(['Auf Wiedersehen', EDITOR_ID], array_slice(stored('test.bye', 'de'), 0, 2), 'and in');

    $languages = languageRows();
    refused(editor()->post('/api/admin/languages', ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français']), 403, 'admin_only', 'adding one');
    refused(editor()->request('PUT', '/api/admin/languages/de', ['name' => 'Deutsch']), 403, 'admin_only', 'renaming one');
    refused(editor()->request('PUT', '/api/admin/languages/de', ['enabled' => false]), 403, 'admin_only', 'switching one off');
    refused(editor()->delete('/api/admin/languages/de'), 403, 'admin_only', 'deleting one');
    same($languages, languageRows(), 'the languages are as they were');
});

test('every write here takes JSON and nothing else: a form is refused as a malformed body is', function () {
    ownStrings();
    $before = everyString();

    $form = fn (string $method, string $url, string $body, string $type = 'application/x-www-form-urlencoded'): Answer
        => owner()->request($method, $url, null, ['body' => $body, 'type' => $type]);
    refused($form('POST', '/api/admin/languages', 'code=xx&name=Test&native_name=Test'), 422, 'invalid_language', 'a form adding a language');
    refused($form('POST', '/api/admin/languages', '--b--', 'multipart/form-data; boundary=b'), 422, 'invalid_language', 'a multipart form');
    refused($form('PUT', '/api/admin/languages/sk', 'enabled=0'), 422, 'invalid_language', 'a form changing one');
    refused($form('DELETE', '/api/admin/languages/sk', 'x=1'), 422, 'invalid_language', 'a form deleting one');
    refused($form('PUT', '/api/admin/translations', 'values[test.bye][sk]=Zbohom'), 422, 'invalid_value', 'a form saving strings');
    refused($form('PUT', '/api/admin/translations/sk/import', '{"test.bye": "Zbohom"}', 'text/plain'), 422, 'invalid_value', 'JSON sent as text');
    same($before, everyString(), 'nothing was written');
    same(['en', 'sk'], array_column(languageRows(), 'code'), 'nor a language added');
    // JSON is the admin's own - with a charset too.
    owner()->request('POST', '/api/admin/languages', null, ['body' => '{"code": "xx", "name": "Test", "native_name": "Test"}', 'type' => 'application/json; charset=utf-8'])->status(201);
    owner()->delete('/api/admin/languages/xx')->status(200);

    // The strings a page reads are everybody's, as ever.
    (new Client('203.0.113.9'))->withHeaders(['Origin' => 'https://elsewhere.example'])->get('/api/translations/sk')->status(200)->path('language', 'sk');
});

// ── Languages ────────────────────────────────────────────────────────────────────────────────────

test('languages: added after the others, offered at once or hidden as the body says; renamed, reordered, switched off - English never', function () {
    ownStrings();
    $admin = fn (array $language, bool $enabled, int $sortOrder, bool $seeded, int $strings): array => $language + ['enabled' => $enabled, 'sort_order' => $sortOrder, 'seeded' => $seeded, 'strings' => $strings];

    same(['languages' => [$admin(ENGLISH, true, 1, true, 3), $admin(SLOVAK, true, 2, true, 1)]], owner()->get('/api/admin/languages')->status(200)->json(), 'the two the site is released in, with how many strings each has');

    // What a new language is: last, and offered unless the body says otherwise - whatever else the body says.
    $made = owner()->post('/api/admin/languages', ['code' => 'pt-br', 'name' => "  Portuguese\x07 (Brazil) ", 'native_name' => str_repeat('ç', 50), 'sort_order' => 0, 'seeded' => true, 'strings' => 9])->status(201);
    same(['language' => ['code' => 'pt-br', 'name' => 'Portuguese (Brazil)', 'native_name' => str_repeat('ç', 50), 'enabled' => true, 'sort_order' => 3, 'seeded' => false, 'strings' => 0]], $made->json(), 'the new language');
    same('pt-br', (new Client)->get('/api/translations/pt-br')->json('language'), 'offered, a code with a region answers itself');

    // Hidden from the start when the body says so (the Languages page does): there for its strings to be
    // written, offered to nobody until somebody switches it on.
    $hidden = owner()->post('/api/admin/languages', ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'enabled' => false])->status(201);
    same(['language' => ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'enabled' => false, 'sort_order' => 4, 'seeded' => false, 'strings' => 0]], $hidden->json(), 'hidden');
    $french = (new Client)->get('/api/translations/fr')->status(200);
    same(['en', ['en', 'sk', 'pt-br']], [$french->json('language'), array_column($french->json('languages'), 'code')], 'nobody is offered it');
    check(in_array('fr', array_column(owner()->get('/api/admin/languages')->json('languages'), 'code'), true), 'the owner\'s page has it');
    same(true, owner()->request('PUT', '/api/admin/languages/fr', ['enabled' => true])->status(200)->json('language.enabled'), 'switched on');
    same('fr', (new Client)->get('/api/translations/fr-ca')->json('language'), 'switched on, it is offered - and answers for its regions');
    same(true, owner()->post('/api/admin/languages', ['code' => 'it', 'name' => 'Italian', 'native_name' => 'Italiano', 'enabled' => true])->status(201)->json('language.enabled'), 'and true is what it is without saying');
    db()->run("DELETE FROM languages WHERE code IN ('fr', 'it')");

    refused(owner()->post('/api/admin/languages', ['code' => 'pt-br', 'name' => 'Portuguese', 'native_name' => 'Português']), 409, 'language_exists');
    $invalid = [
        'enabled as text' => ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'enabled' => 'false'],
        'enabled as a number' => ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'enabled' => 0],
        'enabled as nothing' => ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'enabled' => null],
        'a name nobody can see' => ['code' => 'fr', 'name' => "\u{200B}\u{2060}", 'native_name' => 'Français'],
        'a native name that is a soft hyphen' => ['code' => 'fr', 'name' => 'French', 'native_name' => " \u{00AD} "],
        'no body' => null, 'a list' => ['de'], 'no code' => ['name' => 'French', 'native_name' => 'Français'],
        'a code in capitals' => ['code' => 'FR', 'name' => 'French', 'native_name' => 'Français'],
        'three letters' => ['code' => 'fra', 'name' => 'French', 'native_name' => 'Français'],
        'a code that is a number' => ['code' => 12, 'name' => 'French', 'native_name' => 'Français'],
        'no name' => ['code' => 'fr', 'native_name' => 'Français'],
        'an empty name' => ['code' => 'fr', 'name' => " \x07 ", 'native_name' => 'Français'],
        'a name of 51 characters' => ['code' => 'fr', 'name' => str_repeat('é', 51), 'native_name' => 'Français'],
        'a name that is not text' => ['code' => 'fr', 'name' => ['French'], 'native_name' => 'Français'],
        'no native name' => ['code' => 'fr', 'name' => 'French'],
        'the native name under another name' => ['code' => 'fr', 'name' => 'French', 'nativeName' => 'Français'],
        'a native name that is a number' => ['code' => 'fr', 'name' => 'French', 'native_name' => 5],
    ];
    foreach ($invalid as $what => $body) {
        refused(owner()->request('POST', '/api/admin/languages', $body), 422, 'invalid_language', $what);
    }
    same(3, (int) db()->value('SELECT count(*) FROM languages'), 'none of them was added');

    // Changed: only what is sent.
    $renamed = owner()->request('PUT', '/api/admin/languages/pt-br', ['name' => ' Portuguese ', 'sort_order' => 0, 'code' => 'xx', 'seeded' => true])->status(200);
    same(['language' => ['code' => 'pt-br', 'name' => 'Portuguese', 'native_name' => str_repeat('ç', 50), 'enabled' => true, 'sort_order' => 0, 'seeded' => false, 'strings' => 0]], $renamed->json(), 'renamed and moved');
    same(['pt-br', 'en', 'sk'], array_column((new Client)->get('/api/translations/en')->json('languages'), 'code'), 'in its new place');
    same('Portuguese', owner()->request('PUT', '/api/admin/languages/pt-br', [])->status(200)->json('language.name'), 'nothing sent, nothing changed');
    same('Português', owner()->request('PUT', '/api/admin/languages/pt-br', ['native_name' => 'Português'])->status(200)->json('language.native_name'), 'its own name');
    $changes = [
        'enabled as text' => ['enabled' => 'false'], 'enabled as a number' => ['enabled' => 0], 'half an order' => ['sort_order' => 1.5], 'an order as text' => ['sort_order' => '3'],
        'an order below 0' => ['sort_order' => -1], 'an order above 1000' => ['sort_order' => 1001], 'an empty name' => ['name' => ''], 'a long native name' => ['native_name' => str_repeat('x', 51)],
        'a list' => ['name'], 'no body' => null,
    ];
    foreach ($changes as $what => $body) {
        refused(owner()->request('PUT', '/api/admin/languages/pt-br', $body), 422, 'invalid_language', $what);
    }
    same(1000, owner()->request('PUT', '/api/admin/languages/pt-br', ['sort_order' => 1000])->status(200)->json('language.sort_order'), 'the last place there is');
    refused(owner()->request('PUT', '/api/admin/languages/fr', ['name' => 'French']), 404, 'not_found', 'a language there is not');

    // Switched off: no longer offered, and asked for by name it reads in English. Never refused - but for English.
    same(false, owner()->request('PUT', '/api/admin/languages/sk', ['enabled' => false])->status(200)->json('language.enabled'), 'Slovak too: it keeps its strings');
    $slovak = (new Client)->get('/api/translations/sk')->status(200);
    same(['en', ['en', 'pt-br']], [$slovak->json('language'), array_column($slovak->json('languages'), 'code')], 'read in English');
    check(! array_key_exists('english', $slovak->json()), 'as the English bundle is');
    same('en', (new Client)->get('/api/translations/sk-sk')->json('language'), 'nor does it answer for its regions');
    same(['en', 'sk', 'pt-br'], array_column(owner()->get('/api/admin/languages')->json('languages'), 'code'), 'the owner\'s page sees the hidden ones');
    same(1, owner()->get('/api/admin/languages')->json('languages.1.strings'), 'with its strings');
    same(true, owner()->request('PUT', '/api/admin/languages/sk', ['enabled' => true])->status(200)->json('language.enabled'), 'and on again');

    $english = refused(owner()->request('PUT', '/api/admin/languages/en', ['enabled' => false, 'name' => 'Changed']), 409, 'fallback_language');
    same('English cannot be switched off: every other language falls back to it.', $english->json('message'), 'the sentence');
    same('English', db()->value("SELECT name FROM languages WHERE code = 'en'"), 'and nothing of that change was kept');
    same($admin(ENGLISH, true, 5, true, 3), owner()->request('PUT', '/api/admin/languages/en', ['enabled' => true, 'sort_order' => 5])->status(200)->json('language'), 'on is what it is; its place is anybody\'s to choose');

    // At most 20.
    for ($i = 0; $i < 17; $i++) {
        language('x'.chr(97 + $i), "Filler {$i}");
    }
    $full = refused(owner()->post('/api/admin/languages', ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français']), 422, 'too_many_languages');
    same('The site keeps at most 20 languages. Delete one that is not used first.', $full->json('message'), 'the sentence');
    refused(owner()->post('/api/admin/languages', ['code' => 'pt-br', 'name' => 'Portuguese', 'native_name' => 'Português']), 409, 'language_exists', 'one that is there is said to be there, full or not');
    db()->run("DELETE FROM languages WHERE name LIKE 'Filler %'");
    same(1000, owner()->post('/api/admin/languages', ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français'])->status(201)->json('language.sort_order'), 'last - and never past what an order may be');
});

test('two people adding the last language the site keeps: one waits for the other, and the most holds', function () use ($server) {
    ownStrings();
    for ($i = 0; $i < TranslationCheck::LANGUAGES_MAX - 3; $i++) {
        language('q'.chr(97 + $i), "Filler {$i}");
    }
    same(TranslationCheck::LANGUAGES_MAX - 1, (int) db()->value('SELECT count(*) FROM languages'), 'room for one more');

    // Somebody adds the twentieth and is not finished. Counting alone, a second request would not see that
    // row, would find room and add a twenty-first. (The test cannot wait beside a request, so the second one
    // gives up waiting after a moment - which is how it shows that it waited.)
    $other = Db::pdo($server, T::$database, false);
    $other->beginTransaction();
    $other->exec("INSERT INTO languages (code, name, native_name, sort_order) VALUES ('qz', 'Filler of somebody else', 'Filler', 500)");
    $oneTooMany = ['code' => 'qy', 'name' => 'One too many', 'native_name' => 'One too many'];
    try {
        db()->run("SET lock_timeout = '300ms'");
        $waited = throws(PDOException::class, fn () => T::$app->get(TranslationEditor::class)->createLanguage($oneTooMany), 'it waits for whoever is adding one');
        same('55P03', $waited->getCode(), 'and gave up waiting for the lock: '.$waited->getMessage());
        db()->run('RESET lock_timeout');

        $other->commit();
        refused(owner()->post('/api/admin/languages', $oneTooMany), 422, 'too_many_languages', 'once the other is finished, the count sees its row');
        same([TranslationCheck::LANGUAGES_MAX, 0], [(int) db()->value('SELECT count(*) FROM languages'), (int) db()->value("SELECT count(*) FROM languages WHERE code = 'qy'")], 'twenty, and no more');

        // Reading goes on while somebody adds one: only what writes the table waits.
        $other->beginTransaction();
        $other->exec('LOCK TABLE languages IN SHARE ROW EXCLUSIVE MODE');
        db()->run("SET lock_timeout = '300ms'");
        (new Client)->get('/api/translations/sk')->status(200);
        owner()->get('/api/admin/translations')->status(200);
        save(['test.hello' => ['sk' => 'Ahoj, {name}!']])->status(200); // a save of strings holds the languages' rows, not the table
        same('Ahoj, {name}!', stored('test.hello', 'sk')[0], 'the save went through');
        $other->rollBack();
    } finally {
        if ($other->inTransaction()) {
            $other->rollBack();
        }
        db()->run('RESET lock_timeout');
    }
});

test('deleting a language: never English, never one the site is released in - and its strings go with it', function () use ($server) {
    ownStrings();
    language('de', 'German');
    language('pt-br', 'Portuguese');
    db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.hello', 'de', 'Hallo, {name}!'), ('test.bye', 'de', 'Tschüss')");

    $english = refused(owner()->delete('/api/admin/languages/en'), 409, 'fallback_language');
    same('English cannot be deleted: every other language falls back to it.', $english->json('message'), 'the sentence');
    $slovak = refused(owner()->delete('/api/admin/languages/sk'), 409, 'seeded_language', 'a later migration with strings would fail without it');
    same('This language is one the site is released in: every update brings strings for it, and would fail without it. Switch it off instead.', $slovak->json('message'), 'the sentence');
    refused(owner()->delete('/api/admin/languages/fr'), 404, 'not_found');
    same(['en', 'sk'], TranslationCheck::SEEDED, 'the two');

    // A save of strings in the language is not finished: the delete waits for it, and counts after.
    $other = Db::pdo($server, T::$database, false);
    $other->beginTransaction();
    $other->query('SELECT code FROM languages FOR SHARE')->fetchAll();
    try {
        db()->run("SET lock_timeout = '300ms'");
        $waited = throws(PDOException::class, fn () => T::$app->get(TranslationEditor::class)->deleteLanguage('de'), 'it waits for the save');
        same('55P03', $waited->getCode(), 'and gave up waiting for the row: '.$waited->getMessage());
    } finally {
        $other->rollBack();
        db()->run('RESET lock_timeout');
    }
    same(2, (int) db()->value("SELECT count(*) FROM translations WHERE language_code = 'de'"), 'nothing went');

    // And it counts after the wait. Somebody else (another process: a request cannot be waited beside) holds
    // the languages, writes a third German string and keeps it a moment later; the delete that was waiting
    // says three strings went - not the two it would have counted before it waited.
    $script = T::$storage.DIRECTORY_SEPARATOR.'somebody-else.php';
    file_put_contents($script, '<?php
        require $argv[1]."/vendor/autoload.php";
        $pdo = App\Db\Db::pdo(require $argv[1]."/config/database.local.php", $argv[2], false);
        $pdo->beginTransaction();
        $pdo->query("SELECT code FROM languages FOR SHARE")->fetchAll();
        $pdo->exec("INSERT INTO translations (key_name, language_code, value) VALUES (\'test.count\', \'de\', \'{count} von {total}\')");
        fwrite(STDOUT, "holding\n"); // said at once, whatever this PHP buffers of its output
        usleep(700000);
        $pdo->commit();
    ');
    $somebody = proc_open([PHP_BINARY, $script, (string) realpath(__DIR__.'/..'), T::$database], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    try {
        $said = fgets($pipes[1]);
        if ($said !== "holding\n") { // what went wrong is read only then: reading it waits for the process to end
            throw new AssertionFailed('somebody else could not hold the languages: '.json_encode($said).' '.stream_get_contents($pipes[2]));
        }
        db()->run("SET lock_timeout = '15s'");
        $started = microtime(true);
        same(3, T::$app->get(TranslationEditor::class)->deleteLanguage('de'), 'the strings that were really there when it went');
        check(microtime(true) - $started > 0.3, 'and it had waited for the other to finish');
    } finally {
        proc_close($somebody);
        db()->run('RESET lock_timeout');
    }
    same(0, (int) db()->value("SELECT count(*) FROM translations WHERE language_code = 'de'"), 'all three went');
    language('de', 'German');
    db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.hello', 'de', 'Hallo, {name}!'), ('test.bye', 'de', 'Tschüss')");

    same(['strings' => 2], owner()->delete('/api/admin/languages/de')->status(200)->json(), 'how many strings went with it');
    same(0, (int) db()->value("SELECT count(*) FROM translations WHERE language_code = 'de'"), 'and its strings');
    same(4, (int) db()->value('SELECT count(*) FROM translation_keys'), 'the keys are the code\'s, and stay');
    refused(owner()->delete('/api/admin/languages/de'), 404, 'not_found', 'once');
    same('en', (new Client)->get('/api/translations/de')->json('language'), 'it reads in English now');
    same(['strings' => 0], owner()->delete('/api/admin/languages/pt-br')->status(200)->json(), 'one with no strings');
    same(['en', 'sk'], array_column(owner()->get('/api/admin/translations')->json('languages'), 'code'), 'the two are left');
});

// ── The grid ─────────────────────────────────────────────────────────────────────────────────────

test('saving strings: the changed ones, by whoever saved them; a string sent as it is stays as it was; the last save is kept', function () {
    ownStrings();
    $second = fn (): Client => (new Client)->asIam(3, [Role::ADMIN]);

    $grid = owner()->get('/api/admin/translations')->status(200);
    same(['languages', 'keys'], array_keys($grid->json()), 'the fields');
    same(owner()->get('/api/admin/languages')->json('languages'), $grid->json('languages'), 'every language, hidden or not');
    same([
        ['name' => 'test.bye', 'description' => '', 'values' => ['en' => 'Goodbye']],
        ['name' => 'test.count', 'description' => 'How far; {count} of {total}', 'values' => ['en' => '{count} of {total}']],
        ['name' => 'test.hello', 'description' => 'A greeting; {name} is who is greeted', 'values' => ['en' => 'Hello, {name}!', 'sk' => 'Dobrý deň, {name}!']],
        ['name' => 'test.new', 'description' => 'A key with no string yet', 'values' => []],
    ], $grid->json('keys'), 'by name');
    check(str_contains($grid->body, '{"name":"test.new","description":"A key with no string yet","values":{}}'), 'no strings is {} and not []');

    $untouched = stored('test.bye', 'en');
    $saved = save(['test.hello' => ['sk' => "  Ahoj,\x07 {name}! "]])->status(200);
    same(['en' => 'Hello, {name}!', 'sk' => 'Ahoj, {name}!'], $saved->json('keys.2.values'), 'answered with the grid as it is now; the text cleaned');
    same(['Ahoj, {name}!', OWNER], array_slice(stored('test.hello', 'sk'), 0, 2), 'who saved it is the token\'s person');
    same($untouched, stored('test.bye', 'en'), 'what was not sent was not touched');
    same(['Hello, {name}!', null], array_slice(stored('test.hello', 'en'), 0, 2), 'nor its English, written by a migration: nobody');

    // Somebody else's page still shows the old grid and sends strings as they are: nothing is rewritten.
    $first = stored('test.hello', 'sk');
    save(['test.hello' => ['sk' => 'Ahoj, {name}!', 'en' => 'Hello, {name}!'], 'test.bye' => ['en' => ' Goodbye ']], $second())->status(200);
    same($first, stored('test.hello', 'sk'), 'still the owner\'s, from when it was saved');
    same($untouched, stored('test.bye', 'en'), 'and still nobody\'s');

    // Two people, one string: the last save is kept, and says whose it is. Other people's changes come back with every save.
    save(['test.hello' => ['sk' => 'Vitaj, {name}!']])->status(200);
    $other = save(['test.hello' => ['sk' => 'Vitaj!'], 'test.bye' => ['sk' => 'Dovidenia']], $second())->status(200);
    same(['en' => 'Hello, {name}!', 'sk' => 'Vitaj!'], $other->json('keys.2.values'), 'a translation may leave a placeholder out');
    same(['Vitaj!', 3], array_slice(stored('test.hello', 'sk'), 0, 2), 'the last save');
    check(stored('test.hello', 'sk')[2] > $first[2], 'and when');
    same(['en' => 'Goodbye', 'sk' => 'Dovidenia'], save([])->status(200)->json('keys.0.values'), 'a save of nothing is the fresh grid');
    same(2, owner()->get('/api/admin/languages')->json('languages.1.strings'), 'Slovak has two strings now');

    // English reworded: its placeholders stay. A string for a key that had none: English first.
    save(['test.hello' => ['en' => 'Hi, {name}!'], 'test.new' => ['en' => 'New: {what}', 'sk' => 'Nové: {what}']])->status(200);
    same(['Hi, {name}!', OWNER], array_slice(stored('test.hello', 'en'), 0, 2), 'English, reworded');
    same('Nové: {what}', (new Client)->get('/api/translations/sk')->json('values')['test.new'], 'the new key, in Slovak');

    // An empty string: that language has none of its own again, and reads in English.
    save(['test.hello' => ['sk' => "  \t"]])->status(200);
    same(null, stored('test.hello', 'sk'), 'the string is gone');
    same('Hi, {name}!', (new Client)->get('/api/translations/sk')->json('values')['test.hello'], 'and the key reads in English');
    save(['test.hello' => ['sk' => ' ']])->status(200); // and once more, when there is none
    save(['test.hello' => ['sk' => 'Vitaj, {name}!']])->status(200);

    // Empty is what nobody can see: a zero-width space alone is not kept as a string that draws an empty place.
    save(['test.hello' => ['sk' => "\u{200B}"]])->status(200);
    same(null, stored('test.hello', 'sk'), 'that string goes, like any empty one');
    refused(save(['test.hello' => ['en' => " \u{2060}\u{00AD} "]]), 422, 'fallback_required', 'and English is not left with one');
    save(['test.hello' => ['sk' => "Vitaj,\u{200C} {name}!"]])->status(200); // inside a text such a character is part of it
    same("Vitaj,\u{200C} {name}!", stored('test.hello', 'sk')[0], 'kept as it was written');
});

test('a save holds the languages until it is kept: none is deleted between its check and its writing', function () use ($server) {
    ownStrings();
    language('de', 'German');
    $other = Db::pdo($server, T::$database, false);
    $other->exec("SET lock_timeout = '300ms'");
    $translations = T::$app->get(App\Repositories\Translations::class);

    $translations->transaction(function () use ($translations, $other): void {
        same(['de' => 'German', 'en' => 'English', 'sk' => 'Slovak'], (function (array $names): array {
            ksort($names);

            return $names;
        })($translations->languageNamesHeld()), 'the languages a save checks its strings against');

        // Somebody deletes one of them meanwhile: they wait for the save (here: they give up waiting).
        $waited = throws(PDOException::class, fn () => $other->exec("DELETE FROM languages WHERE code = 'de'"), 'a delete waits for the save');
        same('55P03', $waited->getCode(), 'waiting for the row: '.$waited->getMessage());
        // Reading goes on, and so does another save's hold on the same rows.
        same(3, (int) $other->query('SELECT count(*) FROM languages')->fetchColumn(), 'read meanwhile');
        same(3, count($other->query('SELECT code FROM languages FOR SHARE')->fetchAll()), 'held by two saves at once');
    });
    same(1, $other->exec("DELETE FROM languages WHERE code = 'de'"), 'once the save is kept, the language goes');
});

test('saving strings: all of it is checked before any of it is written, and each refusal names its key', function () {
    ownStrings();
    $before = everyString();
    $refusals = [
        'unknown_key' => [['test.bye' => ['en' => 'Bye'], 'no.such' => ['en' => 'x'], 'nor.this' => ['sk' => 'y']], ['key' => 'no.such', 'keys' => ['no.such', 'nor.this']]],
        'unknown_language' => [['test.bye' => ['sk' => 'Zbohom', 'hu' => 'Viszlát']], ['key' => 'test.bye', 'language' => 'hu']],
        'fallback_required' => [['test.count' => ['sk' => '{count} / {total}'], 'test.bye' => ['en' => '  ']], ['key' => 'test.bye', 'language' => 'en']],
        'placeholder_changed' => [['test.bye' => ['sk' => 'Zbohom'], 'test.hello' => ['en' => 'Hi!']], ['key' => 'test.hello', 'language' => 'en', 'placeholder' => 'name']],
        'unknown_placeholder' => [['test.bye' => ['sk' => 'Zbohom'], 'test.hello' => ['sk' => 'Ahoj, {meno}!']], ['key' => 'test.hello', 'language' => 'sk', 'placeholder' => 'meno']],
        // What the page would show as braces and a word: the name translated into something that is no name, a space inside.
        'invalid_placeholder' => [['test.bye' => ['sk' => 'Zbohom'], 'test.count' => ['sk' => '{počet} / { total }']], ['key' => 'test.count', 'language' => 'sk']],
    ];
    foreach ($refusals as $code => [$values, $about]) {
        $answer = refused(save($values), 422, $code);
        same($about, array_diff_key($answer->json(), ['message' => 0, 'code' => 0]), "{$code} says what it is about");
        same(['message', 'code'], array_slice(array_keys($answer->json()), 0, 2), "{$code}: the sentence and the code first");
        same($before, everyString(), "{$code}: nothing of that save was written - not the good strings before the bad one either");
    }
    $added = refused(save(['test.hello' => ['en' => 'Hi, {name} - {title}!']]), 422, 'placeholder_changed', 'a placeholder the code does not fill');
    same('title', $added->json('placeholder'), 'which one');
    same('The English string of "test.hello" cannot use {title}: the site has no value for it.', $added->json('message'), 'the sentence names the key and the placeholder');
    same('The English string of "test.hello" must keep {name}: the site puts a value there.', save(['test.hello' => ['en' => 'Hi!']])->json('message'), 'and the one it must keep');
    same('The Slovak string of "test.hello" uses {meno}, which its English string does not have.', save(['test.hello' => ['sk' => '{meno}']])->json('message'), 'in another language');
    $braces = refused(save(['test.hello' => ['en' => 'Hi, {{name}}!']]), 422, 'invalid_placeholder', 'in English too');
    same('The English string of "test.hello" has a { or } that is not part of a placeholder. A placeholder is written exactly as the English string has it, like {name}: no spaces, and its name is not translated.',
        $braces->json('message'), 'it says how a placeholder is written, with the key\'s own');

    $invalid = [
        'no values' => ['strings' => []], 'values as text' => ['values' => 'test.bye'], 'values as a list' => ['values' => [['test.bye' => ['en' => 'x']]]],
        'a key with a text' => ['values' => ['test.bye' => 'Bye']], 'a string that is a number' => ['values' => ['test.bye' => ['sk' => 5]]],
        'a string that is an object' => ['values' => ['test.bye' => ['sk' => ['one' => 'x']]]], 'a string that is null' => ['values' => ['test.bye' => ['sk' => null]]],
        '2001 characters' => ['values' => ['test.bye' => ['sk' => str_repeat('ž', 2001)]]],
    ];
    foreach ($invalid as $what => $body) {
        refused(owner()->request('PUT', '/api/admin/translations', $body), 422, 'invalid_value', $what);
    }
    refused(owner()->request('PUT', '/api/admin/translations', null, ['body' => '{"values": ']), 422, 'invalid_value', 'a body that is not JSON');
    refused(owner()->request('PUT', '/api/admin/translations', null, ['body' => '"values"']), 422, 'invalid_value', 'a body that is a text');
    same($before, everyString(), 'nothing was kept');

    save(['test.count' => ['sk' => str_repeat('ž', 2000)]])->status(200); // 2000 characters, counted as people count them
    save(['test.count' => ['sk' => '']])->status(200);

    // A text with more spaces inside it than can be trimmed (and still under the body's limit) is too long -
    // never an empty one: that would delete the stored string with a 200, or pass it over in an import.
    $spaces = 'Ahoj'.str_repeat(' ', 1040000).'{name}';
    foreach (['sk', 'en'] as $code) {
        refused(save(['test.hello' => [$code => $spaces]]), 422, 'invalid_value', "a million spaces inside the \"{$code}\" string");
    }
    refused(import('sk', ['test.hello' => $spaces]), 422, 'invalid_value', 'nor is it passed over as an empty one');
    same($before, everyString(), 'and after all of it, the strings are what they were');
});

test('a body is measured before anything in it is looked at: an empty one and one over 1 MiB are refused', function () {
    ownStrings();
    $before = everyString();
    $put = fn (string $url, string $body): Answer => owner()->request('PUT', $url, null, ['body' => $body]);

    foreach (['/api/admin/translations', '/api/admin/translations/sk/import'] as $url) {
        // What PHP makes of a body larger than the server accepts: nothing arrives.
        same(['message' => 'Nothing arrived. A body larger than this server accepts arrives empty.', 'code' => 'empty_body'],
            refused($put($url, ''), 422, 'empty_body', $url)->json(), 'the whole answer');
        refused(owner()->request('PUT', $url), 422, 'empty_body', "{$url} with no body at all");

        // One byte over: refused for its size, whatever it holds - here not even JSON.
        $over = refused($put($url, str_repeat('x', TranslationCheck::BODY_LIMIT + 1)), 422, 'too_large', $url);
        same(['message' => 'That is more than 1 MB, far more than every string of a language takes.', 'code' => 'too_large'], $over->json(), 'the whole answer');
        $padded = '{"test.bye": "Dovidenia"'.str_repeat(' ', TranslationCheck::BODY_LIMIT - 24).'}';
        same(TranslationCheck::BODY_LIMIT + 1, strlen($padded), 'one byte more than the limit');
        refused($put($url, $padded), 422, 'too_large', "{$url}: a good body, one byte too long");
    }
    same($before, everyString(), 'nothing was written');
    same(1024 * 1024, TranslationCheck::BODY_LIMIT, '1 MiB');

    // Exactly the limit is taken: a good body of that size is a save.
    $exact = '{"test.bye": "Dovidenia"'.str_repeat(' ', TranslationCheck::BODY_LIMIT - 25).'}';
    same(TranslationCheck::BODY_LIMIT, strlen($exact), 'the limit itself');
    $put('/api/admin/translations/sk/import', $exact)->status(200);
    same('Dovidenia', stored('test.bye', 'sk')[0], 'kept');
    $put('/api/admin/translations', '{"values": {"test.bye": {"sk": "Zbohom"}}'.str_repeat(' ', TranslationCheck::BODY_LIMIT - 42).'}')->status(200);
    same('Zbohom', stored('test.bye', 'sk')[0], 'and the grid\'s save of that size');

    // Whether the strings are there at all is asked first: with none, that is what is said.
    db()->run('DELETE FROM translation_keys');
    refused($put('/api/admin/translations', ''), 503, 'translations_unavailable', 'an empty body, with no strings there');
});

test('no string and no language names another site or holds a web address - in the grid, an import and the command line', function () {
    ownStrings();
    language('de', 'German');
    $before = [everyString(), languageRows()];

    // A value that names another site.
    $named = refused(save(['test.bye' => ['sk' => 'Dovidenia'], 'test.hello' => ['sk' => 'Ahoj, {name}! Pozri Prydwen.']]), 422, 'names_another_site');
    same(['key' => 'test.hello', 'language' => 'sk', 'found' => ['prydwen']], array_diff_key($named->json(), ['message' => 0, 'code' => 0]), 'the key, the language and what was found');
    same('The Slovak string of "test.hello" names another site ("prydwen"). Nothing the site shows names or links another website, service or tool.', $named->json('message'), 'the sentence');
    // A value that holds a web address.
    $address = refused(save(['test.bye' => ['en' => 'Goodbye - see https://elsewhere.example/builds']]), 422, 'names_another_site', 'an address');
    same(['key' => 'test.bye', 'language' => 'en', 'found' => ['a web address']], array_diff_key($address->json(), ['message' => 0, 'code' => 0]), 'an address is said to be one');
    same('The English string of "test.bye" holds a web address. Nothing the site shows names or links another website, service or tool.', $address->json('message'), 'the sentence');
    same(['a web address'], save(['test.bye' => ['sk' => 'Pozri www.elsewhere.example']])->status(422)->json('found'), 'without a scheme too');
    same(['enka.network'], save(['test.bye' => ['sk' => 'Údaje: https://enka.network/u/1']])->status(422)->json('found'), 'a named site\'s address is its name');
    same(['genshin optimizer', 'github'], save(['test.bye' => ['de' => 'Genshin Optimizer, GitHub und GITHUB']])->status(422)->json('found'), 'every name found, once, in small letters');

    // The same through an import ...
    $imported = refused(import('de', ['test.bye' => 'Tschüss', 'test.hello' => 'Hallo {name}, von Game8']), 422, 'names_another_site', 'an import');
    same(['key' => 'test.hello', 'language' => 'de', 'found' => ['game8']], array_diff_key($imported->json(), ['message' => 0, 'code' => 0]), 'what it is about');
    refused(import('de', ['test.bye' => 'Siehe http://elsewhere.example']), 422, 'names_another_site', 'an address in an import');

    // ... and the command line.
    $dir = T::$storage;
    TranslationsCommand::writeFile("{$dir}/named.json", ['test.bye' => 'Tschüss', 'test.hello' => 'Hallo {name}, von Mobalytics']);
    [$code, $out] = cli('--import=de', "{$dir}/named.json");
    same(1, $code, $out);
    check(str_contains($out, 'Refused (names_another_site): The German string of "test.hello" names another site ("mobalytics").'), $out);
    TranslationsCommand::writeFile("{$dir}/address.json", ['test.bye' => 'Siehe https://elsewhere.example/x']);
    [$code, $out] = cli('--import=de', "{$dir}/address.json");
    same(1, $code, $out);
    check(str_contains($out, 'Refused (names_another_site): The German string of "test.bye" holds a web address.'), $out);

    // A language's two names.
    $language = refused(owner()->post('/api/admin/languages', ['code' => 'fr', 'name' => 'French (Prydwen)', 'native_name' => 'Français']), 422, 'names_another_site', 'a new language\'s name');
    same(['field' => 'name', 'found' => ['prydwen']], array_diff_key($language->json(), ['message' => 0, 'code' => 0]), 'which name, and what was found');
    same('The name in English names another site ("prydwen"). Nothing the site shows names or links another website, service or tool.', $language->json('message'), 'the sentence');
    $native = refused(owner()->post('/api/admin/languages', ['code' => 'fr', 'name' => 'French', 'native_name' => 'Français - www.elsewhere.example']), 422, 'names_another_site', 'its own name');
    same(['field' => 'native_name', 'found' => ['a web address']], array_diff_key($native->json(), ['message' => 0, 'code' => 0]), 'an address in a name');
    refused(owner()->request('PUT', '/api/admin/languages/de', ['name' => 'Enka']), 422, 'names_another_site', 'a language renamed');
    refused(owner()->request('PUT', '/api/admin/languages/de', ['sort_order' => 3, 'native_name' => 'Deutsch (github.com)']), 422, 'names_another_site', 'and its own name');

    same($before, [everyString(), languageRows()], 'nothing of it was kept');

    // The game's own words and the site's are not other sites.
    save(['test.bye' => ['sk' => 'Genshin Impact, Honkai: Star Rail, Source 2, Enkanomiya, Build Analyzer']])->status(200);
    // What the check works with: bytes. A Slovak word that ends like a site's name after a letter with a
    // diacritic is found too - the string is reworded, the check stays as it is.
    same(['enka'], TranslationCheck::otherSites('pečenka'), 'a known false alarm');
    same([], TranslationCheck::otherSites('sklenka'), 'not after a plain letter');
});

test('a language out as a file and back in: its own strings only; unknown keys refused, empty ones passed over', function () {
    ownStrings();
    language('de', 'German');
    language('pt-br', 'Portuguese');
    db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.bye', 'sk', 'Dovidenia')");

    $export = owner()->get('/api/admin/translations/sk/export')->status(200);
    same(['test.bye' => 'Dovidenia', 'test.hello' => 'Dobrý deň, {name}!'], $export->json(), 'not merged with English: test.count is not there');
    same('attachment; filename="translations-sk.json"', $export->header('Content-Disposition'), 'a file to save');
    same(TranslationsCommand::fileText(['test.bye' => 'Dovidenia', 'test.hello' => 'Dobrý deň, {name}!']), $export->body, 'a key a line, the letters as they are');
    refused(owner()->get('/api/admin/translations/fr/export'), 404, 'not_found');
    same("{}\n", owner()->get('/api/admin/translations/pt-br/export')->status(200)->body, 'the export of a language with no strings is {} and not []');
    same('attachment; filename="translations-pt-br.json"', owner()->get('/api/admin/translations/pt-br/export')->header('Content-Disposition'), 'named by its code');

    // Into German: the empty ones are keys nobody translated yet.
    $in = import('de', ['test.hello' => " Hallo, {name}!\x07", 'test.bye' => '', 'test.count' => "  \t ", 'test.new' => 'Neu'])->status(200);
    same(['languages', 'keys'], array_keys($in->json()), 'answered with the grid as it is now');
    same(['test.hello' => 'Hallo, {name}!', 'test.new' => 'Neu'], owner()->get('/api/admin/translations/de/export')->json(), 'the two that had something in them');
    same(['Hallo, {name}!', OWNER], array_slice(stored('test.hello', 'de'), 0, 2), 'by who imported it');

    // An empty string deletes nothing - not in English either, where a save would refuse it.
    $before = everyString();
    import('de', ['test.hello' => '', 'test.new' => 'Neu'])->status(200);
    import('en', ['test.bye' => ' ', 'test.hello' => 'Hello, {name}!'])->status(200);
    import('de', ['test.hello' => "\u{200B}", 'test.new' => " \u{2060}\u{00AD}"])->status(200); // nor one that only looks empty
    import('de', [])->status(200); // an empty file
    same($before, everyString(), 'what was there stays, as it was and by whom');

    $unknown = refused(import('de', ['test.bye' => 'Tschüss', 'no.such' => 'x', 'nor.this' => '', 'test.count' => '{count} von {total}']), 422, 'unknown_key');
    same(['no.such', 'nor.this'], $unknown->json('keys'), 'every key the site does not have, empty or not');
    same('The site has no such keys: "no.such", "nor.this". Keys are made by the code, never here.', $unknown->json('message'), 'the sentence');
    same('The site has no key "no.such". Keys are made by the code, never here.', import('de', ['no.such' => 'x'])->json('message'), 'one of them');

    refused(import('de', ['test.bye' => 'Tschüss', 'test.hello' => 'Hallo, {dich}!']), 422, 'unknown_placeholder');
    refused(import('en', ['test.hello' => 'Hi!']), 422, 'placeholder_changed');
    refused(import('de', ['test.hello' => ['de' => 'Hallo']]), 422, 'invalid_value', 'a string given by language');
    refused(owner()->request('PUT', '/api/admin/translations/de/import', ['Hallo', 'Tschüss']), 422, 'invalid_value', 'a list');
    refused(owner()->request('PUT', '/api/admin/translations/de/import', null, ['body' => '"Hallo"']), 422, 'invalid_value', 'a text');
    refused(owner()->request('PUT', '/api/admin/translations/de/import', null, ['body' => '{"test.bye": ']), 422, 'invalid_value', 'not JSON');
    refused(import('fr', ['test.bye' => 'Au revoir']), 404, 'not_found', 'a language there is not');
    same($before, everyString(), 'nothing refused was kept');

    // What came out goes back in, and nothing changes.
    import('de', owner()->get('/api/admin/translations/de/export')->json())->status(200);
    import('sk', $export->json())->status(200);
    same($before, everyString(), 'the same strings, by the same people');
});

// ── The command line ─────────────────────────────────────────────────────────────────────────────

test('the command line: --export and --import on the same strings, by nobody', function () {
    ownStrings();
    language('de', 'German');
    language('pt-br', 'Portuguese');
    db()->run("INSERT INTO translations (key_name, language_code, value, updated_by) VALUES ('test.hello', 'de', 'Hallo, {name}!', 1), ('test.new', 'de', 'Neu', 1)");
    $dir = T::$storage;

    [$code, $out] = cli('--export=de', "{$dir}/de.json");
    same(0, $code, $out);
    check(str_contains($out, '2 strings of "de" written to'), $out);
    same(TranslationsCommand::fileText(['test.hello' => 'Hallo, {name}!', 'test.new' => 'Neu']), file_get_contents("{$dir}/de.json"), 'the file');
    [$code, $out] = cli('--export=sk');
    same([0, ['test.hello' => 'Dobrý deň, {name}!']], [$code, json_decode($out, true)], 'to the screen without a file');
    same([0, '{}'], cli('--export', 'pt-br'), 'a language with no strings; the code may stand apart');
    same(1, cli('--export=fr')[0], 'a language there is not');
    same(1, cli('--export=EN')[0], 'a code that is not one');
    same(1, cli('--export')[0], 'no code');

    $before = everyString();
    TranslationsCommand::writeFile("{$dir}/de.json", ['test.hello' => 'Guten Tag, {name}!', 'test.bye' => 'Tschüss', 'test.count' => '', 'test.new' => 'Neu']);
    [$code, $out] = cli('--import=de', "{$dir}/de.json");
    same(0, $code, $out);
    check((bool) preg_match('/4 strings in .*: 2 changed in "de"/', $out), $out);
    same(['Guten Tag, {name}!', null], array_slice(stored('test.hello', 'de'), 0, 2), 'written by nobody, as a migration\'s are');
    same(['Tschüss', null], array_slice(stored('test.bye', 'de'), 0, 2), 'a new one');
    same($before['test.new de'], everyString()['test.new de'], 'a string as it was is still whose it was');

    // Checked as a save is, and all of it or none of it.
    $before = everyString();
    TranslationsCommand::writeFile("{$dir}/bad.json", ['test.bye' => 'Auf Wiedersehen', 'test.hello' => 'Hallo, {dich}!']);
    [$code, $out] = cli('--import=de', "{$dir}/bad.json");
    same(1, $code, $out);
    check((bool) preg_match('/Refused \(unknown_placeholder\).*\{dich\}/', $out), $out);
    TranslationsCommand::writeFile("{$dir}/unknown.json", ['test.bye' => 'Auf Wiedersehen', 'no.such' => 'x', 'nor.this' => 'y']);
    [$code, $out] = cli('--import=de', "{$dir}/unknown.json");
    same(1, $code, $out);
    check(str_contains($out, 'Refused (unknown_key)'), $out);
    check((bool) preg_match('/^\s+no\.such$/m', $out) && (bool) preg_match('/^\s+nor\.this$/m', $out), 'the keys, one a line: '.$out);
    file_put_contents("{$dir}/list.json", '["a"]');
    same(1, cli('--import=de', "{$dir}/list.json")[0], 'a list');
    same(1, cli('--import=de', "{$dir}/nothing.json")[0], 'no such file');
    same(1, cli('--import=fr', "{$dir}/de.json")[0], 'a language there is not');
    same(1, cli('--import=de')[0], 'no file named');
    same(1, cli('--nonsense')[0], 'no such command');
    same(1, cli('--export=de', '--import=de', "{$dir}/de.json")[0], 'one thing at a time');
    same($before, everyString(), 'nothing refused was kept');
});

test('a language\'s strings as a file: flat JSON, the letters as they are, {} for none', function () {
    $dir = T::$storage;
    same("{}\n", TranslationsCommand::fileText([]), 'none');
    same("{}\n", TranslationsCommand::fileText(new stdClass), 'none, as an object');
    same("{\n    \"dashboard.title\": \"Účty – „moje“\",\n    \"a/b\": \"1\"\n}\n", TranslationsCommand::fileText(['dashboard.title' => 'Účty – „moje“', 'a/b' => '1']), 'as they are');

    $values = ['dashboard.title' => 'Účty', 'time.justNow' => "práve\nteraz", '12' => 'a key that looks like a number'];
    TranslationsCommand::writeFile("{$dir}/sk.json", $values);
    same($values, TranslationsCommand::readFile("{$dir}/sk.json"), 'read back');
    file_put_contents("{$dir}/windows.json", "\xEF\xBB\xBF".str_replace("\n", "\r\n", TranslationsCommand::fileText($values)));
    same($values, TranslationsCommand::readFile("{$dir}/windows.json"), 'as an editor on Windows saves it');
    file_put_contents("{$dir}/empty.json", "{}\n");
    same([], TranslationsCommand::readFile("{$dir}/empty.json"), 'an empty one');

    file_put_contents("{$dir}/list.json", '["a", "b"]');
    file_put_contents("{$dir}/broken.json", '{"a": ');
    file_put_contents("{$dir}/text.json", '"a"');
    check(str_contains(throws(RuntimeException::class, fn () => TranslationsCommand::readFile("{$dir}/list.json"), 'a list')->getMessage(), 'one flat object'), 'a list');
    check(str_contains(throws(RuntimeException::class, fn () => TranslationsCommand::readFile("{$dir}/text.json"), 'a text')->getMessage(), 'one flat object'), 'a text');
    check(str_contains(throws(RuntimeException::class, fn () => TranslationsCommand::readFile("{$dir}/broken.json"), 'broken')->getMessage(), 'is not JSON'), 'broken');
    check(str_contains(throws(RuntimeException::class, fn () => TranslationsCommand::readFile("{$dir}/missing.json"), 'missing')->getMessage(), 'There is no file'), 'missing');
});

test('the command line: --status reads a frontend against the database, and exits 1 when a page would show a key', function () {
    ownStrings();
    language('de', 'German');
    db()->run("UPDATE languages SET enabled = FALSE WHERE code = 'de'");
    db()->run("INSERT INTO translations (key_name, language_code, value) VALUES ('test.hello', 'de', 'Hallo, {name}!'), ('test.new', 'en', 'New')");
    $status = function (array $files): array {
        $GLOBALS['frontend'] = frontend($files);
        try {
            return cli('--status');
        } finally {
            unset($GLOBALS['frontend']);
        }
    };

    // Every key of the database is asked for, and every key asked for is there: clean.
    $page = "<h1>{{ 'test.hello' | translate: { name: name() } }}</h1>\n<p>{{ 'test.bye' | translate }} {{ 'test.count' | translate }} {{ 'test.new' | translate }}</p>\n";
    [$code, $out] = $status(['pages/home/home-page.html' => $page]);
    same(0, $code, $out);
    check(str_contains($out, '== build analyzer ('.T::$database.'): the site\'s own strings'), 'it says which database it read: '.$out);
    check((bool) preg_match('/the database has\s+4 keys/', $out) && (bool) preg_match('/the frontend uses\s+4 of them/', $out), $out);
    check((bool) preg_match('/^  en\s+English\s+4 \/ 4$/m', $out) && (bool) preg_match('/^  sk\s+Slovak\s+1 \/ 4$/m', $out), 'how much of each language there is: '.$out);
    check((bool) preg_match('/^  de\s+German\s+1 \/ 4   \(not offered\)$/m', $out), 'a hidden one is said to be: '.$out);
    check(str_contains($out, 'No English string, so nothing to fall back to:') === false, $out);
    check(str_ends_with(rtrim($out), 'Clean.') && ! str_contains($out, 'Not clean.'), $out);

    // A key no page asks for: said, and still clean - a migration deletes it.
    [$code, $out] = $status(['pages/home/home-page.html' => "<h1>{{ 'test.hello' | translate }}</h1>\n<p>{{ 'test.bye' | translate }}</p>\n"]);
    same(0, $code, $out);
    check((bool) preg_match('/In the database, used nowhere.*\n\s+test\.count\n\s+test\.new\n/', $out), $out);

    // A key a page asks for that is not there: exit 1, named with the file and the line.
    [$code, $out] = $status(['pages/home/home-page.html' => $page."<em>{{ 'test.missing' | translate }}</em>\n", 'pages/home/home-page.ts' => "const notOurs = 'other.key';\n"]);
    same(1, $code, $out);
    check((bool) preg_match('/Used by the frontend, not in the database.*\n\s+test\.missing\n\s+pages\/home\/home-page\.html:3\n/', $out), $out);
    check(str_contains($out, 'Not clean.') && ! str_contains($out, '  Clean.'), $out);
    check(! str_contains($out, 'other.key'), 'a namespace the database does not have is nobody\'s key: '.$out);

    // The keys put together at run time are declared, and must be there.
    [$code, $out] = $status(['pages/home/home-page.html' => $page, 'core/i18n/dynamic-keys.ts' => "export const DYNAMIC_KEYS = {\n  'test.kind.': ['one_thing'],\n};\n"]);
    same(1, $code, $out);
    check((bool) preg_match('/not in the database.*\n\s+test\.kind\.one_thing\n\s+core\/i18n\/dynamic-keys\.ts\n/', $out), $out);

    // What the scan does not let through: exit 1.
    [$code, $out] = $status(['pages/home/home-page.html' => $page."<p [innerHTML]=\"'test.bye' | translate\"></p>\n"]);
    same(1, $code, $out);
    check(str_contains($out, 'What the scan does not let through:') && str_contains($out, 'pages/home/home-page.html:3: innerHTML is given a translated string'), $out);

    // A key with no English string: nothing to fall back to.
    db()->run("INSERT INTO translation_keys (name) VALUES ('test.noEnglish')");
    [$code, $out] = $status(['pages/home/home-page.html' => $page."<em>{{ 'test.noEnglish' | translate }}</em>\n"]);
    same(1, $code, $out);
    check((bool) preg_match('/No English string.*\n\s+test\.noEnglish\n/', $out), $out);

    // No frontend to read.
    $GLOBALS['frontend'] = T::$storage.'/no-such-frontend';
    try {
        [$code, $out] = cli('--status');
    } finally {
        unset($GLOBALS['frontend']);
    }
    same(1, $code, $out);
    check(str_contains($out, 'There is no frontend here to read the keys from'), $out);

    // And with no string in the database there is nothing to report on.
    db()->run('DELETE FROM translation_keys');
    [$code, $out] = cli('--status');
    same(1, $code, $out);
    check(str_contains($out, 'The site\'s strings are not in the database '.T::$database.' yet'), $out);
    same(1, cli('--export=sk')[0], 'nor to export');
});

finishTests();
