<?php

namespace App\Repositories;

use App\Db\Db;
use App\Enums\Game;
use App\Models\ImportRun;
use App\Support\Clock;
use App\Support\Json;

final class ImportRuns
{
    public function __construct(private readonly Db $db, private readonly Accounts $accounts) {}

    /**
     * @param  array<string, mixed>|null  $summary
     */
    public function create(int $userId, ?int $accountId, Game $game, string $source, string $status, ?string $fileName, ?array $summary, ?string $error = null): ImportRun
    {
        $now = Clock::now();
        $id = (int) $this->db->insert('import_runs', [
            'user_id' => $userId,
            'account_id' => $accountId,
            'game' => $game->value,
            'source' => $source,
            'status' => $status,
            'file_name' => $fileName,
            'summary' => Json::column($summary),
            'error' => $error,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($id) ?? throw new \RuntimeException('The import run was not stored');
    }

    public function find(int $id): ?ImportRun
    {
        $row = $this->db->one('SELECT * FROM import_runs WHERE id = ?', [$id]);

        return $row === null ? null : ImportRun::fromRow($row);
    }

    /** The run with its account (when it has one), as the import answers. */
    public function withAccount(ImportRun $run): ImportRun
    {
        if ($run->account_id !== null) {
            $run->account = $this->accounts->findOwned($run->user_id, $run->account_id);
        }

        return $run;
    }

    /**
     * An account's import history, newest first.
     *
     * @return list<ImportRun>
     */
    public function forAccount(int $accountId, int $limit = 50): array
    {
        return array_map(
            fn(array $row) => ImportRun::fromRow($row),
            $this->db->all('SELECT * FROM import_runs WHERE account_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit), [$accountId]),
        );
    }
}
