<?php

namespace App\Repositories;

use App\Db\Db;
use App\Enums\Game;
use App\Models\Account;
use App\Models\AccountCharacter;
use App\Models\AccountItem;
use App\Models\AccountWeapon;
use App\Support\Clock;
use App\Support\Json;

/**
 * Game accounts and what was imported into them.
 *
 * Every way to an account goes through its owner: owned(), findOwned() and the import's lookups all name
 * the user, so nothing here answers "account 5" to somebody who is not its owner.
 */
final class Accounts
{
    private const COUNTS = '(SELECT count(*) FROM account_characters c WHERE c.account_id = a.id) AS characters_count,
        (SELECT count(*) FROM account_weapons w WHERE w.account_id = a.id) AS weapons_count,
        (SELECT count(*) FROM account_items i WHERE i.account_id = a.id) AS items_count';

    public function __construct(private readonly Db $db) {}

    /**
     * The user's accounts with their counts, by game and newest import first.
     *
     * @return list<Account>
     */
    public function owned(int $userId): array
    {
        return array_map(
            fn(array $row) => Account::fromRow($row),
            $this->db->all('SELECT a.*, ' . self::COUNTS . ' FROM accounts a WHERE a.user_id = ? ORDER BY a.game ASC, a.last_import_at DESC', [$userId]),
        );
    }

    /** How many accounts the user has. */
    public function countOwned(int $userId): int
    {
        return (int) $this->db->value('SELECT count(*) FROM accounts WHERE user_id = ?', [$userId]);
    }

    /** One of the user's accounts, or null - also for an account that exists but is somebody else's. */
    public function findOwned(int $userId, int $id, bool $withCounts = true): ?Account
    {
        $row = $this->db->one('SELECT a.*' . ($withCounts ? ', ' . self::COUNTS : '') . ' FROM accounts a WHERE a.id = ? AND a.user_id = ?', [$id, $userId]);

        return $row === null ? null : Account::fromRow($row);
    }

    public function findOwnedByUid(int $userId, Game $game, string $uid): ?Account
    {
        $row = $this->db->one('SELECT * FROM accounts WHERE user_id = ? AND game = ? AND uid = ?', [$userId, $game->value, $uid]);

        return $row === null ? null : Account::fromRow($row);
    }

    public function withCounts(Account $account): Account
    {
        $row = $this->db->one('SELECT a.id, ' . self::COUNTS . ' FROM accounts a WHERE a.id = ?', [$account->id]);
        $account->counts = [
            'characters' => (int) ($row['characters_count'] ?? 0),
            'weapons' => (int) ($row['weapons_count'] ?? 0),
            'items' => (int) ($row['items_count'] ?? 0),
        ];

        return $account;
    }

    /** Inserts a new account (id 0) or writes an existing one. */
    public function save(Account $account): void
    {
        $now = Clock::now();
        $columns = [
            'user_id' => $account->user_id,
            'game' => $account->game->value,
            'uid' => $account->uid,
            'nickname' => $account->nickname,
            'level' => $account->level,
            'world_level' => $account->world_level,
            'signature' => $account->signature,
            'has_full_inventory' => $account->has_full_inventory,
            'last_import_at' => $account->last_import_at,
            'meta' => Json::column($account->meta === [] ? null : $account->meta),
            'updated_at' => $now,
        ];

        if ($account->id === 0) {
            $account->id = (int) $this->db->insert('accounts', $columns + ['created_at' => $now]);
        } else {
            $this->db->update('accounts', $columns, ['id' => $account->id]);
        }
    }

    public function rename(Account $account, string $nickname): void
    {
        $this->db->update('accounts', ['nickname' => $nickname, 'updated_at' => Clock::now()], ['id' => $account->id]);
        $account->nickname = $nickname;
    }

    /** Deletes the account and, through the foreign keys, everything imported into it. */
    public function delete(Account $account): void
    {
        $this->db->delete('accounts', ['id' => $account->id]);
    }

    // ── what an account holds ─────────────────────────────────────────────────────────────────────

    private const CHARACTER = 'SELECT ac.*, c.id AS c_id, c.game AS c_game, c.key AS c_key, c.name AS c_name, c.element AS c_element,
            c.rarity AS c_rarity, c.weapon_type AS c_weapon_type, c.external_id AS c_external_id, c.icon_url AS c_icon_url,
            c.released_at AS c_released_at, c.meta AS c_meta, c.updated_at AS c_updated_at
        FROM account_characters ac JOIN game_characters c ON c.id = ac.game_character_id';

    /** @return list<AccountCharacter> */
    public function characters(int $accountId): array
    {
        return array_map(fn(array $row) => AccountCharacter::fromRow($row), $this->db->all(self::CHARACTER . ' WHERE ac.account_id = ? ORDER BY ac.id', [$accountId]));
    }

    public function character(int $accountId, string $characterKey): ?AccountCharacter
    {
        $row = $this->db->one(self::CHARACTER . ' WHERE ac.account_id = ? AND c.key = ?', [$accountId, $characterKey]);

        return $row === null ? null : AccountCharacter::fromRow($row);
    }

    /**
     * Which of these characters the account has, by key, with each one's rank (constellation): the teammates a
     * player already has. The account is one the caller found as its owner's (findOwned()).
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public function characterRanks(int $accountId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        [$in, $params] = Db::in(array_values(array_unique($keys)));
        $ranks = [];
        foreach (
            $this->db->all("SELECT c.key, ac.rank FROM account_characters ac JOIN game_characters c ON c.id = ac.game_character_id WHERE ac.account_id = ? AND c.key IN ({$in})", [
                $accountId,
                ...$params,
            ])
            as $row
        ) {
            $ranks[(string) $row['key']] = (int) $row['rank'];
        }

        return $ranks;
    }

    /**
     * The keys of the account's characters. The account is one the caller found as its owner's (findOwned()).
     *
     * @return list<string>
     */
    public function characterKeys(int $accountId): array
    {
        return array_map('strval', $this->db->column('SELECT c.key FROM account_characters ac JOIN game_characters c ON c.id = ac.game_character_id WHERE ac.account_id = ?', [$accountId]));
    }

    public function characterRow(int $accountId, int $gameCharacterId): ?array
    {
        return $this->db->one('SELECT * FROM account_characters WHERE account_id = ? AND game_character_id = ?', [$accountId, $gameCharacterId]);
    }

    /** @return list<AccountItem> */
    public function items(int $accountId): array
    {
        return array_map(
            fn(array $row) => AccountItem::fromRow($row),
            $this->db->all(
                'SELECT i.*, s.id AS s_id, s.game AS s_game, s.key AS s_key, s.name AS s_name, s.kind AS s_kind,
                    s.external_id AS s_external_id, s.icon_url AS s_icon_url, s.meta AS s_meta, s.updated_at AS s_updated_at
               FROM account_items i JOIN game_sets s ON s.id = i.game_set_id
              WHERE i.account_id = ? ORDER BY i.id',
                [$accountId],
            ),
        );
    }

    /** @return list<AccountWeapon> */
    public function weapons(int $accountId): array
    {
        return array_map(
            fn(array $row) => AccountWeapon::fromRow($row),
            $this->db->all(
                'SELECT aw.*, w.id AS w_id, w.game AS w_game, w.key AS w_key, w.name AS w_name, w.rarity AS w_rarity,
                    w.weapon_type AS w_weapon_type, w.external_id AS w_external_id, w.icon_url AS w_icon_url,
                    w.meta AS w_meta, w.updated_at AS w_updated_at
               FROM account_weapons aw JOIN game_weapons w ON w.id = aw.game_weapon_id
              WHERE aw.account_id = ? ORDER BY aw.id',
                [$accountId],
            ),
        );
    }
}
