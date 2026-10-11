<?php

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\People\Http\PeopleRoutes;
use Anotoki\Lib\People\People;
use App\App;
use App\Auth\Role;
use App\People\SitePeople;

/**
 * The People page's API (admins only) - the anotoki library's people module (PeopleRoutes), on this site's
 * people (App\People\SitePeople). A person is somebody the anotoki IAM signed in here - their row is made at
 * their first request - and their data is their game accounts (with everything imported into them) and their
 * import runs. Deleting it leaves their anotoki account alone: they can sign in again, with nothing here. The
 * syncs they started and the strings they saved stay, by nobody.
 *
 *   GET    /api/admin/people?q=&page=   {people: [{sub, name, first_seen_at, last_seen_at, anonymized_at,
 *                                       iam_url, accounts, imports, last_import}], columns, total, page,
 *                                       page_size, visitors: {count, accounts}, sync, ready, problems} - 50 a
 *                                       page, the most recently seen first; q finds a name or an IAM id.
 *                                       Visitors (a browser's, not a person's) are counted apart, never listed
 *   DELETE /api/admin/people/{sub}      -> {deleted: {"Game accounts": n, "Imports": n}}; 404 not_found for an id
 *                                       that is no person's. An admin may delete their own data too. Asks only
 *                                       the page's question - no recent sign-in (the owner's decision of
 *                                       2026-10-11) - and is audited (person.data_deleted), the entry written in
 *                                       the delete's own transaction: no delete without it, for a restore does
 *                                       every delete made since its backup again by it
 *   POST   /api/admin/people/sync       the scrub of the IAM's deleted accounts, now (audited: people.synced)
 *
 * The library's group guards itself (the site's ADMIN, from the token); this site's own check answers first,
 * in its words (403 admin_only).
 */
return function (Slim\App $slim, App $app): void {
    PeopleRoutes::admin(
        $slim,
        '/api/admin/people',
        $app->get(People::class),
        columns: SitePeople::columns(),
        visitors: fn (PDO $pdo): array => SitePeople::visitors($app),
        // The library's transaction around it, the person's feedback forgotten in it (People::delete()).
        delete: SitePeople::deleteHook(),
        error: siteRefusal(...),
        audit: $app->get(AuditLog::class),
        context: $app->get(AdminContext::class),
    )->add(requireRole($app, Role::ADMIN));
};
