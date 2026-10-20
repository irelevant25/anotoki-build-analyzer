<?php

namespace App\Support;

use App\Enums\Game;

/**
 * Canonical talent keys (Game::talentKeys()): normal, skill, burst.
 */
final class Talents
{
    /**
     * Every key parse() can give (an imported builds file may name no other).
     *
     * @return list<string>
     */
    public static function keys(Game $game): array
    {
        return $game->talentKeys();
    }

    public static function parse(Game $game, string $label): ?string
    {
        $key = Key::from($label);

        return match ($game) {
            Game::Genshin => match (true) {
                in_array($key, ['normal', 'normalattack', 'na', 'auto', 'autoattack', 'attack', 'n'], true) => 'normal',
                in_array($key, ['skill', 'elementalskill', 'e'], true) => 'skill',
                in_array($key, ['burst', 'elementalburst', 'q', 'ult', 'ultimate'], true) => 'burst',
                default => null,
            },
        };
    }

    public static function label(Game $game, string $key): string
    {
        return match ($game) {
            Game::Genshin => match ($key) {
                'normal' => 'Normal Attack',
                'skill' => 'Elemental Skill',
                'burst' => 'Elemental Burst',
                default => ucfirst($key),
            },
        };
    }

    /**
     * "Burst = Skill > Normal Attack" / "Skill >> Burst" => [{talent, priority}] (ties share a priority).
     *
     * @return list<array{talent: string, priority: int}>
     */
    public static function parsePriority(Game $game, string $text): array
    {
        $result = [];
        $priority = 1;
        foreach (preg_split('/\s*(?:>+|≥|»)\s*/u', $text) ?: [] as $group) {
            $added = false;
            foreach (preg_split('/\s*(?:=|\/|,|&|\bor\b)\s*/iu', $group) ?: [] as $label) {
                $label = trim(preg_replace('/\s*[\[(].*$/', '', $label) ?? $label);
                $talent = $label === '' ? null : self::parse($game, $label);
                if ($talent && !in_array($talent, array_column($result, 'talent'), true)) {
                    $result[] = ['talent' => $talent, 'priority' => $priority];
                    $added = true;
                }
            }
            if ($added) {
                $priority++;
            }
        }

        return $result;
    }

    /**
     * Ordered labels ("Burst", "Skill", "Normal Attack") => [{talent, priority}].
     *
     * @param  list<string>  $labels
     * @return list<array{talent: string, priority: int}>
     */
    public static function ordered(Game $game, array $labels): array
    {
        $result = [];
        $priority = 1;
        foreach ($labels as $label) {
            $talent = self::parse($game, (string) $label);
            if ($talent && !in_array($talent, array_column($result, 'talent'), true)) {
                $result[] = ['talent' => $talent, 'priority' => $priority++];
            }
        }

        return $result;
    }
}
