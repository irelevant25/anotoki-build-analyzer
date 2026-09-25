<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncBuildSource;
use App\Models\Build;
use App\Models\BuildSource;
use App\Models\SyncRun;
use App\Services\Builds\BuildSourceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Build sources (scraped websites) and their sync history.
 */
class SourceController extends Controller
{
    public function index(BuildSourceRegistry $registry): array
    {
        $registry->ensureModels();
        $configured = array_keys($registry->all());

        $buildCounts = Build::query()->selectRaw('build_source_id, count(*) as builds, count(distinct game_character_id) as characters')
            ->groupBy('build_source_id')->get()->keyBy('build_source_id');

        return BuildSource::query()
            ->whereIn('key', $configured)
            ->orderBy('game')->orderBy('name')
            ->get()
            ->map(function (BuildSource $source) use ($buildCounts) {
                $latest = $source->runs()->latest('started_at')->first();

                return self::present($source) + [
                    'builds' => (int) ($buildCounts[$source->id]->builds ?? 0),
                    'characters' => (int) ($buildCounts[$source->id]->characters ?? 0),
                    'running' => $latest?->status === 'running' && $latest->started_at->gt(now()->subHours(2)),
                    'latest_run' => $latest ? self::run($latest) : null,
                ];
            })
            ->all();
    }

    public function update(Request $request, string $key): array
    {
        $source = BuildSource::query()->where('key', $key)->firstOrFail();
        $source->update($request->validate(['enabled' => ['required', 'boolean']]));

        return self::present($source);
    }

    /**
     * Queue a sync. Needs a running queue worker (php artisan queue:work).
     */
    public function sync(string $key, BuildSourceRegistry $registry): JsonResponse
    {
        $source = $registry->get($key);
        SyncBuildSource::dispatch($source->key());

        return response()->json(['queued' => true, 'source' => $key], 202);
    }

    public function runs(Request $request): array
    {
        $query = SyncRun::query()->with('source')->latest('started_at');
        if ($request->filled('source')) {
            $query->whereHas('source', fn ($q) => $q->where('key', $request->string('source')));
        }

        return $query->limit(min(100, $request->integer('limit', 30)))->get()
            ->map(fn (SyncRun $run) => self::run($run) + ['source' => ['key' => $run->source->key, 'name' => $run->source->name, 'game' => $run->source->game->value]])
            ->all();
    }

    public function showRun(SyncRun $run): array
    {
        return self::run($run->load('source')) + [
            'source' => ['key' => $run->source->key, 'name' => $run->source->name, 'game' => $run->source->game->value],
            'log' => $run->log,
        ];
    }

    private static function present(BuildSource $source): array
    {
        return [
            'key' => $source->key,
            'game' => $source->game->value,
            'name' => $source->name,
            'base_url' => $source->base_url,
            'enabled' => $source->enabled,
            'last_synced_at' => $source->last_synced_at?->toIso8601String(),
            'last_status' => $source->last_status,
            'last_error' => $source->last_error,
        ];
    }

    private static function run(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'stats' => $run->stats,
        ];
    }
}
