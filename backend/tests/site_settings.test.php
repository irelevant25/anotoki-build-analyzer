<?php

/**
 * The site settings (App\Web\SitePages - anotoki-lib 0.11.0, all three parts) and robots.txt and sitemap.xml, on real
 * requests: what every page is told; the Site settings page's routes, the site's ADMIN's alone and audited;
 * maintenance - everybody but the ADMIN told the site is closed, the ADMIN known by a verified token alone, the
 * reserved paths answering, nothing of it in the error log; Page access - an API path a page's row refuses with it
 * answers 423 to whoever the page is not shown to (a visitor and nobody are nobody), never to the ADMIN, nothing
 * reserved ever claimed either way, a row put in by hand that claims one passed over; the People page's delete leaving
 * what a person changed by nobody; and what robots read - by the site's configured address, never a request's Host,
 * the public pages and each character's builds only while they are anybody's, never what Page access hides.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

use App\Config;
use App\Services\Import\Enka\EnkaStore;
use App\Web\SitePages;
use Tests\Support\Answer;
use Tests\Support\Client;

bootTests();

const OWNER = 1; // the site's ADMIN
const EDITH = 2; // an EDITOR
const ANNA = 3; // another ADMIN
const MONA = 7; // signed in, no role here

/** The address the tests configure (what config/site.local.php names: App\Config's site.address). */
const ADDRESS = 'https://builds.test.example';

// A visitor's import reads the catalog and, for a GOOD file's talents, the showcase service's store: faked.
beforeEach(function (): void {
    seedGenshinCatalog();
    T::$app->set(
        EnkaStore::class,
        new class extends EnkaStore {
            public function __construct() {}

            public function genshinAvatar(string $key): ?array
            {
                return ['SkillOrder' => [10461, 10462, 10463], 'ProudMap' => []];
            }
        },
    );
});

function owner(): Client
{
    return (new Client())->asIam(OWNER, ['ADMIN']);
}

function editor(): Client
{
    return (new Client())->asIam(EDITH, ['EDITOR']);
}

function member(): Client
{
    return (new Client())->asIam(MONA);
}

function nobody(): Client
{
    return new Client();
}

/** An ADMIN saves switches of the site settings: the page's whole. */
function saveSettings(array $settings, ?Client $as = null): array
{
    return ($as ?? owner())
        ->put('/api/admin/site-settings', ['settings' => $settings])
        ->status(200)
        ->json();
}

/** An ADMIN adds a page's row: its id. */
function addPage(string $path, string $visibility, bool $blocked, array $apiPaths, ?Client $as = null): int
{
    $whole = ($as ?? owner())
        ->post('/api/admin/site-settings/pages', ['path' => $path, 'visibility' => $visibility, 'blocked' => $blocked, 'api_paths' => $apiPaths])
        ->status(201)
        ->json();
    foreach ($whole['pages'] as $row) {
        if ($row['path'] === $path) {
            return (int) $row['id'];
        }
    }
    throw new AssertionFailed("the row of {$path} is not on the page");
}

/** The audit log's entries of the site settings, oldest first: [action, actor, target id]. */
function settingsAudit(): array
{
    return array_map(
        static fn(array $row): array => [$row['action'], $row['actor_id'] === null ? null : (int) $row['actor_id'], $row['target_id']],
        db()->all("SELECT action, actor_id, target_id FROM anotoki_audit_log WHERE action LIKE 'site_settings.%' OR action LIKE 'page_access.%' ORDER BY id"),
    );
}

/** Whatever the error log holds so far: every file of it (storage/logs), as text. */
function errorLogText(): string
{
    $text = '';
    foreach (glob(T::$storage . '/logs/errors-*.jsonl') ?: [] as $file) {
        $text .= (string) file_get_contents($file);
    }

    return $text;
}

/** A GOOD file of one character, as an exporter writes it. */
function goodFile(): string
{
    return (string) json_encode([
        'format' => 'GOOD',
        'version' => 2,
        'source' => 'Some Exporter',
        'characters' => [['key' => 'HuTao', 'level' => 80, 'constellation' => 0, 'ascension' => 5, 'talent' => ['auto' => 1, 'skill' => 1, 'burst' => 1]]],
        'weapons' => [],
        'artifacts' => [],
    ]);
}

/** What the IAM says from now on: visitors welcome here, or a sign-in required - its kept answer forgotten. */
function visitorsWelcome(bool $welcome): void
{
    iamRequiresSignIn(!$welcome);
    removeTree(T::$storage . DIRECTORY_SEPARATOR . 'cache');
}

/** A build of a character: its file's day (checked and changed) and, where an editor saved it by hand, that day. */
function sitemapBuild(int $character, string $changed, ?string $edited = null): void
{
    db()->insert('builds', [
        'game_character_id' => $character,
        'role' => 'main_dps',
        'qualifier' => '',
        'min_rank' => 0,
        'position' => 0,
        'weapons' => '[]',
        'sets' => '[]',
        'main_stats' => '{}',
        'substats' => '[]',
        'content_hash' => 'x',
        'checked_at' => $changed,
        'changed_at' => $changed,
        'edited_at' => $edited,
        'created_at' => $changed,
        'updated_at' => $changed,
    ]);
}

/** The sitemap's addresses, in its order - valid XML, or the test fails. */
function sitemapLocs(): array
{
    $answer = nobody()->get('/sitemap.xml')->status(200);
    same(['application/xml; charset=utf-8', 'public, max-age=3600'], [$answer->header('Content-Type'), $answer->header('Cache-Control')], 'XML, kept an hour');
    $xml = simplexml_load_string($answer->body);
    check($xml !== false, 'the sitemap is XML: ' . $answer->body);
    $locs = [];
    foreach ($xml->url as $url) {
        $locs[] = (string) $url->loc;
    }

    return $locs;
}

const LEGAL = [ADDRESS . '/terms', ADDRESS . '/privacy', ADDRESS . '/contact', ADDRESS . '/about', ADDRESS . '/support'];

test('what every page is told, before anybody signs in: the three parts at their defaults - whatever a request carries, also while the IAM requires a sign-in', function () {
    $told = nobody()->get('/api/site-settings')->status(200);
    same(['maintenance' => false, 'announcement' => null, 'pages' => []], $told->json(), 'the three parts, at their defaults');
    same('no-store', $told->header('Cache-Control'), 'never kept');
    // No token is read there: one that does not verify is no refusal - the pages ask before anybody signs in.
    (new Client())->withToken('not-a-token')->get('/api/site-settings')->status(200);
    visitorsWelcome(false);
    nobody()->get('/api/site-settings')->status(200);
});

test('the Site settings page\'s routes are the site\'s ADMIN\'s alone - nobody 401, an editor and a member 403 admin_only - and say what is reserved', function () {
    nobody()->get('/api/admin/site-settings')->status(401);
    editor()->get('/api/admin/site-settings')->status(403)->path('code', 'admin_only');
    member()->get('/api/admin/site-settings')->status(403)->path('code', 'admin_only');
    editor()
        ->put('/api/admin/site-settings', ['settings' => ['maintenance_mode' => true]])
        ->status(403);
    editor()
        ->post('/api/admin/site-settings/pages', ['path' => '/', 'visibility' => 'ADMIN'])
        ->status(403);
    same([0, 0], [(int) db()->value('SELECT count(*) FROM anotoki_site_settings'), (int) db()->value('SELECT count(*) FROM anotoki_page_access')], 'nothing saved by them');

    $page = owner()->get('/api/admin/site-settings')->status(200)->json();
    same(['getting_in', 'announcement', 'page_access'], $page['parts'], 'all three parts');
    same(['/api/meta', '/api/icons'], SitePages::RESERVED_API_PATHS, 'the site\'s own reserved API paths');
    foreach (['/api/meta', '/api/icons', '/api/site-status', '/api/translations', '/api/site-settings', '/api/admin/site-settings'] as $path) {
        check(in_array($path, $page['reserved']['api_paths'], true), "{$path} is reserved");
    }
    foreach (['/api/me', '/api/visitor', '/api/accounts', '/api/builds', '/api/imports'] as $path) {
        check(!in_array($path, $page['reserved']['api_paths'], true), "{$path} is not: maintenance closes it, and Page access may refuse it");
    }
    foreach (['/auth', '/staff', '/admin', '/sign-in', '/terms', '/privacy', '/contact', '/about', '/support'] as $path) {
        check(in_array($path, $page['reserved']['pages'], true), "{$path} is no row's to govern");
    }
});

test('the announcement: the ADMIN saves it - audited, by the token\'s person - and every page is told it; a kind there is none of is refused', function () {
    $whole = saveSettings(['announcement_enabled' => true, 'announcement_level' => 'warning', 'announcement_message' => ['en' => 'Back at five.', 'sk' => 'Späť o piatej.']]);
    same(true, $whole['settings']['announcement_enabled']['value'], 'saved');
    same('user' . OWNER, $whole['settings']['announcement_enabled']['updated_by_name'], 'who changed it last, by the name the site keeps');
    same(['level' => 'warning', 'message' => ['en' => 'Back at five.', 'sk' => 'Späť o piatej.']], nobody()->get('/api/site-settings')->json('announcement'), 'every page is told it');
    same([['site_settings.changed', OWNER, null]], settingsAudit(), 'audited');

    $refused = owner()
        ->put('/api/admin/site-settings', ['settings' => ['announcement_level' => 'panic']])
        ->status(422);
    same('invalid_setting', $refused->json('code'), 'a kind there is none of');
    same('warning', nobody()->get('/api/site-settings')->json('announcement.level'), 'and nothing changed');
});

test(
    'maintenance: everybody but the site\'s ADMIN is told the site is closed - the ADMIN by a verified token alone; a forged, expired or other site\'s token is nobody\'s; the reserved paths answer; nothing of it in the error log',
    function () {
        saveSettings(['maintenance_mode' => true, 'maintenance_message' => ['en' => 'Moving house.', 'sk' => 'Sťahujeme sa.']]);
        $closed = static function (Answer $answer, string $who): void {
            $answer->status(503);
            same('maintenance', $answer->json('code'), "{$who}: closed");
            same('300', $answer->header('Retry-After'), "{$who}: come back later");
            same(['en' => 'Moving house.', 'sk' => 'Sťahujeme sa.'], $answer->json('maintenance_message'), "{$who}: the administrators' words");
        };
        $closed(nobody()->get('/api/builds/genshin'), 'nobody');
        $closed(member()->get('/api/accounts'), 'a member');
        $closed(editor()->get('/api/admin/builds'), 'an editor');
        $closed((new Client('198.51.100.1'))->post('/api/imports/file', ['content' => goodFile(), 'file_name' => 'export.json']), 'an import of nobody\'s');
        same(0, (int) db()->value('SELECT count(*) FROM users WHERE id < 0'), 'no visitor made while closed');
        $closed(nobody()->get('/robots.txt'), 'a robot');
        $closed((new Client())->withToken(iamToken(OWNER, ['ADMIN'], [], 'at+jwt', strangerIam()))->get('/api/builds/genshin'), 'an ADMIN token signed by another key');
        $closed((new Client())->withToken(iamToken(OWNER, ['ADMIN'], ['exp' => time() - 3600]))->get('/api/builds/genshin'), 'an expired ADMIN token');
        $closed((new Client())->withToken(iamToken(OWNER, ['ADMIN'], ['aud' => 'genshin']))->get('/api/builds/genshin'), 'an ADMIN token of another site');

        // The site's ADMIN uses the site as it is - and can open it again.
        owner()->get('/api/builds/genshin')->status(200);
        owner()->get('/api/admin/builds')->status(200);
        owner()->get('/api/admin/site-settings')->status(200);

        // What anybody still reaches: the status - which says so -, the sign-in's configuration and the start (meta), the
        // words, the settings, the pictures (an <img> carries no token, the ADMIN's neither).
        same(
            ['state' => 'maintenance', 'installed' => false, 'maintenance_message' => ['en' => 'Moving house.', 'sk' => 'Sťahujeme sa.']],
            nobody()->get('/api/site-status')->status(200)->json(),
            'the status says so',
        );
        nobody()
            ->get('/api/meta')
            ->status(200)
            ->path('auth.issuer', TEST_ISSUER);
        nobody()->get('/api/translations/en')->status(200);
        same(true, nobody()->get('/api/site-settings')->status(200)->json('maintenance'), 'every page is told it');
        $icon = nobody()->get('/api/icons/c/999999');
        same([404, 'not_found'], [$icon->status, $icon->json('code')], 'a picture is asked for as ever - never 503');

        saveSettings(['maintenance_mode' => false]);
        nobody()->get('/api/builds/genshin')->status(200);
        // Nothing of it in the error log - a state somebody chose, not a failure (App\Admin\AdminTools::EXPECTED_ANSWERS) -,
        // while what is worth seeing still is.
        nobody()->get('/api/nothing-here')->status(404);
        $log = errorLogText();
        check(str_contains($log, 'nothing-here'), 'the error log writes what is worth seeing');
        check(!str_contains($log, 'maintenance') && !str_contains($log, '"status":503'), 'and nothing of the maintenance');
    },
);

test('Page access: a row keeps the builds for people signed in - its API paths 423 to nobody, never to a member; for editors, then switched off - never the ADMIN; all audited', function () {
    $id = addPage('/builds/:game', 'USER', false, ['/api/builds']);
    same([['path' => '/builds/:game', 'visibility' => 'USER', 'blocked' => false]], nobody()->get('/api/site-settings')->json('pages'), 'what every page is told: never the row\'s API paths');
    $locked = nobody()->get('/api/builds/genshin')->status(423);
    same(['page_locked', 'That part of the site is not available.'], [$locked->json('code'), $locked->json('message')], 'in this site\'s words');
    nobody()->get('/api/builds')->status(423);
    nobody()->get('/api/builds/genshin/hutao')->status(423);
    member()->get('/api/builds/genshin')->status(200);

    owner()
        ->put('/api/admin/site-settings/pages', ['pages' => [['id' => $id, 'visibility' => 'EDITOR']]])
        ->status(200);
    member()->get('/api/builds/genshin')->status(423);
    editor()->get('/api/builds/genshin')->status(200);

    owner()
        ->put('/api/admin/site-settings/pages', ['pages' => [['id' => $id, 'visibility' => 'PUBLIC', 'blocked' => true]]])
        ->status(200);
    editor()->get('/api/builds/genshin')->status(423);
    owner()->get('/api/builds/genshin')->status(200);

    owner()
        ->delete("/api/admin/site-settings/pages/{$id}")
        ->status(200);
    nobody()->get('/api/builds/genshin')->status(200);
    same(
        [['page_access.added', OWNER, (string) $id], ['page_access.changed', OWNER, (string) $id], ['page_access.changed', OWNER, (string) $id], ['page_access.removed', OWNER, (string) $id]],
        settingsAudit(),
        'audited, by the ADMIN',
    );
});

test(
    'a visitor is nobody to Page access: a row keeping the account pages\' data for people signed in refuses a visitor\'s own accounts, and an import of nobody\'s makes no visitor - never a person\'s',
    function () {
        $made = (new Client('198.51.100.1'))->post('/api/imports/file', ['content' => goodFile(), 'file_name' => 'export.json'])->status(201);
        $key = $made->header('X-Visitor-Key');
        check($key !== '', 'a visitor, made before the row');
        addPage('/accounts/:id', 'USER', false, ['/api/accounts', '/api/imports']);

        $visitor = (new Client('198.51.100.1'))->withHeaders(['X-Visitor-Key' => $key]);
        $refused = $visitor->get('/api/accounts');
        same([423, 'page_locked'], [$refused->status, $refused->json('code')], 'the visitor\'s own accounts');
        $visitors = (int) db()->value('SELECT count(*) FROM users WHERE id < 0');
        $again = (new Client('198.51.100.2'))->post('/api/imports/file', ['content' => goodFile(), 'file_name' => 'export.json']);
        same([423, ''], [$again->status, $again->header('X-Visitor-Key')], 'an import of nobody\'s: refused, no key');
        same($visitors, (int) db()->value('SELECT count(*) FROM users WHERE id < 0'), 'and no visitor made of it');
        member()->get('/api/accounts')->status(200);
    },
);

test(
    'the site\'s ADMIN is never locked out: a row keeping the editors\' admin API for administrators alone refuses an editor - never the ADMIN; closed and locked at once, the ADMIN has everything',
    function () {
        addPage('/', 'ADMIN', false, ['/api/admin/builds', '/api/admin/translations']);
        $refused = editor()->get('/api/admin/builds');
        same([423, 'page_locked'], [$refused->status, $refused->json('code')], 'an editor is refused the builds\' admin API');
        editor()->get('/api/admin/translations')->status(423);
        member()->get('/api/admin/builds')->status(403)->path('code', 'editor_only');
        owner()->get('/api/admin/builds')->status(200);
        owner()->get('/api/admin/translations')->status(200);

        saveSettings(['maintenance_mode' => true]);
        owner()->get('/api/admin/builds')->status(200);
        owner()->get('/api/admin/site-settings')->status(200);
        saveSettings(['maintenance_mode' => false]);
    },
);

test('nothing reserved can be claimed, either way round - and a row put in by hand that claims one is passed over', function () {
    foreach (['/api/meta', '/api/m', '/api/icons', '/api/icons/c/1', '/api/site-settings', '/api/admin/site-settings/pages', '/api/site-status', '/api/translations/en', '/api/'] as $apiPath) {
        $refused = owner()->post('/api/admin/site-settings/pages', ['path' => '/', 'visibility' => 'USER', 'blocked' => false, 'api_paths' => [$apiPath]]);
        same([422, 'invalid_api_path'], [$refused->status, $refused->json('code')], $apiPath);
    }
    foreach (['/staff', '/sign-in', '/sign-in/again', '/:page', '/admin/people', '/auth/callback', '/privacy', '/about', '/support'] as $page) {
        $refused = owner()->post('/api/admin/site-settings/pages', ['path' => $page, 'visibility' => 'ADMIN', 'blocked' => true, 'api_paths' => []]);
        same([422, 'invalid_page'], [$refused->status, $refused->json('code')], $page);
    }
    same(0, (int) db()->value('SELECT count(*) FROM anotoki_page_access'), 'none of them was kept');

    // Put in by hand, past the page: what it may not govern is passed over - the start and the pictures answer, and
    // nobody is told /staff is governed - while what it may still is (the builds).
    db()->run("INSERT INTO anotoki_page_access (path, visibility, blocked, api_paths) VALUES ('/', 'ADMIN', true, ARRAY['/api/meta', '/api/icons', '/api/builds']), ('/staff', 'ADMIN', true, '{}')");
    nobody()->get('/api/meta')->status(200);
    nobody()->get('/api/icons/c/999999')->status(404);
    nobody()->get('/api/builds/genshin')->status(423);
    same(['/'], array_column(nobody()->get('/api/site-settings')->json('pages'), 'path'), '/staff is no row\'s');
});

test('the People page\'s delete leaves what a person changed of the site settings by nobody - the switch and the row stay', function () {
    $anna = (new Client())->asIam(ANNA, ['ADMIN']);
    saveSettings(['announcement_enabled' => true, 'announcement_message' => ['en' => 'Hello.']], $anna);
    $id = addPage('/import', 'USER', false, [], $anna);
    $changedBy = fn(): array => [
        db()->value("SELECT updated_by FROM anotoki_site_settings WHERE name = 'announcement_message'"),
        db()->value('SELECT updated_by FROM anotoki_page_access WHERE id = ?', [$id]),
    ];
    same([ANNA, ANNA], array_map('intval', $changedBy()), 'hers, by her anotoki id');

    owner()
        ->delete('/api/admin/people/' . ANNA)
        ->status(200);
    same([null, null], $changedBy(), 'by nobody now');
    same(['level' => 'info', 'message' => ['en' => 'Hello.']], nobody()->get('/api/site-settings')->json('announcement'), 'the announcement stays');
    same([['path' => '/import', 'visibility' => 'USER', 'blocked' => false]], nobody()->get('/api/site-settings')->json('pages'), 'and the row');
});

test(
    'robots.txt: robots kept out of the admin panel, the API, the sign-in\'s way back, a reader\'s own accounts, the failed sign-in\'s screen and the staff route - the sitemap by the site\'s configured address, never a request\'s Host',
    function () {
        T::$app->config->set('site.address', ADDRESS);
        $robots = nobody()
            ->withHeaders(['Host' => 'evil.example'])
            ->get('/robots.txt')
            ->status(200);
        same(['text/plain; charset=utf-8', 'public, max-age=3600'], [$robots->header('Content-Type'), $robots->header('Cache-Control')], 'text, kept an hour');
        same(
            "# The robots of every search engine: what to leave alone, and where the site's pages are listed.\nUser-agent: *\nDisallow: /admin\nDisallow: /api\nDisallow: /auth\nDisallow: /accounts\nDisallow: /sign-in\nDisallow: /staff\n\nSitemap: " .
                ADDRESS .
                "/sitemap.xml\n",
            $robots->body,
            'robots.txt',
        );
        nobody()->request('HEAD', '/robots.txt')->status(200);

        // No address configured: the address of where the code runs - a checkout's here -, never the Host.
        T::$app->config->set('site.address', null);
        check(
            str_contains(
                nobody()
                    ->withHeaders(['Host' => 'evil.example'])
                    ->get('/robots.txt')->body,
                "\nSitemap: " . Config::DEVELOPMENT_ADDRESS . "/sitemap.xml\n",
            ),
            'the checkout\'s address',
        );
    },
);

test(
    'sitemap.xml: the legal pages, About and Support always; the dashboard, the import, the builds and each character\'s builds only while visitors are welcome - with the day its builds last changed; never what Page access hides',
    function () {
        T::$app->config->set('site.address', ADDRESS);
        sitemapBuild(idOf('game_characters', 'hutao'), '2026-10-20 10:00:00+00', '2026-10-22 08:00:00+00');
        sitemapBuild(idOf('game_characters', 'kaedeharakazuha'), '2026-10-21 10:00:00+00');

        visitorsWelcome(false);
        same(LEGAL, sitemapLocs(), 'a sign-in required: the legal pages, About and Support alone - every other page sends everybody to the IAM');

        visitorsWelcome(true);
        same(
            [...LEGAL, ADDRESS . '/', ADDRESS . '/import', ADDRESS . '/builds/genshin', ADDRESS . '/builds/genshin/hutao', ADDRESS . '/builds/genshin/kaedeharakazuha'],
            sitemapLocs(),
            'visitors welcome',
        );
        $xml = nobody()->get('/sitemap.xml')->body;
        check(
            str_contains($xml, '<url><loc>' . ADDRESS . '/builds/genshin/hutao</loc><lastmod>2026-10-22</lastmod></url>'),
            'a character with the day its builds last changed - by hand here: ' . $xml,
        );
        check(str_contains($xml, '<url><loc>' . ADDRESS . '/builds/genshin/kaedeharakazuha</loc><lastmod>2026-10-21</lastmod></url>'), 'by a file there');
        check(!str_contains($xml, 'traveleranemo'), 'never a character without builds');

        // Page access hides from robots what it hides from people: a character's builds for people signed in, the import switched off.
        addPage('/builds/:game/:key', 'USER', false, []);
        addPage('/import', 'PUBLIC', true, []);
        same([...LEGAL, ADDRESS . '/', ADDRESS . '/builds/genshin'], sitemapLocs(), 'what Page access hides stays out');
    },
);

finishTests();
