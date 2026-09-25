<?php

namespace Tests\Unit;

use App\Enums\Game;
use App\Support\Key;
use App\Support\Stats;
use App\Support\Talents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StatsTest extends TestCase
{
    public static function genshinLabels(): array
    {
        return [
            ['ATK%', ['atk_']],
            ['ATK', ['atk_']],
            ['Flat ATK', ['atk']],
            ['Energy Recharge', ['enerRech_']],
            ['ER%', ['enerRech_']],
            ['Energy Rechage', ['enerRech_']], // typo seen on genshin.gg
            ['Elemental Mastery', ['eleMas']],
            ['EM', ['eleMas']],
            ['CRIT Rate', ['critRate_']],
            ['Crit DMG', ['critDMG_']],
            ['CRIT Rate/DMG', ['critRate_', 'critDMG_']],
            ['Crit', ['critRate_', 'critDMG_']],
            ['Pyro DMG Bonus', ['pyro_dmg_']],
            ['Hydro DMG%', ['hydro_dmg_']],
            ['Physical DMG Bonus', ['physical_dmg_']],
            ['Elemental DMG Bonus', [Stats::ELEMENTAL_DMG]],
            ['Healing Bonus', ['heal_']],
            ['ele mas', ['eleMas']],
        ];
    }

    #[DataProvider('genshinLabels')]
    public function test_parses_genshin_labels(string $label, array $expected): void
    {
        $this->assertSame($expected, Stats::parse(Game::Genshin, $label));
    }

    public static function hsrLabels(): array
    {
        return [
            ['SPD', ['spd']],
            ['Speed', ['spd']],
            ['Effect Hit Rate', ['effectHitRate_']],
            ['EHR', ['effectHitRate_']],
            ['Effect RES', ['effectRes_']],
            ['Break Effect', ['breakEffect_']],
            ['Energy Regen Rate', ['energyRegen_']],
            ['Energy Regeneration Rate', ['energyRegen_']],
            ['ERR', ['energyRegen_']],
            ['Outgoing Healing Boost', ['heal_']],
            ['Lightning DMG', ['lightning_dmg_']],
            ['Thunder DMG Boost', ['lightning_dmg_']],
            ['Imaginary DMG Boost', ['imaginary_dmg_']],
        ];
    }

    #[DataProvider('hsrLabels')]
    public function test_parses_hsr_labels(string $label, array $expected): void
    {
        $this->assertSame($expected, Stats::parse(Game::Hsr, $label));
    }

    public function test_priority_list_keeps_ties_together(): void
    {
        $this->assertSame([
            ['stat' => 'critRate_', 'priority' => 1],
            ['stat' => 'critDMG_', 'priority' => 1],
            ['stat' => 'atk_', 'priority' => 2],
            ['stat' => 'enerRech_', 'priority' => 3],
            ['stat' => 'eleMas', 'priority' => 3],
        ], Stats::parsePriorityList(Game::Genshin, 'CRIT Rate = CRIT DMG > ATK% > Energy Recharge / EM'));
    }

    public function test_priority_list_strips_notes_and_handles_combined_crit(): void
    {
        $this->assertSame([
            ['stat' => 'effectHitRate_', 'priority' => 1],
            ['stat' => 'spd', 'priority' => 2],
            ['stat' => 'critRate_', 'priority' => 3],
            ['stat' => 'critDMG_', 'priority' => 3],
        ], Stats::parsePriorityList(Game::Hsr, 'Effect Hit Rate (Until 75%) > SPD >= Crit Rate/DMG'));
    }

    public function test_ordered_list_pairs_adjacent_crit_stats(): void
    {
        $this->assertSame([
            ['stat' => 'enerRech_', 'priority' => 1],
            ['stat' => 'critDMG_', 'priority' => 2],
            ['stat' => 'critRate_', 'priority' => 2],
            ['stat' => 'atk_', 'priority' => 3],
        ], Stats::ordered(Game::Genshin, ['Energy Recharge', 'CRIT DMG', 'CRIT Rate', 'ATK%']));
    }

    public function test_elemental_placeholder_resolves_to_character_element(): void
    {
        $this->assertSame('pyro_dmg_', Stats::resolveElemental(Stats::ELEMENTAL_DMG, 'pyro'));
        $this->assertSame('atk_', Stats::resolveElemental('atk_', 'pyro'));
    }

    public function test_talent_priority(): void
    {
        $this->assertSame([
            ['talent' => 'ultimate', 'priority' => 1],
            ['talent' => 'skill', 'priority' => 1],
            ['talent' => 'talent', 'priority' => 2],
            ['talent' => 'basic', 'priority' => 3],
        ], Talents::parsePriority(Game::Hsr, 'Ultimate = Skill > Talent > Basic'));

        $this->assertSame([
            ['talent' => 'skill', 'priority' => 1],
            ['talent' => 'burst', 'priority' => 2],
        ], Talents::parsePriority(Game::Genshin, 'Skill >> Burst'));
    }

    public function test_keys_are_normalized_names(): void
    {
        $this->assertSame('kaedeharakazuha', Key::from('Kaedehara Kazuha'));
        $this->assertSame('kaedeharakazuha', Key::from('KaedeharaKazuha'));
        $this->assertSame('wolfsgravestone', Key::from("Wolf's Gravestone"));
        $this->assertSame('thecatch', Key::from('"The Catch"'));
        $this->assertSame('danhengimbibitorlunae', Key::from('Dan Heng • Imbibitor Lunae'));
        $this->assertSame('topaznumby', Key::from('Topaz &amp; Numby'));
    }
}
