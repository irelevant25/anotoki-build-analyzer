<?php

namespace App\Setup;

use App\Auth\IamGuard;
use App\Config;
use App\Db\Db;
use App\Db\Migrator;
use App\Http\ConnectionFailed;
use App\Http\CurlHttpClient;
use App\Http\HttpClient;
use RuntimeException;
use Throwable;

/**
 * What setup.php does, apart from drawing the page: which values it accepts, the config files it writes,
 * the connection tests, the requirements, the database build. Kept here so it can be tested.
 *
 * Two situations, told apart by where the request comes from (local()):
 *
 *   this computer   a loopback client asking for a localhost address. No token (a CSRF nonce instead), it can
 *                   be run again and again (the saved values are shown, the password never is), sign-in may
 *                   be switched off ("none") for the owner's own copy, and it never deletes itself.
 *   a real host     proves the visitor owns the server with a token only FTP can read, refuses to run once
 *                   storage/installed.lock exists, and deletes itself when done. Sign-in is always "iam".
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
    ];

    public function __construct(public readonly string $root, private readonly ?HttpClient $http = null) {}

    // ── where it runs ─────────────────────────────────────────────────────────────────────────────

    /**
     * This computer: the client is a loopback address and the request was sent to a local name, so a
     * DNS-rebound name pointing at 127.0.0.1 does not count.
     *
     * @param  array<string, mixed>  $server  $_SERVER
     */
    public static function local(array $server): bool
    {
        return IamGuard::isLoopback((string) ($server['REMOTE_ADDR'] ?? ''))
            && IamGuard::isLocalHost(IamGuard::hostOf((string) ($server['HTTP_HOST'] ?? '')));
    }

    public function lockFile(): string
    {
        return $this->root.'/storage/installed.lock';
    }

    public function tokenFile(): string
    {
        return $this->root.'/config/setup-token.local.php';
    }

    // ── the values ────────────────────────────────────────────────────────────────────────────────

    /**
     * What the form starts with: the saved configuration where there is one (never its password), else
     * development values on this computer and the host's own on a real host.
     *
     * @return array<string, string>
     */
    public function defaults(bool $local, string $siteUrl): array
    {
        $host = (string) parse_url($siteUrl, PHP_URL_HOST);
        $values = [
            'db_host' => '127.0.0.1',
            'db_port' => '5432',
            'db_database' => 'build_analyzer',
            'db_username' => $local ? 'postgres' : '',
            'mode' => $local ? 'none' : 'iam',
            // On a host the IAM is expected beside the site: builds.example.com -> iam.example.com.
            'issuer' => $local ? 'http://localhost:4300' : 'https://iam.'.preg_replace('/^[^.]+\./', '', $host),
            'client_id' => 'build-analyzer',
            'local_user_id' => '1',
            'user_agent' => 'build-analyzer/1.0 ('.($local ? 'personal build analyzer' : $siteUrl).')',
            'delay_ms' => '1200',
            'cache_hours' => '12',
            'step_seconds' => '20',
            'stale_hours' => '20',
        ];

        $saved = $this->saved();
        foreach ($saved as $key => $value) {
            $values[$key] = (string) $value;
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
        $read = function (string $file): array {
            $path = $this->root.'/config/'.$file;
            if (! is_file($path)) {
                return [];
            }
            $data = (static fn (string $path) => require $path)($path);

            return is_array($data) ? $data : [];
        };

        $values = [];
        $database = $read('database.local.php');
        foreach (['host' => 'db_host', 'port' => 'db_port', 'database' => 'db_database', 'username' => 'db_username'] as $key => $field) {
            if (isset($database[$key]) && is_scalar($database[$key])) {
                $values[$field] = $database[$key];
            }
        }
        $auth = $read('auth.local.php');
        foreach (['mode', 'issuer', 'client_id', 'local_user_id'] as $key) {
            if (isset($auth[$key]) && is_scalar($auth[$key])) {
                $values[$key] = $auth[$key];
            }
        }
        $fetch = $read('fetch.local.php');
        foreach (['user_agent', 'delay_ms', 'cache_hours', 'step_seconds', 'stale_hours'] as $key) {
            if (isset($fetch[$key]) && is_scalar($fetch[$key])) {
                $values[$key] = $fetch[$key];
            }
        }

        return $values;
    }

    /** The saved database password, for a re-run on this computer that leaves the field empty. */
    public function savedPassword(): ?string
    {
        $path = $this->root.'/config/database.local.php';
        if (! is_file($path)) {
            return null;
        }
        $data = (static fn (string $path) => require $path)($path);

        return is_array($data) && is_string($data['password'] ?? null) ? $data['password'] : null;
    }

    /**
     * Checks and cleans the form into the three config files' contents. The password is kept apart so it is
     * never echoed back into the form.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array{database: array<string, mixed>, auth: array<string, mixed>, fetch: array<string, mixed>}, 1: list<string>}
     */
    public function validate(array $input, string $password, bool $local): array
    {
        $errors = [];
        $value = fn (string $key) => trim((string) ($input[$key] ?? ''));

        $database = [
            'host' => $value('db_host'),
            'port' => $value('db_port'),
            'database' => $value('db_database'),
            'username' => $value('db_username'),
            'password' => $password,
            'persistent' => false,
        ];
        if ($database['host'] === '' || ! ctype_digit($database['port']) || $database['database'] === '' || $database['username'] === '') {
            $errors[] = 'Fill in the database host, a numeric port, the database name and the username.';
        }
        if (preg_match('/[\x00-\x1f]/', $database['host'].$database['database'].$database['username'].$password)) {
            $errors[] = 'The database values cannot contain line breaks or other control characters.';
        }

        $mode = $local && $value('mode') === 'none' ? 'none' : 'iam';
        $issuer = rtrim($value('issuer'), '/');
        $clientId = $value('client_id');
        $userId = $value('local_user_id');
        if ($mode === 'iam') {
            if (! filter_var($issuer, FILTER_VALIDATE_URL) || ! in_array(parse_url($issuer, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $errors[] = 'The IAM address must be a URL, like https://iam.anotoki.eu.';
            }
            if (! preg_match('/^[a-z0-9-]{2,64}$/', $clientId)) {
                $errors[] = 'The client id is lowercase letters, digits and dashes (build-analyzer).';
            }
        }
        if (! ctype_digit($userId) || (int) $userId < 1) {
            $errors[] = 'The owner\'s user id is a positive number (your anotoki IAM user id; 1 on a copy of your own).';
        }

        $fetch = ['user_agent' => $value('user_agent')];
        if ($fetch['user_agent'] === '' || preg_match('/[\x00-\x1f]/', $fetch['user_agent'])) {
            $errors[] = 'Say how the app identifies itself to other sites (the user agent).';
        }
        foreach (['delay_ms' => [0, 600000], 'cache_hours' => [0, 720], 'step_seconds' => [12, 120], 'stale_hours' => [1, 720]] as $key => [$min, $max]) {
            $number = $value($key);
            if (! ctype_digit($number) || (int) $number < $min || (int) $number > $max) {
                $errors[] = "{$key} is a whole number from {$min} to {$max}.";
            } else {
                $fetch[$key] = (int) $number;
            }
        }

        $auth = ['mode' => $mode, 'local_user_id' => (int) $userId];
        if ($mode === 'iam') {
            $auth += ['issuer' => $issuer, 'client_id' => $clientId];
        } else {
            // Kept for the day the copy switches sign-in on; unused while the mode is "none".
            $auth += ['issuer' => $issuer !== '' ? $issuer : 'http://localhost:4300', 'client_id' => $clientId !== '' ? $clientId : 'build-analyzer'];
        }

        return [['database' => $database, 'auth' => $auth, 'fetch' => $fetch], $errors];
    }

    // ── the files ─────────────────────────────────────────────────────────────────────────────────

    /** A config file: PHP returning the values, so any password reads back exactly as it was entered. */
    public static function phpFile(array $values, string $what): string
    {
        return "<?php\n\n// Written by setup.php on ".gmdate('Y-m-d H:i')." UTC: {$what}.\n"
            ."// Per installation and never committed (config/*.local.php is gitignored). Run setup.php again to change it.\n\n"
            .'return '.var_export($values, true).";\n";
    }

    /**
     * Writes the three config files.
     *
     * @param  array{database: array<string, mixed>, auth: array<string, mixed>, fetch: array<string, mixed>}  $settings
     * @return list<string> the files written
     */
    public function writeConfig(array $settings): array
    {
        $files = [
            'database.local.php' => [$settings['database'], 'the database connection'],
            'auth.local.php' => [$settings['auth'], 'how people sign in'],
            'fetch.local.php' => [$settings['fetch'], 'outgoing requests and sync steps'],
        ];

        $written = [];
        foreach ($files as $name => [$values, $what]) {
            if (! $this->writeSecret($this->root.'/config/'.$name, self::phpFile($values, $what))) {
                throw new RuntimeException("config/{$name} could not be written. Make the config/ folder writable for the web server and try again.");
            }
            $written[] = 'config/'.$name;
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
        if (! function_exists('posix_geteuid') || ($owner = @fileowner($folder)) === false) {
            return true;
        }

        return $owner === posix_geteuid();
    }

    // ── the token (a real host) ───────────────────────────────────────────────────────────────────

    public function token(): ?string
    {
        if (! is_file($this->tokenFile())) {
            return null;
        }
        $data = (static fn (string $path) => require $path)($this->tokenFile());
        $token = is_array($data) ? ($data['token'] ?? null) : null;

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

        return @unlink($self) || ! is_file($self);
    }

    // ── requirements ──────────────────────────────────────────────────────────────────────────────

    /**
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    public function requirements(): array
    {
        $checks = [[
            'label' => 'PHP 8.4 or newer',
            'ok' => PHP_VERSION_ID >= self::MIN_PHP && class_exists('Dom\HTMLDocument'),
            'detail' => 'this is PHP '.PHP_VERSION.'; the build pages are read with PHP 8.4\'s Dom\HTMLDocument (choose PHP 8.4 in the hosting panel)',
        ]];
        foreach (self::EXTENSIONS as $extension => $why) {
            $checks[] = ['label' => "PHP extension {$extension}", 'ok' => extension_loaded($extension), 'detail' => "needed for {$why}; enable it in the hosting panel"];
        }
        foreach (['config', 'storage'] as $folder) {
            $path = $this->root.'/'.$folder;
            $checks[] = ['label' => "{$folder}/ writable", 'ok' => is_dir($path) && is_writable($path), 'detail' => 'the web server must be able to write here (FTP: permissions 755 or 775)'];
        }
        $checks[] = ['label' => 'vendor/ uploaded', 'ok' => is_file($this->root.'/vendor/autoload.php'), 'detail' => 'upload the whole build, with its vendor/ folder'];

        return $checks;
    }

    // ── tests of a connection ─────────────────────────────────────────────────────────────────────

    /**
     * Connects with these values and says what is there, in a sentence (never the password).
     *
     * @param  array<string, mixed>  $database
     * @return array{ok: bool, message: string}
     */
    public function testDatabase(array $database): array
    {
        try {
            $pdo = Db::pdo($database, (string) $database['database'], false, 5);
        } catch (Throwable $e) {
            $reason = $this->without($e->getMessage(), (string) ($database['password'] ?? ''));
            if (str_contains($reason, 'does not exist')) {
                try {
                    Db::pdo($database, 'postgres', false, 5);

                    return ['ok' => true, 'message' => "Connected to the server. The database \"{$database['database']}\" does not exist yet; setup will create it (or create it in the hosting panel first, if your user may not)."];
                } catch (Throwable) {
                    // fall through to the original reason
                }
            }

            return ['ok' => false, 'message' => 'Cannot connect: '.$reason];
        }

        $version = (string) $pdo->query('SHOW server_version')->fetchColumn();
        $migrator = new Migrator($pdo, Migrator::directory());
        $tables = (int) $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_schema = current_schema()")->fetchColumn();

        $what = match (true) {
            $migrator->isLaravelDatabase() && $migrator->applied() === [] => 'It was made by the first version of the app (Laravel); setup brings it forward and keeps all its data.',
            $migrator->applied() !== [] => 'It is a build analyzer database: '.count($migrator->applied()).' migration(s) applied, '.count($migrator->pending()).' waiting.',
            $tables === 0 => 'It is empty; setup creates the tables.',
            default => "It already holds {$tables} other table(s); a database of its own is better.",
        };

        return ['ok' => true, 'message' => "Connected to \"{$database['database']}\" (PostgreSQL {$version}). {$what}"];
    }

    /**
     * Asks the IAM for its OpenID configuration ({issuer}/.well-known/openid-configuration).
     *
     * @return array{ok: bool, message: string}
     */
    public function testIam(string $issuer): array
    {
        $issuer = rtrim(trim($issuer), '/');
        if (! filter_var($issuer, FILTER_VALIDATE_URL) || ! in_array(parse_url($issuer, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return ['ok' => false, 'message' => 'The IAM address must be a URL, like https://iam.anotoki.eu.'];
        }

        try {
            $answer = ($this->http ?? new CurlHttpClient)->get($issuer.'/.well-known/openid-configuration', ['Accept' => 'application/json'], 5);
        } catch (ConnectionFailed $e) {
            return ['ok' => false, 'message' => "No answer from {$issuer}: {$e->getMessage()}"];
        }

        if ($answer->status !== 200) {
            return ['ok' => false, 'message' => "{$issuer} answered HTTP {$answer->status} for its OpenID configuration."];
        }
        $document = $answer->json();
        if (! is_array($document) || ! isset($document['issuer'], $document['jwks_uri'])) {
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
     * check cannot tell, and the install goes on (DEVELOPING.md says how to check by hand).
     */
    public function checkExposure(string $siteUrl): void
    {
        $name = 'setup-probe-'.bin2hex(random_bytes(6)).'.txt';
        $secret = bin2hex(random_bytes(16));
        if (@file_put_contents($this->root.'/storage/'.$name, $secret) === false) {
            return;
        }

        try {
            $answer = ($this->http ?? new CurlHttpClient)->get(rtrim($siteUrl, '/').'/storage/'.$name, [], 8);
            $served = $answer->status === 200 && str_contains($answer->body, $secret);
        } catch (Throwable) {
            $served = false;
        } finally {
            @unlink($this->root.'/storage/'.$name);
        }

        if ($served) {
            throw new RuntimeException('The web server hands out files from storage/ (it served a test file from there), so config/ would be readable too. '
                .'Upload the build with its .htaccess files and make sure the host reads them (AllowOverride), then try again.');
        }
    }

    // ── the database ──────────────────────────────────────────────────────────────────────────────

    /**
     * Creates the database when allowed, applies every migration, and says what it did.
     *
     * @param  array<string, mixed>  $database
     * @return list<string> a line per step
     */
    public function buildDatabase(array $database, int $localUserId): array
    {
        $log = [];
        try {
            if (Migrator::ensureDatabase($database, (string) $database['database'])) {
                $log[] = "Created the database \"{$database['database']}\".";
            }
        } catch (Throwable) {
            // Most hosting will not let the application's user create databases; the one made in the control
            // panel is what the connection below finds.
        }

        try {
            $pdo = Db::pdo($database, (string) $database['database'], false, 5);
        } catch (Throwable $e) {
            throw new RuntimeException("Cannot connect to the database \"{$database['database']}\": ".$this->without($e->getMessage(), (string) ($database['password'] ?? '')));
        }

        $migrator = new Migrator($pdo, Migrator::directory());
        $wasLaravel = $migrator->isLaravelDatabase() && $migrator->applied() === [];
        $result = $migrator->applyPending(['local_user_id' => $localUserId]);
        if ($result['failed'] !== null) {
            throw new RuntimeException("Migration {$result['failed']} failed: {$result['error']}");
        }

        $log[] = $result['applied'] === [] ? 'The database is up to date.' : 'Applied '.count($result['applied']).' migration(s).';
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
}
