<?php

namespace App;

use Anotoki\Lib\Admin\AdminContext;
use Anotoki\Lib\Audit\AuditLog;
use Anotoki\Lib\Auth\AccessTokens;
use Anotoki\Lib\Auth\IamClient;
use Anotoki\Lib\Auth\SignInRule;
use Anotoki\Lib\Backups\Backups;
use Anotoki\Lib\ErrorLog\ErrorLog;
use Anotoki\Lib\Feedback\Feedback;
use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\People\People;
use Anotoki\Lib\Translations\Translations;
use App\Admin\AdminTools;
use App\Auth\Iam;
use App\Db\Db;
use App\Db\Migrations;
use App\Http\CurlHttpClient;
use App\Http\Fetcher;
use App\Http\HttpClient;
use App\People\SitePeople;
use App\Repositories\BuildSources;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Translations\SiteTranslations;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Where the app's services come from: the composition root, with no container package behind it.
 *
 * A service is made the first time it is asked for, from its constructor's type hints, and kept for the rest
 * of the request (or command). resetScope() starts the next request afresh - the catalog's in-memory rows,
 * a sync runner's state, the IAM's answer about signing in - except for what outlives requests on purpose:
 * the database connection, the HTTP client, and the anotoki library's people and admin tools, whose route
 * groups (made once with the API) hold the same objects. The tests replace a service with set(), which also
 * survives resetScope().
 */
final class App
{
    /** Kept across resetScope(). */
    private const LASTING = [Db::class, HttpClient::class, People::class, AdminContext::class, AuditLog::class, Feedback::class, Backups::class, ErrorLog::class];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, object> */
    private array $overrides = [];

    /**
     * @param  bool  $web  serving web requests (someone is waiting), rather than running a command
     */
    public function __construct(public readonly Config $config, public readonly bool $web = true) {}

    /**
     * @template T of object
     *
     * @param  class-string<T>  $id
     * @return T
     */
    public function get(string $id): object
    {
        if ($id === self::class) {
            return $this;
        }

        return $this->overrides[$id] ?? ($this->instances[$id] ??= $this->build($id));
    }

    /** Tests: use this object for $id from now on. */
    public function set(string $id, object $instance): void
    {
        $this->overrides[$id] = $instance;
    }

    public function forget(string $id): void
    {
        unset($this->overrides[$id], $this->instances[$id]);
    }

    /** The next request starts with fresh services (see the class comment). */
    public function resetScope(): void
    {
        $this->instances = array_intersect_key($this->instances, array_flip(self::LASTING));
    }

    public function db(): Db
    {
        return $this->get(Db::class);
    }

    /**
     * A new object of $class with its constructor's dependencies from here (build sources, handlers).
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function make(string $class): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new LogicException("Cannot make {$class}: nothing to pass as \${$parameter->getName()}.");
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function build(string $id): object
    {
        return match ($id) {
            Config::class => $this->config,
            Db::class => Db::connect((array) $this->config->get('database')),
            // The family's migration engine (anotoki/lib) on this site's files and table (App\Db\Migrations): the
            // site gate asks it whether an update waits, the Migrations page applies with it. A test puts in one
            // of its own (another folder, a file that fails).
            // Its Apply makes a backup of the database as it is first (the library's backups: before-migration).
            Migrator::class => Migrations::migrator($this->get(Db::class)->raw(), beforeApply: $this->get(Backups::class)->beforeApplyHook()),
            // The site's words (anotoki/lib's translations, with this site's own in Services\Translations\SiteTranslations):
            // the routes of api/routes/translations.php and the command line ask them. Never the People page's delete,
            // on the site's own connection: it makes its own on the connection it is given (SitePeople::deleteData()) -
            // in a restore this one would wait on the restore's locks while the restore waits on it.
            Translations::class => SiteTranslations::make($this->get(Db::class)->raw()),
            HttpClient::class => new CurlHttpClient(),
            Fetcher::class => new Fetcher($this->get(HttpClient::class), $this->get(Cache\Cache::class), $this->config, $this->web),
            // Signing in (anotoki/lib's auth module, api/middleware/auth.php): the IAM, its tokens checked here, and
            // whether it says this site requires signing in - read afresh for each request.
            IamClient::class => Iam::client($this->config),
            AccessTokens::class => new AccessTokens($this->get(IamClient::class)),
            SignInRule::class => new SignInRule($this->get(IamClient::class)),
            // The people the IAM signed in here, and the scrub of the accounts it deleted (App\People\SitePeople).
            People::class => SitePeople::make($this),
            // The admin tools (App\Admin\AdminTools). The API sets an error log of its own, which names each line's
            // route (api/app.php); this one is the command line's.
            ErrorLog::class => AdminTools::errorLog($this),
            AdminContext::class => AdminTools::context($this),
            AuditLog::class => AdminTools::auditLog($this),
            Feedback::class => AdminTools::feedback($this),
            Backups::class => AdminTools::backups($this),
            BuildSourceRegistry::class => new BuildSourceRegistry((array) $this->config->get('sources', []), $this, $this->get(BuildSources::class)),
            default => $this->make($id),
        };
    }
}
