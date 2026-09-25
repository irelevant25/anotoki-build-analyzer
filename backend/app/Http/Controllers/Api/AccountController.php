<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\BuildPresenter;
use App\Models\Account;
use App\Models\AccountCharacter;
use App\Models\Build;
use App\Models\ImportRun;
use App\Services\Analysis\CharacterAnalyzer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AccountController extends Controller
{
    public function index(): array
    {
        return Account::query()
            ->withCount(['characters', 'weapons', 'items'])
            ->orderBy('game')
            ->orderByDesc('last_import_at')
            ->get()
            ->map(fn (Account $account) => $this->summary($account))
            ->all();
    }

    public function show(Account $account, CharacterAnalyzer $analyzer): array
    {
        $account->loadCount(['characters', 'weapons', 'items']);

        return $this->summary($account) + [
            'characters' => $analyzer->overview($account),
        ];
    }

    public function update(Request $request, Account $account): array
    {
        $data = $request->validate([
            'nickname' => ['sometimes', 'required', 'string', 'max:100'],
        ]);
        $account->update($data);

        return $this->summary($account->loadCount(['characters', 'weapons', 'items']));
    }

    public function destroy(Account $account): Response
    {
        $account->delete();

        return response()->noContent();
    }

    /**
     * Full analysis of one owned character, addressed by its catalog key (e.g. /characters/hutao).
     */
    public function character(Request $request, Account $account, string $key, CharacterAnalyzer $analyzer): array
    {
        $owned = AccountCharacter::query()
            ->where('account_id', $account->id)
            ->whereHas('character', fn ($query) => $query->where('key', $key))
            ->with('character')
            ->firstOrFail();

        $buildId = $request->integer('build') ?: null;
        $detail = $analyzer->detail($account, $owned, $buildId);

        $buildIds = array_map(fn ($evaluation) => $evaluation['build']['id'], $detail['evaluations']);
        $builds = Build::query()->with(['source', 'character'])->whereIn('id', $buildIds)->get()
            ->sortBy(fn (Build $build) => array_search($build->id, $buildIds, true))->values();

        return ['account' => $this->summary($account)] + $detail + ['builds' => BuildPresenter::collection($builds)];
    }

    public function imports(Account $account): array
    {
        return $account->imports()->latest()->limit(50)->get()
            ->map(fn (ImportRun $run) => ImportController::runSummary($run))
            ->all();
    }

    public static function summaryOf(Account $account): array
    {
        return [
            'id' => $account->id,
            'game' => $account->game->value,
            'uid' => $account->uid,
            'nickname' => $account->nickname,
            'level' => $account->level,
            'world_level' => $account->world_level,
            'signature' => $account->signature,
            'has_full_inventory' => $account->has_full_inventory,
            'last_import_at' => $account->last_import_at?->toIso8601String(),
            'counts' => [
                'characters' => $account->characters_count ?? $account->characters()->count(),
                'weapons' => $account->weapons_count ?? $account->weapons()->count(),
                'items' => $account->items_count ?? $account->items()->count(),
            ],
            'meta' => $account->meta,
        ];
    }

    private function summary(Account $account): array
    {
        return self::summaryOf($account);
    }
}
