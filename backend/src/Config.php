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
 *                        requests to one site, how long a request may take. Each value it leaves out is
 *                        FETCH_DEFAULTS'; one it no longer reads (an older file's step_seconds) means nothing.
 *   client_address.local.php  optional, written by hand: an installation's own proxies over config/app.php's
 *                        client_address (Cloudflare's ranges) - ['trusted_proxies' => []] where the site is
 *                        not behind Cloudflare. Its trusted_proxies and nothing else: the header a proxy names
 *                        the address in is Cloudflare's CF-Connecting-IP, never one a setting names; a range
 *                        wider than /8 (IPv6: /16) is refused - the site is not set up until it is narrowed.
 *   cloudflare.local.php optional, written by hand, the same file in every anotoki site: the secret the owner's
 *                        Cloudflare zone adds to every request through it, in X-Anotoki-Origin (a Transform Rule,
 *                        the owner's decision of 2026-10-13) - ['origin_secret' => '<a long random value>']. With
 *                        it, CF-Connecting-IP is believed only on a request that carries that secret
 *                        (originSecret(), App\Web\ClientAddress); without the file, from the trusted proxies alone,
 *                        as before. A file there that names no secret (a value that is not a string with something
 *                        in it, another key, no array) fails closed, as a broken one does (below): only deleting
 *                        the file switches the secret off. Its origin_secret and nothing else; never written or
 *                        read by setup.php, never shown or logged.
 *   neutral.local.php    optional, written by hand: ['names' => [...], 'tree_words' => [...]] - names of other sites
 *                        App\Support\Neutral keeps out of what the API sends, beyond the ones it names itself, and the
 *                        words tools/check-names.php looks for in the tracked files. Read by readOptionalFile(), not
 *                        load(); one passed over adds nothing (the names Neutral holds itself still apply).
 *
 * Every one of them is read cleanly or not at all (readLocalFile(): the anotoki family's rule of 2026-10-13, and
 * its first five bytes since 2026-10-18 - docs/decisions.md): a file that does not start with <?php is never
 * compiled, and whatever one prints is caught and never sent - a password or the origin secret would otherwise go
 * out ahead of every answer. A file passed over is said in PHP's error log at most once a day, by its fault alone
 * (never its text: a parse error's message can quote it), and is never read looser than no file:
 *
 *   database, auth       the site is not set up until it is mended (the problem names the file and its fault)
 *   fetch                FETCH_DEFAULTS stand in for it
 *   client_address       no proxy is trusted - every request counts its own connection -, never Cloudflare's
 *                        ranges in its place: the file may be the very one that said [] for a host not behind
 *                        Cloudflare
 *   cloudflare           a secret no request carries (fails closed): every request from Cloudflare's ranges counts
 *                        as the edge it came from until the file is mended - never "no secret", which would believe
 *                        CF-Connecting-IP from every Cloudflare customer's Worker (and so is one read cleanly that
 *                        names no usable secret)
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

    /** What readLocalFile() says of a file that does not start with <?php: passed over, never compiled. */
    public const NOT_PHP = 'does not start with <?php (a byte-order mark, a blank line or a space before it, or no <?php line at all)';

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
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private array $values, private readonly string $root, private readonly array $problems, private readonly ?string $storageRoot = null) {}

    /**
     * The folder this installation's state is in - its config/*.local.php and storage/. On the host a release's
     * backend/ carries release.json (anotoki-deploy) and the state is beside it, in the site's folder, never part of
     * an upload; in a checkout (and wherever release.json is missing) it is backend/ itself.
     */
    public static function stateRoot(?string $root = null): string
    {
        $root = rtrim(str_replace('\\', '/', $root ?? dirname(__DIR__)), '/');

        return is_file($root . '/release.json') ? dirname($root) : $root;
    }

    /**
     * The configuration of this checkout or installation (its *.local.php in stateRoot()'s config/).
     */
    public static function load(?string $root = null): self
    {
        $root = rtrim(str_replace('\\', '/', $root ?? dirname(__DIR__)), '/');
        $state = self::stateRoot($root);
        $testDir = self::testConfigDir();
        $dir = $testDir ?? $state . '/config';

        $storage = $testDir !== null ? $testDir . '/storage' : ($state !== $root ? $state . '/storage' : null);
        $app = self::readLocalFile($root . '/config/app.php');
        $values = is_array($app['value']) ? $app['value'] : [];
        $problems = [];
        // What PHP's error log is told - each file passed over, by its fault alone -, at most once a day each.
        $said = [];

        $local = [];
        foreach (self::LOCAL_FILES as $file) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                $problems[] = "config/{$file} is missing";

                continue;
            }
            $read = self::readLocalFile($path);
            if ($read['problem'] !== null) {
                // Said for what it is, never as missing - and never its text.
                $problems[] = "config/{$file} {$read['problem']}";
                $said[] = "config/{$file} {$read['problem']}: it is passed over, nothing in it read - the site is not set up until it is mended";

                continue;
            }
            if (!is_array($read['value'])) {
                $problems[] = "config/{$file} does not return an array";

                continue;
            }
            $local[substr($file, 0, -strlen('.local.php'))] = $read['value'];
        }
        foreach (self::OPTIONAL_FILES as $file) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $name = substr($file, 0, -strlen('.local.php'));
            $read = self::readLocalFile($path);
            if ($read['problem'] !== null) {
                // Never looser than no file: the fetch settings' defaults - and for the proxies none at all, never
                // Cloudflare's ranges in their place (the file may be the very one that said [] for a host not
                // behind Cloudflare). The site keeps running.
                if ($name === 'client_address') {
                    $local[$name] = ['trusted_proxies' => []];
                }
                $said[] =
                    "config/{$file} {$read['problem']}: it is passed over, nothing in it read - " .
                    ($name === 'client_address' ? 'no proxy is trusted until it is mended: every request counts its own connection' : 'the defaults stand in for it until it is mended');

                continue;
            }
            if (!is_array($read['value'])) {
                $problems[] = "config/{$file} does not return an array";

                continue;
            }
            $local[$name] = $read['value'];
        }

        $values['database'] = $local['database'] ?? [];
        $values['auth'] = ($local['auth'] ?? []) + ['jwks_file' => null, 'leeway' => 60];
        $values['fetch'] = ($local['fetch'] ?? []) + self::FETCH_DEFAULTS;
        // This installation's own proxies (or none) over the committed Cloudflare ranges - the proxies alone: whose
        // header names the address is never a setting (CF-Connecting-IP, App\Web\ClientAddress).
        $own = array_intersect_key((array) ($local['client_address'] ?? []), ['trusted_proxies' => true]);
        $values['client_address'] = $own + (array) ($values['client_address'] ?? []);

        // The origin secret (cloudflare.local.php, by hand): its origin_secret alone. The file is optional, so the
        // site keeps running whatever it holds - but a file that is there and yields no usable secret fails closed,
        // a secret no request carries: one that cannot be read cleanly (the anotoki family's rule, the IAM's
        // iamOriginSecret()), and one read cleanly that names none - blank, no array, another key, a value that is
        // not a string (2026-10-18, docs/decisions.md). Never "no secret", which would believe CF-Connecting-IP from
        // every Cloudflare customer's Worker: only deleting the file switches the secret off. Anything there counts,
        // a folder in the file's place too.
        $cloudflare = $dir . '/' . self::ORIGIN_SECRET_FILE;
        if (file_exists($cloudflare)) {
            $read = self::readLocalFile($cloudflare);
            $secret = $read['problem'] === null && is_array($read['value']) ? $read['value']['origin_secret'] ?? null : null;
            if (is_string($secret) && trim($secret) !== '') {
                $values['cloudflare'] = ['origin_secret' => $secret];
            } else {
                $values['cloudflare'] = ['origin_secret' => self::unmatchableSecret()];
                if ($read['problem'] !== null) {
                    $said[] = 'config/' . self::ORIGIN_SECRET_FILE . " {$read['problem']}: it is passed over, nothing in it read";
                    $said[] =
                        'config/' .
                        self::ORIGIN_SECRET_FILE .
                        ' cannot be read cleanly: no request carries its secret, so every request from Cloudflare\'s ranges counts as the edge it came from until the file is mended';
                } else {
                    $said[] =
                        'config/' .
                        self::ORIGIN_SECRET_FILE .
                        ' holds no origin_secret (a string with something in it): no request carries its secret, so every request from Cloudflare\'s ranges counts as the edge it came from until the file names one - deleting the file switches the secret off';
                }
            }
        }

        if ($testDir !== null && !str_starts_with((string) ($values['database']['database'] ?? ''), self::TEST_DATABASE_PREFIX)) {
            // The test settings never reach a real database: not set up, rather than the wrong one.
            $values['database'] = [];
            $problems[] = self::TEST_CONFIG_ENV . ' names a database that is not a ' . self::TEST_DATABASE_PREFIX . ' one';
        }

        if ($problems === []) {
            $problems = self::check($values);
        }

        foreach ($said as $line) {
            self::sayOncePerDay($storage ?? $root . '/storage', $line);
        }

        return new self($values, $root, $problems, $storage);
    }

    /**
     * One hand-written settings file (config/*.local.php - and the committed app.php, read the same way) - read
     * cleanly, or not at all: {value, problem}, the problem null when it was read cleanly. Setup reads its files
     * through here too (App\Setup\Installer).
     *
     * A file that does not start with <?php - saved with a byte-order mark, a blank line or a space before it, or
     * written by hand without that line - is passed over before PHP ever compiles it: required, it would print
     * what comes before (all of it - the database's password, the origin secret - where the line is missing) ahead
     * of every answer, and one with declare(strict_types=1) after such a start is a compile-time fatal error
     * ("strict_types declaration must be the very first statement") that no try, no handler and no output buffer
     * catches: every request a 500, never "not set up", and a cloudflare.local.php never failing closed (Japanese
     * Academy's review of 2026-10-18, the family's rule since). Its first five bytes are read alone, never the
     * rest. A file PHP may not open is no file either. This covers a bad start only: a file that starts with <?php
     * and still cannot be compiled (a declare after another statement) stays a fatal error PHP raises before
     * anything here can act - nothing printed, nothing served looser - and no slip of an editor makes one.
     *
     * A file that does start with it is required in a closure of its own (it sees none of the caller's variables),
     * with whatever it prints all the same - text after a closing tag, an echo, a buffer of its own left open -
     * caught: it goes nowhere, and the file is no file, its problem the number of bytes it printed. One PHP cannot
     * read or run (a ParseError, an Error) is no file either, said by the kind of error and its line alone: the
     * message can quote the file.
     *
     * @return array{value: mixed, problem: ?string}
     */
    public static function readLocalFile(string $path): array
    {
        $head = @file_get_contents($path, false, null, 0, 5);
        if ($head === false) {
            return ['value' => null, 'problem' => 'could not be read (PHP may not open it)'];
        }
        if (strtolower($head) !== '<?php') {
            return ['value' => null, 'problem' => self::NOT_PHP];
        }

        $level = ob_get_level();
        $value = null;
        $failure = null;
        $printed = 0;
        ob_start();
        try {
            $value = (static fn(string $file): mixed => require $file)($path);
        } catch (\Throwable $e) {
            $failure = 'could not be read (' . get_class($e) . ' on line ' . $e->getLine() . ')';
        } finally {
            // Every buffer down to the caller's, one the file opened and left open included.
            while (ob_get_level() > $level) {
                $buffer = ob_get_clean();
                if ($buffer === false) {
                    break;
                }
                $printed += strlen($buffer);
            }
        }

        if ($failure !== null) {
            return ['value' => null, 'problem' => $failure];
        }
        if ($printed > 0) {
            return ['value' => null, 'problem' => "prints {$printed} bytes of its own (text outside its PHP: text after a closing tag, or something it echoes)"];
        }

        return ['value' => $value, 'problem' => null];
    }

    /**
     * A secret no request can carry - the origin secret of a cloudflare.local.php that is there but cannot be read
     * cleanly or names no usable secret: 256 random bits, made once a process and never printed, logged or stored, so
     * no X-Anotoki-Origin matches it.
     */
    private static function unmatchableSecret(): string
    {
        static $secret = null;

        return $secret ??= bin2hex(random_bytes(32));
    }

    /**
     * A line in PHP's error log - a settings file passed over, by its fault alone -, at most once a UTC day each
     * where storage/cache can keep the day's stamp (every load reads the files, every request a load); where it
     * cannot, each time.
     */
    private static function sayOncePerDay(string $storage, string $line): void
    {
        $stamp = rtrim($storage, '/') . '/cache/config-said-' . substr(hash('sha256', $line), 0, 24) . '.stamp';
        $today = gmdate('Y-m-d');
        if (@file_get_contents($stamp) === $today) {
            return;
        }
        error_log('[config] ' . $line);
        if (!is_dir(dirname($stamp))) {
            @mkdir(dirname($stamp), 0775, true);
        }
        @file_put_contents($stamp, $today, LOCK_EX);
    }

    /** The folder the settings files are read from: BUILD_ANALYZER_TEST_CONFIG's, else stateRoot()'s config/. */
    public static function settingsDir(?string $root = null): string
    {
        return self::testConfigDir() ?? self::stateRoot($root) . '/config';
    }

    /**
     * An optional settings file read outside load() - config/neutral.local.php (App\Support\Neutral) -, cleanly or
     * not at all: its value, or null when it is not there or is passed over (said in PHP's error log at most once a
     * UTC day, by its fault alone, as load() says the others).
     */
    public static function readOptionalFile(string $file, ?string $root = null): mixed
    {
        $path = self::settingsDir($root) . '/' . $file;
        if (!is_file($path)) {
            return null;
        }
        $read = self::readLocalFile($path);
        if ($read['problem'] !== null) {
            $testDir = self::testConfigDir();
            self::sayOncePerDay($testDir !== null ? $testDir . '/storage' : self::stateRoot($root) . '/storage', "config/{$file} {$read['problem']}: it is passed over, nothing in it read");

            return null;
        }

        return $read['value'];
    }

    /** The browser check's settings folder, when BUILD_ANALYZER_TEST_CONFIG names one. */
    private static function testConfigDir(): ?string
    {
        $dir = getenv(self::TEST_CONFIG_ENV);
        if (!is_string($dir) || trim($dir) === '') {
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
        $app = self::readLocalFile($root . '/config/app.php');
        $base = is_array($app['value']) ? $app['value'] : [];
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
            if (!is_scalar($database[$key] ?? null) || trim((string) $database[$key]) === '') {
                $problems[] = "config/database.local.php has no {$key}";
            }
        }
        if (isset($database['port']) && !ctype_digit((string) $database['port'])) {
            $problems[] = 'config/database.local.php has a port that is not a number';
        }

        // Sign-in through the IAM is the only way in, so the IAM must be named - whatever else the file says.
        $auth = $values['auth'] ?? [];
        if (!is_string($auth['issuer'] ?? null) || !preg_match('#^https?://[^/\s]+#', $auth['issuer'])) {
            $problems[] = 'config/auth.local.php has no issuer (the IAM\'s address)';
        } elseif (!IamClient::secureAddress(trim($auth['issuer']))) {
            // The keys tokens are checked with come from the issuer: never over plain http across a network.
            $problems[] = 'config/auth.local.php has an issuer that is not https (http only on this computer)';
        }
        if (!is_string($auth['client_id'] ?? null) || trim($auth['client_id']) === '') {
            $problems[] = 'config/auth.local.php has no client_id';
        }

        $fetch = $values['fetch'] ?? [];
        if (!is_string($fetch['user_agent'] ?? null) || trim($fetch['user_agent']) === '') {
            $problems[] = 'config/fetch.local.php has no user_agent';
        }
        foreach (['timeout', 'delay_ms'] as $key) {
            if (isset($fetch[$key]) && (!is_int($fetch[$key]) || $fetch[$key] < 0)) {
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
     * with a space). A file that is there but cannot be read cleanly, or names no such string, is a secret no request
     * carries (load(): it fails closed) - so null means no file at all (or, in a configuration built in code, no
     * string set).
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
            if (!is_array($value) || !array_key_exists($segment, $value)) {
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
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
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
        return $relative === '' ? $this->root : $this->root . '/' . ltrim($relative, '/');
    }

    public function storage(string $relative = ''): string
    {
        $base = $this->storageRoot ?? $this->path('storage');

        return $relative === '' ? $base : $base . '/' . ltrim($relative, '/');
    }
}
