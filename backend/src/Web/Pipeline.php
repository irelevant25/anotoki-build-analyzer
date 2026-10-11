<?php

namespace App\Web;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Several middleware run as one, in the order given, before $last: what api/middleware/auth.php puts on a
 * route group as one check - the anotoki library's Identify (who the request is), the site's rule (a role, a
 * person, an owner) and the library's RecordPerson (the person's row) - so that a group still takes one
 * ->add() and a route added to it later cannot go without any part of it.
 *
 * Each step is a PSR-15 middleware or a Slim-style closure ($request, $handler).
 */
final class Pipeline implements RequestHandlerInterface
{
    /**
     * @param  list<MiddlewareInterface|Closure(ServerRequestInterface, RequestHandlerInterface): ResponseInterface>  $steps
     */
    public function __construct(private readonly array $steps, private readonly RequestHandlerInterface $last) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->steps === []) {
            return $this->last->handle($request);
        }
        $step = $this->steps[0];
        $rest = new self(array_slice($this->steps, 1), $this->last);

        return $step instanceof MiddlewareInterface ? $step->process($request, $rest) : $step($request, $rest);
    }
}
