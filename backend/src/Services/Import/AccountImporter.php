<?php

namespace App\Services\Import;

use App\Auth\Visitors;
use App\Cache\Cache;
use App\Config;
use App\Db\Db;
use App\Enums\Game;
use App\Models\Account;
use App\Models\CatalogEntry;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Models\ImportRun;
use App\Models\User;
use App\Repositories\Accounts;
use App\Repositories\ImportRuns;
use App\Repositories\Users;
use App\Services\Catalog\Catalog;
use App\Services\Catalog\CatalogSync;
use App\Support\Clock;
use App\Support\Json;
use App\Support\Log;
use App\Support\Neutral;
use Throwable;

/**
 * Writes a ParsedAccount to the database, into an account of the importing user.
 *
 * - Full inventory exports (GOOD) replace all weapons and artifacts of the account.
 * - Showcase data only replaces the gear of the characters it contains.
 * Characters are always upserted, never deleted.
 *
 * The game catalog is shared by every user, and an uploaded file is not trusted with it: names are only
 * looked up, and whatever the catalog lacks is skipped and reported. An in-game ID the catalog lacks may
 * refresh that one list from the game data provider (not from the file), rate-limited across all users.
 */
class AccountImporter
{
    /** @var array<string, true> "game:type" lists refreshed (or refused) during this import */
    private array $refreshTried = [];

    public function __construct(
        private readonly Catalog $catalog,
        private readonly CatalogSync $catalogSync,
        private readonly Db $db,
        private readonly Accounts $accounts,
        private readonly ImportRuns $runs,
        private readonly Users $users,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    public function import(ParsedAccount $parsed, User $owner, ?Account $target = null, ?string $fileName = null): ImportRun
    {
        $this->catalog->resetUnmatched();
        $this->checkTarget($parsed, $owner, $target);

        // Any catalog download happens here, before the write transaction, so no transaction is held
        // open across a network request.
        $this->prepareCatalog($parsed);

        $id = $this->db->transaction(function () use ($parsed, $owner, $target, $fileName) {
            // One writer of an owner's accounts at a time: another import, an accounts file, the move. What
            // the request found before this may have changed meanwhile - the owner's row moved, swept or
            // deleted, the account deleted or moved - so it is all read again under the lock.
            if (! $this->users->lockForWrite($owner->id)) {
                throw Visitors::ownerGone($owner);
            }
            if ($target !== null) {
                $target = $this->accounts->findOwned($owner->id, $target->id, withCounts: false)
                    ?? throw new ImportException('The selected account does not exist.', 'account_missing');
                $this->checkTarget($parsed, $owner, $target);
            }
            $this->roomForAccount($parsed, $owner, $target);
            $account = $this->account($parsed, $owner, $target);
            $warnings = $parsed->warnings;
            $now = Clock::now();

            $characterIds = [];
            $characterWarnings = [];
            foreach ($parsed->characters as $row) {
                $character = $this->character($parsed->game, $row['ref']);
                if (! $character) {
                    $characterWarnings[] = ImportKinds::warning('unknown_character', ['ref' => ImportKinds::ref($row['ref'])],
                        'Unknown character '.json_encode($row['ref']).' was skipped.');

                    continue;
                }
                $characterIds[] = $character->id;

                $existing = $this->accounts->characterRow($account->id, $character->id);
                $existingStats = Json::decode($existing['final_stats'] ?? null);
                $values = [
                    'level' => $row['level'],
                    'ascension' => $row['ascension'],
                    'rank' => $row['rank'],
                    'talents' => Json::column($row['talents'] ?? Json::decode($existing['talents'] ?? null)),
                    // File exports have no totals; keep the last showcase totals (the UI shows their date).
                    'final_stats' => Json::column($row['final_stats'] ?? $existingStats),
                    'source' => $row['final_stats'] ? $parsed->source : ($existingStats ? $existing['source'] : $parsed->source),
                    'synced_at' => $row['final_stats'] ? $now : (($existing['synced_at'] ?? null) ?? $now),
                    'updated_at' => $now,
                ];

                if ($existing === null) {
                    $this->db->insert('account_characters', [
                        'account_id' => $account->id,
                        'game_character_id' => $character->id,
                        'created_at' => $now,
                    ] + $values);
                } else {
                    $this->db->update('account_characters', $values, ['id' => (int) $existing['id']]);
                }
            }

            // Remove the gear this import replaces.
            if ($parsed->fullInventory) {
                $this->db->run('DELETE FROM account_weapons WHERE account_id = ?', [$account->id]);
                $this->db->run('DELETE FROM account_items WHERE account_id = ?', [$account->id]);
            } elseif ($characterIds !== []) {
                [$in, $params] = Db::in($characterIds);
                $this->db->run("DELETE FROM account_weapons WHERE account_id = ? AND equipped_character_id IN ({$in})", [$account->id, ...$params]);
                $this->db->run("DELETE FROM account_items WHERE account_id = ? AND equipped_character_id IN ({$in})", [$account->id, ...$params]);
            }

            [$weaponCount, $weaponWarnings] = $this->insertWeapons($account, $parsed);
            [$itemCount, $itemWarnings] = $this->insertItems($account, $parsed);

            $unmatched = [];
            foreach ($this->catalog->unmatched() as $bucket => $names) {
                $unmatched[$bucket] = array_values(array_unique($names));
            }
            $skipped = [...$characterWarnings, ...$weaponWarnings, ...$itemWarnings];
            if ($skipped) {
                $skipped[] = ImportKinds::warning('catalog_behind', [],
                    'What the game catalog does not know yet was skipped. Import again later: the catalog catches up on its own, or an editor refreshes it in the admin panel (Builds page).');
            }

            // Each sentence once, the first of its kind kept; the kinds stand index for index beside them.
            $said = [];
            foreach ([...$warnings, ...$skipped] as $warning) {
                $said[$warning['message']] ??= $warning;
            }
            $said = array_values($said);

            return $this->runs->create($owner->id, $account->id, $parsed->game, $parsed->source, ($unmatched || $skipped) ? 'partial' : 'success', $fileName, [
                'characters' => count($characterIds),
                'weapons' => $weaponCount,
                'items' => $itemCount,
                'full_inventory' => $parsed->fullInventory,
                'tool' => $parsed->meta['tool'] ?? null,
                'warnings' => array_column($said, 'message'),
                'warning_kinds' => array_map(fn (array $warning) => ['code' => $warning['kind'], 'params' => $warning['params']], $said),
                'unmatched' => $unmatched,
            ])->id;
        });

        return $this->runs->withAccount($this->runs->find($id));
    }

    /**
     * Record a failed attempt so the import history shows it (on the account, when there is one of the owner's).
     * The history is shown in the UI, so the message is kept free of other sites' names and addresses. What
     * happened is kept as a kind too, in the summary ({"error": {code, params}}): the page words it from that,
     * and the presenter lifts it out, so a failed run is still sent without a summary.
     *
     * Null when there is nobody to record it for: the owner's row went while the import ran (a visitor moved
     * or swept, a person's data deleted). The account is named only while it is still the owner's.
     */
    public function recordFailure(Game $game, string $source, Throwable $e, User $owner, ?Account $account = null, ?string $fileName = null): ?ImportRun
    {
        $message = $e instanceof ImportException ? $e->getMessage() : 'The import stopped with an unexpected error: '.$e->getMessage();
        if (! $e instanceof ImportException) {
            Log::exception($e, 'Import');
        }
        $error = $e instanceof ImportException ? ['code' => $e->kind, 'params' => $e->params] : ['code' => 'unexpected', 'params' => []];

        return $this->db->transaction(function () use ($game, $source, $owner, $account, $fileName, $error, $message): ?ImportRun {
            if (! $this->users->lockForWrite($owner->id)) {
                return null;
            }
            $accountId = $account !== null && $account->user_id === $owner->id
                && $this->accounts->findOwned($owner->id, $account->id, withCounts: false) !== null ? $account->id : null;

            return $this->runs->create($owner->id, $accountId, $game, $source, 'failed', $fileName, ['error' => $error],
                mb_substr((string) Neutral::text($message), 0, 2000));
        });
    }

    /** The account an import targets must be the owner's, of the data's game, and of its UID when both have one. */
    private function checkTarget(ParsedAccount $parsed, User $owner, ?Account $target): void
    {
        if ($target && $target->user_id !== $owner->id) {
            // Callers only pass the owner's accounts; refuse anything else rather than write into it.
            throw new ImportException('The selected account does not exist.', 'account_missing');
        }
        if ($target && $target->game !== $parsed->game) {
            // One game since 0.5.0: no data can be of another game than its account (a second game brings back a
            // kind of its own for this, with the words for it).
            throw new \LogicException("The selected account is a {$target->game->label()} account, but the data is from {$parsed->game->label()}.");
        }
        if ($target && $target->uid && $parsed->uid && $target->uid !== $parsed->uid) {
            throw new ImportException("The data belongs to UID {$parsed->uid}, but the selected account is UID {$target->uid}.", 'account_uid_mismatch', [
                'data_uid' => $parsed->uid,
                'account_uid' => $target->uid,
            ]);
        }
    }

    /**
     * Under the owner's lock: a visitor's import that would add a game account past visitors.max_accounts is
     * refused (the route checked before any work; two imports at once could both have passed that).
     */
    private function roomForAccount(ParsedAccount $parsed, User $owner, ?Account $target): void
    {
        if (! $owner->isVisitor() || $target !== null) {
            return;
        }
        if ($parsed->uid && $this->accounts->findOwnedByUid($owner->id, $parsed->game, $parsed->uid) !== null) {
            return;
        }
        $max = (int) $this->config->get('visitors.max_accounts', 5);
        if ($this->accounts->countOwned($owner->id) >= $max) {
            throw Visitors::accountLimit($max);
        }
    }

    private function account(ParsedAccount $parsed, User $owner, ?Account $target): Account
    {
        $account = $target
            ?? ($parsed->uid ? $this->accounts->findOwnedByUid($owner->id, $parsed->game, $parsed->uid) : null)
            ?? new Account(id: 0, user_id: $owner->id, game: $parsed->game, uid: $parsed->uid);

        $account->uid ??= $parsed->uid;
        foreach (['nickname' => $parsed->nickname, 'level' => $parsed->level, 'world_level' => $parsed->worldLevel, 'signature' => $parsed->signature] as $field => $value) {
            if ($value !== null) {
                $account->{$field} = $value;
            }
        }

        $account->has_full_inventory = $account->has_full_inventory || $parsed->fullInventory;
        $account->last_import_at = Clock::now();
        $account->meta = array_merge($account->meta, array_filter($parsed->meta, fn ($value) => $value !== null));
        if (! $account->nickname) {
            $account->nickname = $parsed->game->label().' account';
        }
        $this->accounts->save($account);

        return $account;
    }

    /**
     * @return array{0: int, 1: list<array{message: string, kind: string, params: array<string, string|int>}>}
     */
    private function insertWeapons(Account $account, ParsedAccount $parsed): array
    {
        $rows = [];
        $warnings = [];
        $now = Clock::now();

        foreach ($parsed->weapons as $weapon) {
            $model = $this->weapon($parsed->game, $weapon['ref']);
            if (! $model) {
                $warnings[] = ImportKinds::warning('unknown_weapon', ['weapon_label' => $parsed->game->weaponLabel(), 'ref' => ImportKinds::ref($weapon['ref'])],
                    'Unknown '.strtolower($parsed->game->weaponLabel()).' '.json_encode($weapon['ref']).' was skipped.');

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

        $this->db->insertMany('account_weapons', $rows, 200);

        return [count($rows), $warnings];
    }

    /**
     * @return array{0: int, 1: list<array{message: string, kind: string, params: array<string, string|int>}>}
     */
    private function insertItems(Account $account, ParsedAccount $parsed): array
    {
        $rows = [];
        $warnings = [];
        $now = Clock::now();

        foreach ($parsed->items as $item) {
            $set = $this->set($parsed->game, $item['set']);
            if (! $set) {
                $warnings[] = ImportKinds::warning('unknown_set', ['ref' => ImportKinds::ref($item['set'])],
                    'Unknown set '.json_encode($item['set']).' was skipped.');

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
                'substats' => Json::encode(array_values($item['substats'])),
                'equipped_character_id' => $item['location'] ? $this->character($parsed->game, $item['location'])?->id : null,
                'locked' => $item['locked'],
                'external_uid' => $item['uid'],
                'source' => $parsed->source,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->db->insertMany('account_items', $rows, 100);

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

    private function set(Game $game, array $ref): ?GameSet
    {
        /** @var GameSet|null */
        return $this->resolve($game, Catalog::SET, $ref, fn (string $name) => $this->catalog->set($game, $name));
    }

    /**
     * Canonical key first, then the external (in-game) ID, then the name. Lookups only: see prepareCatalog()
     * for when the catalog is refreshed.
     */
    private function resolve(Game $game, string $type, array $ref, callable $byName): ?CatalogEntry
    {
        if (! empty($ref['key'])) {
            $found = $this->catalog->find($game, $type, $ref['key']);
            if ($found) {
                return $found;
            }
        }

        if (! empty($ref['external_id'])) {
            $found = $this->catalog->byExternalId($game, $type, $ref['external_id']);
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

    /**
     * Refresh the catalog lists this import needs: all three when the game has no catalog yet (an import
     * before any catalog sync), otherwise the lists in which the data names an in-game ID the catalog lacks
     * (a character or set newer than the last sync).
     */
    private function prepareCatalog(ParsedAccount $parsed): void
    {
        $game = $parsed->game;

        if (! $this->catalogSync->hasCatalog($game)) {
            foreach (CatalogSync::LISTS as $type) {
                $this->refreshCatalog($game, $type);
            }

            return;
        }

        $refs = [];
        foreach ($parsed->characters as $row) {
            $refs[] = [Catalog::CHARACTER, $row['ref']];
        }
        foreach ($parsed->weapons as $weapon) {
            $refs[] = [Catalog::WEAPON, $weapon['ref']];
            $refs[] = [Catalog::CHARACTER, $weapon['location'] ?: []];
        }
        foreach ($parsed->items as $item) {
            $refs[] = [Catalog::SET, $item['set']];
            $refs[] = [Catalog::CHARACTER, $item['location'] ?: []];
        }

        $missing = [];
        foreach ($refs as [$type, $ref]) {
            if (isset($missing[$type]) || empty($ref['external_id'])) {
                continue;
            }
            if (! empty($ref['key']) && $this->catalog->find($game, $type, $ref['key'])) {
                continue;
            }
            if (! $this->catalog->byExternalId($game, $type, $ref['external_id'])) {
                $missing[$type] = true;
            }
        }

        foreach (array_keys($missing) as $type) {
            $this->refreshCatalog($game, $type);
        }
    }

    /**
     * One list from the game data provider, at most once per game and list every import_refresh_minutes for
     * all users together, so uploads cannot turn into a stream of downloads.
     */
    private function refreshCatalog(Game $game, string $type): void
    {
        $bucket = "{$game->value}:{$type}";
        if (isset($this->refreshTried[$bucket])) {
            return;
        }
        $this->refreshTried[$bucket] = true;

        $minutes = max(1, (int) $this->config->get('catalog.import_refresh_minutes', 60));
        if (! $this->cache->add("catalog-import-refresh:{$bucket}", Clock::timestamp(), $minutes * 60)) {
            return;
        }

        try {
            $this->catalogSync->refresh($game, $type);
        } catch (Throwable $e) {
            Log::exception($e, "Catalog refresh for an import ({$bucket})");
        }
    }
}
