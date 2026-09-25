<?php

namespace App\Http\Controllers\Api;

use App\Enums\Game;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ImportRun;
use App\Services\Import\ImportException;
use App\Services\Import\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    /**
     * Showcase import through Enka.Network: only a UID is needed.
     */
    public function enka(Request $request): JsonResponse
    {
        $data = $request->validate([
            'game' => ['required', Rule::enum(Game::class)],
            'uid' => ['required', 'string', 'regex:/^\d{9,10}$/'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ], [
            'uid.regex' => 'A UID is 9 or 10 digits long.',
        ]);

        $target = ! empty($data['account_id']) ? Account::query()->find($data['account_id']) : null;

        try {
            $run = $this->imports->fromEnka(Game::from($data['game']), $data['uid'], $target);
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(self::runSummary($run->load('account')), 201);
    }

    /**
     * Full inventory import from an exporter file. The file content can be sent as multipart "file"
     * or as a JSON string "content" (the web app reads the file in the browser, which also allows pasting).
     */
    public function file(Request $request): JsonResponse
    {
        $data = $request->validate([
            'game' => ['nullable', Rule::enum(Game::class)],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'file' => ['nullable', 'file', 'max:20480'],
            'content' => ['required_without:file', 'nullable', 'string'],
            'file_name' => ['nullable', 'string', 'max:255'],
        ]);

        $content = $request->hasFile('file') ? (string) $request->file('file')->get() : (string) $data['content'];
        $fileName = $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : ($data['file_name'] ?? null);
        $target = ! empty($data['account_id']) ? Account::query()->find($data['account_id']) : null;
        $game = ! empty($data['game']) ? Game::from($data['game']) : null;

        try {
            $run = $this->imports->fromFile($content, $game, $target, $fileName);
        } catch (ImportException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(self::runSummary($run->load('account')), 201);
    }

    public static function runSummary(ImportRun $run): array
    {
        return [
            'id' => $run->id,
            'game' => $run->game->value,
            'source' => $run->source,
            'status' => $run->status,
            'file_name' => $run->file_name,
            'summary' => $run->summary,
            'error' => $run->error,
            'created_at' => $run->created_at?->toIso8601String(),
            'account' => $run->relationLoaded('account') && $run->account ? AccountController::summaryOf($run->account) : null,
            'account_id' => $run->account_id,
        ];
    }
}
