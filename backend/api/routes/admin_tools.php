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
use App\Admin\AdminTools;
use App\App;
use App\Auth\Role;

/**
 * The anotoki family's admin tools (anotoki/lib 0.4: their routes, on this site's - App\Admin\AdminTools), for
 * the site's ADMIN alone: an EDITOR is refused every one (403 admin_only), as is a person with no role (and
 * nobody: 401 sign_in_required). Each group guards itself too (the library asks AdminContext's isAdmin before
 * anything else); this site's own check answers first, in its words.
 *
 *   /api/admin/errors     the error log: GET ?days=&level=&status=&search= (the report), GET /{fingerprint} (every
 *                         occurrence), DELETE ?before=YYYY-MM-DD (clears it - audited errors.cleared)
 *   /api/admin/audit      the audit log: GET ?actor=&action=&target_type=&target_id=&from=&to=&page=, GET /facets
 *   /api/admin/feedback   what visitors sent: GET ?kind=&status=&section=&search=&page=, GET /facets, GET /unread
 *                         (the admin panel's badge), GET /{id} (opening a new one makes it read), PUT /{id}/status,
 *                         DELETE /{id} (both audited)
 *   /api/admin/backups    GET (the list and the status), GET /proof, POST (one made now), GET|DELETE /{id},
 *                         POST /{id}/ticket (a single-use download link), GET /{id}/preview (what a restore would
 *                         take away), POST /{id}/restore {confirm: the id's last six} - making, deleting,
 *                         downloading and restoring ask for a sign-in of the last ten minutes (403
 *                         reauth_required: the page offers "Sign in again"), each audited
 *   GET /api/admin/backups/{id}/file?ticket=   the download itself - DELIBERATELY outside every check: a
 *                         browser's navigation sends no token, and the single-use, 60-second ticket is the check
 *
 * The error log and the backups answer while an update waits (they need no table; a backup just before Apply
 * is the point - restore stays closed): open in the site gate, their ADMIN check from the token alone
 * (requireTokenRole(): no row of users read or written). The audit log and feedback need their tables
 * (the library's anotoki_admin set): until it is applied they answer 503 feature_unavailable.
 */
return function (Slim\App $slim, App $app): void {
    $context = $app->get(AdminContext::class);
    $audit = $app->get(AuditLog::class);
    $names = static fn (array $ids): array => AdminTools::names($app, $ids);

    ErrorLogRoutes::admin($slim, '/api/admin/errors', $app->get(ErrorLog::class), $context, $audit)
        ->add(requireTokenRole($app, Role::ADMIN));

    BackupsRoutes::admin($slim, '/api/admin/backups', $app->get(Backups::class), $context)
        ->add(requireTokenRole($app, Role::ADMIN));
    BackupsRoutes::file($slim, '/api/admin/backups', $app->get(Backups::class), $context);

    AuditRoutes::admin($slim, '/api/admin/audit', $audit, $context, $names)
        ->add(requireRole($app, Role::ADMIN));

    FeedbackRoutes::admin($slim, '/api/admin/feedback', $app->get(Feedback::class), $context, $audit, $names)
        ->add(requireRole($app, Role::ADMIN));
};
