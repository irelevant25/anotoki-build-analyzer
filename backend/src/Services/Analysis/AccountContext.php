<?php

namespace App\Services\Analysis;

use App\Models\Account;
use App\Models\AccountCharacter;
use App\Models\AccountItem;
use App\Models\AccountWeapon;
use App\Models\CatalogEntry;
use App\Models\GameCharacter;
use App\Models\GameSet;
use App\Models\GameWeapon;
use App\Repositories\Accounts;
use App\Repositories\CatalogRows;
use App\Services\Catalog\Catalog;

/**
 * Everything the analyzer needs about one account, loaded with a handful of queries and indexed in memory.
 */
final class AccountContext
{
    /** @var array<int, array<string, AccountItem>> character id => slot => item */
    private array $equippedItems = [];

    /** @var array<int, AccountWeapon> character id => weapon */
    private array $equippedWeapons = [];

    /** @var array<string, list<AccountItem>> slot => all items (full inventory only) */
    private array $itemsBySlot = [];

    /** @var array<string, list<AccountWeapon>> weapon key => owned copies */
    private array $weaponsByKey = [];

    /** @var array<int, string> character id => name */
    private array $characterNames = [];

    /** @var array<string, GameWeapon|null> weapon key => the catalog's weapon, null for one it lacks */
    private array $catalogWeapons = [];

    /** @var array<string, GameSet|null> set key => the catalog's set, null for one it lacks */
    private array $catalogSets = [];

    /** @var array<int, array<string, float>|null> character id => the sheet's totals worked out from the gear, null while the catalog has no stats of the character */
    private array $sheetTotals = [];

    public function __construct(public readonly Account $account, Accounts $accounts, private readonly CatalogRows $rows)
    {
        $characterIds = [];

        foreach ($accounts->items($account->id) as $item) {
            if ($item->equipped_character_id) {
                $this->equippedItems[$item->equipped_character_id][$item->slot] = $item;
                $characterIds[] = $item->equipped_character_id;
            }
            $this->itemsBySlot[$item->slot][] = $item;
        }

        foreach ($accounts->weapons($account->id) as $weapon) {
            if ($weapon->equipped_character_id) {
                // Keep the highest-level copy if an import ever produced duplicates.
                $current = $this->equippedWeapons[$weapon->equipped_character_id] ?? null;
                if (!$current || $weapon->level > $current->level) {
                    $this->equippedWeapons[$weapon->equipped_character_id] = $weapon;
                }
                $characterIds[] = $weapon->equipped_character_id;
            }
            $this->weaponsByKey[$weapon->weapon->key][] = $weapon;
        }

        foreach ($rows->byIds(Catalog::CHARACTER, array_values(array_unique($characterIds))) as $character) {
            if ($character instanceof GameCharacter) {
                $this->characterNames[$character->id] = $character->name;
            }
        }
    }

    /**
     * @return array<string, AccountItem>
     */
    public function equippedItems(int $characterId): array
    {
        return $this->equippedItems[$characterId] ?? [];
    }

    public function equippedWeapon(int $characterId): ?AccountWeapon
    {
        return $this->equippedWeapons[$characterId] ?? null;
    }

    /**
     * @return list<AccountItem>
     */
    public function itemsInSlot(string $slot): array
    {
        return $this->itemsBySlot[$slot] ?? [];
    }

    /**
     * @return list<AccountWeapon>
     */
    public function ownedWeapons(string $key): array
    {
        return $this->weaponsByKey[$key] ?? [];
    }

    public function characterName(?int $characterId): ?string
    {
        return $characterId ? $this->characterNames[$characterId] ?? null : null;
    }

    /**
     * The catalog's weapons of these keys, by key - the ones it knows -, each asked for once per account (the
     * stat targets of every build need the build's weapons' stats).
     *
     * @param  list<string>  $keys
     * @return array<string, GameWeapon>
     */
    public function catalogWeapons(array $keys): array
    {
        return $this->catalogEntries(Catalog::WEAPON, $this->catalogWeapons, $keys);
    }

    /**
     * The catalog's sets of these keys, by key - the ones it knows.
     *
     * @param  list<string>  $keys
     * @return array<string, GameSet>
     */
    public function catalogSets(array $keys): array
    {
        return $this->catalogEntries(Catalog::SET, $this->catalogSets, $keys);
    }

    /**
     * The character's totals as its sheet would show them, worked out from the gear it wears (SheetTotals: the
     * character at its level, the weapon, the pieces' stats, the 2-piece bonuses), once per character; null while
     * the catalog keeps no stats of the character. What the stat targets are compared with where the account has
     * no in-game totals of it.
     *
     * @return array<string, float>|null
     */
    public function sheetTotals(AccountCharacter $owned): ?array
    {
        $id = $owned->game_character_id;
        if (!array_key_exists($id, $this->sheetTotals)) {
            $this->sheetTotals[$id] = SheetTotals::compute($this->account->game, $owned, $this->equippedWeapon($id), $this->equippedItems($id));
        }

        return $this->sheetTotals[$id];
    }

    /**
     * @param  array<string, CatalogEntry|null>  $cache
     * @param  list<string>  $keys
     * @return array<string, CatalogEntry>
     */
    private function catalogEntries(string $type, array &$cache, array $keys): array
    {
        $keys = array_values(array_unique($keys));
        $missing = array_values(array_filter($keys, fn(string $key) => !array_key_exists($key, $cache)));
        if ($missing !== []) {
            $found = $this->rows->byKeys($this->account->game, $type, $missing);
            foreach ($missing as $key) {
                $cache[$key] = $found[$key] ?? null;
            }
        }

        $entries = [];
        foreach ($keys as $key) {
            if ($cache[$key] !== null) {
                $entries[$key] = $cache[$key];
            }
        }

        return $entries;
    }

    /**
     * @param  iterable<AccountItem>  $items
     * @return array<string, int> set key => pieces
     */
    public static function setCounts(iterable $items): array
    {
        $counts = [];
        foreach ($items as $item) {
            $key = $item->set->key;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }
}
