<?php

namespace App\Enums;

enum Game: string
{
    case Genshin = 'genshin';
    case Hsr = 'hsr';

    public function label(): string
    {
        return match ($this) {
            self::Genshin => 'Genshin Impact',
            self::Hsr => 'Honkai: Star Rail',
        };
    }

    /**
     * Equipment slots in in-game order.
     *
     * @return list<string>
     */
    public function slots(): array
    {
        return match ($this) {
            self::Genshin => ['flower', 'plume', 'sands', 'goblet', 'circlet'],
            self::Hsr => ['head', 'hands', 'body', 'feet', 'sphere', 'rope'],
        };
    }

    /**
     * Slots whose main stat can roll differently (the ones builds give advice for).
     *
     * @return list<string>
     */
    public function variableSlots(): array
    {
        return match ($this) {
            self::Genshin => ['sands', 'goblet', 'circlet'],
            self::Hsr => ['body', 'feet', 'sphere', 'rope'],
        };
    }

    /**
     * HSR splits equipment into Cavern relics (4 slots) and Planar ornaments (2 slots).
     */
    public function isPlanarSlot(string $slot): bool
    {
        return $this === self::Hsr && in_array($slot, ['sphere', 'rope'], true);
    }

    public function slotLabel(string $slot): string
    {
        return match ($slot) {
            'flower' => 'Flower of Life',
            'plume' => 'Plume of Death',
            'sands' => 'Sands of Eon',
            'goblet' => 'Goblet of Eonothem',
            'circlet' => 'Circlet of Logos',
            'head' => 'Head',
            'hands' => 'Hands',
            'body' => 'Body',
            'feet' => 'Feet',
            'sphere' => 'Planar Sphere',
            'rope' => 'Link Rope',
            default => ucfirst($slot),
        };
    }

    /**
     * Main stats that can appear on each slot. "elemental_dmg_" is expanded per element.
     *
     * @return array<string, list<string>>
     */
    public function mainStatOptions(): array
    {
        return match ($this) {
            self::Genshin => [
                'flower' => ['hp'],
                'plume' => ['atk'],
                'sands' => ['hp_', 'atk_', 'def_', 'eleMas', 'enerRech_'],
                'goblet' => ['hp_', 'atk_', 'def_', 'eleMas', 'physical_dmg_', 'anemo_dmg_', 'geo_dmg_', 'electro_dmg_', 'hydro_dmg_', 'pyro_dmg_', 'cryo_dmg_', 'dendro_dmg_'],
                'circlet' => ['hp_', 'atk_', 'def_', 'eleMas', 'critRate_', 'critDMG_', 'heal_'],
            ],
            self::Hsr => [
                'head' => ['hp'],
                'hands' => ['atk'],
                'body' => ['hp_', 'atk_', 'def_', 'critRate_', 'critDMG_', 'heal_', 'effectHitRate_'],
                'feet' => ['hp_', 'atk_', 'def_', 'spd'],
                'sphere' => ['hp_', 'atk_', 'def_', 'physical_dmg_', 'fire_dmg_', 'ice_dmg_', 'lightning_dmg_', 'wind_dmg_', 'quantum_dmg_', 'imaginary_dmg_'],
                'rope' => ['hp_', 'atk_', 'def_', 'breakEffect_', 'energyRegen_'],
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function elements(): array
    {
        return match ($this) {
            self::Genshin => ['pyro', 'hydro', 'anemo', 'electro', 'dendro', 'cryo', 'geo'],
            self::Hsr => ['physical', 'fire', 'ice', 'lightning', 'wind', 'quantum', 'imaginary'],
        };
    }

    /**
     * Genshin weapon types / HSR paths.
     *
     * @return list<string>
     */
    public function weaponTypes(): array
    {
        return match ($this) {
            self::Genshin => ['sword', 'claymore', 'polearm', 'bow', 'catalyst'],
            self::Hsr => ['destruction', 'hunt', 'erudition', 'harmony', 'nihility', 'preservation', 'abundance', 'remembrance', 'elation'],
        };
    }

    public function weaponLabel(): string
    {
        return $this === self::Genshin ? 'Weapon' : 'Light Cone';
    }

    public function itemLabel(): string
    {
        return $this === self::Genshin ? 'Artifact' : 'Relic';
    }

    public function rankLabel(): string
    {
        return $this === self::Genshin ? 'Constellation' : 'Eidolon';
    }

    /**
     * Maximum enhancement level of a 5-star artifact/relic.
     */
    public function maxItemLevel(int $rarity = 5): int
    {
        return match ($this) {
            self::Genshin => $rarity >= 3 ? $rarity * 4 : 4, // 5* => 20, 4* => 16, 3* => 12
            self::Hsr => $rarity * 3, // 5* => 15, 4* => 12
        };
    }
}
