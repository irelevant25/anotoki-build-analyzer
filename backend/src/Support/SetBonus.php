<?php

namespace App\Support;

use App\Enums\Game;
use App\Models\GameSet;

/**
 * What a set's 2-piece bonus adds to the character sheet, read from the words the catalog keeps of it
 * (`meta.bonuses[0]`, genshin.anotoki.eu's: "ATK +18%.", "Cryo DMG Bonus +15%", "Increases Elemental Mastery by
 * 80.", "Max HP increased by 1000."). A bonus that is no sheet stat - a skill's or a burst's DMG, a resistance,
 * shield strength, healing received - is none: the targets leave it out, as the sheet does.
 */
final class SetBonus
{
    /**
     * The stat and value a set's 2-piece bonus adds, or null for none the sheet shows.
     *
     * @return array{stat: string, value: float}|null
     */
    public static function twoPiece(Game $game, GameSet $set): ?array
    {
        $text = $set->meta['bonuses'][0] ?? null;

        return is_string($text) ? self::parse($game, $text) : null;
    }

    /**
     * The CRIT Rate a set's 4-piece bonus adds in combat, read from its words (`meta.bonuses[1]`): never on the
     * sheet, but what the stat targets balance CRIT Rate and CRIT DMG with - a build on Blizzard Strayer needs less
     * CRIT Rate of its own. Nought for a set whose 4-piece says nothing of CRIT Rate.
     */
    public static function fourPieceCritRate(GameSet $set): float
    {
        $text = $set->meta['bonuses'][1] ?? null;

        return is_string($text) ? self::parseCritRate($text) : 0.0;
    }

    /**
     * Every "CRIT Rate ... N%" of a bonus text added up ("their CRIT Rate is increased by 20%. If the opponent is
     * Frozen, CRIT Rate is increased by an additional 20%." is 40), the first number where two are offered
     * ("15%/30%"), times the stacks where the text says "Max 3 stacks" or "up to 3 stacks". A narrow reading of
     * the game's own wording; a 4-piece worded another way counts as nought, which only makes a target's CRIT
     * Rate a little higher than it need be.
     */
    public static function parseCritRate(string $text): float
    {
        $plain = preg_replace('/\s+/u', ' ', $text) ?? '';
        if (!preg_match_all('/CRIT Rate[^.%]*?(\d+(?:\.\d+)?)%/i', $plain, $matches)) {
            return 0.0;
        }
        $stacks = preg_match('/\b(?:max|up to)\s+(\d+)\s+stacks?\b/i', $plain, $m) ? (int) $m[1] : 1;

        return round(array_sum(array_map('floatval', $matches[1])) * max(1, $stacks), 1);
    }

    /**
     * @return array{stat: string, value: float}|null
     */
    public static function parse(Game $game, string $text): ?array
    {
        $plain = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        // "Increases Elemental Mastery by 80.", "ATK increased by 18%.", "Energy Recharge + 20%.", "HP +20%"
        if (!preg_match('/^(?:increases\s+)?(?<stat>[a-z %]+?)\s*(?:\+|increased by|by)\s*(?<value>\d+(?:\.\d+)?)\s*(?<percent>%)?\.?$/i', $plain, $m)) {
            return null;
        }
        $percent = ($m['percent'] ?? '') === '%';
        // "Character Healing Effectiveness" is the sheet's Healing Bonus; "Max HP" its HP.
        $label = trim(str_ireplace(['character healing effectiveness', 'max hp'], ['healing bonus', 'hp'], $m['stat']));
        $stats = Stats::parse($game, $label);
        if (count($stats) !== 1) {
            return null;
        }
        $stat = $stats[0];
        // A number without a percent sign on HP, ATK or DEF is the flat stat ("Max HP increased by 1000").
        if (!$percent && Stats::isPercent($stat) && isset(Stats::FLAT_TO_PERCENT[rtrim($stat, '_')])) {
            $stat = rtrim($stat, '_');
        }
        // A percent sign on a stat that has none, or none on one that does: no sheet stat was named.
        if ($percent !== Stats::isPercent($stat)) {
            return null;
        }

        return ['stat' => $stat, 'value' => (float) $m['value']];
    }
}
