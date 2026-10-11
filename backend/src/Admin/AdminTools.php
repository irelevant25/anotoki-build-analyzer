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
use Anotoki\Lib\People\People;
use Anotoki\Lib\Support\Refusal;
use App\App;
use App\Auth\RequestCaller;
use App\Db\Migrations;
use App\People\SitePeople;
use App\Web\AfterResponse;
use App\Web\ClientAddress;
use App\Web\Json;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;
use Throwable;

/**
 * The anotoki family's admin tools (anotoki/lib 0.5: Anotoki\Lib\ErrorLog, Audit, Feedback, Backups), made for
 * this site - for its ADMIN alone (the owner's decision of 2026-10-07: EDITORs see none of them). Their routes
 * are api/routes/admin_tools.php and api/routes/feedback.php, their pages the library's on /admin/errors,
 * /admin/audit, /admin/feedback and /admin/backups.
 *
 * What this site tells them once (context()): who acts (the token's person, never a body), whether that is the
 * site's ADMIN (from the token), the proof a backup's download, making, deleting or restoring asks for (a
 * sign-in of the last ten minutes, from the token's auth_time - the page offers "Sign in again"), the proxies whose
 * CF-Connecting-IP names a request's address (config/app.php's client_address: Cloudflare's ranges - the library's
 * one rule, Support\ClientAddress, which the site's own counters read by too: App\Web\ClientAddress),
 * the people's names by id (the backups' maker, the audit log's and feedback's people) and its error body
 * {message, code}.
 *
 *   error log   storage/logs/errors-YYYY-MM-DD.jsonl (UTC days, 30 kept, 64 MB at most): what failed - an
 *               exception jsonErrors() answered 500, PHP's warnings and fatal errors, the errors the pages meet
 *               in the browser (POST /api/client-errors), and answers worth seeing (404, 405, 413, 415, 429, every
 *               5xx) - but an answer that says a state, not a failure (EXPECTED_ANSWERS: the site gate while an
 *               update waits, a feature whose table waits). storage/logs/app.log stays for the site's info lines.
 *   audit log   the table anotoki_audit_log (the library's anotoki_admin/001): what was done in the admin panel
 *               - sources switched, syncs started and cancelled, a source's builds imported, the strings and
 *               languages, a person's data deleted, migrations applied, and the tools' own (errors cleared,
 *               backups, feedback). No address is kept with an entry.
 *   feedback    the table anotoki_feedback (anotoki_admin/002): what visitors send from the form at the foot of
 *               every page - no mail; the admin panel counts what is new. Nothing of where a message came from
 *               is kept with it; the limits count apart, a day, in storage/cache.
 *   backups     storage/backups/*.anotoki-backup: the whole database by COPY (no pg_dump on the host), daily
 *               after an answer (14 kept), before every update (5), before a restore (5), and by hand - each
 *               sealed under this installation's key (storage/anotoki-lib.key): only a backup made here is
 *               restored here. Where that key can be neither read nor made, a backup is still made, unsealed (the
 *               error log says so), and is restored offline only (backup.php --extract). A restore does again what
 *               the People page and the Feedback page deleted since its backup, then checks the IAM's deleted
 *               accounts at once (People::restoreHooks()).
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
            error: static fn (ResponseInterface $response, Refusal $refusal): ResponseInterface => Json::message(
                $response,
                $refusal->status,
                $refusal->message,
                ['code' => $refusal->code] + $refusal->extra,
            ),
            isAdmin: static fn (ServerRequestInterface $request): bool => Identify::person($request)?->isAdmin() ?? false,
            names: static fn (array $ids): array => self::names($app, $ids),
            // Where a request comes from, for the feedback's limits and the error log's client hash: the library's
            // rule - CF-Connecting-IP (its default clientHeader, never another) believed only on a connection from
            // these - over the same list as the site's own counters. Without it the library would read REMOTE_ADDR
            // alone: Cloudflare's edge, for everybody.
            trustedProxies: ClientAddress::trustedProxies($app->config),
        );
    }

    /**
     * The answers that say a state the site is in, not a failure - never written into the error log, however often
     * they come: the library's own (update_pending, the site gate's while an update waits; not_set_up;
     * feature_unavailable, an admin tool whose table waits) and this site's of the same kind, each a waiting
     * migration's table (visitors_unavailable, translations_unavailable, people_unavailable). Any other answer of a
     * status worth seeing is written - a 503 that is a failure too (feedback's storage_unavailable: its limits
     * cannot be counted; the move's visitor_move_retry).
     */
    public const EXPECTED_ANSWERS = [...ErrorLog::EXPECTED_CODES, 'visitors_unavailable', 'translations_unavailable', 'people_unavailable'];

    /**
     * The error log - with the Slim app that answers the requests it names, each line's route too. The family's rule
     * (anotoki-lib 0.5.0): every 5xx and the answers worth seeing (404, 405, 413, 415, 429), but EXPECTED_ANSWERS.
     */
    public static function errorLog(App $app, ?SlimApp $slim = null): ErrorLog
    {
        return new ErrorLog($app->config->storage('logs'), [
            'root' => $app->config->path(),
            'context' => $app->get(AdminContext::class),
            // Who asked: the token's person - also for an exception jsonErrors() writes down, which has the request
            // only as it came in, before its route group identified it (App\Auth\RequestCaller).
            'actorId' => static fn (ServerRequestInterface $request): ?int => Identify::person($request)?->sub ?? $app->get(RequestCaller::class)->sub,
            'expected' => self::EXPECTED_ANSWERS,
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
     * who acted, on the audit log's and the feedback's pages, and who made a backup (the Backups page's "By"). An
     * id it does not know shows no name.
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
            // What a restore must do again (the library's People, 0.5.0): inside its transaction, before anybody sees
            // the rows, every delete of the People page made since the backup - its hook on the restore's connection
            // (SitePeople::deleteHook()), and the feedback deleted since; once it committed, the scrub of the IAM's
            // deleted accounts at once - one that cannot finish (the IAM out of reach) is the answer's
            // after_restore_error, and the upkeep's hourly stamp goes after this answer, so the next request
            // checks again.
            ...$app->get(People::class)->restoreHooks(
                delete: SitePeople::deleteHook(),
                defer: static fn (callable $work) => AfterResponse::add($work),
            ),
        ]);
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
