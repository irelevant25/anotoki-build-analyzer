<?php

namespace App\Setup;

use Anotoki\Lib\Auth\IamClient;
use App\Config;
use App\Db\Db;
use App\Db\Migrations;
use App\Http\ConnectionFailed;
use App\Http\CurlHttpClient;
use App\Http\HttpClient;
use RuntimeException;
use Throwable;

/**
 * What setup.php does, apart from drawing the page: which values it accepts, the config files it writes,
 * the connection tests, the requirements, the database build. Kept here so it can be tested.
 *
 * It asks for the database and the anotoki IAM - and, on a host, the setup token - and nothing else.
 * Two situations, told apart by where the request comes from (local()):
 *
 *   this computer   a loopback client asking for a localhost address. No token (a CSRF nonce instead), it can
 *                   be run again and again (the saved values are shown, the password never is), and it never
 *                   deletes itself.
 *   a real host     proves the visitor owns the server with a token only FTP can read, refuses to run once
 *                   storage/installed.lock exists, shows no saved value, and deletes itself when done.
 */
final class Installer
{
    public const MIN_PHP = 80400;

    /** @var array<string, string> the extensions the app needs, and what for */
    public const EXTENSIONS = [
        'pdo_pgsql' => 'the database',
        'openssl' => 'checking sign-in tokens',
        'mbstring' => 'text handling',
        'curl' => 'downloading builds, the catalog and showcases',
        'dom' => 'reading build pages',
        'json' => 'everything the API answers',
        'zlib' => 'the database\'s backups (each is compressed)',
    ];

    /** Where config/ and storage/ are: beside backend/ on the host (a release), backend/ itself in a checkout - Config::stateRoot(). */
    private readonly string $state;

    /** @param string $root the code's folder - backend/, or the release's backend/ on the host */
    public function __construct(public readonly string $root, private readonly ?HttpClient $http = null)
    {
        $this->state = Config::stateRoot($root);
    }

    // ── where it runs ─────────────────────────────────────────────────────────────────────────────

    /**
     * This computer: the client is a loopback address and the request was sent to a local name, so a
     * DNS-rebound name pointing at 127.0.0.1 does not count.
     *
     * @param  array<string, mixed>  $server  $_SERVER
     */
    public static function local(array $server): bool
    {
        return self::isLoopback((string) ($server['REMOTE_ADDR'] ?? '')) && self::isLocalHost(self::hostOf((string) ($server['HTTP_HOST'] ?? '')));
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

    public function lockFile(): string
    {
        return $this->state . '/storage/installed.lock';
    }

    public function tokenFile(): string
    {
        return $this->state . '/config/setup-token.local.php';
    }

    // ── the values ────────────────────────────────────────────────────────────────────────────────

    /**
     * What the form starts with: development values on this computer - over them the saved configuration
     * where there is one (never its password) - and the host's own on a real host, which never shows what a
     * file there holds.
     *
     * @return array<string, string>
     */
    public function defaults(bool $local, string $siteUrl): array
    {
        $host = (string) parse_url($siteUrl, PHP_URL_HOST);
        $values = [
            'db_host' => '127.0.0.1',
            'db_port' => '5432',
            'db_database' => 'anotoki_builder',
            'db_username' => $local ? 'postgres' : '',
            // On a host the IAM is expected beside the site: builds.example.com -> iam.example.com.
            'issuer' => $local ? 'http://localhost:4300' : 'https://iam.' . preg_replace('/^[^.]+\./', '', $host),
            'client_id' => 'build-analyzer',
        ];

        if ($local) {
            foreach ($this->saved() as $key => $value) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * The values of the config files there are now, as form fields - without the password.
     *
     * @return array<string, scalar>
     */
    public function saved(): array
    {
        $values = [];
        $database = $this->readConfig('database.local.php');
        foreach (['host' => 'db_host', 'port' => 'db_port', 'database' => 'db_database', 'username' => 'db_username'] as $key => $field) {
            if (isset($database[$key]) && is_scalar($database[$key])) {
                $values[$field] = $database[$key];
            }
        }
        $auth = $this->readConfig('auth.local.php');
        foreach (['issuer', 'client_id'] as $key) {
            if (isset($auth[$key]) && is_scalar($auth[$key])) {
                $values[$key] = $auth[$key];
            }
        }

        return $values;
    }

    /** The saved database password, for a re-run on this computer that leaves the field empty. */
    public function savedPassword(): ?string
    {
        $data = $this->readConfig('database.local.php');

        return is_string($data['password'] ?? null) ? $data['password'] : null;
    }

    /**
     * Checks and cleans the form into the two config files' contents. The password is kept apart so it is
     * never echoed back into the form. Anything else the form sends (an old page's sign-in switch, an owner's
     * id, fetch settings) is not read.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array{database: array<string, mixed>, auth: array{issuer: string, client_id: string}}, 1: list<string>}
     */
    public function validate(array $input, string $password): array
    {
        $errors = [];
        $value = fn(string $key) => trim((string) ($input[$key] ?? ''));

        $database = [
            'host' => $value('db_host'),
            'port' => $value('db_port'),
            'database' => $value('db_database'),
            'username' => $value('db_username'),
            'password' => $password,
            'persistent' => false,
        ];
        if ($database['host'] === '' || !ctype_digit($database['port']) || $database['database'] === '' || $database['username'] === '') {
            $errors[] = 'Fill in the database host, a numeric port, the database name and the username.';
        }
        if (preg_match('/[\x00-\x1f]/', $database['host'] . $database['database'] . $database['username'] . $password)) {
            $errors[] = 'The database values cannot contain line breaks or other control characters.';
        }

        $issuer = rtrim($value('issuer'), '/');
        $clientId = $value('client_id');
        if (!filter_var($issuer, FILTER_VALIDATE_URL) || !in_array(parse_url($issuer, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $errors[] = 'The IAM address must be a URL, like https://iam.anotoki.eu.';
        } elseif (!IamClient::secureAddress($issuer)) {
            // The keys every sign-in is checked with come from there: never over plain http across a network.
            $errors[] = 'The IAM address must be https (http only on this computer: localhost, 127.0.0.1).';
        }
        if (!preg_match('/^[a-z0-9-]{2,64}$/', $clientId)) {
            $errors[] = 'The client id is lowercase letters, digits and dashes (build-analyzer).';
        }

        return [['database' => $database, 'auth' => ['issuer' => $issuer, 'client_id' => $clientId]], $errors];
    }

    /**
     * The files setup writes that are there but cannot be read cleanly, each by its fault (never its text) - on this
     * computer, where the page says so, since it shows none of their values and keeps none of their password. On a
     * real host none: anybody can open the page there before the token proves who they are, and it says nothing of
     * the files it finds - neither a value nor what is wrong with one (a fault can count the bytes a file prints).
     *
     * @return list<string> "config/database.local.php does not start with <?php (...)"
     */
    public function passedOver(bool $local): array
    {
        if (!$local) {
            return [];
        }
        $said = [];
        foreach (['database.local.php', 'auth.local.php'] as $file) {
            $path = $this->state . '/config/' . $file;
            if (is_file($path) && ($problem = Config::readLocalFile($path)['problem']) !== null) {
                $said[] = "config/{$file} {$problem}";
            }
        }

        return $said;
    }

    /**
     * A config file's values, or none - read cleanly or not at all (Config::readLocalFile()): one saved with a
     * byte-order mark or without its <?php line is never compiled, and nothing a file prints reaches the page.
     *
     * @return array<string, mixed>
     */
    private function readConfig(string $file): array
    {
        $path = $this->state . '/config/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $read = Config::readLocalFile($path);

        return $read['problem'] === null && is_array($read['value']) ? $read['value'] : [];
    }

    // ── the files ─────────────────────────────────────────────────────────────────────────────────

    /** A config file: PHP returning the values, so any password reads back exactly as it was entered. */
    public static function phpFile(array $values, string $what, string $change = 'Run setup.php again to change it.'): string
    {
        return "<?php\n\n// Written by setup.php on " .
            gmdate('Y-m-d H:i') .
            " UTC: {$what}.\n" .
            "// Per installation and never committed (config/*.local.php is gitignored). {$change}\n\n" .
            'return ' .
            var_export($values, true) .
            ";\n";
    }

    /** How the app names itself to the sites it reads: the site's address on a host. */
    public static function userAgent(bool $local, string $siteUrl): string
    {
        return 'build-analyzer/1.0 (' . ($local ? 'personal build analyzer' : $siteUrl) . ')';
    }

    /**
     * Writes the database and sign-in files, and the outgoing requests' file with its defaults when there is
     * none yet (one that is there - its values perhaps edited by hand - is kept as it is).
     *
     * @param  array{database: array<string, mixed>, auth: array<string, mixed>}  $settings
     * @param  string  $userAgent  for a new fetch.local.php (userAgent())
     * @return list<string> the files written
     */
    public function writeConfig(array $settings, string $userAgent = 'build-analyzer/1.0'): array
    {
        $files = [
            'database.local.php' => [$settings['database'], 'the database connection', null],
            'auth.local.php' => [$settings['auth'], 'where people sign in: the anotoki IAM', null],
        ];
        if (!is_file($this->state . '/config/fetch.local.php')) {
            $fetch = ['user_agent' => $userAgent] + array_intersect_key(Config::FETCH_DEFAULTS, array_flip(['delay_ms']));
            $files['fetch.local.php'] = [$fetch, 'outgoing requests, the defaults', 'Edit it by hand; setup.php keeps it as it is.'];
        }

        $written = [];
        foreach ($files as $name => [$values, $what, $change]) {
            $contents = $change === null ? self::phpFile($values, $what) : self::phpFile($values, $what, $change);
            if (!$this->writeSecret($this->state . '/config/' . $name, $contents)) {
                throw new RuntimeException("config/{$name} could not be written. Make the config/ folder writable for the web server and try again.");
            }
            $written[] = 'config/' . $name;
        }

        return $written;
    }

    /**
     * A file only its owner can read (umask at creation, chmod after). No-ops on Windows.
     *
     * Owner-only works where PHP runs as the FTP account, as on most shared hosts. Where it runs as another
     * user (the folder belongs to someone else), a 0600 file would be unreadable over FTP - the token could
     * not be copied - so it is made 0644 there instead; neither file is ever served (config/ is denied).
     */
    public function writeSecret(string $path, string $contents): bool
    {
        $mode = $this->phpOwns(dirname($path)) ? 0600 : 0644;
        $previous = umask($mode === 0600 ? 0077 : 0022);
        $ok = @file_put_contents($path, $contents) !== false;
        umask($previous);
        if ($ok) {
            @chmod($path, $mode);
        }

        return $ok;
    }

    public function phpOwns(string $folder): bool
    {
        if (!function_exists('posix_geteuid') || ($owner = @fileowner($folder)) === false) {
            return true;
        }

        return $owner === posix_geteuid();
    }

    // ── the token (a real host) ───────────────────────────────────────────────────────────────────

    public function token(): ?string
    {
        if (!is_file($this->tokenFile())) {
            return null;
        }
        // Read as every settings file is (a broken one is no token: a new one is made in its place).
        $read = Config::readLocalFile($this->tokenFile());
        $data = $read['problem'] === null ? $read['value'] : null;
        $token = is_array($data) ? $data['token'] ?? null : null;

        return is_string($token) && strlen($token) >= 32 ? $token : null;
    }

    public function makeToken(): ?string
    {
        $token = bin2hex(random_bytes(24));

        return $this->writeSecret($this->tokenFile(), self::phpFile(['token' => $token], 'the setup token, deleted when setup is done')) ? $token : null;
    }

    /** Deletes the token and setup.php itself; true when setup.php is gone. */
    public function removeSetupFiles(string $self): bool
    {
        @unlink($this->tokenFile());

        return @unlink($self) || !is_file($self);
    }

    // ── requirements ──────────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public function requirements(): array
    {
        $checks = [
            [
                'label' => 'PHP 8.4 or newer',
                'ok' => PHP_VERSION_ID >= self::MIN_PHP && class_exists('Dom\HTMLDocument'),
                'detail' => 'this is PHP ' . PHP_VERSION . '; the build pages are read with PHP 8.4\'s Dom\HTMLDocument (choose PHP 8.4 in the hosting panel)',
            ],
        ];
        foreach (self::EXTENSIONS as $extension => $why) {
            $checks[] = ['label' => "PHP extension {$extension}", 'ok' => extension_loaded($extension), 'detail' => "needed for {$why}; enable it in the hosting panel"];
        }
        foreach (['config', 'storage'] as $folder) {
            $path = $this->state . '/' . $folder;
            $this->ensureStateFolder($path);
            $checks[] = ['label' => "{$folder}/ writable", 'ok' => is_dir($path) && is_writable($path), 'detail' => 'the web server must be able to write here (FTP: permissions 755 or 775)'];
        }
        $checks[] = ['label' => 'vendor/ uploaded', 'ok' => is_file($this->root . '/vendor/autoload.php'), 'detail' => 'upload the whole build, with its vendor/ folder'];

        return $checks;
    }

    // ── tests of a connection ─────────────────────────────────────────────────────────────────────

    /**
     * Connects with these values and says what is there, in the words every anotoki setup page answers in
     * (never the password). Reads only: it makes nothing, not even the migrations' table.
     *
     *   Connected to the database "<name>": it is empty - Save makes the tables.
     *   Connected to the database "<name>": N update(s) to apply - Save applies them.
     *   Connected to the database "<name>": it is up to date.
     *   Connected to the server. The database "<name>" does not exist yet - Save creates it.
     *   Connected to the server, but there is no database "<name>" and this user may not create one. Make it in the hosting panel first.
     *   The server is there, but "<user>" cannot open the database "<name>": <reason>
     *   Cannot connect: <reason>
     *
     * A database of the first (Laravel) version counts its migrations like any other: Save brings it forward.
     *
     * @param  array<string, mixed>  $database
     * @return array{ok: bool, message: string}
     */
    public function testDatabase(array $database): array
    {
        $name = (string) $database['database'];
        $password = (string) ($database['password'] ?? '');

        try {
            $pdo = Db::pdo($database, $name, false, 5);
            $tables = (int) $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'")->fetchColumn();
            $pending = count(Migrations::migrator($pdo)->applicable());

            return [
                'ok' => true,
                'message' =>
                    "Connected to the database \"{$name}\": " .
                    ($tables === 0 ? 'it is empty - Save makes the tables.' : ($pending > 0 ? "{$pending} update(s) to apply - Save applies them." : 'it is up to date.')),
            ];
        } catch (Throwable $e) {
            $first = $e->getMessage();
        }

        try {
            $server = Db::pdo($database, 'postgres', false, 5);
            $exists = $server->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $exists->execute([$name]);
            if ($exists->fetchColumn()) {
                return ['ok' => false, 'message' => "The server is there, but \"{$database['username']}\" cannot open the database \"{$name}\": " . $this->shortError($first, $password)];
            }
            $create = (bool) $server->query('SELECT rolcreatedb OR rolsuper FROM pg_roles WHERE rolname = current_user')->fetchColumn();

            return $create
                ? ['ok' => true, 'message' => "Connected to the server. The database \"{$name}\" does not exist yet - Save creates it."]
                : ['ok' => false, 'message' => "Connected to the server, but there is no database \"{$name}\" and this user may not create one. Make it in the hosting panel first."];
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Cannot connect: ' . $this->shortError($first, $password)];
        }
    }

    /** A driver message without its SQLSTATE noise, on one line, short - and without the password. */
    private function shortError(string $message, string $password): string
    {
        $message = (string) preg_replace('/^SQLSTATE\[\w+\]\s*(\[\d+\]\s*)?/', '', trim($this->without($message, $password)));
        $message = (string) preg_replace('/\s+/', ' ', $message);

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 299) . '…' : $message;
    }

    /**
     * Asks the IAM for its OpenID configuration ({issuer}/.well-known/openid-configuration).
     *
     * @return array{ok: bool, message: string}
     */
    public function testIam(string $issuer): array
    {
        $issuer = rtrim(trim($issuer), '/');
        if (!filter_var($issuer, FILTER_VALIDATE_URL) || !in_array(parse_url($issuer, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return ['ok' => false, 'message' => 'The IAM address must be a URL, like https://iam.anotoki.eu.'];
        }
        if (!IamClient::secureAddress($issuer)) {
            return ['ok' => false, 'message' => 'The IAM address must be https (http only on this computer: localhost, 127.0.0.1).'];
        }

        try {
            $answer = ($this->http ?? new CurlHttpClient())->get($issuer . '/.well-known/openid-configuration', ['Accept' => 'application/json'], 5);
        } catch (ConnectionFailed $e) {
            return ['ok' => false, 'message' => "No answer from {$issuer}: {$e->getMessage()}"];
        }

        if ($answer->status !== 200) {
            return ['ok' => false, 'message' => "{$issuer} answered HTTP {$answer->status} for its OpenID configuration."];
        }
        $document = $answer->json();
        if (!is_array($document) || !isset($document['issuer'], $document['jwks_uri'])) {
            return ['ok' => false, 'message' => "{$issuer} answered, but not with an OpenID configuration."];
        }
        if (rtrim((string) $document['issuer'], '/') !== $issuer) {
            return ['ok' => false, 'message' => "That IAM calls itself {$document['issuer']}; use exactly that address."];
        }

        return ['ok' => true, 'message' => "The IAM at {$issuer} answered; its keys are at {$document['jwks_uri']}."];
    }

    /**
     * Refuses to go on when the web server hands out files from outside the app's public files: config/
     * would be readable. A probe file in storage/ is asked for over HTTP; when the request itself fails the
     * check cannot tell, and the install goes on (DEV.md says how to check by hand).
     */
    public function checkExposure(string $siteUrl): void
    {
        $name = 'setup-probe-' . bin2hex(random_bytes(6)) . '.txt';
        $secret = bin2hex(random_bytes(16));
        if (@file_put_contents($this->state . '/storage/' . $name, $secret) === false) {
            return;
        }

        try {
            $answer = ($this->http ?? new CurlHttpClient())->get(rtrim($siteUrl, '/') . '/storage/' . $name, [], 8);
            $served = $answer->status === 200 && str_contains($answer->body, $secret);
        } catch (Throwable) {
            $served = false;
        } finally {
            @unlink($this->state . '/storage/' . $name);
        }

        if ($served) {
            throw new RuntimeException(
                'The web server hands out files from storage/ (it served a test file from there), so config/ would be readable too. ' .
                    'Upload the build with its .htaccess files and make sure the host reads them (AllowOverride), then try again.',
            );
        }
    }

    // ── the database ──────────────────────────────────────────────────────────────────────────────

    /**
     * Creates the database when allowed, applies every migration (the engine of migration.php and the
     * Migrations page: App\Db\Migrations), and says what it did. The accounts of a database of the first
     * (Laravel) version go to IAM user 1 (001_adopt_laravel_database.sql).
     *
     * @param  array<string, mixed>  $database
     * @return list<string> a line per step
     */
    public function buildDatabase(array $database): array
    {
        $log = [];
        try {
            if (Db::ensureDatabase($database, (string) $database['database'])) {
                $log[] = "Created the database \"{$database['database']}\".";
            }
        } catch (Throwable) {
            // Most hosting will not let the application's user create databases; the one made in the control
            // panel is what the connection below finds.
        }

        try {
            $pdo = Db::pdo($database, (string) $database['database'], false, 5);
        } catch (Throwable $e) {
            throw new RuntimeException("Cannot connect to the database \"{$database['database']}\": " . $this->without($e->getMessage(), (string) ($database['password'] ?? '')));
        }

        $migrator = Migrations::migrator($pdo);
        $wasLaravel = Migrations::isLaravelDatabase($pdo) && $migrator->status()['applied'] === [];
        $result = $migrator->apply();
        if ($result['busy']) {
            throw new RuntimeException('Another update is being applied to this database right now (the Migrations page or migration.php). Nothing was applied: wait a moment, then save again.');
        }
        if ($result['error'] !== null) {
            throw new RuntimeException(($result['failed'] !== null ? "Migration {$result['failed']['name']} failed: " : '') . $result['error']);
        }

        $log[] = $result['applied'] === [] ? 'The database is up to date.' : 'Applied ' . count($result['applied']) . ' migration(s).';
        if ($wasLaravel) {
            $log[] = 'The database of the first (Laravel) version was brought forward, with all its data, and checked.';
        }

        return $log;
    }

    /** A driver message without the password in it, should a driver ever repeat it. */
    private function without(string $message, string $password): string
    {
        return $password === '' ? $message : str_replace($password, '***', $message);
    }

    /** The configuration the files describe right now. */
    public function config(): Config
    {
        return Config::load($this->root);
    }

    /**
     * A folder of the state made if it is missing - the first setup of a site, whose release never brings one. On the
     * host (a release) it gets a deny-all .htaccess of its own, though the site's switch never sends a request into it.
     */
    private function ensureStateFolder(string $path): void
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        if ($this->state !== rtrim(str_replace('\\', '/', $this->root), '/') && is_dir($path) && !is_file($path . '/.htaccess')) {
            @file_put_contents(
                $path . '/.htaccess',
                "# Not for the web: only PHP reads these files.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
            );
        }
    }
}
