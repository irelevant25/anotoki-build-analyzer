<?php

namespace App\Services\Analysis;

use App\Models\Account;
use App\Models\AccountItem;
use App\Models\AccountWeapon;
use App\Models\GameCharacter;
use Illuminate\Support\Collection;

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

    /** @var array<int, GameCharacter> */
    private array $characters = [];

    public function __construct(public readonly Account $account)
    {
        $items = AccountItem::query()->with('set')->where('account_id', $account->id)->get();
        foreach ($items as $item) {
            if ($item->equipped_character_id) {
                $this->equippedItems[$item->equipped_character_id][$item->slot] = $item;
            }
            $this->itemsBySlot[$item->slot][] = $item;
        }

        $weapons = AccountWeapon::query()->with('weapon')->where('account_id', $account->id)->get();
        foreach ($weapons as $weapon) {
            if ($weapon->equipped_character_id) {
                // Keep the highest-level copy if an import ever produced duplicates.
                $current = $this->equippedWeapons[$weapon->equipped_character_id] ?? null;
                if (! $current || $weapon->level > $current->level) {
                    $this->equippedWeapons[$weapon->equipped_character_id] = $weapon;
                }
            }
            $this->weaponsByKey[$weapon->weapon->key][] = $weapon;
        }

        $characterIds = $items->pluck('equipped_character_id')->merge($weapons->pluck('equipped_character_id'))->filter()->unique();
        $this->characters = GameCharacter::query()->whereIn('id', $characterIds)->get()->keyBy('id')->all();
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
        return $characterId ? ($this->characters[$characterId]->name ?? null) : null;
    }

    /**
     * @param  Collection<int, AccountItem>|array<AccountItem>  $items
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
