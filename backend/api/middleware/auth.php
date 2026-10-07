<?php

use App\App;
use App\Auth\IamGuard;
use App\Auth\Role;
use App\Auth\SignInPolicy;
use App\Auth\Visitors;
use App\Cache\Cache;
use App\Models\User;
use App\Web\ClientAddress;
use App\Web\HttpError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Who a request is, and what they may do. A person comes only from the verified IAM access token
 * (App\Auth\IamGuard) - never from a body, a parameter, an address or a header of the site's own. The one
 * other identity is a browser's visitor (App\Auth\Visitors: a key the server made, sent back in
 * X-Visitor-Key), and only requireOwner() reads it: a visitor reaches its own accounts and imports and
 * nothing else, and has no role. Whoever it is is put on the request as `user`.
 *
 * Who a request is, in this order:
 *
 *   1. Authorization: Bearer ... (with or without a token after it): that person, or 401 - a bad or expired
 *      token never falls back to the key, which is not even read. Only the Bearer scheme counts: "Basic ..."
 *      (a host behind a password file) is as if no Authorization header had been sent.
 *   2. else, when the IAM says this site requires sign-in (App\Auth\SignInPolicy, the IAM's switch for the
 *      application): 401 sign_in_required on every route but /api/meta, the strings, the icons and
 *      /api/site-status (the site gate's, which reads no credentials at all: the pages must always learn
 *      where the site stands) - for nobody and for a visitor key alike; the key is never looked up, counted
 *      or swept early, and no visitor is made. The move at sign-in (token and key) works either way.
 *   3. else an X-Visitor-Key: that visitor (requireOwner() only); unknown or malformed, or a row that went
 *      while the request was on its way (moved at sign-in in another tab, swept): 401 visitor_unknown.
 *   4. else nobody: the public routes, and a first import that makes a visitor within the limits.
 *
 *   requireSignIn($app)              a signed-in person, any role or none (never a visitor)
 *   requireOwner($app[, true])       whose own data a request reads and writes: the signed-in person, else
 *                                    this browser's visitor; with true (the imports) nobody may go on to
 *                                    makeVisitor($app), which makes a visitor for an import that goes through
 *   requireRole($app, ...$roles)     a signed-in person with one of $roles (ADMIN passes every EDITOR check):
 *                                    Role::STAFF for the admin panel, Role::ADMIN for the system's own routes
 *   requireTokenRole($app, ...$roles)  the same, judged from the token alone - no row of users read or made:
 *                                    the Migrations page's routes, which answer while a migration waits
 *   optionalSignIn($app)             a public route that answers a signed-in person a little more
 *
 * The answers:
 *
 *   no token                         401 sign_in_required: "Sign in to continue." (with WWW-Authenticate: Bearer)
 *   sign-in required here, no token  401 sign_in_required on the public routes too (optionalSignIn()), and for a
 *                                    visitor key (requireOwner())
 *   a token that does not verify     401 sign_in_required as well, on every route that reads one - public ones
 *                                    too: a request that sends credentials is that person or it is refused,
 *                                    and is never answered as if it had sent none
 *   not an editor or an admin        403 editor_only: "Only an editor or an admin can do this."
 *   not an admin                     403 admin_only: "Only an admin can do this."
 *   a visitor key nobody has         401 visitor_unknown (requireOwner() only; a malformed one too)
 *   too many visitors                429 too_many_visitors (makeVisitor(): at once, or new this hour)
 *   migration 004 not applied        503 visitors_unavailable, wherever a visitor is looked for or made
 */

/**
 * The person of a request (once per request), null when it sent no credentials (no Authorization header of
 * the Bearer scheme). Credentials that do not verify are a 401, here, for every caller.
 */
function requestPerson(App $app, ServerRequestInterface $request): ?User
{
    $user = $request->getAttribute('user');
    if ($user instanceof User && ! $user->isVisitor()) {
        return $user;
    }

    $guard = $app->get(IamGuard::class);
    $user = $guard->user($request);
    if ($user === null && $guard->sentCredentials($request)) {
        throw signInRequired();
    }

    return $user;
}

function signInRequired(): HttpError
{
    return new HttpError(401, 'Sign in to continue.', ['code' => 'sign_in_required'], ['WWW-Authenticate' => 'Bearer']);
}

/**
 * Whether the IAM says this site requires sign-in (App\Auth\SignInPolicy). Asked only for a request that is
 * nobody's or a visitor's - a signed-in person never needs it.
 */
function signInRequiredHere(App $app): bool
{
    return $app->get(SignInPolicy::class)->required();
}

/** Middleware: a signed-in person, any role or none. */
function requireSignIn(App $app): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        $user = requestPerson($app, $request) ?? throw signInRequired();

        return $handler->handle($request->withAttribute('user', $user));
    };
}

/**
 * Middleware: the one whose own accounts and imports the request reads and writes - the only middleware
 * that reads a visitor key, and so the only way a visitor reaches anything:
 *
 *   1. a token: that person (a token that does not verify was refused by requestPerson() already, so a bad
 *      or expired token never falls back to the key; with a good one the key is not read at all);
 *   2. else, when the IAM says the site requires sign-in: 401 sign_in_required at once - the key is not read,
 *      so no visitor is looked up, seen, counted or made (the throttles and makeVisitor() are the route's,
 *      inside this one);
 *   3. else an X-Visitor-Key: that visitor (App\Auth\Visitors::find(); unknown or malformed: 401
 *      visitor_unknown; migration 004 pending: 503 visitors_unavailable);
 *   4. else nobody: 401 sign_in_required - unless $mayMakeVisitor (the two imports), where the request goes
 *      on as "a visitor to be made" and makeVisitor() makes one once the import's limits let it through.
 *
 * Every account query takes currentUser($request)->id, so whoever this is - a person or a visitor - they
 * reach their own data and nobody else's. Now and then (at most once an hour) such a request also leaves
 * the clean-up of long-unseen visitors for after its answer.
 */
function requireOwner(App $app, bool $mayMakeVisitor = false): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app, $mayMakeVisitor): ResponseInterface {
        $visitors = $app->get(Visitors::class);
        $user = requestPerson($app, $request);
        if ($user === null) {
            if (signInRequiredHere($app)) {
                throw signInRequired();
            }
            $key = Visitors::keyOf($request);
            if ($key !== null) {
                $user = $visitors->find($key);
            } elseif ($mayMakeVisitor) {
                return $handler->handle($request->withAttribute('visitor_to_make', true));
            } else {
                throw signInRequired();
            }
        }
        $visitors->sweepAfterAnswer();

        return $handler->handle($request->withAttribute('user', $user));
    };
}

/**
 * Middleware, innermost on an import route (after its throttles, so a refused import never makes one): for
 * a request requireOwner() let through as "a visitor to be made", a new visitor (Visitors::make(), its
 * limits) whose key goes back in the X-Visitor-Key header of the answer - with Cache-Control: no-store, and
 * nowhere else. When the import does not go through, the visitor is deleted again with what the failure
 * left, and its counts are given back: a visitor exists only once something of theirs is saved.
 */
function makeVisitor(App $app): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        if ($request->getAttribute('visitor_to_make') !== true || $request->getAttribute('user') instanceof User) {
            return $handler->handle($request);
        }

        $visitors = $app->get(Visitors::class);
        [$visitor, $key] = $visitors->make($request);
        try {
            $response = $handler->handle($request->withAttribute('visitor_to_make', false)->withAttribute('user', $visitor));
        } catch (Throwable $e) {
            $visitors->unmake($visitor, $request);
            throw $e;
        }
        if ($response->getStatusCode() >= 300) {
            $visitors->unmake($visitor, $request);

            return $response;
        }
        $visitors->sweepAfterAnswer(now: true);

        return $response->withHeader(Visitors::HEADER, $key)->withHeader('Cache-Control', 'no-store');
    };
}

/**
 * Middleware: a signed-in person with one of $roles. Put on a route group, so that a route added to it
 * later cannot go without the check.
 */
function requireRole(App $app, string ...$roles): Closure
{
    if ($roles === []) {
        throw new LogicException('requireRole() needs at least one role.');
    }

    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app, $roles): ResponseInterface {
        $user = requestPerson($app, $request) ?? throw signInRequired();
        refuseUnlessOneOf($user, $roles);

        return $handler->handle($request->withAttribute('user', $user));
    };
}

/**
 * Middleware: requireRole() judged from the token alone - who it is and its roles, with no row of users read
 * or made (App\Auth\IamGuard::tokenUser()). For the routes that must answer on a database the migrations have
 * yet to build: the Migrations page's, which stay open while an update waits (api/middleware/site_gate.php) -
 * on a new or a half-built database even the users table may not be there yet. The same answers as
 * requireRole(): 401 sign_in_required without a token or with one that does not verify, 403 admin_only /
 * editor_only without the role.
 */
function requireTokenRole(App $app, string ...$roles): Closure
{
    if ($roles === []) {
        throw new LogicException('requireTokenRole() needs at least one role.');
    }

    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app, $roles): ResponseInterface {
        $user = $app->get(IamGuard::class)->tokenUser($request) ?? throw signInRequired();
        refuseUnlessOneOf($user, $roles);

        return $handler->handle($request->withAttribute('user', $user));
    };
}

/** 403 unless $user has one of $roles (ADMIN passes every EDITOR check): admin_only where only ADMIN will do. */
function refuseUnlessOneOf(User $user, array $roles): void
{
    foreach ($roles as $role) {
        if ($user->hasRole($role)) {
            return;
        }
    }

    throw $roles === [Role::ADMIN]
        ? new HttpError(403, 'Only an admin can do this.', ['code' => 'admin_only'])
        : new HttpError(403, 'Only an editor or an admin can do this.', ['code' => 'editor_only']);
}

/**
 * Middleware: a public route - while the site is open to everybody. Nobody is refused for sending nothing,
 * unless the IAM says the site requires sign-in (then 401 sign_in_required, a visitor key or not); a
 * signed-in person is put on the request (currentUserOrNull()), and a token that does not verify is a 401
 * as everywhere.
 */
function optionalSignIn(App $app): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        $user = requestPerson($app, $request);
        if ($user === null && signInRequiredHere($app)) {
            throw signInRequired();
        }

        return $handler->handle($user === null ? $request : $request->withAttribute('user', $user));
    };
}

/**
 * Middleware: at most $perMinute requests a minute per signed-in person (after the sign-in check of the
 * group) - and for a visitor, or a visitor about to be made, per address (App\Web\ClientAddress), so that a
 * fresh key starts nothing over; with $visitorsPerMinute, also at most that many a minute from all visitors
 * together. A limit is a number, or the config/app.php path of one (read at each request). Counted in the
 * database so it holds across the host's PHP processes.
 */
function throttle(App $app, string $name, int|string $perMinute, int|string|null $visitorsPerMinute = null): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app, $name, $perMinute, $visitorsPerMinute): ResponseInterface {
        $limit = fn (int|string|null $limit): ?int => is_string($limit) ? (int) $app->config->get($limit) : $limit;
        $perMinute = $limit($perMinute);
        $visitorsPerMinute = $limit($visitorsPerMinute);
        $user = $request->getAttribute('user');
        $person = $user instanceof User && ! $user->isVisitor();
        $who = $person ? 'user:'.$user->id : 'ip:'.ClientAddress::counted($request, $app->config);
        $cache = $app->get(Cache::class);
        $tooMany = fn (): HttpError => new HttpError(429, 'That is a lot of imports in one minute. Wait a moment and try again.', ['code' => 'too_many_imports'], ['Retry-After' => '60']);

        if ($cache->hit("rate:{$name}:{$who}", 60) > $perMinute) {
            throw $tooMany();
        }
        if (! $person && $visitorsPerMinute !== null && $cache->hit("rate:{$name}:visitors", 60) > $visitorsPerMinute) {
            throw $tooMany();
        }

        return $handler->handle($request);
    };
}

/**
 * The person of a request that passed requireSignIn() or requireRole() - or the person or visitor of one
 * that passed requireOwner(). A request still waiting for makeVisitor() has nobody: 401.
 */
function currentUser(ServerRequestInterface $request): User
{
    $user = $request->getAttribute('user');
    if (! $user instanceof User) {
        throw signInRequired();
    }

    return $user;
}

/** The signed-in person of a public route (optionalSignIn()), or null. */
function currentUserOrNull(ServerRequestInterface $request): ?User
{
    $user = $request->getAttribute('user');

    return $user instanceof User ? $user : null;
}
