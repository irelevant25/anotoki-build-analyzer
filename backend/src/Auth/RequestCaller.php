<?php

namespace App\Auth;

/**
 * Who the request being answered is, once the library's Identify knew it (api/middleware/auth.php) - for what
 * is written about a request outside its route group: the error log's line of an exception that jsonErrors()
 * answers 500, which holds the request only as it came in, before any group put the person on it. Kept for one
 * request: App::resetScope() starts each without one, and nothing ever reads it as a check.
 */
final class RequestCaller
{
    /** The verified token's person (the IAM's id); null for nobody, a visitor, or not identified yet. */
    public ?int $sub = null;
}
