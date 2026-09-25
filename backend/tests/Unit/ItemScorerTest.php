<?php

namespace Tests\Unit;

use App\Enums\Game;
use App\Services\Analysis\ItemScorer;
use PHPUnit\Framework\TestCase;

class ItemScorerTest extends TestCase
{
    private function scorer(): ItemScorer
    {
        return new ItemScorer(Game::Genshin, [
            ['stat' => 'critRate_', 'priority' => 1],
            ['stat' => 'critDMG_', 'priority' => 1],
            ['stat' => 'atk_', 'priority' => 2],
            ['stat' => 'enerRech_', 'priority' => 3],
        ]);
    }

    public function test_flat_stats_get_a_fraction_of_the_percent_weight(): void
    {
        $weights = $this->scorer()->weights();

        $this->assertSame(1.0, $weights['critRate_']);
        $this->assertSame(0.8, $weights['atk_']);
        $this->assertEqualsWithDelta(0.28, $weights['atk'], 0.001);
        $this->assertArrayNotHasKey('hp', $weights);
    }

    public function test_a_perfect_piece_scores_close_to_ideal(): void
    {
        // All five upgrades into CRIT DMG, max rolls everywhere.
        $result = $this->scorer()->score('atk_', 5, [
            ['key' => 'critDMG_', 'value' => 7.77 * 6],
            ['key' => 'critRate_', 'value' => 3.89],
            ['key' => 'enerRech_', 'value' => 6.48],
            ['key' => 'eleMas', 'value' => 23.31],
        ]);

        $this->assertGreaterThanOrEqual(90, $result['percent']);
        $this->assertSame('S', $result['grade']);
    }

    public function test_useless_substats_score_low(): void
    {
        $result = $this->scorer()->score('atk_', 5, [
            ['key' => 'hp', 'value' => 896],
            ['key' => 'def', 'value' => 58],
            ['key' => 'def_', 'value' => 21.9],
            ['key' => 'eleMas', 'value' => 40],
        ]);

        $this->assertSame(0, $result['percent']);
        $this->assertSame('D', $result['grade']);
    }

    public function test_substats_get_weights_and_roll_estimates(): void
    {
        $result = $this->scorer()->score('hp', 5, [['key' => 'critDMG_', 'value' => 21.0]]);

        $this->assertSame(1.0, $result['substats'][0]['weight']);
        $this->assertSame(3, $result['substats'][0]['rolls']);
    }
}
