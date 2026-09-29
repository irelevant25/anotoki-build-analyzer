<?php

namespace App\Presenters;

use App\Models\Account;
use App\Models\ImportRun;
use App\Services\Analysis\CharacterAnalyzer;
use App\Support\Clock;
use App\Support\Neutral;

/**
 * Accounts and their imports as the API sends them (mirrored in frontend/src/app/core/models.ts).
 */
final class AccountPresenter
{
    /** What an account's meta may show: nothing that names the tool or service the data came from. */
    private const META_KEYS = ['region', 'achievements', 'platform', 'format_version', 'trailblazer'];

    public static function summary(Account $account): array
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
            'last_import_at' => Clock::iso($account->last_import_at),
            'counts' => $account->counts ?? ['characters' => 0, 'weapons' => 0, 'items' => 0],
            'meta' => array_intersect_key($account->meta, array_flip(self::META_KEYS)) ?: null,
        ];
    }

    /**
     * An import run. Its source is "showcase" or "file", never the service or tool behind it; the summary
     * carries no tool name, and its messages were written without other sites' names.
     */
    public static function importRun(ImportRun $run): array
    {
        $summary = $run->summary;
        if ($summary !== null) {
            unset($summary['tool']);
            $summary['warnings'] = array_map(fn ($warning) => Neutral::text((string) $warning), $summary['warnings'] ?? []);
        }

        return [
            'id' => $run->id,
            'game' => $run->game->value,
            'source' => CharacterAnalyzer::importKind($run->source),
            'status' => $run->status,
            'file_name' => $run->file_name,
            'summary' => $summary,
            'error' => Neutral::text($run->error),
            'created_at' => Clock::iso($run->created_at),
            'account' => $run->account ? self::summary($run->account) : null,
            'account_id' => $run->account_id,
        ];
    }
}
