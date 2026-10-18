<?php

namespace App\Services\Builds;

use App\App;
use App\Enums\Game;
use App\Models\BuildSource as BuildSourceModel;
use App\Repositories\BuildSources;
use InvalidArgumentException;

/**
 * All scrapers listed in config/app.php ("sources"), their database rows, and what the outside world calls
 * them.
 *
 * Nothing the API sends to be shown names a site, so a source is "Source N" of its game - N its place among
 * that game's sources in the configuration - and the API's key for it is "{game}-{N}" ("genshin-2"). The
 * internal key ("prydwen.genshin") stays in the database and on the command line.
 */
class BuildSourceRegistry
{
    /** @var array<string, BuildSource>|null internal key => source */
    private ?array $sources = null;

    /** @var array<string, int> internal key => number within its game */
    private array $numbers = [];

    /**
     * @param  list<class-string<BuildSource>>  $classes
     */
    public function __construct(private readonly array $classes, private readonly App $app, private readonly BuildSources $rows) {}

    /**
     * @return array<string, BuildSource>
     */
    public function all(?Game $game = null): array
    {
        if ($this->sources === null) {
            $this->sources = [];
            $counters = [];
            foreach ($this->classes as $class) {
                /** @var BuildSource $source */
                $source = $this->app->make($class);
                $this->sources[$source->key()] = $source;
                $gameKey = $source->game()->value;
                $counters[$gameKey] = ($counters[$gameKey] ?? 0) + 1;
                $this->numbers[$source->key()] = $counters[$gameKey];
            }
        }

        return $game === null ? $this->sources : array_filter($this->sources, fn(BuildSource $source) => $source->game() === $game);
    }

    public function get(string $key): BuildSource
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown build source [{$key}]");
    }

    /** A source by its API key ("genshin-2"), or by its internal key (the command line). */
    public function find(string $key): ?BuildSource
    {
        foreach ($this->all() as $internal => $source) {
            if ($internal === $key || $this->publicKey($internal) === $key) {
                return $source;
            }
        }

        return null;
    }

    /** "Source 2"; a source no longer configured is a "Retired source". */
    public function label(string $key): string
    {
        $this->all();

        return isset($this->numbers[$key]) ? 'Source ' . $this->numbers[$key] : 'Retired source';
    }

    /** "genshin-2", or null for a source no longer configured. */
    public function publicKey(string $key): ?string
    {
        $source = $this->all()[$key] ?? null;

        return $source ? $source->game()->value . '-' . $this->numbers[$key] : null;
    }

    /** "Source 2 (Genshin Impact)", for log lines and skip reasons. */
    public function title(BuildSource $source): string
    {
        return $this->label($source->key()) . ' (' . $source->game()->label() . ')';
    }

    /**
     * The database row for a source, created on first use. Its name column holds the label, so nothing
     * shown from the database names the site either.
     */
    public function model(BuildSource $source): BuildSourceModel
    {
        $model = $this->rows->firstOrCreate($source->key(), $source->game()->value, $this->label($source->key()), $source->baseUrl());
        if ($model->name !== $this->label($source->key())) {
            $this->rows->update($model, ['name' => $this->label($source->key())]);
        }

        return $model;
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
