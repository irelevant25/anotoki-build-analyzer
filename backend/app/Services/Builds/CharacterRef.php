<?php

namespace App\Services\Builds;

/**
 * A character as listed on a build website.
 */
final readonly class CharacterRef
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $url,
        /** False for announced / speculative characters that have no build yet. */
        public bool $released = true,
        public array $meta = [],
    ) {}
}
