<?php

namespace App\Auth;

use App\Config;
use App\Models\User;
use App\Support\Iam\IamTokenVerifier;
use App\Support\Log;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is making this request.
 *
 * Auth mode "iam": the bearer access token, verified locally against the IAM's keys; its "sub" is the user
 * id and its "roles" claim this application's roles. Anything else is anonymous.
 * Auth mode "none": the owner (auth.local.php local_user_id) with every role, but only for requests from
 * this computer's own pages, so a copy started with sign-in off is open neither to the network nor to
 * other sites open in the owner's browser (see fromOwnPages()).
 *
 * The mode comes from configuration, never from the request.
 */
final class IamGuard
{
    public function __construct(
        private readonly Config $config,
        private readonly UserProvisioner $users,
        private readonly IamTokenVerifier $verifier,
    ) {}

    public function mode(): AuthMode
    {
        return AuthMode::of($this->config);
    }

    public function user(ServerRequestInterface $request): ?User
    {
        return match ($this->mode()) {
            AuthMode::None => $this->localOwner($request),
            AuthMode::Iam => $this->fromToken($request),
        };
    }

    /**
     * 127.0.0.0/8, ::1, or an IPv4-mapped 127.x address.
     */
    public static function isLoopback(?string $ip): bool
    {
        $ip = strtolower(trim((string) $ip, '[]'));
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '127.');
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && inet_pton($ip) === inet_pton('::1');
    }

    /**
     * A host name or address that can only mean this computer.
     */
    public static function isLocalHost(?string $host): bool
    {
        $host = strtolower(trim((string) $host, '[]'));

        return $host === 'localhost' || str_ends_with($host, '.localhost') || self::isLoopback($host);
    }

    /**
     * The host a request was sent to, without the port ("localhost:4600" is "localhost", "[::1]:8400" "::1").
     */
    public static function hostOf(string $hostHeader): string
    {
        $host = strtolower(trim($hostHeader));
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            return $end === false ? $host : substr($host, 1, $end - 1);
        }

        return substr_count($host, ':') === 1 ? explode(':', $host)[0] : $host;
    }

    /**
     * Auth mode "none" trusts the request itself, so it must come from this computer and from the app's own
     * pages. A loopback client alone is not enough: any other site open in the owner's browser connects from
     * 127.0.0.1 too (a "simple" cross-site POST needs no CORS), and so does a page under a DNS-rebound name.
     * So: a loopback client, a loopback Host, no cross-site fetch, and an Origin (when sent) on this computer.
     */
    public static function fromOwnPages(ServerRequestInterface $request): bool
    {
        $server = $request->getServerParams();
        if (! self::isLoopback($server['REMOTE_ADDR'] ?? null)) {
            return false;
        }
        $host = $request->getHeaderLine('Host') !== '' ? $request->getHeaderLine('Host') : $request->getUri()->getHost();
        if (! self::isLocalHost(self::hostOf($host))) {
            return false;
        }
        if (strtolower($request->getHeaderLine('Sec-Fetch-Site')) === 'cross-site') {
            return false;
        }
        if (! $request->hasHeader('Origin')) {
            return true;
        }

        $origin = parse_url($request->getHeaderLine('Origin'), PHP_URL_HOST);

        return is_string($origin) && self::isLocalHost($origin);
    }

    private function localOwner(ServerRequestInterface $request): ?User
    {
        if (! self::fromOwnPages($request)) {
            return null;
        }

        return $this->users->ensure((int) $this->config->get('auth.local_user_id', 1), nameIfNew: 'owner')->withRoles(Role::ALL);
    }

    private function fromToken(ServerRequestInterface $request): ?User
    {
        // Apache under CGI / FastCGI passes the header on only through the rewrite in api/.htaccess.
        $server = $request->getServerParams();
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }

        $token = $this->verifier->bearer($header === '' ? null : $header);
        if ($token === null) {
            return null;
        }

        $claims = $this->verifier->verify($token, $reason);
        $id = $claims === null ? null : $this->verifier->userId($claims);
        if ($id === null) {
            Log::info('IAM access token refused: '.($reason ?? 'no user id'));

            return null;
        }

        $name = is_string($claims['preferred_username'] ?? null) ? $claims['preferred_username'] : null;

        return $this->users->ensure($id, $name)->withRoles($this->verifier->roles($claims));
    }
}
