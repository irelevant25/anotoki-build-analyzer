<?php

use Anotoki\Lib\Auth\AccessTokens;
use Anotoki\Lib\Auth\Http\Identify;
use Anotoki\Lib\Auth\Http\RequireCaller;
use Anotoki\Lib\Auth\Http\RequirePerson;
use Anotoki\Lib\Auth\Http\RequireRole;
use Anotoki\Lib\Auth\SignInRule;
use Anotoki\Lib\People\Http\RecordPerson;
use Anotoki\Lib\People\People;
use Anotoki\Lib\People\PeopleRecorder;
use Anotoki\Lib\Support\Refusal;
use App\App;
use App\Auth\RequestCaller;
use App\Auth\Role;
use App\Auth\Visitors;
use App\Cache\Cache;
use App\Db\Db;
use App\Models\User;
use App\Support\Clock;
use App\Web\ClientAddress;
use App\Web\HttpError;
use App\Web\Json;
use App\Web\Pipeline;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Who a request is, and what they may do - the anotoki family's auth module (anotoki/lib: Anotoki\Lib\Auth),
 * on this site's routes. A person comes only from the verified IAM access token (the library's Identify,
 * with its AccessTokens: RS256 and typ at+jwt pinned, the IAM's keys, this client) - never from a body, a
 * parameter, an address, a cookie (the IAM's sign-in hint least of all) or a header of the site's own. The
 * one other identity is a browser's visitor (App\Auth\Visitors: a key the server made, sent back in
 * X-Visitor-Key), and only requireOwner() reads it: a visitor reaches its own accounts and imports and
 * nothing else, and has no role.
 *
 * Identify sits on each route group that needs to know (every group but /api/meta, the icons, the strings of
 * a language and /api/site-status, which read no credentials at all), together with the group's own rule,
 * as one middleware (App\Web\Pipeline) - so a route added to a group cannot go without either. In this order:
 *
 *   1. Authorization: Bearer ... (with or without a token after it): that person, or 401 invalid_token - a
 *      bad or expired token never falls back to the key, which is not even read. Only the Bearer scheme
 *      counts: "Basic ..." (a host behind a password file) is as if no Authorization header had been sent.
 *   2. else, when the IAM says this site requires sign-in (the library's SignInRule: the IAM's switch for the
 *      application, failing closed, kept a minute in storage/cache/iam): nobody, and the key is never looked
 *      up, counted or swept early, and no visitor is made - every route here answers 401 sign_in_required.
 *      The move at sign-in (token and key) works either way.
 *   3. else an X-Visitor-Key (requireOwner() only): that visitor; unknown or malformed, or a row that went
 *      while the request was on its way (moved at sign-in in another tab, swept): 401 visitor_unknown.
 *   4. else nobody: the public routes, and a first import that makes a visitor within the limits.
 *
 *   requireSignIn($app)              a signed-in person, any role or none (never a visitor)
 *   requireOwner($app[, true])       whose own data a request reads and writes: the signed-in person, else
 *                                    this browser's visitor; with true (the imports) nobody may go on to
 *                                    makeVisitor($app), which makes a visitor for an import that goes through
 *   requireRole($app, ...$roles)     a signed-in person with one of $roles (ADMIN passes every EDITOR check):
 *                                    Role::STAFF for the admin panel, Role::ADMIN for the system's own routes
 *   requireTokenRole($app, ...$roles)  the same, without the person's row: the routes that answer while a
 *                                    migration waits (the Migrations page's, the error log's, the backups')
 *   optionalSignIn($app)             a public route that answers a signed-in person a little more
 *
 * Each but requireTokenRole() keeps the person's row of users as the token says (the library's RecordPerson:
 * made on a first request, the name the token's, last seen at most every five minutes; never an account the
 * IAM deleted) before the route runs - every account and import points at it.
 *
 * The answers, in this site's shape {message, code}:
 *
 *   no token                         401 sign_in_required: "Sign in to continue." (WWW-Authenticate: Bearer
 *                                    realm="<client id>")
 *   sign-in required here, no token  401 sign_in_required on the public routes too (optionalSignIn()), and for a
 *                                    visitor key (requireOwner())
 *   a token that does not verify     401 invalid_token (error="invalid_token" in WWW-Authenticate), on every
 *                                    route that reads one - public ones too: a request that sends credentials
 *                                    is that person or it is refused, never answered as if it had sent none
 *   not an editor or an admin        403 editor_only: "Only an editor or an admin can do this."
 *   not an admin                     403 admin_only: "Only an admin can do this."
 *   a visitor key nobody has         401 visitor_unknown (requireOwner() only; a malformed one too)
 *   too many visitors                429 too_many_visitors (makeVisitor(): at once, or new this hour)
 *   migration 004 not applied        503 visitors_unavailable, wherever a visitor is looked for or made
 */

/**
 * The library's Identify, made for each request from this request's services: the token checker, the IAM's
 * sign-in rule (one answer per request) - and, with $visitors, this site's visitors (requireOwner() only).
 */
function identifyRequest(App $app, bool $visitors = false): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app, $visitors): ResponseInterface {
        $identify = new Identify(
            $app->get(AccessTokens::class),
            $app->get(SignInRule::class),
            new ResponseFactory,
            // Visitors::find(): the visitor, seen now - or 401 visitor_unknown, or 503 visitors_unavailable while
            // migration 004 waits, in this site's words (App\Web\HttpError, answered by jsonErrors()).
            visitor: $visitors ? fn (string $key): User => $app->get(Visitors::class)->find($key) : null,
            responder: siteRefusal(...),
        );

        return (new Pipeline([
            $identify,
            // Who it is, for the error log's line should the route fail (App\Auth\RequestCaller).
            function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app): ResponseInterface {
                $app->get(RequestCaller::class)->sub = Identify::person($request)?->sub;

                return $handler->handle($request);
            },
        ], $handler))->handle($request);
    };
}

/**
 * A refusal of the library's (Identify, RequireRole, RequireCaller, PeopleRoutes, the admin tools) in this
 * site's shape, {message, code, ...what it is about}: the library's code and sentence, but this site's own
 * sentence for the refusal it always had (a page words it by its code; the sentence is for logs).
 */
function siteRefusal(ResponseInterface $response, Refusal $refusal): ResponseInterface
{
    $message = match ($refusal->code) {
        'sign_in_required' => 'Sign in to continue.',
        default => $refusal->message,
    };

    return Json::message($response, $refusal->status, $message, ['code' => $refusal->code] + $refusal->extra);
}

/**
 * Several steps run as one middleware, in this order (App\Web\Pipeline).
 *
 * @param  list<Psr\Http\Server\MiddlewareInterface|Closure>  $steps
 */
function middlewareSteps(array $steps): Closure
{
    return fn (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface => (new Pipeline($steps, $handler))->handle($request);
}

/**
 * The person's row of users as their token says (the library's RecordPerson), before the route runs - when it has
 * anything to write: no row yet, another name in the token, or a last seen five minutes old (PeopleRecorder::
 * EVERY). The library writes it in one statement, which holds the row for that moment even when it changes
 * nothing; read first, without a lock, a person's requests never wait for their own import holding their row
 * (Users::lockForWrite()) - unless the row must be written.
 */
function recordPerson(App $app): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app): ResponseInterface {
        $person = Identify::person($request);
        if ($person === null) {
            return $handler->handle($request);
        }
        // Through to_jsonb: a database before 004 has no last_seen_at yet, and the library records without it.
        $row = $app->db()->one("SELECT to_jsonb(u)->>'name' AS name, to_jsonb(u)->>'last_seen_at' AS last_seen_at FROM users u WHERE u.id = ?", [$person->sub]);
        $name = PeopleRecorder::clip($person->name, 100);
        $seen = $row === null ? null : Db::time($row['last_seen_at']);
        $stale = $row === null
            || ($name !== null && $name !== $row['name'])
            || $seen === null || $seen < Clock::now()->modify('-'.PeopleRecorder::EVERY.' seconds');

        return $stale ? (new RecordPerson($app->get(People::class)))->process($request, $handler) : $handler->handle($request);
    };
}

/** The role check of a group, from the token alone: admin_only where only ADMIN will do, editor_only otherwise. */
function roleCheck(string ...$roles): RequireRole
{
    if ($roles === []) {
        throw new LogicException('A role check needs at least one role.');
    }

    return $roles === [Role::ADMIN]
        ? RequireRole::admin()->refusing('admin_only', 'Only an admin can do this.')
        : RequireRole::any(...$roles)->refusing('editor_only', 'Only an editor or an admin can do this.');
}

function signInRequired(): HttpError
{
    return new HttpError(401, 'Sign in to continue.', ['code' => 'sign_in_required'], ['WWW-Authenticate' => 'Bearer']);
}

/** Middleware: a signed-in person, any role or none. */
function requireSignIn(App $app): Closure
{
    return middlewareSteps([identifyRequest($app), new RequirePerson, recordPerson($app)]);
}

/**
 * Middleware: the one whose own accounts and imports the request reads and writes - the only middleware
 * that reads a visitor key, and so the only way a visitor reaches anything (see the file comment, 1-4).
 * Nobody: 401 sign_in_required - unless $mayMakeVisitor (the two imports), where the request goes on as "a
 * visitor to be made" (the library's RequireCaller 'make') and makeVisitor() makes one once the import's
 * limits let it through - when it was sent as JSON (jsonFromNobody()).
 *
 * Every account query takes currentUser($request)->id, so whoever this is - a person or a visitor - they
 * reach their own data and nobody else's. Now and then (at most once an hour) such a request also leaves
 * the clean-up of long-unseen visitors for after its answer.
 */
function requireOwner(App $app, bool $mayMakeVisitor = false): Closure
{
    return middlewareSteps([
        identifyRequest($app, visitors: true),
        new RequireCaller($mayMakeVisitor ? RequireCaller::MAKE : RequireCaller::REFUSE),
        ...($mayMakeVisitor ? [jsonFromNobody()] : []),
        recordPerson($app),
        function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app): ResponseInterface {
            if (currentUserOrNull($request) !== null) {
                $app->get(Visitors::class)->sweepAfterAnswer();
            }

            return $handler->handle($request);
        },
    ]);
}

/**
 * Middleware, in requireOwner() for the imports: a request of nobody's - the one kind that makes a visitor -
 * is JSON (Content-Type application/json), or 415 unsupported_media_type before anything is counted or made.
 * A form - urlencoded, multipart or plain text - is what any page open in the browser can send to this site
 * without asking first, and it would make a visitor and run an import from the reader's address; a JSON body
 * from another site's page needs the browser to ask first, and this site never says yes. A person (the token)
 * and a visitor (X-Visitor-Key) send headers no other page can send, so their imports may still be a file
 * upload (multipart).
 */
function jsonFromNobody(): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        if ($request->getAttribute(RequireCaller::MAKE_VISITOR) === true) {
            $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
            if ($type !== 'application/json') {
                throw new HttpError(415, 'Without signing in, an import is sent as JSON.', ['code' => 'unsupported_media_type']);
            }
        }

        return $handler->handle($request);
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
        if ($request->getAttribute(RequireCaller::MAKE_VISITOR) !== true || currentUserOrNull($request) !== null) {
            return $handler->handle($request);
        }

        $visitors = $app->get(Visitors::class);
        [$visitor, $key] = $visitors->make($request);
        try {
            $response = $handler->handle($request->withAttribute(RequireCaller::MAKE_VISITOR, false)->withAttribute('user', $visitor));
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
 * Middleware: a signed-in person with one of $roles, from the token. Put on a route group, so that a route
 * added to it later cannot go without the check.
 */
function requireRole(App $app, string ...$roles): Closure
{
    return middlewareSteps([identifyRequest($app), roleCheck(...$roles), recordPerson($app)]);
}

/**
 * Middleware: requireRole() with no row of users read or written - for the routes that must answer on a
 * database the migrations have yet to build: the Migrations page's, the error log's and the backups', which
 * stay open while an update waits (api/middleware/site_gate.php) - on a new or a half-built database even the
 * users table may not be there yet. The same answers as requireRole().
 */
function requireTokenRole(App $app, string ...$roles): Closure
{
    return middlewareSteps([identifyRequest($app), roleCheck(...$roles)]);
}

/**
 * Middleware: a public route - while the site is open to everybody. Nobody is refused for sending nothing,
 * unless the IAM says the site requires sign-in (then 401 sign_in_required); a signed-in person is put on the
 * request (currentUserOrNull()), and a token that does not verify is a 401 as everywhere. A visitor key is
 * not read here: on a public route a visitor is nobody.
 */
function optionalSignIn(App $app): Closure
{
    return middlewareSteps([identifyRequest($app), new RequireCaller(RequireCaller::EMPTY), recordPerson($app)]);
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
        $user = currentUserOrNull($request);
        $person = $user !== null && ! $user->isVisitor();
        // Per address by a keyed hash of it, never the address itself: the cache table, and its backups, keep it.
        $who = $person ? 'user:'.$user->id : 'ip:'.ClientAddress::countedKey($request, $app->config);
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
    return currentUserOrNull($request) ?? throw signInRequired();
}

/**
 * Who the request is, as the checks above left it: a visitor makeVisitor() just made, the person of the
 * verified token (with this application's roles), the visitor of the key - or null (nobody).
 */
function currentUserOrNull(ServerRequestInterface $request): ?User
{
    $user = $request->getAttribute('user');
    if ($user instanceof User) {
        return $user;
    }
    $person = Identify::person($request);
    if ($person !== null) {
        return User::fromPerson($person);
    }
    $visitor = Identify::visitor($request);

    return $visitor instanceof User ? $visitor : null;
}
