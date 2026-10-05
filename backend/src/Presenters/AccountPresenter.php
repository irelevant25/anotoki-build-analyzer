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
    /** What an account's meta may show (and an accounts file carry): nothing that names the tool or service the data came from. */
    public const META_KEYS = ['region', 'achievements', 'platform', 'format_version', 'trailblazer'];

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
     *
     * What a page words in the reader's language stands beside the English: a failed run's kind as error_code
     * and error_params (null for a run stored before kinds were kept), lifted out of the stored summary - a
     * failed run is sent with no summary, as it always was - and the warnings' kinds as
     * summary.warning_kinds, index for index with summary.warnings, sent only when they are as many.
     */
    public static function importRun(ImportRun $run): array
    {
        $summary = $run->summary;
        $errorCode = null;
        $errorParams = null;

        if ($run->status === 'failed') {
            $error = $summary['error'] ?? null;
            if (is_array($error) && is_string($error['code'] ?? null) && $error['code'] !== '') {
                $errorCode = $error['code'];
                $errorParams = self::params($error['params'] ?? null);
            }
            $summary = null;
        }

        if ($summary !== null) {
            unset($summary['tool']);
            $summary['warnings'] = array_map(fn ($warning) => Neutral::text((string) $warning), $summary['warnings'] ?? []);
            // The names the file wrote that matched nothing: as the file wrote them, so a site's name too.
            if (is_array($summary['unmatched'] ?? null)) {
                $summary['unmatched'] = Neutral::deep($summary['unmatched']);
            }

            $kinds = self::warningKinds($summary['warning_kinds'] ?? null);
            if ($kinds !== null && count($kinds) === count($summary['warnings'])) {
                $summary['warning_kinds'] = $kinds;
            } else {
                unset($summary['warning_kinds']);
            }
        }

        return [
            'id' => $run->id,
            'game' => $run->game->value,
            'source' => CharacterAnalyzer::importKind($run->source),
            'status' => $run->status,
            'file_name' => $run->file_name,
            'summary' => $summary,
            'error' => Neutral::text($run->error),
            'error_code' => $errorCode,
            'error_params' => $errorParams,
            'created_at' => Clock::iso($run->created_at),
            'account' => $run->account ? self::summary($run->account) : null,
            'account_id' => $run->account_id,
        ];
    }

    /**
     * The parameters of an import error or warning as they are sent: a JSON object even when there are none,
     * and neutral, because they quote names from an uploaded file.
     */
    public static function params(mixed $params): array|\stdClass
    {
        if (! is_array($params) || $params === []) {
            return new \stdClass;
        }

        return Neutral::deep($params);
    }

    /**
     * Stored warning kinds as a list of {code, params}; null when they are not that.
     *
     * @return list<array{code: string, params: array|\stdClass}>|null
     */
    private static function warningKinds(mixed $stored): ?array
    {
        if (! is_array($stored)) {
            return null;
        }

        $kinds = [];
        foreach ($stored as $kind) {
            if (! is_array($kind) || ! is_string($kind['code'] ?? null)) {
                return null;
            }
            $kinds[] = ['code' => $kind['code'], 'params' => self::params($kind['params'] ?? null)];
        }

        return $kinds;
    }
}
