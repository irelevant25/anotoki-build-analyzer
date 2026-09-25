<?php

namespace App\Services\Builds;

use App\Enums\Game;
use App\Models\BuildSource as BuildSourceModel;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * All scrapers listed in config/hoyo.php ("sources"), plus their database rows.
 */
class BuildSourceRegistry
{
    /** @var array<string, BuildSource>|null */
    private ?array $sources = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, BuildSource>
     */
    public function all(?Game $game = null): array
    {
        if ($this->sources === null) {
            $this->sources = [];
            foreach (config('hoyo.sources', []) as $class) {
                /** @var BuildSource $source */
                $source = $this->container->make($class);
                $this->sources[$source->key()] = $source;
            }
        }

        return $game === null
            ? $this->sources
            : array_filter($this->sources, fn (BuildSource $source) => $source->game() === $game);
    }

    public function get(string $key): BuildSource
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown build source [{$key}]");
    }

    /**
     * The database row for a source, created on first use.
     */
    public function model(BuildSource $source): BuildSourceModel
    {
        return BuildSourceModel::query()->firstOrCreate(
            ['key' => $source->key()],
            ['game' => $source->game()->value, 'name' => $source->name(), 'base_url' => $source->baseUrl(), 'enabled' => true],
        );
    }

    /**
     * Make sure every configured source has a row (so the UI can list them before the first sync).
     */
    public function ensureModels(): void
    {
        foreach ($this->all() as $source) {
            $this->model($source);
        }
    }
}
