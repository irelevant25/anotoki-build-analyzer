<?php

namespace App\Console\Commands;

use App\Enums\Game;
use App\Services\Catalog\CatalogSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('catalog:sync {game? : genshin or hsr (default: both)} {--force : Refresh even when the catalog is fresh}')]
#[Description('Download character, weapon and set lists (names, rarity, elements, icons) from yatta.moe')]
class SyncCatalog extends Command
{
    public function handle(CatalogSync $sync): int
    {
        $games = $this->argument('game') ? [Game::from($this->argument('game'))] : Game::cases();
        $failed = false;

        foreach ($games as $game) {
            if (! $this->option('force') && ! $sync->isStale($game)) {
                $this->line("{$game->label()}: catalog is fresh, skipping (use --force to refresh).");

                continue;
            }

            try {
                $counts = $sync->sync($game);
                $this->info(sprintf('%s: %d characters, %d weapons, %d sets.', $game->label(), $counts['characters'], $counts['weapons'], $counts['sets']));
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$game->label()}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
