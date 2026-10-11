<?php

namespace App\Admin;

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Auth\Http\Identify;
use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\Backups\Copy;
use Anotoki\Lib\ErrorLog\ErrorLog;
use Anotoki\Lib\Feedback\Feedback;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\People\Http\PeopleUpkeep;
use Anotoki\Lib\People\People;
use Anotoki\Lib\Support\Refusal;
use App\App;
use App\Auth\RequestCaller;
use App\Db\Migrations;
use App\Web\AfterResponse;
use App\Web\ClientAddress;
use App\Web\Json;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App as SlimApp;
use Throwable;

/**
 * The anotoki family's admin tools (anotoki/lib 0.4: Anotoki\Lib\ErrorLog, Audit, Feedback, Backups), made for
 * this site - for its ADMIN alone (the owner's decision of 2026-10-07: EDITORs see none of them). Their routes
 * are api/routes/admin_tools.php and api/routes/feedback.php, their pages the library's on /admin/errors,
 * /admin/audit, /admin/feedback and /admin/backups.
 *
 * What this site tells them once (context()): who acts (the token's person, never a body), whether that is the
 * site's ADMIN (from the token), the proof a backup's download, making, deleting or restoring asks for (a
 * sign-in of the last ten minutes, from the token's auth_time - the page offers "Sign in again"), the address
 * bucket its limits count (App\Web\ClientAddress: the trusted proxy's header, an IPv6 /64), and its error body
 * {message, code}.
 *
 *   error log   storage/logs/errors-YYYY-MM-DD.jsonl (UTC days, 30 kept, 64 MB at most): what failed - an
 *               exception jsonErrors() answered 500, PHP's warnings and fatal errors, and answers worth seeing
 *               (404, 405, 413, 415, 429, a 5xx but 503: every 503 here is a decided "not now" - the site gate
 *               while an update waits, a feature whose migration waits - not a failure). storage/logs/app.log
 *               stays for the site's info lines.
 *   audit log   the table anotoki_audit_log (the library's anotoki_admin/001): what was done in the admin panel
 *               - sources switched, syncs started and cancelled, a source's builds imported, the strings and
 *               languages, a person's data deleted, migrations applied, and the tools' own (errors cleared,
 *               backups, feedback). No address is kept with an entry.
 *   feedback    the table anotoki_feedback (anotoki_admin/002): what visitors send from the form at the foot of
 *               every page - no mail; the admin panel counts what is new. Nothing of where a message came from
 *               is kept with it; the limits count apart, a day, in storage/cache.
 *   backups     storage/backups/*.anotoki-backup: the whole database by COPY (no pg_dump on the host), daily
 *               after an answer (14 kept), before every update (5), before a restore (5), and by hand.
 */
final class AdminTools
{
    /** The feedback form's places, as the admin page shows them (the form words each in the visitor's language). */
    public const FEEDBACK_SECTIONS = ['Dashboard', 'Import', 'Accounts', 'Builds'];

    public static function context(App $app): AdminContext
    {
        return new AdminContext(
            actorId: static fn (ServerRequestInterface $request): ?int => Identify::person($request)?->sub,
            proof: AdminContext::recentSignIn(static fn (ServerRequestInterface $request): ?int => Identify::person($request)?->authTime),
            clientKey: static fn (ServerRequestInterface $request): string => ClientAddress::counted($request, $app->config),
            error: static fn (ResponseInterface $response, Refusal $refusal): ResponseInterface => Json::message(
                $response,
                $refusal->status,
                $refusal->message,
                ['code' => $refusal->code] + $refusal->extra,
            ),
            isAdmin: static fn (ServerRequestInterface $request): bool => Identify::person($request)?->isAdmin() ?? false,
        );
    }

    /** The error log - with the Slim app that answers the requests it names, each line's route too. */
    public static function errorLog(App $app, ?SlimApp $slim = null): ErrorLog
    {
        return new ErrorLog($app->config->storage('logs'), [
            'root' => $app->config->path(),
            'context' => $app->get(AdminContext::class),
            // Who asked: the token's person - also for an exception jsonErrors() writes down, which has the request
            // only as it came in, before its route group identified it (App\Auth\RequestCaller).
            'actorId' => static fn (ServerRequestInterface $request): ?int => Identify::person($request)?->sub ?? $app->get(RequestCaller::class)->sub,
            'responses' => static fn (int $status): bool => ($status >= 500 && $status !== 503) || in_array($status, ErrorLog::DEFAULT_STATUSES, true),
        ] + ($slim === null ? [] : ['app' => $slim]));
    }

    public static function auditLog(App $app): AuditLog
    {
        return new AuditLog(static fn (): PDO => $app->db()->raw(), [
            // While anotoki_admin/001 waits (an upload before Apply), entries wait here; after every Apply they go in.
            'pendingFile' => $app->config->storage('logs/audit-pending.jsonl'),
            // The page's actor filter by a name: the people whose name holds it (never a visitor's row).
            'actorIds' => static fn (string $name): array => array_map('intval', $app->db()->column(
                'SELECT id FROM users WHERE id > 0 AND strpos(lower(name), lower(?)) > 0 ORDER BY id LIMIT 50',
                [$name],
            )),
        ]);
    }

    /**
     * The people's names by id, as this site keeps them (the IAM's preferred_username at their last request):
     * who acted, on the audit log's and the feedback's pages. An id it does not know shows no name.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public static function names(App $app, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach ($app->db()->all('SELECT id, name FROM users WHERE id = ANY (CAST(? AS bigint[]))', ['{'.implode(',', $ids).'}']) as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    public static function feedback(App $app): Feedback
    {
        return new Feedback(static fn (): PDO => $app->db()->raw(), [
            'storage' => $app->config->storage(),
            'sections' => self::FEEDBACK_SECTIONS,
            'defer' => static fn (callable $work) => AfterResponse::add($work),
        ]);
    }

    public static function backups(App $app): Backups
    {
        $config = $app->config;

        return new Backups(static fn (): PDO => self::copyConnection($app), $config->storage('backups'), [
            'site' => 'build-analyzer',
            // Read only (the files applied, each file's SQL): the backup keeps the schema it was made with.
            'migrator' => static fn (PDO $pdo): Migrator => Migrations::migrator($pdo),
            // The day's backup waits while an update waits - as the site gate sees it: the app's own Migrator.
            'ready' => static fn (PDO $pdo): bool => $app->get(Migrator::class)->applicable() === [],
            'lock' => Migrations::LOCK,
            // Never restored: what the site counts and locks by the minute (the throttles, the syncs' locks).
            'keep' => ['cache', 'cache_locks'],
            'audit' => $app->get(AuditLog::class),
            'defer' => static fn (callable $work) => AfterResponse::add($work),
            'onError' => static function (Throwable $e) use ($app): void {
                $app->get(ErrorLog::class)->record($e);
            },
            // A restore brings back the people's scrub as it stood then: it runs again at once (scrubAfterRestore()).
            'afterRestore' => static function () use ($app): void {
                self::scrubAfterRestore($app);
            },
        ]);
    }

    /**
     * After a restore, the scrub of the IAM's deleted accounts at once: the restored database brings back the
     * scrub's place of its day, and with it the names of whoever the IAM deleted since. A scrub that does not
     * finish - the IAM out of reach, its time up with more to read, a run already going - is the restore's failure
     * to say (the library's after_restore_error: the Backups page's note; the error log), never a restore that
     * looks complete with a deleted person named again; and the hourly upkeep's stamp goes then, so that the next
     * request runs the scrub again instead of an hour later - after this answer: this request's own upkeep would
     * claim the hour again at once, and meet the IAM as it is this moment (the survey's surveyScrubAfterRestore()).
     * On the site's own connection, as every scrub: its hooks write in the scrub's transactions.
     *
     * What was deleted on the People page after the backup was made comes back with it: replaying those deletes is
     * the library's 0.4.2 (docs/operations.md, "Backups").
     */
    public static function scrubAfterRestore(App $app): void
    {
        $people = $app->get(People::class);
        $run = $people->scrubber()->run();
        if ($run['state'] === 'done') {
            return;
        }

        $directory = $people->iam->cacheDirectory();
        if ($directory !== null) {
            AfterResponse::add(static function () use ($directory): void {
                @unlink($directory.'/'.PeopleUpkeep::STAMP);
            });
        }
        $why = match ($run['state']) {
            'unreachable', 'stopped' => lcfirst(rtrim((string) $run['error'], '.')),
            'more' => 'its time ran out with more of the IAM\'s list to read',
            'busy' => 'another check was running',
            'not_ready' => 'what it needs is not there: '.implode('; ', $run['problems']),
            default => 'it ended '.$run['state'],
        };

        throw new RuntimeException(
            "the accounts the IAM deleted were not checked again ({$why}). Whoever it deleted since this backup was made may be named here "
            .'until they are: the check runs again with the next request, and at once with Check now on the People page.'
        );
    }

    /**
     * A connection the backups COPY on: a Pdo\Pgsql where PHP has the class (8.4+), so COPY raises no
     * deprecation - in UTC, as every connection of the site.
     */
    public static function copyConnection(App $app): PDO
    {
        $database = (array) $app->config->get('database');
        $pdo = Copy::connect(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $database['host'] ?? '', $database['port'] ?? '', $database['database'] ?? ''),
            (string) ($database['username'] ?? ''),
            (string) ($database['password'] ?? ''),
            [PDO::ATTR_TIMEOUT => 5],
        );
        $pdo->exec("SET TIME ZONE 'UTC'; SET client_encoding = 'UTF8'");

        return $pdo;
    }
}
