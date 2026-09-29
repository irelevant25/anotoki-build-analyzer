<?php

namespace App\Services\Analysis;

use App\Models\Account;
use App\Models\AccountItem;
use App\Models\AccountWeapon;
use App\Models\GameCharacter;
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

    public function __construct(public readonly Account $account, Accounts $accounts, CatalogRows $rows)
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
                if (! $current || $weapon->level > $current->level) {
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
        return $characterId ? ($this->characterNames[$characterId] ?? null) : null;
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
