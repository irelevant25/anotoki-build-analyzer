<?php

use Anotoki\Lib\Translations\Translations;
use App\App;
use App\Auth\Role;
use App\Db\Db;
use App\Repositories\Users;
use App\Support\Clock;
use App\Web\HttpError;
use App\Web\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteCollectorProxy;

/**
 * The people who have used the site, and deleting a person's data (admins only). A person is somebody the
 * anotoki IAM signed in here - their row is made at their first request - and their data is their game
 * accounts (with everything imported into them) and their import runs. Deleting it leaves their anotoki
 * account alone: they can sign in again, with nothing here. The syncs they started and the strings they
 * saved stay, by nobody.
 *
 *   GET    /api/admin/people        {people: [{id, name, accounts, import_runs, created_at, last_import_at}],
 *                                    visitors: {count, accounts}} - visitors (a browser's, not a person's)
 *                                    are counted apart and are never listed
 *   DELETE /api/admin/people/{id}   -> {accounts, import_runs}: what went; 404 for an id that is no person's.
 *                                    An admin may delete their own data too.
 */
return function (Slim\App $slim, App $app): void {
    $slim->group('/api/admin/people', function (RouteCollectorProxy $group) use ($app) {
        $group->get('', function (Request $request, Response $response) use ($app): Response {
            $users = $app->get(Users::class);
            $people = array_map(fn (array $person) => array_replace($person, [
                'created_at' => Clock::iso(Db::time($person['created_at'])),
                'last_import_at' => Clock::iso(Db::time($person['last_import_at'])),
            ]), $users->withCounts());

            return Json::send($response, ['people' => $people, 'visitors' => $users->visitorCounts()]);
        });

        $group->delete('/{id:[0-9]+}', function (Request $request, Response $response, array $args) use ($app): Response {
            $id = (int) $args['id'];
            $deleted = $app->get(Db::class)->transaction(function () use ($app, $id): ?array {
                $deleted = $app->get(Users::class)->deletePerson($id);
                if ($deleted !== null) {
                    // On the same connection, in this transaction: the strings they saved stay, saved by nobody.
                    $app->get(Translations::class)->forgetWriter($id);
                }

                return $deleted;
            });

            return Json::send($response, $deleted ?? throw HttpError::notFound());
        });
    })->add(requireRole($app, Role::ADMIN));
};
