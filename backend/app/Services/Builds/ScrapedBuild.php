<?php

namespace App\Services\Builds;

use DateTimeInterface;

/**
 * A build as read from a website, before names are resolved against the catalog.
 *
 * Stats are already canonical keys (App\Support\Stats); weapons and sets are still display names.
 */
final class ScrapedBuild
{
    public function __construct(
        public string $variant,
        public ?string $role = null,
        public bool $recommended = false,
        /** @var list<array{name: string, refinement?: int|null, note?: string|null}> ordered best first */
        public array $weapons = [],
        /**
         * Set options ordered best first. Each option lists pieces; a piece accepts any of its names
         * (e.g. "any +80 EM set"). "choose" = how many pieces must be satisfied (null = all).
         *
         * @var list<array{kind: string, choose?: int|null, pieces: list<array{names: list<string>, label: string, count: int}>, note?: string|null}>
         */
        public array $sets = [],
        /** @var array<string, list<array{stat: string, priority: int}>> slot => acceptable main stats */
        public array $mainStats = [],
        /** @var list<array{stat: string, priority: int}> */
        public array $substats = [],
        /** @var list<array{talent: string, priority: int}> */
        public array $talents = [],
        /** @var list<array{stat: string, min: float|null, max: float|null, text: string}> */
        public array $statTargets = [],
        public ?string $notes = null,
        public ?string $sourceUrl = null,
        public ?DateTimeInterface $updatedAt = null,
        public ?string $gameVersion = null,
    ) {}
}
