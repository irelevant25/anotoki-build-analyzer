<?php

namespace App\Services\Import;

use App\Enums\Game;
use App\Models\Account;
use App\Models\AccountCharacter;
use App\Models\AccountItem;
use App\Models\AccountWeapon;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Models\ImportRun;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\CatalogSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes a ParsedAccount to the database.
 *
 * - Full inventory exports (GOOD, scanner files) replace all weapons and artifacts/relics of the account.
 * - Showcase data (Enka) only replaces the gear of the characters it contains.
 * Characters are always upserted, never deleted.
 */
class AccountImporter
{
    private bool $catalogRefreshed = false;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly CatalogSync $catalogSync,
    ) {}

    public function import(ParsedAccount $parsed, ?Account $target = null, ?string $fileName = null): ImportRun
    {
        $this->catalog->resetUnmatched();
        $this->ensureCatalog($parsed->game);

        if ($target && $target->game !== $parsed->game) {
            throw new ImportException("The selected account is a {$target->game->label()} account, but the data is from {$parsed->game->label()}.");
        }
        if ($target && $target->uid && $parsed->uid && $target->uid !== $parsed->uid) {
            throw new ImportException("The data belongs to UID {$parsed->uid}, but the selected account is UID {$target->uid}.");
        }

        return DB::transaction(function () use ($parsed, $target, $fileName) {
            $account = $this->account($parsed, $target);
            $warnings = $parsed->warnings;

            $characterIds = [];
            foreach ($parsed->characters as $row) {
                $character = $this->character($parsed->game, $row['ref']);
                if (! $character) {
                    $warnings[] = 'Unknown character '.json_encode($row['ref']).' was skipped.';

                    continue;
                }
                $characterIds[] = $character->id;

                $existing = AccountCharacter::query()->firstOrNew([
                    'account_id' => $account->id,
                    'game_character_id' => $character->id,
                ]);
                $existing->fill([
                    'level' => $row['level'],
                    'ascension' => $row['ascension'],
                    'rank' => $row['rank'],
                    'talents' => $row['talents'] ?? $existing->talents,
                    // File exports have no totals; keep the last Enka totals (the UI shows their date).
                    'final_stats' => $row['final_stats'] ?? $existing->final_stats,
                    'source' => $row['final_stats'] ? $parsed->source : ($existing->final_stats ? $existing->source : $parsed->source),
                    'synced_at' => $row['final_stats'] ? now() : ($existing->synced_at ?? now()),
                ])->save();
            }

            // Remove the gear this import replaces.
            if ($parsed->fullInventory) {
                AccountWeapon::query()->where('account_id', $account->id)->delete();
                AccountItem::query()->where('account_id', $account->id)->delete();
            } elseif ($characterIds !== []) {
                AccountWeapon::query()->where('account_id', $account->id)->whereIn('equipped_character_id', $characterIds)->delete();
                AccountItem::query()->where('account_id', $account->id)->whereIn('equipped_character_id', $characterIds)->delete();
            }

            [$weaponCount, $weaponWarnings] = $this->insertWeapons($account, $parsed);
            [$itemCount, $itemWarnings] = $this->insertItems($account, $parsed);

            $unmatched = [];
            foreach ($this->catalog->unmatched() as $bucket => $names) {
                $unmatched[$bucket] = array_values(array_unique($names));
            }

            return ImportRun::query()->create([
                'account_id' => $account->id,
                'game' => $parsed->game->value,
                'source' => $parsed->source,
                'status' => ($unmatched || $weaponWarnings || $itemWarnings) ? 'partial' : 'success',
                'file_name' => $fileName,
                'summary' => [
                    'characters' => count($characterIds),
                    'weapons' => $weaponCount,
                    'items' => $itemCount,
                    'full_inventory' => $parsed->fullInventory,
                    'tool' => $parsed->meta['tool'] ?? ($parsed->source === 'enka' ? 'Enka.Network' : null),
                    'warnings' => array_values(array_unique([...$warnings, ...$weaponWarnings, ...$itemWarnings])),
                    'unmatched' => $unmatched,
                ],
            ]);
        });
    }

    /**
     * Record a failed attempt so the import history shows it.
     */
    public function recordFailure(Game $game, string $source, Throwable $e, ?Account $account = null, ?string $fileName = null): ImportRun
    {
        return ImportRun::query()->create([
            'account_id' => $account?->id,
            'game' => $game->value,
            'source' => $source,
            'status' => 'failed',
            'file_name' => $fileName,
            'error' => mb_substr($e->getMessage(), 0, 2000),
        ]);
    }

    private function account(ParsedAccount $parsed, ?Account $target): Account
    {
        $account = $target
            ?? ($parsed->uid ? Account::query()->firstOrNew(['game' => $parsed->game->value, 'uid' => $parsed->uid]) : new Account(['game' => $parsed->game->value]));

        $account->fill(array_filter([
            'uid' => $account->uid ?? $parsed->uid,
            'nickname' => $parsed->nickname,
            'level' => $parsed->level,
            'world_level' => $parsed->worldLevel,
            'signature' => $parsed->signature,
        ], fn ($value) => $value !== null));

        $account->has_full_inventory = $account->has_full_inventory || $parsed->fullInventory;
        $account->last_import_at = now();
        $account->meta = array_merge($account->meta ?? [], array_filter($parsed->meta, fn ($value) => $value !== null));
        if (! $account->nickname) {
            $account->nickname = $parsed->game->label().' account';
        }
        $account->save();

        return $account;
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function insertWeapons(Account $account, ParsedAccount $parsed): array
    {
        $rows = [];
        $warnings = [];
        $now = now();

        foreach ($parsed->weapons as $weapon) {
            $model = $this->weapon($parsed->game, $weapon['ref']);
            if (! $model) {
                $warnings[] = 'Unknown '.strtolower($parsed->game->weaponLabel()).' '.json_encode($weapon['ref']).' was skipped.';

                continue;
            }
            $rows[] = [
                'account_id' => $account->id,
                'game_weapon_id' => $model->id,
                'level' => $weapon['level'],
                'ascension' => $weapon['ascension'],
                'refinement' => max(1, min(5, $weapon['refinement'])),
                'equipped_character_id' => $weapon['location'] ? $this->character($parsed->game, $weapon['location'])?->id : null,
                'locked' => $weapon['locked'],
                'external_uid' => $weapon['uid'],
                'source' => $parsed->source,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            AccountWeapon::query()->insert($chunk);
        }

        return [count($rows), $warnings];
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function insertItems(Account $account, ParsedAccount $parsed): array
    {
        $rows = [];
        $warnings = [];
        $now = now();

        foreach ($parsed->items as $item) {
            $set = $this->set($parsed->game, $item['set'], $item['slot']);
            if (! $set) {
                $warnings[] = 'Unknown set '.json_encode($item['set']).' was skipped.';

                continue;
            }
            $rows[] = [
                'account_id' => $account->id,
                'game_set_id' => $set->id,
                'slot' => $item['slot'],
                'rarity' => $item['rarity'],
                'level' => $item['level'],
                'main_stat' => $item['main_stat'],
                'main_value' => $item['main_value'],
                'substats' => json_encode(array_values($item['substats'])),
                'equipped_character_id' => $item['location'] ? $this->character($parsed->game, $item['location'])?->id : null,
                'locked' => $item['locked'],
                'external_uid' => $item['uid'],
                'source' => $parsed->source,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // SQLite limits bound parameters per statement; 14 columns x 60 rows stays well below it.
        foreach (array_chunk($rows, 60) as $chunk) {
            AccountItem::query()->insert($chunk);
        }

        return [count($rows), $warnings];
    }

    private function character(Game $game, array $ref): ?GameCharacter
    {
        /** @var GameCharacter|null */
        return $this->resolve($game, Catalog::CHARACTER, $ref, fn (string $name) => $this->catalog->character($game, $name));
    }

    private function weapon(Game $game, array $ref): ?GameWeapon
    {
        /** @var GameWeapon|null */
        return $this->resolve($game, Catalog::WEAPON, $ref, fn (string $name) => $this->catalog->weapon($game, $name));
    }

    private function set(Game $game, array $ref, string $slot): ?GameSet
    {
        $kind = $game === Game::Genshin ? 'artifact' : ($game->isPlanarSlot($slot) ? 'planar' : 'relic');

        /** @var GameSet|null */
        return $this->resolve($game, Catalog::SET, $ref, fn (string $name) => $this->catalog->set($game, $name, ['kind' => $kind]));
    }

    /**
     * External ID first (exact), then name. An unknown ID triggers one catalog refresh (a character may be
     * newer than the local catalog) before falling back to the name.
     */
    private function resolve(Game $game, string $type, array $ref, callable $byName): ?Model
    {
        if (! empty($ref['key'])) {
            $found = $this->catalog->find($game, $type, $ref['key']);
            if ($found) {
                return $found;
            }
        }

        if (! empty($ref['external_id'])) {
            $found = $this->catalog->byExternalId($game, $type, $ref['external_id']);
            if (! $found && $this->refreshCatalog($game)) {
                $found = $this->catalog->byExternalId($game, $type, $ref['external_id']);
            }
            if ($found) {
                return $found;
            }
        }

        $name = $ref['name'] ?? null;
        if ($name) {
            return $byName($name);
        }

        return null;
    }

    private function ensureCatalog(Game $game): void
    {
        if (GameCharacter::query()->where('game', $game->value)->whereNotNull('external_id')->doesntExist()) {
            $this->refreshCatalog($game);
        }
    }

    private function refreshCatalog(Game $game): bool
    {
        if ($this->catalogRefreshed) {
            return false;
        }
        $this->catalogRefreshed = true;

        try {
            $this->catalogSync->sync($game);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
