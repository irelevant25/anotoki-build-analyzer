<?php

namespace App\Services\Import;

use App\Enums\Game;

/**
 * Normalized result of reading an import source, before anything is written to the database.
 *
 * Catalog references are arrays {external_id?: string, name?: string, key?: string}; the importer resolves
 * them (by in-game ID first, then by name).
 */
final class ParsedAccount
{
    /** @var list<array{ref: array, level: int, ascension: int|null, rank: int, talents: array|null, final_stats: array|null}> */
    public array $characters = [];

    /** @var list<array{ref: array, level: int, ascension: int|null, refinement: int, location: array|null, locked: bool, uid: string|null}> */
    public array $weapons = [];

    /**
     * Artifacts / relics. substats: [{key, value, rolls}] with canonical stat keys.
     *
     * @var list<array{set: array, slot: string, rarity: int, level: int, main_stat: string, main_value: float|null, substats: list<array{key: string, value: float, rolls: int|null}>, location: array|null, locked: bool, uid: string|null}>
     */
    public array $items = [];

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(
        public readonly Game $game,
        /** enka | good | hsr_scanner */
        public readonly string $source,
        /** True when the source is a full inventory export (replaces previous items), false for showcase data. */
        public readonly bool $fullInventory,
        public ?string $uid = null,
        public ?string $nickname = null,
        public ?int $level = null,
        public ?int $worldLevel = null,
        public ?string $signature = null,
        /** Extra details for display, e.g. the tool that produced the file. */
        public array $meta = [],
    ) {}
}
