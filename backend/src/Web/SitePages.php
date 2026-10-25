<?php

namespace App\Web;

use Anotoki\Lib\Auth\SignInRule;
use Anotoki\Lib\Robots\Robots;
use Anotoki\Lib\SiteSettings\SiteSettings;
use App\App;
use App\Db\Db;
use App\Enums\Game;
use App\Repositories\Builds;
use PDO;

/**
 * The public site's pages as the anotoki family's site settings and robots see them (anotoki-lib 0.11.0, the owner's
 * requests of 2026-10-25: genshin's site settings on every site, and robots.txt and sitemap.xml on every site) - what
 * is this site's own of the library's Anotoki\Lib\SiteSettings and Anotoki\Lib\Robots.
 *
 * The site settings: switches the site's ADMIN throws without a deploy, on the admin panel's Site settings page
 * (/admin/site-settings, the library's page; api/routes/site_settings.php), kept in the library's tables (its set
 * anotoki_admin, 003). This site offers all three parts:
 *
 *   getting_in     maintenance: the API answers everybody but the site's ADMIN 503 maintenance - the site gate's
 *                  (api/middleware/site_gate.php), which knows the ADMIN by the token alone, as it runs before any
 *                  route group identifies the request - and the pages draw the family's closed sign; an
 *                  administrator signed out signs in at /staff
 *   announcement   a bar above everybody's pages (<anotoki-announcement>, sites/main/app-layout)
 *   page_access    the public pages - the dashboard (/), the import (/import), an account (/accounts/:id) and a
 *                  character of it (/accounts/:id/characters/:key), the builds browser (/builds/:game) and a
 *                  character's builds (/builds/:game/:key): each switched off, or kept for people signed in, for
 *                  editors, for administrators; the API paths a row names answer 423 page_locked to the others (the
 *                  library's PageAccessGate, in every route group right after its own check: api/middleware/auth.php)
 *
 * What keeps answering whatever a row says, and while the site is closed (the reserved API paths,
 * RESERVED_API_PATHS): the library's own - the site's status, the translations' bundles, the settings' two routes -
 * and this site's: /api/meta, the start of every page (where people sign in and whether the IAM requires it: the
 * sign-in's configuration, AuthRoutes::configBody(); the game's facts) - and /api/icons, the catalog's pictures: an
 * <img> carries no token, so a row that claimed them would refuse every picture to everybody, the administrators
 * too, and while the site is closed so would the gate. Who the reader is comes from the IAM's token alone - no route
 * here says it -, and /api/me is the person's own accounts file (the account pages' "Your data"): maintenance closes
 * it like everything else, and a row may take it with the account pages. So does the move at sign-in
 * (/api/visitor/move): refused, the visitor's key stays in the browser and the move is asked again (VisitorKey).
 *
 * The pages no row governs (RESERVED_PAGES): the library's - the sign-in's way back (/auth), /staff, /admin, the legal
 * pages, About and Support - and the screen of a sign-in that did not work, at this site's own address (/sign-in).
 *
 * robots.txt and sitemap.xml (api/routes/robots.php): robots kept out of the library's /admin, /api and /auth and
 * this site's ROBOTS_DISALLOW; the sitemap by the site's configured address (App\Config::siteAddress() - never a
 * request's Host): the legal pages, About and Support always, and while the IAM lets visitors in the public pages
 * with every character's builds page (publicEntries()) - never what Page access hides.
 */
final class SitePages
{
    /** The site's own API paths that keep answering whatever the settings say, each itself and everything under it. */
    public const RESERVED_API_PATHS = ['/api/meta', '/api/icons'];

    /** The pages no row may govern beyond the library's own: the screen of a sign-in that did not work (shared/auth.guards.ts). */
    public const RESERVED_PAGES = ['/sign-in'];

    /**
     * The public pages Page access governs, as the router declares them (sites/main/main.routes.ts: pageAccessGuard on
     * each) - with the API paths each reads, for the administrators who name them in a row (docs/operations.md).
     */
    public const GOVERNED = [
        '/' => ['/api/builds', '/api/accounts', '/api/me'],
        '/import' => ['/api/imports', '/api/accounts'],
        '/accounts/:id' => ['/api/accounts', '/api/me'],
        '/accounts/:id/characters/:key' => ['/api/accounts'],
        '/builds/:game' => ['/api/builds'],
        '/builds/:game/:key' => ['/api/builds'],
    ];

    /** What robots stay out of beside the library's /admin, /api and /auth: a reader's own accounts, the screen of a failed sign-in, the administrators' way in. */
    public const ROBOTS_DISALLOW = ['/accounts', '/sign-in', '/staff'];

    /** What the sitemap always lists: the legal pages, About and Support - anybody's, also while the IAM requires signing in here. */
    public const ALWAYS_LISTED = ['/terms', '/privacy', '/contact', '/about', '/support'];

    /** The site settings, all three parts: for the site gate, the routes, the Page access gate and the sitemap. The connection is asked only when a question needs it. */
    public static function settings(App $app): SiteSettings
    {
        return new SiteSettings(pdo: static fn(): PDO => $app->db()->raw(), parts: SiteSettings::PARTS, reservedApiPaths: self::RESERVED_API_PATHS, reservedPages: self::RESERVED_PAGES);
    }

    /**
     * robots.txt and sitemap.xml. No file keeps the sitemap between requests (the library's cacheFile): what is public
     * here changes with the builds an editor imports and with the IAM's "Sign-in required", neither of which a kept
     * copy would know of for an hour; made each time it is one query, and the IAM's answer is kept a minute already
     * (SignInRule). The answer itself may be kept an hour by a browser or the edge (the library's Cache-Control).
     */
    public static function robots(App $app): Robots
    {
        return new Robots(
            address: static fn(): string => $app->config->siteAddress(),
            disallow: self::ROBOTS_DISALLOW,
            pages: self::ALWAYS_LISTED,
            entries: static fn(): array => self::publicEntries($app),
            settings: $app->get(SiteSettings::class),
        );
    }

    /**
     * The public pages the sitemap lists while the IAM lets visitors in here (the pages are anybody's then): the
     * dashboard, the import and each game's builds browser, and every character with builds - its builds page, with the
     * day one of its builds last changed, by a file or by hand. While a sign-in is required, none: those pages send
     * everybody to the IAM. An account's pages are a reader's own, never listed (ROBOTS_DISALLOW).
     *
     * @return list<string|array{path: string, lastmod: ?\DateTimeImmutable}>
     */
    public static function publicEntries(App $app): array
    {
        if ($app->get(SignInRule::class)->required()) {
            return [];
        }
        $entries = ['/', '/import'];
        foreach (Game::cases() as $game) {
            $entries[] = '/builds/' . $game->value;
            foreach ($app->get(Builds::class)->charactersWithBuilds($game) as $row) {
                $entries[] = ['path' => '/builds/' . $game->value . '/' . $row['key'], 'lastmod' => Db::time($row['changed_at'])];
            }
        }

        return $entries;
    }
}
