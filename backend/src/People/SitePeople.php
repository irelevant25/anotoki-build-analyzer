<?php

namespace App\People;

use Anotoki\Lib\Admin\Schema as AdminSchema;
use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Auth\IamClient;
use Anotoki\Lib\Feedback\Feedback;
use Anotoki\Lib\People\People;
use Anotoki\Lib\People\PeopleColumn;
use Anotoki\Lib\People\PeopleTable;
use App\App;
use App\Db\Db;
use App\Repositories\Users;
use App\Services\Translations\SiteTranslations;
use Closure;
use PDO;
use Psr\Http\Message\ServerRequestInterface;

/**
 * This site's people as the anotoki library's people module (Anotoki\Lib\People) sees them: its own `users`
 * table - one row per person the IAM signed in here, the IAM's id as its id (visitors' rows have negative ids,
 * and the library never reads or writes them) - through a mapping, never a table of the library's:
 *
 *   name       `name` (VARCHAR(100) NOT NULL): the token's preferred_username, "user {id}" for a token without
 *   first seen `created_at`
 *   last seen  `last_seen_at` - a visitor's only, before; a person's too now (written at most every five
 *              minutes; users_visitor_check has always allowed it)
 *   updated    `updated_at`: when the name changed
 *   deleted    `anonymized_at` (migration 007): when the IAM deleted the account
 *
 * The scrub (the library's Scrubber, after an answer at most hourly - PeopleUpkeep in api/middleware/upkeep.php -
 * and at once after a restore, People::afterRestore()): every account the IAM deleted, thirty days after its owner
 * asked, is nobody's here from then on - its row's name the IAM's placeholder `deleted-{id}`, and the copies of who
 * they were blanked by the hook below; their game accounts and imports stay, nobody's now (the owner's rule: the
 * data stays), and so do the builds imports they made, which keep their id and never their name. The hooks run inside the account's transaction on the scrub's connection - the site's own, or a
 * restore's after it committed - so they write on the $pdo they are given, never on the site's own (App\Db\Db),
 * and are idempotent: an id comes again.
 *
 * The People page (the library's, @anotoki/lib/people/page; its routes api/routes/people.php) shows each
 * person's game accounts, imports and last import beside the library's columns, the visitors apart, and
 * deletes a person's data (deleteHook(): deleteData()) - which a restore does again for every delete made since
 * its backup (People::replay(), App\Admin\AdminTools::backups()).
 */
final class SitePeople
{
    public static function table(): PeopleTable
    {
        return new PeopleTable(name: 'name', firstSeen: 'created_at', lastSeen: 'last_seen_at', updatedAt: 'updated_at', nameFallback: static fn(int $sub): string => "user {$sub}");
    }

    public static function make(App $app): People
    {
        return new People(
            self::table(),
            static fn(): PDO => $app->db()->raw(),
            $app->get(IamClient::class),
            hooks: [
                // What the library's admin tools keep of the person: the address and browser a feedback message
                // was sent with (the message stays: it is the site's), the details naming them in the audit log -
                // both on the scrub's connection, in the account's transaction.
                static function (PDO $pdo, int $sub): void {
                    if (AdminSchema::has($pdo, AdminSchema::FEEDBACK_TABLE)) {
                        Feedback::forgetPeopleOn($pdo, [$sub]);
                    }
                    if (AdminSchema::has($pdo, AdminSchema::AUDIT_TABLE)) {
                        (new AuditLog(static fn(): PDO => $pdo))->scrubPeople([$sub]);
                    }
                },
            ],
        );
    }

    /**
     * The People page's columns of this site's own: each person's game accounts, imports (a failed one too)
     * and the time of their last import - for one page of people at a time.
     *
     * @return list<PeopleColumn>
     */
    public static function columns(): array
    {
        // [sub => value] for one page of people; with $none, every one of them has a value (a count: 0).
        $values = static function (string $sql, mixed $none = null): \Closure {
            return static function (PDO $pdo, array $subs) use ($sql, $none): array {
                $subs = array_map('intval', $subs);
                $statement = $pdo->prepare($sql);
                $statement->execute(['{' . implode(',', $subs) . '}']);
                $found = $statement->fetchAll(PDO::FETCH_KEY_PAIR);

                return $none === null ? $found : array_replace(array_fill_keys($subs, $none), $found);
            };
        };

        return [
            new PeopleColumn('accounts', 'Game accounts', 'count', $values('SELECT user_id, count(*) FROM accounts WHERE user_id = ANY (CAST(? AS bigint[])) GROUP BY user_id', 0)),
            new PeopleColumn('imports', 'Imports', 'count', $values('SELECT user_id, count(*) FROM import_runs WHERE user_id = ANY (CAST(? AS bigint[])) GROUP BY user_id', 0)),
            // Unix seconds: the library answers a time column as ISO 8601 UTC; none for somebody who never imported.
            new PeopleColumn(
                'last_import',
                'Last import',
                'time',
                $values(
                    'SELECT user_id, floor(extract(epoch FROM max(created_at)))::bigint
                   FROM import_runs WHERE user_id = ANY (CAST(? AS bigint[])) GROUP BY user_id',
                ),
            ),
        ];
    }

    /**
     * The visitors line: how many visitors there are and how many game accounts they keep (a browser's, not
     * a person's: never listed, never deleted from the People page - the 30-day clean-up takes them).
     *
     * @return array{count: int, accounts: int}
     */
    public static function visitors(App $app): array
    {
        return $app->get(Users::class)->visitorCounts();
    }

    /**
     * The People page's delete hook (PeopleRoutes' `delete:`) - and the one a restore does again, for every delete
     * the audit log has since its backup (People::restoreHooks(), App\Admin\AdminTools::backups()): deleteData() on
     * the connection it is given. Its request is the page's; null when a restore does it again, which changes
     * nothing here - the database's part is all this site does either way.
     *
     * @return Closure(PDO, int, ?ServerRequestInterface): ?array{deleted: array<string, int>}
     */
    public static function deleteHook(): Closure
    {
        return static fn(PDO $pdo, int $sub, ?ServerRequestInterface $request = null): ?array => self::deleteData($pdo, $sub);
    }

    /**
     * The People page's Delete: an IAM person's row and, by the foreign keys, their game accounts with
     * everything imported into them and their import runs - their anotoki account stays, and signing in
     * again starts them afresh, with nothing. The builds they imported and the strings they saved stay, by
     * nobody (build_imports.imported_by goes NULL with the row), and so does what they sent through the feedback
     * form - but nothing of the person stays with them: their feedback loses the address and browser it was sent
     * with (the library does that itself, in the same transaction). Null for an id that is no person's here (a
     * visitor's row never is one).
     *
     * It runs inside the library's transaction (People::delete(), anotoki-lib 0.5.0), on the $pdo that holds it:
     * the People page's connection - or a restore's, which holds every restored table locked until it commits.
     * So ALL of it is on $pdo - never on the site's own connection (the container's Db, Users or Translations),
     * which in a restore would wait on the restore's locks while the restore waits on this - and it begins,
     * commits or rolls back nothing (the library refuses a hook that does).
     *
     * @return array{deleted: array<string, int>}|null
     */
    public static function deleteData(PDO $pdo, int $sub): ?array
    {
        $deleted = (new Users(new Db($pdo)))->deletePerson($sub);
        if ($deleted === null) {
            return null;
        }
        // The strings they saved stay, saved by nobody.
        SiteTranslations::make($pdo)->forgetWriter($sub);

        return [
            'deleted' => [
                'Game accounts' => $deleted['accounts'],
                'Imports' => $deleted['import_runs'],
            ],
        ];
    }
}
