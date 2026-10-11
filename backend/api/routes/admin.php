<?php

use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Auth\Http\Identify;
use Anotoki\Lib\Migrations\Http\MigrationsRoutes;
use Anotoki\Lib\Migrations\Migrator;
use App\App;
use App\Auth\Role;
use App\Db\Migrations;
use App\Support\Log;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Schema changes on a host without a shell (admins only; the admin panel's Migrations page): upload the new
 * files in migrations/ by FTP, then apply them here. The anotoki family's routes (anotoki/lib:
 * MigrationsRoutes), on the same engine as `php migration.php` and setup.php (App\Db\Migrations) - the library's
 * admin tools' set `anotoki_admin`, this site's set `build_analyzer`, then the library's `anotoki_translations`
 * and `anotoki_people` (in vendor/, uploaded with it):
 *
 *   GET  /api/admin/migrations              {sets, applied: [{set, name, applied_at}], pending: [{set, name,
 *                                           ready, blocked}], missing: [{set, name, applied_at}]}
 *   GET  /api/admin/migrations/file?set=&name=   {set, name, sql} - one file's SQL; 404 not_found otherwise
 *   POST /api/admin/migrations/apply        {applied: [{set, name}], failed: {set, name}|null, error, note}
 *                                           - 200 whether or not a file failed (the page says where it
 *                                           stopped: the failed file rolled back whole, the ones before it
 *                                           applied); 409 busy while another apply runs (one at a time,
 *                                           whoever starts it: every apply takes the same advisory lock).
 *                                           Before anything is applied, a backup of the database as it is
 *                                           (the library's backups: its name is the note); one that cannot be
 *                                           made stops the Apply with nothing applied
 *
 * These routes answer while an update waits - the site gate keeps them open (api/middleware/site_gate.php) -
 * and their ADMIN check reads the token alone (requireTokenRole()): on a database whose migrations have not
 * run, they must not need a table one of them makes. After an Apply, the audit log's entries that waited for
 * its table go in (AuditLog::flushPending()), and what was applied is an entry of its own (migrations.applied).
 */
return function (Slim\App $slim, App $app): void {
    // A file as the log names it: the site's by its name, the library's with its set in front (anotoki_translations/...).
    $name = static fn (array $file): string => ($file['set'] === Migrations::SET ? '' : $file['set'].'/').$file['name'];
    MigrationsRoutes::admin($slim, '/api/admin/migrations', fn (): Migrator => $app->get(Migrator::class), function (Request $request, array $result) use ($app, $name): void {
        // A failing migration is reported, not thrown: on a host without a shell the admin has only the page's
        // answer to go on, and the log (FTP-only) keeps it.
        if ($result['failed'] !== null) {
            Log::error("Migration {$name($result['failed'])} failed: {$result['error']}");
        }
        foreach ($result['applied'] as $file) {
            Log::info("Migration {$name($file)} applied.");
        }

        $audit = $app->get(AuditLog::class);
        $audit->flushPending();
        if ($result['applied'] !== [] || $result['failed'] !== null) {
            $audit->record($request, Identify::person($request)?->sub, 'migrations.applied', null, null, array_filter([
                'files' => array_map($name, $result['applied']),
                'failed' => $result['failed'] !== null ? $name($result['failed']) : null,
            ], static fn (mixed $value): bool => $value !== null));
        }
    })
        // An apply on the host is not cut short when the browser goes, and has five minutes.
        ->add(function (Request $request, RequestHandlerInterface $handler): Response {
            if ($request->getMethod() === 'POST') {
                ignore_user_abort(true);
                @set_time_limit(300);
            }

            return $handler->handle($request);
        })
        ->add(requireTokenRole($app, Role::ADMIN));
};
