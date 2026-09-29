<?php

use App\App;
use App\Auth\AuthMode;
use App\Auth\IamGuard;
use App\Auth\Role;
use App\Cache\Cache;
use App\Models\User;
use App\Web\HttpError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware: a signed-in person with $role (ADMIN passes every MEMBER check), put on the request as
 * `user`. Who that is comes only from the verified IAM access token - or, in auth mode "none", from the
 * configuration, for requests from this computer's own pages (App\Auth\IamGuard).
 *
 *   no valid token                 401 "Sign in to continue." (with WWW-Authenticate: Bearer)
 *   mode "none", another computer  403, saying sign-in is off and this only answers its own pages
 *   no role here / not an admin    403
 */
function requireRole(App $app, string $role): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app, $role): ResponseInterface {
        $user = $request->getAttribute('user');
        if (! $user instanceof User) {
            $guard = $app->get(IamGuard::class);
            $user = $guard->user($request);

            if ($user === null) {
                throw $guard->mode() === AuthMode::None
                    ? new HttpError(403, 'Sign-in is switched off on this server (auth mode "none"), so it only answers its own pages on the computer it runs on.')
                    : new HttpError(401, 'Sign in to continue.', headers: ['WWW-Authenticate' => 'Bearer']);
            }
        }

        if (! $user->hasRole($role)) {
            throw new HttpError(403, $role === Role::ADMIN
                ? 'Only an admin can do this.'
                : 'Your account has no access to the build analyzer. Ask the owner to add you.');
        }

        return $handler->handle($request->withAttribute('user', $user));
    };
}

/**
 * Middleware: at most $perMinute requests a minute per signed-in person (after requireRole), counted in
 * the database so it holds across the host's PHP processes.
 */
function throttle(App $app, string $name, int $perMinute): Closure
{
    return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app, $name, $perMinute): ResponseInterface {
        $user = $request->getAttribute('user');
        $who = $user instanceof User ? 'user:'.$user->id : 'ip:'.($request->getServerParams()['REMOTE_ADDR'] ?? '?');

        if ($app->get(Cache::class)->hit("rate:{$name}:{$who}", 60) > $perMinute) {
            throw new HttpError(429, 'That is a lot of imports in one minute. Wait a moment and try again.', headers: ['Retry-After' => '60']);
        }

        return $handler->handle($request);
    };
}

/** The signed-in person of a request that passed requireRole(). */
function currentUser(ServerRequestInterface $request): User
{
    $user = $request->getAttribute('user');
    if (! $user instanceof User) {
        throw new HttpError(401, 'Sign in to continue.', headers: ['WWW-Authenticate' => 'Bearer']);
    }

    return $user;
}
