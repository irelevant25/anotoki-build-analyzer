<?php

namespace App\Support;

use App\Enums\Game;

/**
 * Canonical talent keys. Genshin: normal, skill, burst. HSR: basic, skill, ultimate, talent, memo_skill, memo_talent.
 */
final class Talents
{
    public static function parse(Game $game, string $label): ?string
    {
        $key = Key::from($label);

        if ($game === Game::Genshin) {
            return match (true) {
                in_array($key, ['normal', 'normalattack', 'na', 'auto', 'autoattack', 'attack', 'n'], true) => 'normal',
                in_array($key, ['skill', 'elementalskill', 'e'], true) => 'skill',
                in_array($key, ['burst', 'elementalburst', 'q', 'ult', 'ultimate'], true) => 'burst',
                default => null,
            };
        }

        return match (true) {
            in_array($key, ['basic', 'basicattack', 'basicatk', 'ba', 'normal', 'normalattack'], true) => 'basic',
            in_array($key, ['skill', 'e'], true) => 'skill',
            in_array($key, ['ult', 'ultimate', 'q', 'burst'], true) => 'ultimate',
            $key === 'talent' => 'talent',
            str_contains($key, 'memosprite') && str_contains($key, 'talent') => 'memo_talent',
            str_contains($key, 'memosprite') => 'memo_skill',
            default => null,
        };
    }

    public static function label(Game $game, string $key): string
    {
        return match ($key) {
            'normal' => 'Normal Attack',
            'skill' => $game === Game::Genshin ? 'Elemental Skill' : 'Skill',
            'burst' => 'Elemental Burst',
            'basic' => 'Basic ATK',
            'ultimate' => 'Ultimate',
            'talent' => 'Talent',
            'memo_skill' => 'Memosprite Skill',
            'memo_talent' => 'Memosprite Talent',
            default => ucfirst($key),
        };
    }

    /**
     * "Ultimate = Skill > Talent > Basic" / "Skill >> Burst" => [{talent, priority}] (ties share a priority).
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
                if ($talent && ! in_array($talent, array_column($result, 'talent'), true)) {
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
            if ($talent && ! in_array($talent, array_column($result, 'talent'), true)) {
                $result[] = ['talent' => $talent, 'priority' => $priority++];
            }
        }

        return $result;
    }
}
