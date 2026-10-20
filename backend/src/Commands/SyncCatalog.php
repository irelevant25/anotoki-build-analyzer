<?php

namespace App\Commands;

use App\App;
use App\Cli;
use App\Enums\Game;
use App\Services\Catalog\CatalogSync;
use Throwable;

/**
 * sync-catalog.php: refresh the game catalog from the command line - each list of each game, as the Builds page
 * does one list at a time. Without --force a game whose catalog was refreshed within refresh_days is left as it is.
 */
final class SyncCatalog
{
    /** @param list<string> $argv */
    public static function run(App $app, array $argv): int
    {
        [$options, $positional] = Cli::arguments($argv);

        $games = Game::cases();
        if (isset($positional[0])) {
            $game = Game::tryFrom($positional[0]);
            if ($game === null) {
                Cli::error('The game is ' . implode(' or ', array_map(fn(Game $game) => $game->value, Game::cases())) . '.');

                return 1;
            }
            $games = [$game];
        }

        $sync = $app->get(CatalogSync::class);
        $failed = false;
        foreach ($games as $game) {
            if (!isset($options['force']) && !$sync->isStale($game)) {
                Cli::line("{$game->label()}: refreshed within " . (int) $app->config->get('catalog.refresh_days', 3) . ' days, skipping (use --force to refresh).');

                continue;
            }
            foreach (CatalogSync::LISTS as $list) {
                try {
                    Cli::line("{$game->label()}: {$sync->syncList($game, $list)} {$list}.");
                } catch (Throwable $e) {
                    $failed = true;
                    Cli::error("{$game->label()} {$list}: {$e->getMessage()}");
                }
            }
        }

        return $failed ? 1 : 0;
    }
}
