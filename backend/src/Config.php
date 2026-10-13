<?php

namespace App;

use Anotoki\Lib\Auth\IamClient;

/**
 * The configuration: the application's own settings (config/app.php, committed) and the files that
 * describe one installation (config/*.local.php, gitignored, written by setup.php):
 *
 *   database.local.php   required: the PostgreSQL connection
 *   auth.local.php       required: where people sign in - the anotoki IAM's address (issuer: https, http only
 *                        on this computer) and this app's client id there. Sign-in through the IAM is the
 *                        only way in; a "mode" or "local_user_id" left in an older file means nothing and opens
 *                        nothing.
 *   fetch.local.php      optional: outgoing requests - how the app identifies itself, the delay between two
 *                        requests to one site, the page cache, and how long one sync step may run. Each value
 *                        it leaves out is FETCH_DEFAULTS'.
 *   client_address.local.php  optional, written by hand: an installation's own proxies over config/app.php's
 *                        client_address (Cloudflare's ranges) - ['trusted_proxies' => []] where the site is
 *                        not behind Cloudflare. Its trusted_proxies and nothing else: the header a proxy names
 *                        the address in is Cloudflare's CF-Connecting-IP, never one a setting names; a range
 *                        wider than /8 (IPv6: /16) is refused - the site is not set up until it is narrowed.
 *   cloudflare.local.php optional, written by hand, the same file in every anotoki site: the secret the owner's
 *                        Cloudflare zone adds to every request through it, in X-Anotoki-Origin (a Transform Rule,
 *                        the owner's decision of 2026-10-13) - ['origin_secret' => '<a long random value>']. With
 *                        it, CF-Connecting-IP is believed only on a request that carries that secret
 *                        (originSecret(), App\Web\ClientAddress); without it, or with a value that is not a string
 *                        with something in it, from the trusted proxies alone, as before. Its origin_secret and
 *                        nothing else; never written or read by setup.php, never shown or logged - a file that
 *                        does not return an array (PHP cannot run it, say) is named in the problems, never what
 *                        PHP said of it, which can quote it.
 *
 * There is no committed default for the required files: an installation that has not been set up says so
 * (503 "not_set_up", see api/middleware) instead of quietly running against a database or an IAM nobody
 * chose. One environment variable exists, for the browser check alone: BUILD_ANALYZER_TEST_CONFIG names a
 * folder that stands in for config/ (its database.local.php, auth.local.php, fetch.local.php) and whose
 * storage/ stands in for backend/storage - honoured only when the database it names is a throwaway one
 * (build_analyzer_test_...); naming any other database leaves the API "not set up". A host never sets it.
 */
final class Config
{
    /** The files setup.php writes and the app cannot run without. */
    public const LOCAL_FILES = ['database.local.php', 'auth.local.php'];

    /** The files that may be there: fetch's values over FETCH_DEFAULTS, client_address's over config/app.php's. */
    public const OPTIONAL_FILES = ['fetch.local.php', 'client_address.local.php'];

    /** The file that may hold the origin secret (see the class comment; read apart from the others: it holds a secret). */
    public const ORIGIN_SECRET_FILE = 'cloudflare.local.php';

    /** The browser check's settings folder (see the class comment). */
    public const TEST_CONFIG_ENV = 'BUILD_ANALYZER_TEST_CONFIG';

    /** What the name of a database the test settings may point at begins with: the tests' throwaway ones. */
    public const TEST_DATABASE_PREFIX = 'build_analyzer_test_';

    /** The fetch settings without fetch.local.php, and the ones it leaves out. */
    public const FETCH_DEFAULTS = [
        'user_agent' => 'build-analyzer/1.0',
        'browser_user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
        'timeout' => 30,
        'delay_ms' => 1200,
        'cache_hours' => 12,
        'step_seconds' => 20,
        'stale_hours' => 20,
    ];

    /** @param array<string, mixed> $values */
    private function __construct(
        private array $values,
        private readonly string $root,
        private readonly array $problems,
        private readonly ?string $storageRoot = null,
    ) {}

    /**
     * The configuration of this checkout or installation (backend/config/).
     */
    public static function load(?string $root = null): self
    {
        $root = rtrim(str_replace('\\', '/', $root ?? dirname(__DIR__)), '/');
        $testDir = self::testConfigDir();
        $dir = $testDir ?? $root.'/config';

        $values = self::requireArray($root.'/config/app.php') ?? [];
        $problems = [];

        $local = [];
        foreach (self::LOCAL_FILES as $file) {
            $path = $dir.'/'.$file;
            if (! is_file($path)) {
                $problems[] = "config/{$file} is missing";

                continue;
            }
            $data = self::requireArray($path);
            if ($data === null) {
                $problems[] = "config/{$file} does not return an array";

                continue;
            }
            $local[substr($file, 0, -strlen('.local.php'))] = $data;
        }
        foreach (self::OPTIONAL_FILES as $file) {
            $path = $dir.'/'.$file;
            if (! is_file($path)) {
                continue;
            }
            $data = self::requireArray($path);
            if ($data === null) {
                $problems[] = "config/{$file} does not return an array";

                continue;
            }
            $local[substr($file, 0, -strlen('.local.php'))] = $data;
        }

        $values['database'] = $local['database'] ?? [];
        $values['auth'] = ($local['auth'] ?? []) + ['jwks_file' => null, 'leeway' => 60];
        $values['fetch'] = ($local['fetch'] ?? []) + self::FETCH_DEFAULTS;
        // This installation's own proxies (or none) over the committed Cloudflare ranges - the proxies alone: whose
        // header names the address is never a setting (CF-Connecting-IP, App\Web\ClientAddress).
        $own = array_intersect_key((array) ($local['client_address'] ?? []), ['trusted_proxies' => true]);
        $values['client_address'] = $own + (array) ($values['client_address'] ?? []);

        // The origin secret (cloudflare.local.php, by hand): its origin_secret alone. Read apart from the other files,
        // and what PHP throws while running it kept quiet - its message can quote the file, and the file is a secret.
        $cloudflare = $dir.'/'.self::ORIGIN_SECRET_FILE;
        if (is_file($cloudflare)) {
            try {
                $data = self::requireArray($cloudflare);
            } catch (\Throwable) {
                $data = null;
            }
            if ($data === null) {
                $problems[] = 'config/'.self::ORIGIN_SECRET_FILE.' does not return an array';
            } else {
                $values['cloudflare'] = array_intersect_key($data, ['origin_secret' => true]);
            }
        }

        if ($testDir !== null && ! str_starts_with((string) ($values['database']['database'] ?? ''), self::TEST_DATABASE_PREFIX)) {
            // The test settings never reach a real database: not set up, rather than the wrong one.
            $values['database'] = [];
            $problems[] = self::TEST_CONFIG_ENV.' names a database that is not a '.self::TEST_DATABASE_PREFIX.' one';
        }

        if ($problems === []) {
            $problems = self::check($values);
        }

        return new self($values, $root, $problems, $testDir !== null ? $testDir.'/storage' : null);
    }

    /** The browser check's settings folder, when BUILD_ANALYZER_TEST_CONFIG names one. */
    private static function testConfigDir(): ?string
    {
        $dir = getenv(self::TEST_CONFIG_ENV);
        if (! is_string($dir) || trim($dir) === '') {
            return null;
        }

        return rtrim(str_replace('\\', '/', $dir), '/');
    }

    /**
     * A configuration built in code (the tests): $values replace sections of config/app.php, and $storage
     * (when given) stands in for backend/storage.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values, ?string $root = null, ?string $storage = null): self
    {
        $root = rtrim(str_replace('\\', '/', $root ?? dirname(__DIR__)), '/');
        $base = self::requireArray($root.'/config/app.php') ?? [];
        $values = array_replace($base, $values);
        $values['auth'] = ($values['auth'] ?? []) + ['jwks_file' => null, 'leeway' => 60];
        $values['fetch'] = ($values['fetch'] ?? []) + self::FETCH_DEFAULTS;

        return new self($values, $root, self::check($values), $storage);
    }

    /**
     * What is wrong with a set of values, as sentences naming the file (never a value).
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function check(array $values): array
    {
        $problems = [];
        $database = $values['database'] ?? [];
        foreach (['host', 'port', 'database', 'username'] as $key) {
            if (! is_scalar($database[$key] ?? null) || trim((string) $database[$key]) === '') {
                $problems[] = "config/database.local.php has no {$key}";
            }
        }
        if (isset($database['port']) && ! ctype_digit((string) $database['port'])) {
            $problems[] = 'config/database.local.php has a port that is not a number';
        }

        // Sign-in through the IAM is the only way in, so the IAM must be named - whatever else the file says.
        $auth = $values['auth'] ?? [];
        if (! is_string($auth['issuer'] ?? null) || ! preg_match('#^https?://[^/\s]+#', $auth['issuer'])) {
            $problems[] = 'config/auth.local.php has no issuer (the IAM\'s address)';
        } elseif (! IamClient::secureAddress(trim($auth['issuer']))) {
            // The keys tokens are checked with come from the issuer: never over plain http across a network.
            $problems[] = 'config/auth.local.php has an issuer that is not https (http only on this computer)';
        }
        if (! is_string($auth['client_id'] ?? null) || trim($auth['client_id']) === '') {
            $problems[] = 'config/auth.local.php has no client_id';
        }

        $fetch = $values['fetch'] ?? [];
        if (! is_string($fetch['user_agent'] ?? null) || trim($fetch['user_agent']) === '') {
            $problems[] = 'config/fetch.local.php has no user_agent';
        }
        foreach (['timeout', 'delay_ms', 'cache_hours', 'step_seconds', 'stale_hours'] as $key) {
            if (isset($fetch[$key]) && (! is_int($fetch[$key]) || $fetch[$key] < 0)) {
                $problems[] = "config/fetch.local.php has a {$key} that is not a whole number";
            }
        }

        // The proxies whose CF-Connecting-IP is believed are never a range that takes in most of the internet: from
        // anywhere in it, a forged header would walk past every per-address limit. Cloudflare's own ranges are /13
        // and longer (IPv6: /29 and longer), so a range wider than /8 (IPv6: /16) is a mistake, and the site says
        // so instead of believing it.
        foreach ((array) ($values['client_address']['trusted_proxies'] ?? []) as $range) {
            if (is_string($range) && self::catchAll($range)) {
                $problems[] = 'config/client_address.local.php trusts a proxy range wider than /8 (IPv4) or /16 (IPv6)';

                break;
            }
        }

        return $problems;
    }

    /** A CIDR range too wide to be a proxy's: shorter than /8 for IPv4, /16 for IPv6. One address is no range. */
    private static function catchAll(string $range): bool
    {
        if (preg_match('#^\s*([0-9A-Fa-f:.]+)\s*/\s*([0-9]{1,3})\s*$#D', $range, $match) !== 1) {
            return false;
        }

        return (int) $match[2] < (str_contains($match[1], ':') ? 16 : 8);
    }

    public function isSetUp(): bool
    {
        return $this->problems === [];
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The secret the owner's Cloudflare zone adds to every request through it (X-Anotoki-Origin, the library's
     * ClientAddress::ORIGIN_HEADER: a Transform Rule - the owner's decision of 2026-10-13), from cloudflare.local.php;
     * null without one. Cloudflare's published ranges are every Cloudflare customer's, not the owner's zone alone:
     * anybody's Worker can reach the host from them and write the CF-Connecting-IP it likes, a new one for every
     * request. With the secret that header is believed only on a request that carries it, and any other request from
     * Cloudflare counts as the edge it came from - coarser, never looser (App\Web\ClientAddress, and the admin tools'
     * AdminContext). Only a string with something in it is a secret (trimmed: a header's value never starts or ends
     * with a space); anything else is none, as without the file.
     */
    public function originSecret(): ?string
    {
        $secret = $this->get('cloudflare.origin_secret');

        return is_string($secret) && trim($secret) !== '' ? trim($secret) : null;
    }

    /**
     * A value by dot path: "auth.issuer", "fetch.delay_ms", "aliases.genshin.sets".
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** Change one value for this process only: the tests, and command-line options such as --cache-hours. */
    public function set(string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        $target = &$this->values;
        foreach ($segments as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target[$last] = $value;
    }

    /**
     * Tests only: every value, to put back with restore() after a test changed some.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->values;
    }

    /** @param array<string, mixed> $values from snapshot() */
    public function restore(array $values): void
    {
        $this->values = $values;
    }

    /** A path inside backend/ ("storage/http-cache"). */
    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->root : $this->root.'/'.ltrim($relative, '/');
    }

    public function storage(string $relative = ''): string
    {
        $base = $this->storageRoot ?? $this->path('storage');

        return $relative === '' ? $base : $base.'/'.ltrim($relative, '/');
    }

    private static function requireArray(string $file): ?array
    {
        if (! is_file($file)) {
            return null;
        }
        // A file written by hand without its <?php line, with text after its closing tag, or with a BOM would print what it holds
        // - a password, a secret - before the answer: what it prints is caught, and the file counts as no array.
        ob_start();
        try {
            $data = (static fn (string $path) => require $path)($file);
        } finally {
            $printed = (string) ob_get_clean();
        }
        if ($printed !== '') {
            error_log('[config] config/'.basename($file).' prints '.strlen($printed).' bytes of its own (no <?php line, text after ?>, or a BOM): it is not read.');

            return null;
        }

        return is_array($data) ? $data : null;
    }
}
