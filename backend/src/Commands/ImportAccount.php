<?php

namespace App\Commands;

use App\App;
use App\Cli;
use App\Enums\Game;
use App\Repositories\Accounts;
use App\Repositories\Users;
use App\Services\Import\ImportException;
use App\Services\Import\ImportService;

/**
 * import-account.php: import a game account from the command line.
 *
 *   php import-account.php <game> <uid | path to an exported JSON file> --user=IAM_ID [--account=ID]   (game: genshin)
 */
final class ImportAccount
{
    /** @param list<string> $argv */
    public static function run(App $app, array $argv): int
    {
        [$options, $positional] = Cli::arguments($argv);

        if (count($positional) < 2) {
            Cli::error('Usage: php import-account.php <game> <uid|file> --user=IAM_ID [--account=ID]   (game: ' . implode(' or ', array_map(fn(Game $game) => $game->value, Game::cases())) . ')');

            return 1;
        }
        $game = Game::tryFrom($positional[0]);
        if ($game === null) {
            Cli::error('The game is ' . implode(' or ', array_map(fn(Game $game) => $game->value, Game::cases())) . '.');

            return 1;
        }
        $source = $positional[1];

        $user = $options['user'] ?? null;
        $userId = is_string($user) && ctype_digit($user) ? (int) $user : 0;
        if ($userId < 1) {
            Cli::error('--user=IAM_ID is required: the anotoki IAM user id (a positive number) whose account this is.');

            return 1;
        }
        // Someone who has not signed in yet gets their row now; the name follows on their first sign-in.
        $owner = $app->get(Users::class)->ensure($userId, "user {$userId}");

        $target = null;
        if (isset($options['account'])) {
            $target = $app->get(Accounts::class)->findOwned($userId, (int) $options['account'], withCounts: false);
            if (!$target) {
                Cli::error("User {$userId} has no account #{$options['account']}.");

                return 1;
            }
        }

        try {
            $imports = $app->get(ImportService::class);
            $run = is_file($source) ? $imports->fromFile((string) file_get_contents($source), $owner, $target, basename($source)) : $imports->fromEnka($game, $source, $owner, $target);
        } catch (ImportException $e) {
            Cli::error($e->getMessage());

            return 1;
        }

        $summary = $run->summary;
        Cli::info(
            sprintf(
                'Imported into account #%d of user %d: %d characters, %d %s, %d %s (%s).',
                $run->account_id,
                $userId,
                $summary['characters'],
                $summary['weapons'],
                strtolower($game->weaponLabel()) . 's',
                $summary['items'],
                strtolower($game->itemLabel()) . 's',
                $run->status,
            ),
        );

        foreach ($summary['warnings'] ?? [] as $warning) {
            Cli::warn("  ! {$warning}");
        }
        foreach ($summary['unmatched'] ?? [] as $bucket => $names) {
            Cli::warn("  ? not in catalog ({$bucket}): " . implode(', ', $names));
        }

        return 0;
    }
}
