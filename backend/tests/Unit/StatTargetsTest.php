<?php

namespace Tests\Unit;

use App\Enums\Game;
use App\Services\Builds\Parsing\StatTargets;
use PHPUnit\Framework\TestCase;

class StatTargetsTest extends TestCase
{
    public function test_extracts_goals_from_genshin_notes(): void
    {
        $targets = StatTargets::extract(Game::Genshin,
            'Goal: 2,000+ <stat>ATK</stat>, 125-145% <stat>Energy Recharge</stat>, 180%+ <stat>CRIT DMG</stat>, 150-200 Elemental Mastery.');

        $byStat = array_column($targets, null, 'stat');
        $this->assertSame(2000.0, $byStat['atk']['min']);
        $this->assertSame(125.0, $byStat['enerRech_']['min']);
        $this->assertSame(145.0, $byStat['enerRech_']['max']);
        $this->assertSame(180.0, $byStat['critDMG_']['min']);
        $this->assertSame(150.0, $byStat['eleMas']['min']);
        $this->assertSame(200.0, $byStat['eleMas']['max']);
    }

    public function test_extracts_prydwen_endgame_list(): void
    {
        $html = '<ul><li><p>HP: <b>3000+</b></p></li><li><p>ATK: <b>2500 - 3000+ </b></p></li>'
            .'<li><p>EHR: <b>75%</b> (More can be fine)</p></li><li><p>SPD: <b>160+</b></p></li></ul>';
        $byStat = array_column(StatTargets::extract(Game::Hsr, $html), null, 'stat');

        $this->assertSame(3000.0, $byStat['hp']['min']);
        $this->assertSame(2500.0, $byStat['atk']['min']);
        $this->assertSame(3000.0, $byStat['atk']['max']);
        $this->assertSame(75.0, $byStat['effectHitRate_']['min']);
        $this->assertSame(160.0, $byStat['spd']['min']);
    }

    public function test_ignores_effect_descriptions(): void
    {
        $this->assertSame([], StatTargets::extract(Game::Genshin, '4pc grants the party 20% ATK after Burst and 35% Charged Attack DMG.'));
    }
}
