<?php

namespace App\Services\Import;

use App\Enums\Game;
use App\Models\Account;
use App\Models\ImportRun;
use App\Models\User;
use App\Repositories\Accounts;
use App\Services\Import\Enka\EnkaClient;
use App\Services\Import\Enka\EnkaGenshinParser;
use App\Services\Import\Enka\EnkaHsrParser;
use App\Services\Import\Files\GoodParser;
use App\Services\Import\Files\HsrScannerParser;
use App\Web\HttpError;
use Throwable;

/**
 * Entry point for all imports (API and command line). Every import is made by and for one user.
 */
class ImportService
{
    public function __construct(
        private readonly EnkaClient $enka,
        private readonly EnkaGenshinParser $enkaGenshin,
        private readonly EnkaHsrParser $enkaHsr,
        private readonly GoodParser $good,
        private readonly HsrScannerParser $hsrScanner,
        private readonly AccountImporter $importer,
        private readonly Accounts $accounts,
    ) {}

    /**
     * A showcase import by UID.
     *
     * @param  User  $owner  the account (new or found by UID) is this user's; nobody else's is ever touched
     * @param  Account|null  $target  merge into this account of the owner's (e.g. one created from a file, which
     *                                has no UID yet); by default the account is found by UID
     */
    public function fromEnka(Game $game, string $uid, User $owner, ?Account $target = null): ImportRun
    {
        $uid = trim($uid);

        try {
            $json = $this->enka->profile($game, $uid);
            $parsed = $game === Game::Genshin ? $this->enkaGenshin->parse($json) : $this->enkaHsr->parse($json);
            $parsed->uid ??= $uid;

            return $this->importer->import($parsed, $owner, $target);
        } catch (HttpError $e) {
            // A refusal of the request (its owner gone meanwhile, a visitor's limit), not a failed import: nothing to record.
            throw $e;
        } catch (Throwable $e) {
            $this->importer->recordFailure($game, 'enka', $e, $owner, $target ?? $this->accounts->findOwnedByUid($owner->id, $game, $uid));

            throw $e;
        }
    }

    /**
     * Import an exported JSON file. The format (GOOD or the Star Rail scanner format) is detected from the content.
     */
    public function fromFile(string $contents, User $owner, ?Game $expectedGame = null, ?Account $target = null, ?string $fileName = null): ImportRun
    {
        $source = 'file';
        $game = $expectedGame ?? ($target?->game ?? Game::Genshin);

        try {
            // Some exporters write a UTF-8 BOM.
            $json = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents, true);
            if (!is_array($json)) {
                throw new ImportException('The file is not valid JSON.', 'file_not_json');
            }

            [$game, $source, $parsed] = match (true) {
                GoodParser::matches($json) => [Game::Genshin, 'good', $this->good->parse($json)],
                HsrScannerParser::matches($json) => [Game::Hsr, 'hsr_scanner', $this->hsrScanner->parse($json)],
                default => throw new ImportException(
                    'Unknown file format. Upload a Genshin Impact inventory export in the GOOD format, or a Honkai: Star Rail inventory export (with "relics" and "light_cones").',
                    'file_unknown_format',
                ),
            };

            if ($expectedGame && $expectedGame !== $game) {
                throw new ImportException("This file contains {$game->label()} data, but you chose {$expectedGame->label()}.", 'file_game_mismatch', [
                    'file_game' => $game->label(),
                    'chosen_game' => $expectedGame->label(),
                ]);
            }

            return $this->importer->import($parsed, $owner, $target, $fileName);
        } catch (HttpError $e) {
            // A refusal of the request (its owner gone meanwhile, a visitor's limit), not a failed import: nothing to record.
            throw $e;
        } catch (Throwable $e) {
            $this->importer->recordFailure($game, $source, $e, $owner, $target, $fileName);

            throw $e;
        }
    }
}
