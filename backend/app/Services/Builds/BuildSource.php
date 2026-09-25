<?php

namespace App\Services\Builds;

use App\Enums\Game;

/**
 * One website that publishes recommended builds for one game.
 */
interface BuildSource
{
    /** Unique key stored in build_sources.key, e.g. "genshin-builds.genshin". */
    public function key(): string;

    public function game(): Game;

    public function name(): string;

    public function baseUrl(): string;

    /** Milliseconds to wait between two requests to this site. */
    public function delayMs(): int;

    /**
     * @return list<CharacterRef>
     */
    public function listCharacters(): array;

    /**
     * @return list<ScrapedBuild>
     */
    public function fetchBuilds(CharacterRef $character): array;
}
