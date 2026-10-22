<?php

namespace App\Enums;

/**
 * The games the site offers - Genshin Impact alone since 0.5.0 (Honkai: Star Rail went, the owner's decision of
 * 2026-10-20). Every fact that differs between games is a method here, a match with an arm per game, so a game
 * added later is a case and its arms - and the CHECKs of the migrations that list the games, widened by one of its
 * own (docs/decisions.md).
 */
enum Game: string
{
    case Genshin = 'genshin';

    public function label(): string
    {
        return match ($this) {
            self::Genshin => 'Genshin Impact',
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
        };
    }

    public function slotLabel(string $slot): string
    {
        return match ($slot) {
            'flower' => 'Flower of Life',
            'plume' => 'Plume of Death',
            'sands' => 'Sands of Eon',
            'goblet' => 'Goblet of Eonothem',
            'circlet' => 'Circlet of Logos',
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
        };
    }

    /**
     * @return list<string>
     */
    public function elements(): array
    {
        return match ($this) {
            self::Genshin => ['pyro', 'hydro', 'anemo', 'electro', 'dendro', 'cryo', 'geo'],
        };
    }

    /**
     * @return list<string>
     */
    public function weaponTypes(): array
    {
        return match ($this) {
            self::Genshin => ['sword', 'claymore', 'polearm', 'bow', 'catalyst'],
        };
    }

    public function weaponLabel(): string
    {
        return match ($this) {
            self::Genshin => 'Weapon',
        };
    }

    public function itemLabel(): string
    {
        return match ($this) {
            self::Genshin => 'Artifact',
        };
    }

    /** What the game calls a character's rank (account_characters.rank): its constellation. */
    public function rankLabel(): string
    {
        return match ($this) {
            self::Genshin => 'Constellation',
        };
    }

    /** The prefix the game writes a rank with ("C2"). */
    public function rankPrefix(): string
    {
        return match ($this) {
            self::Genshin => 'C',
        };
    }

    /** The highest rank a character reaches (C6). */
    public function maxRank(): int
    {
        return match ($this) {
            self::Genshin => 6,
        };
    }

    /**
     * What a build is for, in the order a character's tabs list them (a build's role; migration 016 closes the set):
     * the page words each (`builds.role.*`).
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return match ($this) {
            self::Genshin => ['main_dps', 'sub_dps', 'support'],
        };
    }

    /** The most characters a team has - the party the game fields: a build's recommended teams have 2 to this many. */
    public function teamSize(): int
    {
        return match ($this) {
            self::Genshin => 4,
        };
    }

    /**
     * Whether a catalog key is the game's one character played in several elements, a row for each (the Travelers,
     * `traveler{element}` - Providers\GenshinCatalog): an account has it in the element it was imported in, and plays
     * it in any.
     */
    public function isTraveler(string $key): bool
    {
        return match ($this) {
            self::Genshin => preg_match('/^traveler(?:anemo|geo|electro|dendro|hydro|pyro|cryo)$/', $key) === 1,
        };
    }

    /** The prefix the game writes a weapon's refinement with ("R1"). */
    public function refinementPrefix(): string
    {
        return match ($this) {
            self::Genshin => 'R',
        };
    }

    /** A weapon's highest level. */
    public function maxWeaponLevel(): int
    {
        return match ($this) {
            self::Genshin => 90,
        };
    }

    /** The character level below which the analysis says to level up (most stats come from it). */
    public function characterLevelTarget(): int
    {
        return match ($this) {
            self::Genshin => 80,
        };
    }

    /**
     * The talents a character levels, in the game's order (App\Support\Talents names them).
     *
     * @return list<string>
     */
    public function talentKeys(): array
    {
        return match ($this) {
            self::Genshin => ['normal', 'skill', 'burst'],
        };
    }

    /**
     * The kinds of sets the game has, each worn on a part of the slots (game_sets.kind).
     *
     * @return list<string>
     */
    public function setKinds(): array
    {
        return match ($this) {
            self::Genshin => ['artifact'],
        };
    }

    /** The kind of set a slot's piece belongs to. */
    public function setKindOf(string $slot): string
    {
        return match ($this) {
            self::Genshin => 'artifact',
        };
    }

    /** A kind of set as a sentence names it, with a capital (it can open a sentence before a colon). */
    public function setLabel(string $kind): string
    {
        return match ($this) {
            self::Genshin => 'Artifact set',
        };
    }

    /**
     * How many pieces of one set a build can ask for, by kind (a 2-piece and a 4-piece bonus).
     *
     * @return list<int>
     */
    public function setPieceCounts(string $kind): array
    {
        return match ($this) {
            self::Genshin => [2, 4],
        };
    }

    /** What a kind of set weighs in the sets part of a score, against the game's other kinds. */
    public function setWeight(string $kind): float
    {
        return match ($this) {
            self::Genshin => 1.0,
        };
    }

    /** A flat roll is worth roughly this fraction of the matching % roll at endgame stat levels. */
    public function flatStatFactor(): float
    {
        return match ($this) {
            self::Genshin => 0.35,
        };
    }

    /**
     * Maximum enhancement level of an artifact.
     */
    public function maxItemLevel(int $rarity = 5): int
    {
        return match ($this) {
            self::Genshin => $rarity >= 3 ? $rarity * 4 : 4, // 5* => 20, 4* => 16, 3* => 12
        };
    }
}
