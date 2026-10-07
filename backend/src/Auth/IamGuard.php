<?php

namespace App\Auth;

use App\Models\User;
use App\Support\Iam\IamTokenVerifier;
use App\Support\Log;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is making this request: the bearer access token of the anotoki IAM, verified locally against the
 * IAM's keys - its "sub" is the user id, its "roles" claim this application's roles. There is no other way
 * in: no setting, header, parameter or address of the request makes anybody somebody.
 *
 * A request either sends credentials or it does not (sentCredentials(): an Authorization header of the Bearer
 * scheme, with or without a token after it). One that sends them is that person or nobody at all -
 * api/middleware/auth.php answers it 401 and never treats it as a request that sent none, nor reads its
 * visitor key. Any other scheme ("Basic ...", which a host behind a password file sends with every request)
 * is no credentials: such a request is as if it had sent no Authorization header.
 */
final class IamGuard
{
    public function __construct(
        private readonly UserProvisioner $users,
        private readonly IamTokenVerifier $verifier,
    ) {}

    /** The token's person, with this application's roles; null when no token was sent or it does not verify. */
    public function user(ServerRequestInterface $request): ?User
    {
        $token = $this->verified($request);

        return $token === null ? null : $this->users->ensure($token['id'], $token['name'])->withRoles($token['roles']);
    }

    /**
     * The token's person as the token alone says - the user id, the name it carries, this application's roles -
     * with no row of users read or made; null as for user(). For the routes that must answer on a database a
     * waiting migration has yet to build (the Migrations page's: requireTokenRole() in api/middleware/auth.php).
     */
    public function tokenUser(ServerRequestInterface $request): ?User
    {
        $token = $this->verified($request);

        return $token === null ? null : (new User($token['id'], $token['name'] ?? "user {$token['id']}"))->withRoles($token['roles']);
    }

    /**
     * What a token that verifies says: the IAM user id, its preferred_username, its roles here.
     *
     * @return array{id: int, name: string|null, roles: list<string>}|null
     */
    private function verified(ServerRequestInterface $request): ?array
    {
        $token = $this->verifier->bearer($this->authorization($request));
        if ($token === null) {
            if ($this->sentCredentials($request)) {
                Log::info('IAM access token refused: no token after Bearer');
            }

            return null;
        }

        $claims = $this->verifier->verify($token, $reason);
        $id = $claims === null ? null : $this->verifier->userId($claims);
        if ($id === null) {
            Log::info('IAM access token refused: '.($reason ?? 'no user id'));

            return null;
        }

        return [
            'id' => $id,
            'name' => is_string($claims['preferred_username'] ?? null) ? $claims['preferred_username'] : null,
            'roles' => $this->verifier->roles($claims),
        ];
    }

    /**
     * Whether the request claims to be somebody: an Authorization header of the Bearer scheme - a token that
     * may or may not verify, or none after the word. Another scheme is not credentials here.
     */
    public function sentCredentials(ServerRequestInterface $request): bool
    {
        return $this->verifier->sentBearer($this->authorization($request));
    }

    private function authorization(ServerRequestInterface $request): ?string
    {
        // Apache under CGI / FastCGI passes the header on only through the rewrite in api/.htaccess.
        $server = $request->getServerParams();
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }

        return trim($header) === '' ? null : $header;
    }
}
