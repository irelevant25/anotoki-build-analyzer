<?php

namespace App\Auth;

use App\Cache\Cache;
use App\Config;
use App\Models\User;
use App\Repositories\Users;
use App\Support\Clock;
use App\Support\Log;
use App\Web\AfterResponse;
use App\Web\ClientAddress;
use App\Web\HttpError;
use PDOException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Visitor accounts: somebody who uses the site without signing in gets what a member gets for their own
 * data - their imports kept on the server, their accounts, the analysis - under a row of users with a
 * negative id (an IAM id is always positive). Nothing else makes, finds, sweeps or moves a visitor.
 *
 * The key: 32 random bytes the server makes, base64url without padding (43 characters). It leaves the server
 * once, in the X-Visitor-Key header of the answer whose import made the visitor (never in a body, a URL or
 * a log); the browser keeps it and sends it back in the X-Visitor-Key request header. The row keeps only its
 * SHA-256, and a request is that visitor when the hash of the key it sends is a row's - the key has 256 bits,
 * so a plain lookup is safe.
 *
 *   find()      the visitor of a key, seen now (written at most once an hour); unknown or malformed: 401
 *               visitor_unknown
 *   make()      a new visitor, after the limits: visitors at once, new ones an hour per address and in all
 *   unmake()    the one just made, when its first import did not go through - and its counts given back
 *   sweep()     visitors unseen for keep_days deleted, a few at a time, after somebody's answer
 *   moveInto()  at sign-in, a visitor's accounts and imports into the person's (the person's copy of an
 *               account both have stays)
 *
 * Between an upload and "Apply" migration 004 has not added the columns yet: every visitor path then
 * answers 503 visitors_unavailable (never a 500), and the clean-up does nothing.
 */
final class Visitors
{
    public const HEADER = 'X-Visitor-Key';

    private const KEY = '/^[A-Za-z0-9_-]{43}$/';

    private ?bool $ready = null;

    public function __construct(
        private readonly Users $users,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    /** The key a request sends, or null when it sends none (an empty header is none). */
    public static function keyOf(ServerRequestInterface $request): ?string
    {
        $key = trim($request->getHeaderLine(self::HEADER));

        return $key === '' ? null : $key;
    }

    public static function wellFormed(string $key): bool
    {
        return preg_match(self::KEY, $key) === 1;
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public function keepDays(): int
    {
        return max(1, (int) $this->config->get('visitors.keep_days', 30));
    }

    /** Whether migration 004 is applied (asked once a request). */
    public function ready(): bool
    {
        if ($this->ready === null) {
            try {
                $this->ready = $this->users->visitorsReady();
            } catch (PDOException) {
                $this->ready = false;
            }
        }

        return $this->ready;
    }

    /** The visitor of this key, seen now. */
    public function find(string $key): User
    {
        if (! self::wellFormed($key)) {
            throw self::unknown();
        }
        $this->mustBeReady();

        $found = $this->users->findVisitor(self::hash($key)) ?? throw self::unknown();
        $now = Clock::now();
        $hourAgo = $now->modify('-1 hour');
        if ($found['last_seen_at'] === null || $found['last_seen_at'] < $hourAgo) {
            $this->users->touchVisitor($found['user']->id, $now, $hourAgo);
        }

        return $found['user'];
    }

    /**
     * A new visitor for the request that is about to import, and its key - or a 429 too_many_visitors when
     * there are too many visitors already, or too many new ones this hour from its address or from
     * everywhere. Only a visitor made here is counted: a refused attempt gives its count straight back.
     *
     * @return array{0: User, 1: string}
     */
    public function make(ServerRequestInterface $request): array
    {
        $this->mustBeReady();

        if ($this->users->visitorCount() >= max(0, (int) $this->config->get('visitors.max_visitors', 1000))) {
            throw self::tooMany('Too many people are using the site without signing in just now. Sign in to continue, or try again later.');
        }

        $address = $this->addressCounter($request);
        if ($this->cache->hit($address, 3600) > (int) $this->config->get('visitors.new_per_hour_per_address', 10)) {
            $this->cache->giveBack($address);
            throw self::tooMany('Too many new visitors from this address. Sign in to continue, or try again later.');
        }
        if ($this->cache->hit('visitors:new', 3600) > (int) $this->config->get('visitors.new_per_hour', 60)) {
            $this->cache->giveBack('visitors:new');
            $this->cache->giveBack($address);
            throw self::tooMany('Too many people started using the site without signing in this hour. Sign in to continue, or try again later.');
        }

        $key = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        try {
            $visitor = $this->users->makeVisitor(self::hash($key));
        } catch (\Throwable $e) {
            $this->cache->giveBack('visitors:new');
            $this->cache->giveBack($address);
            throw $e;
        }

        return [$visitor, $key];
    }

    /** The visitor make() made, gone again with whatever its failed import left; its counts given back. */
    public function unmake(User $visitor, ServerRequestInterface $request): void
    {
        if (! $visitor->isVisitor()) {
            return;
        }
        $this->users->deleteVisitor($visitor->id);
        $this->cache->giveBack('visitors:new');
        $this->cache->giveBack($this->addressCounter($request));
    }

    /**
     * Deletes the visitors unseen for keep_days, a batch at a time (visitors.sweep_batch, each statement
     * allowed visitors.sweep_timeout_ms); nothing while migration 004 is pending. Returns how many went.
     */
    public function sweep(): int
    {
        if (! $this->ready()) {
            return 0;
        }

        $swept = $this->users->sweepVisitors(
            Clock::now()->modify('-'.$this->keepDays().' days'),
            (int) $this->config->get('visitors.sweep_batch', 20),
            (int) $this->config->get('visitors.sweep_timeout_ms', 2000),
        );
        if ($swept > 0) {
            Log::info("Visitors unseen for {$this->keepDays()} days deleted: {$swept}");
        }

        return $swept;
    }

    /**
     * The clean-up, after this request's answer: always when $now (a visitor was just made), else at most
     * once an hour across the site. It never fails the request it runs in: a failure is logged.
     */
    public function sweepAfterAnswer(bool $now = false): void
    {
        AfterResponse::add(function () use ($now): void {
            if ($now || $this->cache->add('visitors:swept', true, 3600)) {
                $this->sweep();
            }
        });
    }

    /**
     * Moves this key's visitor into $person (Users::moveVisitor()). found false: no visitor has this key -
     * moved already (by another tab, say), swept, or never there - which is no error, so the move can be
     * asked again. Another request holding either row past the lock timeout, or a write that met the
     * person's own (a unique key, a serialization failure, a deadlock), is 503 visitor_move_retry: nothing
     * was moved, and the same request may simply be sent again. The person's own row gone meanwhile (an
     * admin deleted their data on the People page) is ownerGone(): 401 sign_in_required, nothing moved.
     *
     * $silent: the sign-in behind the move was nobody's click - the IAM's sign-in hint's silent hop (the owner's
     * decision of 2026-10-11). Then the visitor's data comes in only when the person has nothing here yet - their
     * first time, after playing as a visitor; an account with a game account or an import here never takes in a
     * browser's visitor data without its person's click: nothing moves, the visitor stays as it is - its key
     * still opens its data on this browser - and the answer says so (held: true). A sign-in the person clicked
     * moves, as always.
     *
     * @return array{found: bool, moved: int, kept: int, imports: int, held: bool}
     */
    public function moveInto(User $person, string $key, bool $silent = false): array
    {
        if ($person->isVisitor()) {
            throw new \LogicException('Only a signed-in person takes a visitor in.');
        }
        $this->mustBeReady();

        try {
            $moved = $this->users->moveVisitor(self::hash($key), $person->id, (int) $this->config->get('visitors.move_lock_timeout_ms', 5000), onlyIntoNothing: $silent);
        } catch (PDOException $e) {
            if (in_array((string) $e->getCode(), ['23505', '40001', '40P01', '55P03'], true)) {
                Log::info('Visitor move interrupted, to be asked again: SQLSTATE '.$e->getCode());

                throw new HttpError(503, 'Moving what this browser imported did not finish this time. Try again in a moment.', ['code' => 'visitor_move_retry'], ['Retry-After' => '2']);
            }
            throw $e;
        }

        if ($moved === false) {
            throw self::ownerGone($person);
        }

        return match (true) {
            $moved === null => ['found' => false, 'moved' => 0, 'kept' => 0, 'imports' => 0, 'held' => false],
            isset($moved['held']) => ['found' => true, 'moved' => 0, 'kept' => 0, 'imports' => 0, 'held' => true],
            default => ['found' => true] + $moved + ['held' => false],
        };
    }

    public static function unknown(): HttpError
    {
        return new HttpError(401, 'This browser\'s visit has ended: what it imported was deleted after a long time unused, or moved into an account at sign-in.', ['code' => 'visitor_unknown']);
    }

    /**
     * The owner's row went while their request was on its way (Users::lockForWrite() found none, or
     * Users::moveVisitor() no person to move into): a visitor
     * moved into an account at sign-in in another tab, or swept, answers as an unknown key; a person whose
     * data an admin deleted on the People page is no longer known here either - 401 sign_in_required, and the
     * next request (the page's retry with a fresh token) starts them afresh. Nothing was written.
     */
    public static function ownerGone(User $owner): HttpError
    {
        return $owner->isVisitor()
            ? self::unknown()
            : new HttpError(401, 'Your data here was deleted while this was being saved, and nothing was saved. Sign in to continue.', ['code' => 'sign_in_required'], ['WWW-Authenticate' => 'Bearer']);
    }

    /**
     * A visitor's import that would add a game account past visitors.max_accounts. Checked twice: by the
     * route before any work, and by AccountImporter again under the owner's lock, so two imports at once
     * cannot both add the last one.
     */
    public static function accountLimit(int $max): HttpError
    {
        return new HttpError(422, "Without signing in, this browser keeps at most {$max} game accounts. Import into one of them, or sign in to add more.", [
            'code' => 'visitor_limit',
            'params' => ['limit' => 'accounts', 'max' => $max],
        ]);
    }

    /** A pending migration 004: like the strings' routes before Apply, a 503 with a code - never a 500. */
    public function mustBeReady(): void
    {
        if (! $this->ready()) {
            throw new HttpError(
                503,
                'Using the site without signing in is not ready yet: an admin applies the waiting database update on the Migrations page. Sign in to continue.',
                ['code' => 'visitors_unavailable'],
                ['Retry-After' => '60'],
            );
        }
    }

    private static function tooMany(string $message): HttpError
    {
        return new HttpError(429, $message, ['code' => 'too_many_visitors'], ['Retry-After' => '3600']);
    }

    private function addressCounter(ServerRequestInterface $request): string
    {
        // A keyed hash of the address, never the address: the cache table and its backups keep the counter.
        return 'visitors:new:'.ClientAddress::countedKey($request, $this->config);
    }
}
