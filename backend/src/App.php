<?php

namespace App;

use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\Translations\Translations;
use App\Auth\IamSignInPolicy;
use App\Auth\SignInPolicy;
use App\Db\Db;
use App\Db\Migrations;
use App\Http\CurlHttpClient;
use App\Http\Fetcher;
use App\Http\HttpClient;
use App\Repositories\BuildSources;
use App\Services\Builds\BuildSourceRegistry;
use App\Services\Translations\SiteTranslations;
use App\Support\Iam\IamTokenVerifier;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Where the app's services come from: the composition root, with no container package behind it.
 *
 * A service is made the first time it is asked for, from its constructor's type hints, and kept for the rest
 * of the request (or command). resetScope() starts the next request afresh - the catalog's in-memory rows,
 * a sync runner's state - except for what outlives requests on purpose: the database connection and the
 * HTTP client. The tests replace a service with set(), which also survives resetScope().
 */
final class App
{
    /** Kept across resetScope(). */
    private const LASTING = [Db::class, HttpClient::class];

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
            return new $class;
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
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
            Migrator::class => Migrations::migrator($this->get(Db::class)->raw()),
            // The site's words (anotoki/lib's translations, with this site's own in Services\Translations\SiteTranslations):
            // the routes of api/routes/translations.php, the People page's delete and the command line ask them.
            Translations::class => SiteTranslations::make($this->get(Db::class)->raw()),
            HttpClient::class => new CurlHttpClient,
            Fetcher::class => new Fetcher($this->get(HttpClient::class), $this->get(Cache\Cache::class), $this->config, $this->web),
            IamTokenVerifier::class => IamTokenVerifier::fromConfig($this->config),
            // Whether the IAM requires sign-in here (the tests put a fixed answer in its place).
            SignInPolicy::class => IamSignInPolicy::fromConfig($this->config),
            BuildSourceRegistry::class => new BuildSourceRegistry((array) $this->config->get('sources', []), $this, $this->get(BuildSources::class)),
            default => $this->make($id),
        };
    }
}
