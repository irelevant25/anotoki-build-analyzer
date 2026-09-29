<?php

namespace App\Commands;

use App\App;
use App\Auth\UserProvisioner;
use App\Cli;
use App\Enums\Game;
use App\Repositories\Accounts;
use App\Services\Import\ImportException;
use App\Services\Import\ImportService;

/**
 * import-account.php: import a game account from the command line.
 *
 *   php import-account.php <genshin|hsr> <uid | path to an exported JSON file> [--account=ID] [--user=IAM_ID]
 */
final class ImportAccount
{
    /** @param list<string> $argv */
    public static function run(App $app, array $argv): int
    {
        [$options, $positional] = Cli::arguments($argv);

        if (count($positional) < 2) {
            Cli::error('Usage: php import-account.php <genshin|hsr> <uid|file> [--account=ID] [--user=IAM_ID]');

            return 1;
        }
        $game = Game::tryFrom($positional[0]);
        if ($game === null) {
            Cli::error('The game is genshin or hsr.');

            return 1;
        }
        $source = $positional[1];

        $userId = (int) ($options['user'] ?? $app->config->get('auth.local_user_id', 1));
        if ($userId < 1) {
            Cli::error('--user must be an IAM user id (a positive number).');

            return 1;
        }
        // Someone who has not signed in yet gets their row now; the name follows on their first sign-in.
        $owner = $app->get(UserProvisioner::class)->ensure($userId);

        $target = null;
        if (isset($options['account'])) {
            $target = $app->get(Accounts::class)->findOwned($userId, (int) $options['account'], withCounts: false);
            if (! $target) {
                Cli::error("User {$userId} has no account #{$options['account']}.");

                return 1;
            }
        }

        try {
            $imports = $app->get(ImportService::class);
            $run = is_file($source)
                ? $imports->fromFile((string) file_get_contents($source), $owner, $game, $target, basename($source))
                : $imports->fromEnka($game, $source, $owner, $target);
        } catch (ImportException $e) {
            Cli::error($e->getMessage());

            return 1;
        }

        $summary = $run->summary;
        Cli::info(sprintf('Imported into account #%d of user %d: %d characters, %d %s, %d %s (%s).',
            $run->account_id, $userId, $summary['characters'], $summary['weapons'], strtolower($game->weaponLabel()).'s',
            $summary['items'], strtolower($game->itemLabel()).'s', $run->status));

        foreach ($summary['warnings'] ?? [] as $warning) {
            Cli::warn("  ! {$warning}");
        }
        foreach ($summary['unmatched'] ?? [] as $bucket => $names) {
            Cli::warn("  ? not in catalog ({$bucket}): ".implode(', ', $names));
        }

        return 0;
    }
}
