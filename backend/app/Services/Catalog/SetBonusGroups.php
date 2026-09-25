<?php

namespace App\Services\Catalog;

use App\Enums\Game;
use App\Models\GameSet;

/**
 * Genshin sets grouped by the stat their 2-piece bonus gives, so a build that says "2pc ATK% + 2pc EM"
 * can accept any matching set. Built from the 2pc bonus texts stored by the catalog sync.
 */
class SetBonusGroups
{
    private const PATTERNS = [
        'atk_' => '/^ATK \+18%/i',
        'eleMas' => '/Elemental Mastery (?:by )?\+?80/i',
        'enerRech_' => '/^Energy Recharge \+20%/i',
        'hp_' => '/^HP \+20%/i',
        'def_' => '/^DEF \+30%/i',
        'heal_' => '/^Healing Bonus \+15%/i',
        'critRate_' => '/^CRIT Rate \+12%/i',
        'physical_dmg_' => '/Physical DMG (?:Bonus )?(?:\+|is increased by )25%/i',
    ];

    /** @var array<string, list<string>>|null */
    private ?array $groups = null;

    /**
     * Names of sets whose 2pc bonus gives $stat (canonical key such as "atk_" or "pyro_dmg_").
     *
     * @return list<string>
     */
    public function names(Game $game, string $stat): array
    {
        if ($game !== Game::Genshin) {
            return [];
        }

        $this->groups ??= $this->build();

        return $this->groups[$stat] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function build(): array
    {
        $groups = [];
        $sets = GameSet::query()->where('game', Game::Genshin->value)->get(['name', 'meta']);

        foreach ($sets as $set) {
            $twoPiece = strip_tags((string) ($set->meta['bonuses'][0] ?? ''));
            if ($twoPiece === '') {
                continue;
            }

            foreach (self::PATTERNS as $stat => $pattern) {
                if (preg_match($pattern, $twoPiece)) {
                    $groups[$stat][] = $set->name;
                }
            }

            if (preg_match('/(Pyro|Hydro|Anemo|Electro|Dendro|Cryo|Geo) DMG Bonus/i', $twoPiece, $m)) {
                $groups[strtolower($m[1]).'_dmg_'][] = $set->name;
            }
        }

        return $groups;
    }
}
