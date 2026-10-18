<?php

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Audit\Http\AuditRoutes;
use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Backups\Http\BackupsRoutes;
use Anotoki\Lib\ErrorLog\ErrorLog;
use Anotoki\Lib\ErrorLog\Http\ErrorLogRoutes;
use Anotoki\Lib\Feedback\Feedback;
use Anotoki\Lib\Feedback\Http\FeedbackRoutes;
use App\App;
use App\Auth\Role;

/**
 * The anotoki family's admin tools (anotoki/lib 0.5: their routes, on this site's - App\Admin\AdminTools), for
 * the site's ADMIN alone: an EDITOR is refused every one (403 admin_only), as is a person with no role (and
 * nobody: 401 sign_in_required). Each group guards itself too (the library asks AdminContext's isAdmin before
 * anything else); this site's own check answers first, in its words. Who did what is named by AdminContext's
 * names (App\Admin\AdminTools::names(): the audit log's and feedback's people, a backup's maker).
 *
 *   /api/admin/errors     the error log: GET ?days=&level=&status=&search= (the report), GET /{fingerprint} (every
 *                         occurrence), DELETE ?before=YYYY-MM-DD (clears it - audited errors.cleared)
 *   /api/admin/audit      the audit log: GET ?actor=&action=&target_type=&target_id=&from=&to=&page=, GET /facets
 *   /api/admin/feedback   what visitors sent: GET ?kind=&status=&section=&search=&page=, GET /facets, GET /unread
 *                         (the admin panel's badge), GET /{id} (opening a new one makes it read), PUT /{id}/status,
 *                         DELETE /{id} (both audited)
 *   /api/admin/backups    GET (the list - each with its maker's name, created_by_name, and whether it is sealed -
 *                         and the status), GET /proof, POST (one made now), GET|DELETE /{id}, POST /{id}/ticket (a
 *                         single-use download link), GET /{id}/preview (what a restore would take away), POST
 *                         /{id}/restore {confirm: the id's last six} - making, deleting, downloading and restoring
 *                         ask for a sign-in of the last ten minutes (403 reauth_required: the page offers "Sign in
 *                         again"), each audited; only a backup made here (sealed under this installation's key)
 *                         is restored here - any other 422 archive_invalid, for php backup.php --extract
 *   GET /api/admin/backups/{id}/file?ticket=   the download itself - DELIBERATELY outside every check: a
 *                         browser's navigation sends no token, and the single-use, 60-second ticket is the check
 *
 * The error log and the backups answer while an update waits (they need no table; a backup just before Apply
 * is the point - restore stays closed): open in the site gate, their ADMIN check from the token alone
 * (requireTokenRole(): no row of users read or written - the backups' list asks for its makers' names, and an
 * answer without them is all a database without users gives). The audit log and feedback need their tables
 * (the library's anotoki_admin set): until it is applied they answer 503 feature_unavailable.
 *
 * And the error log's one public route, the errors the pages meet in the browser (@anotoki/lib/error-report, the
 * owner's decision of 2026-10-11: every site's error log holds its pages' errors too):
 *
 *   POST /api/client-errors {type?, message, page?, stack?}   -> 204, written as source "client", every text
 *                         redacted again (the page scrubbed it first). JSON only (415), 8 KB at most (413), a
 *                         message (422). The library's limits: 20 an hour from one sender - a signed-in person by
 *                         their account, anybody else by their address bucket (App\Web\ClientAddress's rule) - and
 *                         500 a day from the visitors together, 500 from the people signed in (429 too_many,
 *                         retry_after). Behind this site's rule for a public route (optionalSignIn(): the person
 *                         from their token, a token that does not verify 401 invalid_token, nobody 401
 *                         sign_in_required while the IAM requires sign-in here) and the site gate (closed while
 *                         an update waits, as every route that is not one of its open paths).
 */
return function (Slim\App $slim, App $app): void {
    $context = $app->get(AdminContext::class);
    $audit = $app->get(AuditLog::class);

    ErrorLogRoutes::admin($slim, '/api/admin/errors', $app->get(ErrorLog::class), $context, $audit)->add(requireTokenRole($app, Role::ADMIN));
    ErrorLogRoutes::client($slim, '/api/client-errors', $app->get(ErrorLog::class), $context)->add(optionalSignIn($app));

    BackupsRoutes::admin($slim, '/api/admin/backups', $app->get(Backups::class), $context)->add(requireTokenRole($app, Role::ADMIN));
    BackupsRoutes::file($slim, '/api/admin/backups', $app->get(Backups::class), $context);

    AuditRoutes::admin($slim, '/api/admin/audit', $audit, $context)->add(requireRole($app, Role::ADMIN));

    FeedbackRoutes::admin($slim, '/api/admin/feedback', $app->get(Feedback::class), $context, $audit)->add(requireRole($app, Role::ADMIN));
};
