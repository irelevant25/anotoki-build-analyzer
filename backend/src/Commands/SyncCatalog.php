<?php

namespace App\Commands;

use App\App;
use App\Cli;
use App\Enums\Game;
use App\Services\Catalog\CatalogAnswer;
use App\Services\Catalog\CatalogSync;
use Throwable;

/**
 * sync-catalog.php: refresh the game catalog from the command line - each list of each game from its provider, as
 * the admin panel's Builds page does one list at a time (a conditional request: a list as it was is left alone).
 * Without --force only a game whose catalog is stale (a list not confirmed within refresh_days) is asked, and then
 * by version tag; --force asks every list in full. --list=characters (weapons, sets) takes that list alone.
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
        $lists = CatalogSync::LISTS;
        if (isset($options['list'])) {
            if (!is_string($options['list']) || !in_array($options['list'], CatalogSync::LISTS, true)) {
                Cli::error('The list is ' . implode(', ', CatalogSync::LISTS) . '.');

                return 1;
            }
            $lists = [$options['list']];
        }
        $force = isset($options['force']);

        $sync = $app->get(CatalogSync::class);
        $failed = false;
        foreach ($games as $game) {
            if (!$force && !$sync->isStale($game)) {
                Cli::line("{$game->label()}: refreshed within " . (int) $app->config->get('catalog.refresh_days', 3) . ' days, skipping (use --force to refresh).');

                continue;
            }
            foreach ($lists as $list) {
                try {
                    $result = $sync->refresh($game, $list, $force);
                } catch (Throwable $e) {
                    $result = ['outcome' => CatalogAnswer::FAILED, 'written' => 0, 'problem' => $e->getMessage()];
                }
                match ($result['outcome']) {
                    CatalogAnswer::CHANGED => Cli::line("{$game->label()} {$list}: {$result['written']} written."),
                    CatalogAnswer::UNCHANGED => Cli::line("{$game->label()} {$list}: as they were."),
                    default => Cli::error("{$game->label()} {$list}: {$result['problem']}"),
                };
                $failed = $failed || !in_array($result['outcome'], [CatalogAnswer::CHANGED, CatalogAnswer::UNCHANGED], true);
            }
        }

        return $failed ? 1 : 0;
    }
}
