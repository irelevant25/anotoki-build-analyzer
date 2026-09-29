<?php

namespace App\Commands;

use App\App;
use App\Cli;
use App\Enums\Game;
use App\Models\SyncRun;
use App\Repositories\SyncRuns;
use App\Services\Sync\SyncRunner;

/**
 * sync-catalog.php: refresh the game catalog from the command line.
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
                Cli::error('The game is genshin or hsr.');

                return 1;
            }
            $games = [$game];
        }

        $runner = $app->get(SyncRunner::class);
        ['started' => $started, 'skipped' => $skipped] = $runner->startCatalog($games, isset($options['force']));
        foreach ($skipped as $skip) {
            Cli::line("{$skip['name']} is {$skip['reason']}, skipping (use --force to refresh).");
        }

        $ids = array_map(fn (SyncRun $run) => (int) $run->id, $started);
        $runner->runUntilDone($ids, fn (string $line) => Cli::line($line));

        return $app->get(SyncRuns::class)->anyFailed($ids) ? 1 : 0;
    }
}
