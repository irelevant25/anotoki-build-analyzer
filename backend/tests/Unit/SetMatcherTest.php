<?php

namespace Tests\Unit;

use App\Services\Analysis\SetMatcher;
use PHPUnit\Framework\TestCase;

class SetMatcherTest extends TestCase
{
    private function piece(array $keys, int $count): array
    {
        return ['keys' => $keys, 'count' => $count];
    }

    public function test_four_piece(): void
    {
        $option = ['pieces' => [$this->piece(['emblem'], 4)]];

        $this->assertTrue(SetMatcher::evaluate(['emblem' => 4, 'glad' => 1], $option)['satisfied']);
        $partial = SetMatcher::evaluate(['emblem' => 2, 'glad' => 3], $option);
        $this->assertFalse($partial['satisfied']);
        $this->assertGreaterThan(0, $partial['fraction']);
        $this->assertLessThan(1, $partial['fraction']);
    }

    public function test_two_plus_two(): void
    {
        $option = ['pieces' => [$this->piece(['glad'], 2), $this->piece(['shimenawa'], 2)]];

        $this->assertTrue(SetMatcher::evaluate(['glad' => 2, 'shimenawa' => 2, 'x' => 1], $option)['satisfied']);
        $this->assertFalse(SetMatcher::evaluate(['glad' => 4, 'x' => 1], $option)['satisfied']);
    }

    public function test_group_pieces_need_distinct_sets(): void
    {
        // "2pc EM set + 2pc EM set": a single 4pc Gilded Dreams gives the +80 EM bonus only once.
        $em = ['gilded', 'wanderer', 'instructor'];
        $option = ['pieces' => [$this->piece($em, 2), $this->piece($em, 2)]];

        $this->assertFalse(SetMatcher::evaluate(['gilded' => 4, 'x' => 1], $option)['satisfied']);
        $this->assertTrue(SetMatcher::evaluate(['gilded' => 2, 'wanderer' => 2, 'x' => 1], $option)['satisfied']);
    }

    public function test_choose_two_of_many(): void
    {
        $option = [
            'choose' => 2,
            'pieces' => [$this->piece(['noblesse'], 2), $this->piece(['fury'], 2), $this->piece(['emblem'], 2)],
        ];

        $this->assertTrue(SetMatcher::evaluate(['fury' => 2, 'emblem' => 3], $option)['satisfied']);
        $this->assertFalse(SetMatcher::evaluate(['fury' => 2, 'other' => 3], $option)['satisfied']);
    }
}
