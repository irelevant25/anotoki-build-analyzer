<?php

namespace App\Services\Builds;

use App\Enums\Game;

/**
 * One website that publishes recommended builds for one game.
 *
 * key() and name() are internal (the command line and the docs use them). The API and the UI never name a
 * site: they show a source as "Source N" of its game (BuildSourceRegistry::label()).
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
